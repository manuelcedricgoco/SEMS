<?php
/* =============================================================================
 * organizer_panel.php
 * Dashboard ng Organizer — SEMS (Student Event Management System)
 * ============================================================================= */

session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

// ── ARCHIVED SESSION GUARD — add this below the existing guard ──
$archivedCheck = $pdo->prepare("SELECT deleted_at FROM users WHERE user_id = ? LIMIT 1");
$archivedCheck->execute([$_SESSION['user_id']]);
$archivedRow = $archivedCheck->fetch(PDO::FETCH_ASSOC);

if (!$archivedRow || !empty($archivedRow['deleted_at'])) {
    session_destroy();
    header("Location: ../includes/auth.php?error=archived");
    exit();
}

// ── ORG/CLUB ARCHIVED GUARD ──────────────────────────────────
$orgClubArchivedCheck = $pdo->prepare("
    SELECT
        o.deleted_at AS org_deleted,
        c.deleted_at AS club_deleted
    FROM users u
    LEFT JOIN organizations o ON u.org_id  = o.org_id
    LEFT JOIN clubs         c ON u.club_id = c.club_id
    WHERE u.user_id = ?
    LIMIT 1
");
$orgClubArchivedCheck->execute([$_SESSION['user_id']]);
$orgClubRow = $orgClubArchivedCheck->fetch(PDO::FETCH_ASSOC);

if ($orgClubRow && (!empty($orgClubRow['org_deleted']) || !empty($orgClubRow['club_deleted']))) {
    session_destroy();
    header("Location: ../includes/auth.php?error=org_archived");
    exit();
}

$uid = (int) $_SESSION['user_id'];


/* =============================================================================
 * ORGANIZER CONTEXT — org/club/dept + org scope
 * ============================================================================= */
$myCtx = $pdo->prepare("
    SELECT u.org_id, u.club_id, u.dept_id, o.scope AS org_scope
    FROM users u
    LEFT JOIN organizations o ON u.org_id = o.org_id
    WHERE u.user_id = ?
");
$myCtx->execute([$uid]);
$myRow     = $myCtx->fetch(PDO::FETCH_ASSOC) ?: [];
$myOrgId   = !empty($myRow['org_id'])   ? (int) $myRow['org_id']   : null;
$myClubId  = !empty($myRow['club_id'])  ? (int) $myRow['club_id']  : null;
$myDeptId  = !empty($myRow['dept_id'])  ? (int) $myRow['dept_id']  : null;
$myOrgScope = $myRow['org_scope'] ?? null; // 'all' | 'dept' | null

/*
 * FORCED VISIBILITY — organizer cannot override this:
 *   org scope='all'  → SSG / LSC          → visibility='all',  dept=NULL, club=NULL
 *   org scope='dept' → PAD Clan, JFMS…   → visibility='dept', dept=myDeptId
 *   club set, no org → Sci-Math, P2P…    → visibility='club', club=myClubId
 */
if ($myOrgId && $myOrgScope === 'all') {
    $forcedVisibility = 'all';
    $forcedDeptId     = null;
    $forcedClubId     = null;
    $forcedOrgId      = $myOrgId;
} elseif ($myOrgId && $myOrgScope === 'dept') {
    $forcedVisibility = 'dept';
    $forcedDeptId     = $myDeptId;
    $forcedClubId     = null;
    $forcedOrgId      = $myOrgId;
} elseif ($myClubId) {
    $forcedVisibility = 'club';
    $forcedDeptId     = null;
    $forcedClubId     = $myClubId;
    $forcedOrgId      = null;
} else {
    $forcedVisibility = 'all';
    $forcedDeptId     = null;
    $forcedClubId     = null;
    $forcedOrgId      = null;
}

$visBadgeMap = [
    'all'  => ['label' => 'All Students', 'cls' => 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400'],
    'dept' => ['label' => 'Department',   'cls' => 'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400'],
    'club' => ['label' => 'Club',         'cls' => 'bg-sky-100 dark:bg-sky-900/30 text-sky-600 dark:text-sky-400'],
];
$forcedBadge = $visBadgeMap[$forcedVisibility];


/* =============================================================================
 * HANDLE POST ANNOUNCEMENT (from modal on this page)
 * ============================================================================= */
$annSuccess = '';
$annError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_ann') {
    $annTitle   = trim($_POST['ann_title'] ?? '');
    $annBody    = trim($_POST['ann_body']  ?? '');
    $annPinned  = isset($_POST['ann_pinned']) ? 1 : 0;

    if ($annTitle === '' || $annBody === '') {
        $annError = 'Title and message are required.';
    } else {
        // Visibility is ALWAYS forced — $_POST['ann_visibility'] is ignored
        $ins = $pdo->prepare("
            INSERT INTO announcements
                (title, body, organizer_id, org_id, club_id, dept_id, visibility, is_pinned)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $annTitle,
            $annBody,
            $uid,
            $forcedOrgId,
            $forcedClubId,
            $forcedDeptId,
            $forcedVisibility,
            $annPinned,
        ]);
        $annSuccess = 'Announcement posted successfully!';
    }
}


/* =============================================================================
 * DYNAMIC WHERE CLAUSE for Events Queries
 * ============================================================================= */
$eventWhere  = "e.organizer_id = ?";
$eventParams = [$uid];

if ($myOrgId || $myClubId) {
    $eventWhere = "(e.organizer_id = ?";
    $orParts    = [];
    if ($myOrgId)  { $orParts[] = "e.org_id = ?";  $eventParams[] = $myOrgId; }
    if ($myClubId) { $orParts[] = "e.club_id = ?";  $eventParams[] = $myClubId; }
    $eventWhere .= " OR " . implode(' OR ', $orParts) . ")";
}


/* =============================================================================
 * ORGANIZATION / CLUB DETAILS
 * ============================================================================= */
$orgName    = 'Organization';
$orgType    = 'Organization';
$hasOrgLogo = false;
$orgData    = null;
$orgMime    = 'image/jpeg';

try {
    $orgQ = $pdo->prepare("
        SELECT o.org_name, o.logo, 'Organization' as type
        FROM users u
        LEFT JOIN organizations o ON u.org_id = o.org_id
        WHERE u.user_id = ? AND o.org_id IS NOT NULL
    ");
    $orgQ->execute([$uid]);
    $orgData = $orgQ->fetch(PDO::FETCH_ASSOC);

    if ($orgData) {
        $orgName    = htmlspecialchars($orgData['org_name']);
        $orgType    = $orgData['type'];
        $hasOrgLogo = !empty($orgData['logo']);
    } else {
        $clubQ = $pdo->prepare("
            SELECT c.club_name as org_name, c.logo, 'Club' as type
            FROM users u
            LEFT JOIN clubs c ON u.club_id = c.club_id
            WHERE u.user_id = ? AND c.club_id IS NOT NULL
        ");
        $clubQ->execute([$uid]);
        $orgData = $clubQ->fetch(PDO::FETCH_ASSOC);
        if ($orgData) {
            $orgName    = htmlspecialchars($orgData['org_name']);
            $orgType    = $orgData['type'];
            $hasOrgLogo = !empty($orgData['logo']);
        }
    }

    if ($hasOrgLogo && !empty($orgData['logo'])) {
        $finfo           = finfo_open(FILEINFO_MIME_TYPE);
        $detectedOrgMime = finfo_buffer($finfo, $orgData['logo']);
        if ($detectedOrgMime && strpos($detectedOrgMime, 'image/') === 0) {
            $orgMime = $detectedOrgMime;
        }
    }
} catch (Exception $e) {}


/* =============================================================================
 * PROFILE DATA
 * ============================================================================= */
$profileStmt = $pdo->prepare("
    SELECT
        COALESCE(p.first_name,    o.first_name)    as first_name,
        COALESCE(p.last_name,     o.last_name)     as last_name,
        COALESCE(p.middle_name,   o.middle_name)   as middle_name,
        COALESCE(p.profile_image, o.profile_image) as profile_image,
        o.position,
        d.dept_name
    FROM users u
    LEFT JOIN profiles  p ON u.user_id = p.user_id
    LEFT JOIN organizer o ON u.user_id = o.user_id
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE u.user_id = ?
");
$profileStmt->execute([$uid]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

$middleName = !empty($profile['middle_name'])
    ? ' ' . strtoupper(substr($profile['middle_name'], 0, 1)) . '. '
    : ' ';
$fullName = htmlspecialchars(($profile['first_name'] ?? '') . $middleName . ($profile['last_name'] ?? ''));
$initials = strtoupper(substr($profile['first_name'] ?? 'O', 0, 1) . substr($profile['last_name'] ?? '', 0, 1));
$hasImage = !empty($profile['profile_image']);
$mime     = 'image/jpeg';
if ($hasImage) {
    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_buffer($finfo, $profile['profile_image']);
    if ($detectedMime && strpos($detectedMime, 'image/') === 0) $mime = $detectedMime;
}


/* =============================================================================
 * STAT CARDS
 * ============================================================================= */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events e WHERE " . $eventWhere);
$stmt->execute($eventParams);
$myEvents = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM events e WHERE " . $eventWhere . " AND e.start_datetime > NOW() AND e.status = 'approved'");
$stmt->execute($eventParams);
$upcoming = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations r JOIN events e ON r.event_id = e.event_id WHERE " . $eventWhere);
$stmt->execute($eventParams);
$registrations = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN events e ON a.event_id = e.event_id WHERE " . $eventWhere . " AND a.login_time IS NOT NULL AND a.logout_time IS NOT NULL");
$stmt->execute($eventParams);
$attendance = $stmt->fetchColumn();

$attendanceRate = $registrations > 0 ? round(($attendance / $registrations) * 100) : 0;


/* =============================================================================
 * RECENT EVENTS
 * ============================================================================= */
$recentQ = $pdo->prepare("
    SELECT
        e.event_id, e.title, e.status, e.start_datetime, e.end_datetime, e.organizer_id,
        v.venue_name, v.capacity AS max_capacity,
        (SELECT COUNT(*) FROM registrations WHERE event_id = e.event_id) AS reg_count
    FROM events e
    LEFT JOIN venues v ON e.venue_id = v.venue_id
    WHERE " . $eventWhere . "
    ORDER BY e.created_at DESC
    LIMIT 6
");
$recentQ->execute($eventParams);
$recentEvents = $recentQ->fetchAll(PDO::FETCH_ASSOC);


/* =============================================================================
 * RECENT ACTIVITY FEED
 * ============================================================================= */
$activityQ = $pdo->prepare("
    (
        SELECT 'registration' AS type, r.registered_at AS date,
               COALESCE(p.first_name, u.email) AS username, e.title AS event_title
        FROM registrations r
        JOIN events e ON r.event_id = e.event_id
        JOIN users u ON r.user_id = u.user_id
        LEFT JOIN profiles p ON u.user_id = p.user_id
        WHERE " . $eventWhere . "
        ORDER BY r.registered_at DESC LIMIT 5
    )
    UNION ALL
    (
        SELECT 'event_created' AS type, e.created_at AS date, NULL AS username, e.title AS event_title
        FROM events e
        WHERE " . $eventWhere . "
        ORDER BY e.created_at DESC LIMIT 3
    )
    ORDER BY date DESC LIMIT 8
");
$activityQ->execute(array_merge($eventParams, $eventParams));
$activities = $activityQ->fetchAll(PDO::FETCH_ASSOC);


/* =============================================================================
 * CHART DATA
 * ============================================================================= */
$chartQ = $pdo->prepare("
    SELECT
        DATE_FORMAT(e.start_datetime, '%b') AS month,
        COUNT(DISTINCT e.event_id)          AS events,
        COUNT(DISTINCT r.reg_id)            AS registrations
    FROM events e
    LEFT JOIN registrations r ON e.event_id = r.event_id
    WHERE " . $eventWhere . "
      AND e.start_datetime >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(e.start_datetime, '%Y-%m'), DATE_FORMAT(e.start_datetime, '%b')
    ORDER BY e.start_datetime ASC
    LIMIT 6
");
$chartQ->execute($eventParams);
$chartData   = $chartQ->fetchAll(PDO::FETCH_ASSOC);
$chartLabels = json_encode(array_column($chartData, 'month'));
$chartEvents = json_encode(array_column($chartData, 'events'));
$chartRegs   = json_encode(array_column($chartData, 'registrations'));


/* =============================================================================
 * ANNOUNCEMENTS — scoped to this organizer's own org/club only
 * ============================================================================= */
$annParams    = [$uid];
$annOrgClause = "a.organizer_id = ?";

if ($myOrgId)  { $annOrgClause .= " OR a.org_id = ?";  $annParams[] = $myOrgId; }
if ($myClubId) { $annOrgClause .= " OR a.club_id = ?"; $annParams[] = $myClubId; }

$annQ = $pdo->prepare("
    SELECT
        a.announcement_id, a.title, a.body, a.is_pinned, a.visibility, a.created_at,
        COALESCE(o.first_name, p.first_name, u.email) AS author_name
    FROM announcements a
    JOIN users    u ON a.organizer_id = u.user_id
    LEFT JOIN organizer o ON u.user_id = o.user_id
    LEFT JOIN profiles  p ON u.user_id = p.user_id
    WHERE a.deleted_at IS NULL
      AND ($annOrgClause)
    ORDER BY a.is_pinned DESC, a.created_at DESC
    LIMIT 5
");
$annQ->execute($annParams);
$announcements = $annQ->fetchAll(PDO::FETCH_ASSOC);
$annCount      = count($announcements);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizer Dashboard – SEMS</title>
    <link rel="icon" href="/assets/dashboard-icon-indigo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/CSS/organizer_panel.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        brand: {
                            50:'#f0fdf4',100:'#dcfce7',200:'#bbf7d0',300:'#86efac',
                            400:'#4ade80',500:'#22c55e',600:'#16a34a',700:'#15803d',
                            800:'#166534',900:'#14532d',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

<div id="sb-overlay" onclick="closeSidebar()"></div>

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
<aside id="sidebar"
    class="fixed top-0 left-0 h-screen w-64 bg-white dark:bg-gray-800
           border-r border-gray-200 dark:border-gray-700
           flex flex-col z-50 transition-transform duration-300 -translate-x-full lg:translate-x-0">

    <div class="p-5 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-3">
            <?php if ($hasOrgLogo): ?>
                <img src="data:<?= $orgMime ?>;base64,<?= base64_encode($orgData['logo']) ?>"
                     class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                     alt="<?= $orgName ?>">
            <?php else: ?>
                <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/40">
                    <i class="fas fa-building text-white text-lg"></i>
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words"><?= $orgName ?></p>
                <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full
                             bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                    <?= $orgType ?>
                </span>
            </div>
        </div>
    </div>

    <nav class="flex-1 overflow-y-auto p-3 space-y-1">
        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>

        <a href="/organizer/organizer_panel.php"
           class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                <i class="fas fa-gauge-high"></i>
            </span>
            Dashboard
        </a>

        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>

        <a href="/organizer/organizer_event.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 flex items-center justify-center text-sm">
                <i class="fas fa-calendar-alt"></i>
            </span>
            <span class="flex-1">Events  & Announcements</span>
            <?php if ($myEvents > 0): ?>
                <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 px-2 py-0.5 rounded-full font-semibold"><?= $myEvents ?></span>
            <?php endif; ?>
        </a>

        <a href="/organizer/organizer_qrscan.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm">
                <i class="fas fa-qrcode"></i>
            </span>
            QR Scanner
        </a>

        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>

        <a href="/organizer/organizer_tracking.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm">
                <i class="fas fa-users"></i>
            </span>
            <span class="flex-1">Registrations</span>
            <?php if ($registrations > 0): ?>
                <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?= $registrations ?></span>
            <?php endif; ?>
        </a>

        <a href="/organizer/organizer_attendance.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                <i class="fas fa-user-check"></i>
            </span>
            Attendance
        </a>

        <a href="/organizer/organizer_analytics.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                <i class="fas fa-chart-line"></i>
            </span>
            Analytics
        </a>

        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Communication</p>
        <a href="/organizer/organizer_chat.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
           aria-current="page">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-rose-100 dark:bg-rose-900/40 text-rose-500 dark:text-rose-400 flex items-center justify-center text-sm">
    <i class="fas fa-comments"></i>
</span>
            Messages
            <span id="sidebarBadge"
                  class="ml-auto text-[11px] bg-brand-500 text-white px-1.5 py-0.5 rounded-full font-semibold hidden"></span>
        </a>

        <a href="/organizer/organizer_admin_chat.php"
                   class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
                    <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-500 dark:text-indigo-400 flex items-center justify-center text-sm">
    <i class="fas fa-user-shield"></i>
</span>
                    Admin Messages
                    <span id="adminBadge" class="ml-auto hidden text-[10px] font-bold bg-brand-500 text-white rounded-full px-1.5 py-0.5"></span>
                </a>
    </nav>

    <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
        <a href="/organizer/organizer_settings.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 flex items-center justify-center text-sm">
                <i class="fas fa-gear"></i>
            </span>
            Settings
        </a>
        <a href="../includes/logout.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-sm">
                <i class="fas fa-right-from-bracket"></i>
            </span>
            Logout
        </a>
    </div>
</aside>


<!-- ═══════════════════════════════════════════════════════════
     MAIN WRAPPER
════════════════════════════════════════════════════════════ -->
<div class="lg:ml-64 min-h-screen flex flex-col">

    <!-- HEADER -->
    <header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-800/90 border-b border-gray-200
                   dark:border-gray-700 px-4 sm:px-6 py-3"
            style="backdrop-filter:blur(10px);">
        <div class="flex items-center gap-3">
            <button onclick="openSidebar()"
                    class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                <i class="fas fa-bars text-base"></i>
            </button>
            <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">Dashboard</span>

            <div class="flex-1 mx-2 sm:mx-4 max-w-xs sm:max-w-sm">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" id="searchInput" onkeyup="filterEvents(this.value)"
                           placeholder="Search events…"
                           class="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-gray-100 dark:bg-gray-700
                                  border border-transparent focus:border-brand-400 dark:focus:border-brand-500
                                  text-gray-700 dark:text-gray-200 placeholder-gray-400 outline-none transition-colors duration-200">
                </div>
            </div>

            <div class="flex items-center gap-2 ml-auto">
                <button onclick="toggleTheme()" title="Toggle theme"
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400
                               hover:bg-gray-200 dark:hover:bg-gray-600 transition-all duration-300 hover:rotate-12">
                    <i id="themeIcon" class="fas fa-moon text-sm"></i>
                </button>

                <!-- Notification Bell -->
                <div class="relative">
                    <button onclick="toggleNotif(event)"
                            class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                                   hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors relative">
                        <i class="fas fa-bell text-sm"></i>
                        <?php if (count($activities) > 0): ?>
                            <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-red-500 rounded-full
                                         border-2 border-white dark:border-gray-800 animate-pulse"></span>
                        <?php endif; ?>
                    </button>
                    <div id="notifPanel"
                         class="hidden absolute right-0 mt-2 w-72 bg-white dark:bg-gray-800 rounded-xl shadow-xl
                                border border-gray-200 dark:border-gray-700 overflow-hidden z-50">
                        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                            <span class="font-semibold text-sm text-gray-900 dark:text-white">Notifications</span>
                            <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-500 px-2 py-0.5 rounded-full"><?= count($activities) ?></span>
                        </div>
                        <div class="max-h-60 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                            <?php foreach (array_slice($activities, 0, 5) as $act): ?>
                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                    <div class="flex items-start gap-3">
                                        <span class="w-7 h-7 rounded-lg flex items-center justify-center text-xs flex-shrink-0 mt-0.5
                                                     <?= $act['type'] === 'registration' ? 'bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' ?>">
                                            <i class="fas fa-<?= $act['type'] === 'registration' ? 'user-plus' : 'calendar-plus' ?>"></i>
                                        </span>
                                        <div>
                                            <p class="text-xs text-gray-700 dark:text-gray-300 leading-snug">
                                                <?php if ($act['type'] === 'registration'): ?>
                                                    <strong><?= htmlspecialchars($act['username']) ?></strong> registered for
                                                    <span class="text-brand-600 dark:text-brand-400"><?= htmlspecialchars($act['event_title']) ?></span>
                                                <?php else: ?>
                                                    Created event <span class="text-brand-600 dark:text-brand-400"><?= htmlspecialchars($act['event_title']) ?></span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-[11px] text-gray-400 mt-0.5"><?= date('M j, g:i A', strtotime($act['date'])) ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($activities)): ?>
                                <p class="text-center text-xs text-gray-400 py-6">No recent activity</p>
                            <?php endif; ?>
                        </div>
                        <a href="/organizer/organizer_tracking.php"
                           class="block text-center text-xs text-brand-600 dark:text-brand-400 py-2.5
                                  hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors
                                  border-t border-gray-200 dark:border-gray-700 font-medium">
                            View all activity →
                        </a>
                    </div>
                </div>

                <!-- Profile -->
                <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">
                    <div class="hidden sm:block text-right leading-tight">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= $fullName ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($profile['position'] ?? ($profile['dept_name'] ?? 'Organizer')) ?></p>
                    </div>
                    <div class="w-9 h-9 rounded-full overflow-hidden bg-gradient-to-br from-brand-400 to-blue-500
                                flex items-center justify-center text-white text-xs font-bold
                                ring-2 ring-brand-200 dark:ring-brand-700 hover:scale-105 transition-transform cursor-pointer">
                        <?php if ($hasImage): ?>
                            <img src="data:<?= $mime ?>;base64,<?= base64_encode($profile['profile_image']) ?>" class="w-full h-full object-cover" alt="Profile">
                        <?php else: ?>
                            <?= $initials ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>


    <!-- MAIN CONTENT -->
    <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-7xl mx-auto w-full">

        <!-- Flash Messages -->
        <?php if ($annSuccess): ?>
            <div class="flex items-center gap-3 p-4 bg-emerald-50 dark:bg-emerald-900/20
                        border border-emerald-200 dark:border-emerald-700 rounded-xl text-sm
                        text-emerald-700 dark:text-emerald-400">
                <i class="fas fa-circle-check"></i> <?= htmlspecialchars($annSuccess) ?>
            </div>
        <?php endif; ?>
        <?php if ($annError): ?>
            <div class="flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/20
                        border border-red-200 dark:border-red-700 rounded-xl text-sm
                        text-red-600 dark:text-red-400">
                <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($annError) ?>
            </div>
        <?php endif; ?>

        <!-- Welcome -->
        <div class="anim-up d-0">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                Welcome back, <?= htmlspecialchars($profile['first_name'] ?? 'Organizer') ?> 👋
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Here's a quick look at your events today.</p>
        </div>

        <!-- STAT CARDS -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <?php
            $stats = [
                ['label'=>'Total Events',   'value'=>$myEvents,           'icon'=>'fa-calendar-alt',   'bg'=>'bg-violet-100 dark:bg-violet-900/30','ic'=>'text-violet-600 dark:text-violet-400','bar'=>'bg-violet-400'],
                ['label'=>'Upcoming',        'value'=>$upcoming,           'icon'=>'fa-calendar-check', 'bg'=>'bg-brand-100 dark:bg-brand-900/30',  'ic'=>'text-brand-600 dark:text-brand-400',  'bar'=>'bg-brand-400'],
                ['label'=>'Registrations',   'value'=>$registrations,      'icon'=>'fa-users',          'bg'=>'bg-amber-100 dark:bg-amber-900/30',  'ic'=>'text-amber-600 dark:text-amber-400',  'bar'=>'bg-amber-400'],
                ['label'=>'Attendance Rate', 'value'=>$attendanceRate.'%', 'icon'=>'fa-chart-pie',      'bg'=>'bg-sky-100 dark:bg-sky-900/30',      'ic'=>'text-sky-600 dark:text-sky-400',      'bar'=>'bg-sky-400'],
            ];
            foreach ($stats as $i => $s): ?>
                <div class="card-hover anim-up d-<?= $i+1 ?> bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-start justify-between mb-4">
                        <span class="icon-wrap w-11 h-11 rounded-xl <?= $s['bg'] ?> <?= $s['ic'] ?> flex items-center justify-center text-lg">
                            <i class="fas <?= $s['icon'] ?>"></i>
                        </span>
                        <span class="text-xs text-brand-600 dark:text-brand-400 bg-brand-50 dark:bg-brand-900/20 px-2 py-0.5 rounded-full font-semibold">
                            <i class="fas fa-arrow-trend-up mr-0.5"></i> Live
                        </span>
                    </div>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $s['value'] ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 font-medium"><?= $s['label'] ?></p>
                    <div class="mt-3 h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="h-full rounded-full <?= $s['bar'] ?> opacity-80" style="width:<?= rand(55,92) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- CHART + ACTIVITY -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 card-hover anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Event Performance</h3>
                        <p class="text-xs text-gray-400 mt-0.5">Registrations vs Events — last 6 months</p>
                    </div>
                    <select class="text-sm bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 px-3 py-1.5 rounded-lg border-0 outline-none focus:ring-2 focus:ring-brand-400">
                        <option>Last 6 Months</option>
                        <option>This Year</option>
                    </select>
                </div>
                <div class="chart-wrap"><canvas id="perfChart"></canvas></div>
            </div>

            <div class="card-hover anim-up d-3 bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Recent Activity</h3>
                <div class="space-y-3 max-h-72 overflow-y-auto pr-1">
                    <?php foreach ($activities as $i => $act): ?>
                        <div class="anim-left d-<?= min($i,5) ?> flex items-start gap-3 pb-3 border-b border-gray-100 dark:border-gray-700/60 last:border-0 last:pb-0">
                            <span class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs
                                         <?= $act['type']==='registration' ? 'bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400' ?>">
                                <i class="fas fa-<?= $act['type']==='registration' ? 'user-plus' : 'calendar-plus' ?>"></i>
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-gray-700 dark:text-gray-300 leading-snug">
                                    <?php if ($act['type']==='registration'): ?>
                                        <strong class="text-gray-900 dark:text-white"><?= htmlspecialchars($act['username']) ?></strong>
                                        <span class="text-gray-400"> registered for </span>
                                        <span class="text-brand-600 dark:text-brand-400 font-medium"><?= htmlspecialchars($act['event_title']) ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-500">Created </span>
                                        <span class="text-brand-600 dark:text-brand-400 font-medium"><?= htmlspecialchars($act['event_title']) ?></span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-[11px] text-gray-400 mt-0.5"><?= date('M j, g:i A', strtotime($act['date'])) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($activities)): ?>
                        <div class="text-center py-8 text-gray-400">
                            <i class="fas fa-inbox text-3xl mb-2 block opacity-40"></i>
                            <p class="text-xs">No recent activity</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ANNOUNCEMENTS -->
        <div class="anim-up d-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                    <i class="fas fa-bullhorn text-rose-500 text-base"></i>
                    Announcements
                    <?php if ($annCount > 0): ?>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/30 text-rose-600 dark:text-rose-400">
                            <?= $annCount ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <div class="flex items-center gap-2">
                    <a href="/organizer/organizer_announcements.php"
                       class="text-sm text-brand-600 dark:text-brand-400 font-medium hover:underline flex items-center gap-1 group">
                        View all <i class="fas fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                    </a>
                    <button onclick="openAnnModal()"
                            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-xl
                                   bg-rose-500 hover:bg-rose-600 text-white transition-colors active:scale-95">
                        <i class="fas fa-plus text-[10px]"></i> Post
                    </button>
                </div>
            </div>

            <?php if (empty($announcements)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-dashed border-gray-300 dark:border-gray-600 p-10 text-center">
                    <i class="fas fa-bullhorn text-3xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">No announcements yet</p>
                    <p class="text-xs text-gray-400 mb-4">Keep your members informed by posting updates.</p>
                    <button onclick="openAnnModal()"
                            class="inline-flex items-center gap-2 px-4 py-2 bg-rose-500 hover:bg-rose-600
                                   text-white text-xs font-semibold rounded-xl transition-colors active:scale-95">
                        <i class="fas fa-plus"></i> Post Announcement
                    </button>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
                    <?php foreach ($announcements as $i => $ann):
                        $vb = $visBadgeMap[$ann['visibility']] ?? ['label'=>'All','cls'=>'bg-gray-100 text-gray-500'];
                    ?>
                        <div class="card-hover anim-up d-<?= min($i,4) ?> bg-white dark:bg-gray-800 rounded-2xl
                                    border border-gray-200 dark:border-gray-700 overflow-hidden relative flex">
                            <div class="w-1 flex-shrink-0 <?= $ann['is_pinned'] ? 'bg-amber-400' : 'bg-rose-400' ?>"></div>
                            <div class="flex gap-3 p-4 flex-1 min-w-0">
                                <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 text-sm
                                             <?= $ann['is_pinned'] ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400' : 'bg-rose-100 dark:bg-rose-900/30 text-rose-500 dark:text-rose-400' ?>">
                                    <i class="fas fa-<?= $ann['is_pinned'] ? 'thumbtack' : 'bullhorn' ?>"></i>
                                </span>
                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-wrap items-start gap-1.5 mb-1">
                                        <h4 class="font-semibold text-gray-900 dark:text-white text-sm leading-snug flex-1 min-w-0 line-clamp-1">
                                            <?= htmlspecialchars($ann['title']) ?>
                                        </h4>
                                        <?php if ($ann['is_pinned']): ?>
                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full flex-shrink-0 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">📌 Pinned</span>
                                        <?php endif; ?>
                                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full flex-shrink-0 <?= $vb['cls'] ?>"><?= $vb['label'] ?></span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-2 mb-2">
                                        <?= htmlspecialchars($ann['body']) ?>
                                    </p>
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="flex items-center gap-1 text-[11px] text-gray-400">
                                            <i class="fas fa-user-circle"></i> <?= htmlspecialchars($ann['author_name']) ?>
                                        </span>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[11px] text-gray-400"><?= date('M j, Y', strtotime($ann['created_at'])) ?></span>
                                            <a href="/organizer/organizer_announcements.php?id=<?= $ann['announcement_id'] ?>"
                                               class="text-gray-300 dark:text-gray-600 hover:text-rose-500 dark:hover:text-rose-400 transition-colors">
                                                <i class="fas fa-arrow-up-right-from-square text-[11px]"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- RECENT EVENTS GRID -->
        <div class="anim-up d-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Events</h3>
                <a href="/organizer/organizer_event.php"
                   class="text-sm text-brand-600 dark:text-brand-400 font-medium hover:underline flex items-center gap-1 group">
                    View all <i class="fas fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                </a>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4" id="eventGrid">
                <?php if (empty($recentEvents)): ?>
                    <div class="sm:col-span-2 xl:col-span-3 bg-white dark:bg-gray-800 rounded-2xl
                                border border-dashed border-gray-300 dark:border-gray-600 p-12 text-center">
                        <i class="fas fa-calendar-plus text-4xl text-gray-300 dark:text-gray-600 mb-4 block"></i>
                        <h4 class="font-semibold text-gray-700 dark:text-gray-300 mb-1">No events yet</h4>
                        <p class="text-sm text-gray-400 mb-5">Create your first event to see it here.</p>
                        <a href="/organizer/organizer_event.php"
                           class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-500 hover:bg-brand-600
                                  text-white text-sm font-semibold rounded-xl transition-colors active:scale-95">
                            <i class="fas fa-plus"></i> Create Event
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($recentEvents as $i => $ev):
                        $isCreator = (int)$ev['organizer_id'] === $uid;
                        $maxCap    = (int)($ev['max_capacity'] ?? 0);
                        $fillPct   = $maxCap > 0 ? min(100, round(($ev['reg_count'] / $maxCap) * 100)) : 0;
                        $statusMap = [
                            'approved' => ['pill'=>'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400','dot'=>'bg-emerald-500'],
                            'rejected' => ['pill'=>'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',               'dot'=>'bg-red-500'],
                            'pending'  => ['pill'=>'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',        'dot'=>'bg-amber-400'],
                        ];
                        $cfg = $statusMap[$ev['status']] ?? $statusMap['pending'];
                    ?>
                        <div class="card-hover anim-up d-<?= min($i,5) ?> bg-white dark:bg-gray-800
                                    rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden filterable-event"
                             data-title="<?= strtolower(htmlspecialchars($ev['title'])) ?>">
                            <div class="h-1.5 bg-gradient-to-r from-brand-400 to-blue-500"></div>
                            <div class="p-5">
                                <div class="flex items-start justify-between gap-2 mb-3">
                                    <h4 class="font-semibold text-gray-900 dark:text-white text-sm leading-snug line-clamp-2 flex-1">
                                        <?= htmlspecialchars($ev['title']) ?>
                                    </h4>
                                    <?php if (!$isCreator): ?>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full
                                                     bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400
                                                     border border-blue-200 dark:border-blue-700 flex-shrink-0">
                                            <i class="fas fa-users" style="font-size:.6rem"></i> Team
                                        </span>
                                    <?php endif; ?>
                                    <span class="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1
                                                 rounded-full capitalize flex-shrink-0 <?= $cfg['pill'] ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $cfg['dot'] ?>"></span>
                                        <?= $ev['status'] ?>
                                    </span>
                                </div>

                                <p class="text-xs text-gray-400 flex items-center gap-1.5 mb-3">
                                    <i class="fas fa-location-dot text-brand-400"></i>
                                    <?= htmlspecialchars($ev['venue_name'] ?? 'No venue') ?>
                                </p>

                                <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl p-3 mb-4">
                                    <span class="w-9 h-9 rounded-lg bg-white dark:bg-gray-600 shadow-sm
                                                 flex items-center justify-center text-brand-500 flex-shrink-0">
                                        <i class="fas fa-calendar-day text-sm"></i>
                                    </span>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-900 dark:text-white">
                                            <?= date('F j, Y', strtotime($ev['start_datetime'])) ?>
                                        </p>
                                        <p class="text-[11px] text-gray-400"><?= date('g:i A', strtotime($ev['start_datetime'])) ?></p>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <div class="flex justify-between text-xs mb-1.5 text-gray-500 dark:text-gray-400">
                                        <span>Registrations</span>
                                        <span class="font-semibold text-gray-700 dark:text-gray-200">
                                            <?= $ev['reg_count'] ?>/<?= $ev['max_capacity'] ?>
                                        </span>
                                    </div>
                                    <div class="h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full bg-gradient-to-r from-brand-400 to-blue-500 transition-all duration-700"
                                             style="width:<?= $fillPct ?>%"></div>
                                    </div>
                                </div>

                                <div class="flex gap-2">
                                    <a href="/organizer/organizer_event.php?id=<?= $ev['event_id'] ?>"
                                       class="flex-1 text-center py-2 text-xs font-semibold rounded-xl
                                              bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200
                                              hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors active:scale-95">
                                        Details
                                    </a>
                                    <a href="/organizer/organizer_qrscan.php?event=<?= $ev['event_id'] ?>"
                                       class="px-3 py-2 text-xs font-semibold rounded-xl
                                              bg-brand-50 dark:bg-brand-900/20 text-brand-600 dark:text-brand-400
                                              border border-brand-200 dark:border-brand-800
                                              hover:bg-brand-500 hover:text-white dark:hover:bg-brand-500 dark:hover:text-white
                                              transition-all active:scale-95">
                                        <i class="fas fa-qrcode"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<!-- Scroll to Top -->
<button onclick="window.scrollTo({top:0,behavior:'smooth'})"
        class="fixed bottom-5 right-5 w-10 h-10 rounded-full bg-brand-500 hover:bg-brand-600
               text-white shadow-lg shadow-brand-400/30 flex items-center justify-center text-sm
               hover:scale-110 active:scale-95 transition-all duration-200 z-40">
    <i class="fas fa-chevron-up"></i>
</button>


<!-- ═══════════════════════════════════════════════════════════
     POST ANNOUNCEMENT MODAL
     — Posts back to THIS page (organizer_panel.php)
     — Visibility is forced server-side; dropdown is read-only/display only
════════════════════════════════════════════════════════════ -->
<div id="annModal"
     class="fixed inset-0 z-50 hidden items-center justify-center p-4"
     style="background:rgba(0,0,0,.45);backdrop-filter:blur(4px);">

    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-lg
                border border-gray-200 dark:border-gray-700 overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-rose-100 dark:bg-rose-900/30 text-rose-500 flex items-center justify-center text-sm">
                    <i class="fas fa-bullhorn"></i>
                </span>
                <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Post Announcement</h3>
            </div>
            <button onclick="closeAnnModal()"
                    class="w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400
                           hover:text-gray-600 dark:hover:text-gray-200 flex items-center justify-center transition-colors">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="/organizer/organizer_panel.php" class="p-6 space-y-4">
            <input type="hidden" name="action" value="create_ann">

            <!-- Title -->
            <div>
                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    Title <span class="text-rose-500">*</span>
                </label>
                <input type="text" name="ann_title" required maxlength="200"
                       placeholder="e.g. Reminder: Attendance Policy"
                       class="w-full px-3.5 py-2.5 text-sm rounded-xl bg-gray-50 dark:bg-gray-700/60
                              border border-gray-200 dark:border-gray-600 text-gray-800 dark:text-gray-100
                              placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-rose-400
                              dark:focus:ring-rose-500 transition-all">
            </div>

            <!-- Body -->
            <div>
                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    Message <span class="text-rose-500">*</span>
                </label>
                <textarea name="ann_body" required rows="4" placeholder="Write your announcement here…"
                          class="w-full px-3.5 py-2.5 text-sm rounded-xl bg-gray-50 dark:bg-gray-700/60
                                 border border-gray-200 dark:border-gray-600 text-gray-800 dark:text-gray-100
                                 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-rose-400
                                 dark:focus:ring-rose-500 transition-all resize-none"></textarea>
            </div>

            <!-- Visibility (read-only display — forced by server) + Pin -->
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                        Reaches
                    </label>
                    <!-- Shown as a locked badge — organizer cannot change this -->
                    <div class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl
                                bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600">
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= $forcedBadge['cls'] ?>">
                            <?= $forcedBadge['label'] ?>
                        </span>
                        <i class="fas fa-lock text-gray-400 text-[10px] ml-auto" title="Set automatically based on your role"></i>
                    </div>
                </div>
                <div class="flex flex-col justify-end">
                    <label class="flex items-center gap-3 cursor-pointer px-3.5 py-2.5 rounded-xl
                                  bg-gray-50 dark:bg-gray-700/60 border border-gray-200 dark:border-gray-600 select-none">
                        <input type="checkbox" name="ann_pinned" value="1" class="w-4 h-4 rounded accent-amber-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">
                            <i class="fas fa-thumbtack text-amber-500 mr-1 text-xs"></i> Pin this
                        </span>
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-1">
                <button type="button" onclick="closeAnnModal()"
                        class="flex-1 py-2.5 text-sm font-semibold rounded-xl bg-gray-100 dark:bg-gray-700
                               text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600
                               transition-colors active:scale-95">
                    Cancel
                </button>
                <button type="submit"
                        class="flex-1 py-2.5 text-sm font-semibold rounded-xl bg-rose-500 hover:bg-rose-600
                               text-white transition-colors active:scale-95 flex items-center justify-center gap-2">
                    <i class="fas fa-paper-plane text-xs"></i> Post
                </button>
            </div>
        </form>
    </div>
</div>


<!-- Data Bridge: PHP → JS -->
<script>
    const SEMS_PANEL_DATA = {
        chartLabels: <?= $chartLabels ?>,
        chartEvents: <?= $chartEvents ?>,
        chartRegs:   <?= $chartRegs ?>,
    };
</script>
<script src="/js/organizer_panel.js"></script>

</body>
</html>