<?php
/* =============================================================================
 * organizer_tracking.php
 * Registration Tracker ng Organizer — SEMS (Student Event Management System)
 * ============================================================================= */

// ─── SESSION AT DATABASE ───────────────────────────────────────────────────────
session_start();
$pdo = require_once '../includes/db.php';

// ─── GUARD ────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];

/* =============================================================================
 * ORGANIZER CONTEXT
 * ============================================================================= */
$myCtx = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = ?");
$myCtx->execute([$uid]);
$myCtxRow = $myCtx->fetch(PDO::FETCH_ASSOC) ?: ['org_id' => null, 'club_id' => null];
$myOrgId  = !empty($myCtxRow['org_id'])  ? (int)$myCtxRow['org_id']  : null;
$myClubId = !empty($myCtxRow['club_id']) ? (int)$myCtxRow['club_id'] : null;

/* =============================================================================
 * HELPER
 * ============================================================================= */
function buildOrgEventWhere(string $prefix, int $uid, ?int $orgId, ?int $clubId, array &$params): string
{
    $p = $prefix !== '' ? $prefix . '.' : '';
    $params[] = $uid;

    if ($orgId || $clubId) {
        $orParts = [];
        if ($orgId)  { $orParts[] = "{$p}org_id = ?";  $params[] = $orgId; }
        if ($clubId) { $orParts[] = "{$p}club_id = ?"; $params[] = $clubId; }
        return "({$p}organizer_id = ? OR " . implode(' OR ', $orParts) . ")";
    }

    return "{$p}organizer_id = ?";
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
$ps = $pdo->prepare("
    SELECT
        COALESCE(p.first_name,    o.first_name)    AS first_name,
        COALESCE(p.last_name,     o.last_name)     AS last_name,
        COALESCE(p.middle_name,   o.middle_name)   AS middle_name,
        COALESCE(p.profile_image, o.profile_image) AS profile_image,
        o.position,
        d.dept_name
    FROM users u
    LEFT JOIN profiles    p ON u.user_id = p.user_id
    LEFT JOIN organizer   o ON u.user_id = o.user_id
    LEFT JOIN departments d ON u.dept_id  = d.dept_id
    WHERE u.user_id = ?
");
$ps->execute([$uid]);
$profile = $ps->fetch(PDO::FETCH_ASSOC);

$firstName  = $profile['first_name'] ?? 'Organizer';
$lastName   = $profile['last_name']  ?? '';
$middleName = !empty($profile['middle_name'])
    ? ' ' . strtoupper(substr($profile['middle_name'], 0, 1)) . '. '
    : ' ';
$fullName = htmlspecialchars(($profile['first_name'] ?? '') . $middleName . ($profile['last_name'] ?? ''));
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
if (empty(trim($initials))) $initials = 'OR';
$deptName = htmlspecialchars($profile['dept_name'] ?? 'Department');

$hasImage         = !empty($profile['profile_image']);
$mime             = 'image/jpeg';
$profileImageData = '';

if ($hasImage) {
    try {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $det   = finfo_buffer($finfo, $profile['profile_image']);
        if ($det && strpos($det, 'image/') === 0) $mime = $det;
        $profileImageData = base64_encode($profile['profile_image']);
    } catch (Exception $e) {
        $hasImage = false;
    }
}

/* =============================================================================
 * SIDEBAR BADGE
 * ============================================================================= */
$sbParams = [];
$sbWhere  = buildOrgEventWhere('', $uid, $myOrgId, $myClubId, $sbParams);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $sbWhere AND status != 'rejected' AND deleted_at IS NULL");
$stmt->execute($sbParams);
$myEvents = $stmt->fetchColumn();

/* =============================================================================
 * CLEANUP
 * ============================================================================= */
try {
    $pdo->exec("DELETE FROM registrations WHERE NOT EXISTS (
        SELECT 1 FROM events e WHERE e.event_id = registrations.event_id
    )");
} catch (Exception $e) {}

try {
    $pdo->exec("DELETE r FROM registrations r
                JOIN events e ON r.event_id = e.event_id
                WHERE e.status = 'rejected'");
} catch (Exception $e) {}

/* =============================================================================
 * PAGINATION
 * ============================================================================= */
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset  = ($page - 1) * $perPage;

/* =============================================================================
 * POST ACTION: Cancel Registration
 * ============================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reg'])) {
    $regId = (int)$_POST['reg_id'];

    if ($regId > 0) {
        $delParams = [$regId];
        $delWhere  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $delParams);

        $del = $pdo->prepare("
            DELETE r FROM registrations r
            JOIN events e ON r.event_id = e.event_id
            WHERE r.reg_id = ? AND $delWhere
        ");
        $del->execute($delParams);
    }

    header("Location: organizer_tracking.php");
    exit();
}

/* =============================================================================
 * FETCH REGISTRATIONS
 * ============================================================================= */
$rq1Params = [];
$rq1Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $rq1Params);
$rq2Params = [];
$rq2Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $rq2Params);

$regQ = $pdo->prepare("
    SELECT * FROM (

        SELECT
            r.reg_id,
            e.title             AS event_title,
            e.start_datetime,
            e.end_datetime,
            CONCAT(p.first_name, ' ', p.last_name) AS student_name,
            u.email,
            p.student_number,
            p.profile_image     AS student_photo,
            qr.qr_value,
            r.registered_at,
            CASE
                WHEN a.attendance_id IS NOT NULL                           THEN 'confirmed'
                WHEN e.end_datetime IS NOT NULL AND e.end_datetime < NOW() THEN 'absent'
                ELSE 'pending'
            END                 AS status,
            'manual'            AS reg_source
        FROM registrations r
        JOIN events e              ON r.event_id  = e.event_id
        JOIN users  u              ON r.user_id   = u.user_id AND u.role = 'student'
        LEFT JOIN profiles p       ON u.user_id   = p.user_id
        LEFT JOIN student_qr_codes qr ON qr.user_id = u.user_id
        LEFT JOIN attendance a     ON a.event_id  = r.event_id AND a.user_id = r.user_id
        WHERE $rq1Where
          AND e.status     = 'approved'
          AND e.deleted_at IS NULL

        UNION ALL

        SELECT
            NULL                AS reg_id,
            e.title             AS event_title,
            e.start_datetime,
            e.end_datetime,
            CONCAT(p.first_name, ' ', p.last_name) AS student_name,
            u.email,
            p.student_number,
            p.profile_image     AS student_photo,
            qr.qr_value,
            e.created_at        AS registered_at,
            CASE
                WHEN a.attendance_id IS NOT NULL                           THEN 'confirmed'
                WHEN e.end_datetime IS NOT NULL AND e.end_datetime < NOW() THEN 'absent'
                ELSE 'pending'
            END                 AS status,
            'auto'              AS reg_source
        FROM events e
        JOIN event_departments ed  ON ed.event_id = e.event_id
        JOIN users u               ON u.dept_id   = ed.dept_id AND u.role = 'student'
        LEFT JOIN profiles p       ON u.user_id   = p.user_id
        LEFT JOIN student_qr_codes qr ON qr.user_id = u.user_id
        LEFT JOIN attendance a     ON a.event_id  = e.event_id AND a.user_id = u.user_id
        LEFT JOIN registrations r  ON r.event_id  = e.event_id AND r.user_id = u.user_id
        WHERE $rq2Where
          AND e.status        = 'approved'
          AND e.deleted_at    IS NULL
          AND e.is_restricted = 1
          AND r.reg_id        IS NULL

    ) AS combined
    ORDER BY registered_at DESC
    LIMIT ? OFFSET ?
");
$regQ->execute(array_merge($rq1Params, $rq2Params, [$perPage, $offset]));
$registrations = $regQ->fetchAll(PDO::FETCH_ASSOC);

/* =============================================================================
 * PRE-PROCESS PROFILE IMAGES
 * ============================================================================= */
foreach ($registrations as &$reg) {
    if (!empty($reg['student_photo'])) {
        try {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $dm = finfo_buffer($fi, $reg['student_photo']);
            $reg['photo_mime'] = ($dm && strpos($dm, 'image/') === 0) ? $dm : 'image/jpeg';
            $reg['photo_b64']  = base64_encode($reg['student_photo']);
            $reg['has_photo']  = true;
        } catch (Exception $e) {
            $reg['has_photo'] = false;
        }
    } else {
        $reg['has_photo'] = false;
    }
    unset($reg['student_photo']);
}
unset($reg);

/* =============================================================================
 * TOTAL COUNT
 * ============================================================================= */
$cq1Params = [];
$cq1Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $cq1Params);
$cq2Params = [];
$cq2Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $cq2Params);

$countQ = $pdo->prepare("
    SELECT COUNT(*) FROM (
        SELECT 1 FROM registrations r
        JOIN events e ON r.event_id = e.event_id
        JOIN users  u ON r.user_id  = u.user_id AND u.role = 'student'
        WHERE $cq1Where
          AND e.status     = 'approved'
          AND e.deleted_at IS NULL

        UNION ALL

        SELECT 1 FROM events e
        JOIN event_departments ed ON ed.event_id = e.event_id
        JOIN users u              ON u.dept_id   = ed.dept_id AND u.role = 'student'
        LEFT JOIN registrations r ON r.event_id  = e.event_id AND r.user_id = u.user_id
        WHERE $cq2Where
          AND e.status        = 'approved'
          AND e.deleted_at    IS NULL
          AND e.is_restricted = 1
          AND r.reg_id        IS NULL
    ) AS combined
");
$countQ->execute(array_merge($cq1Params, $cq2Params));
$totalRegs  = (int)$countQ->fetchColumn();
$totalPages = (int)ceil($totalRegs / $perPage);

/* =============================================================================
 * STATUS SUMMARY
 * ============================================================================= */
$confirmedCount = count(array_filter($registrations, fn($r) => $r['status'] === 'confirmed'));
$pendingCount   = count(array_filter($registrations, fn($r) => $r['status'] === 'pending'));
$absentCount    = count(array_filter($registrations, fn($r) => $r['status'] === 'absent'));

/* ── Shared gradient palette ───────────────────────────────────────────────── */
$gradients = [
    'from-violet-400 to-purple-500',
    'from-sky-400 to-blue-500',
    'from-rose-400 to-pink-500',
    'from-amber-400 to-orange-500',
    'from-teal-400 to-cyan-500',
    'from-brand-400 to-emerald-500',
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrations – SEMS</title>
    <link rel="icon" href="/assets/registration-icon-indigo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/CSS/organizer_tracking.css">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        brand: {
                            50:  '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0',
                            300: '#86efac', 400: '#4ade80', 500: '#22c55e',
                            600: '#16a34a', 700: '#15803d', 800: '#166534', 900: '#14532d'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

    <!-- ─── Sidebar Overlay (Mobile) ──────────────────────────────────────────── -->
    <div id="sb-overlay" onclick="closeSidebar()"></div>


    <!-- =========================================================================
     SIDEBAR
    ========================================================================= -->
    <aside id="sidebar"
        class="fixed top-0 left-0 h-screen w-64 z-50
               bg-white dark:bg-gray-800
               border-r border-gray-200 dark:border-gray-700
               flex flex-col transition-transform duration-300
               -translate-x-full lg:translate-x-0">

        <!-- Sidebar Header -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <?php if ($hasOrgLogo): ?>
                    <img src="data:<?= $orgMime ?>;base64,<?= base64_encode($orgData['logo']) ?>"
                         class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                         alt="<?= $orgName ?>">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/30">
                        <i class="fas fa-building text-white text-lg"></i>
                    </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words">
                        <?= $orgName ?>
                    </p>
                    <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full
                                 bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                        <?= $orgType ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Navigation Links -->
        <nav class="flex-1 overflow-y-auto p-3 space-y-1">

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>

            <a href="/organizer/organizer_panel.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40
                             text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                    <i class="fas fa-gauge-high"></i>
                </span>
                Dashboard
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>

            <a href="/organizer/organizer_event.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40
                             text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                    <i class="fas fa-clipboard-list"></i>
                </span>
                <span class="flex-1">Events &amp; Announcements</span>
                <?php if ($myEvents > 0): ?>
                    <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400
                                 px-2 py-0.5 rounded-full font-semibold">
                        <?= $myEvents ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="/organizer/organizer_qrscan.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40
                             text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm">
                    <i class="fas fa-qrcode"></i>
                </span>
                QR Scanner
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>

            <a href="/organizer/organizer_tracking.php"
               class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40
                             text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm">
                    <i class="fas fa-users"></i>
                </span>
                <span class="flex-1">Registrations</span>
                <?php if ($totalRegs > 0): ?>
                    <span class="text-xs bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400
                                 px-2 py-0.5 rounded-full font-semibold">
                        <?= $totalRegs ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="/organizer/organizer_attendance.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40
                             text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fas fa-user-check"></i>
                </span>
                Attendance
            </a>

            <a href="/organizer/organizer_analytics.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40
                             text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                    <i class="fas fa-chart-line"></i>
                </span>
                Analytics
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Communication</p>

            <a href="/organizer/organizer_chat.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors">
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

        <!-- Sidebar Footer -->
        <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
            <a href="/organizer/organizer_settings.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700
                             text-gray-500 flex items-center justify-center text-sm">
                    <i class="fas fa-gear"></i>
                </span>
                Settings
            </a>
            <a href="../includes/logout.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30
                             text-red-500 flex items-center justify-center text-sm">
                    <i class="fas fa-right-from-bracket"></i>
                </span>
                Logout
            </a>
        </div>
    </aside>


    <!-- =========================================================================
     MAIN WRAPPER
    ========================================================================= -->
    <div class="lg:ml-64 min-h-screen flex flex-col">

        <!-- =====================================================================
         STICKY HEADER
        ===================================================================== -->
        <header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-800/90
                       border-b border-gray-200 dark:border-gray-700 px-4 sm:px-6 py-3"
                style="backdrop-filter: blur(10px);">

            <div class="flex items-center gap-3">

                <!-- Hamburger (Mobile) -->
                <button onclick="openSidebar()"
                        class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700
                               text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-bars"></i>
                </button>

                <!-- Page Title -->
                <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">
                    Registrations
                </span>

                <!-- Live Chip -->
                <span class="hidden md:flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500
                             bg-gray-100 dark:bg-gray-700 px-3 py-1.5 rounded-full border border-gray-200 dark:border-gray-600">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                    <?= $orgName ?> &middot; <?= $totalRegs ?> records
                </span>

                <!-- Right Actions -->
                <div class="flex items-center gap-2 ml-auto">

                    <!-- Export CSV (Desktop) -->
                    <button onclick="exportToCSV()"
                            class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold
                                   bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                                   hover:bg-brand-500 hover:text-white transition-all active:scale-95">
                        <i class="fas fa-file-export text-xs"></i>
                        <span class="hidden md:inline">Export CSV</span>
                    </button>

                    <!-- Dark Mode Toggle -->
                    <button onclick="toggleTheme()"
                            title="Toggle theme"
                            class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400
                                   hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                        <i id="themeIcon" class="fas fa-moon text-sm"></i>
                    </button>

                    <!-- Profile -->
                    <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">
                        <div class="hidden sm:block text-right leading-tight">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= $fullName ?></p>
                            <p class="text-xs text-gray-400">
                                <?= htmlspecialchars($profile['position'] ?? $deptName) ?>
                            </p>
                        </div>
                        <div class="w-9 h-9 rounded-full overflow-hidden
                                    bg-gradient-to-br from-brand-400 to-blue-500
                                    flex items-center justify-center text-white text-xs font-bold
                                    ring-2 ring-brand-200 dark:ring-brand-700
                                    hover:scale-105 transition-transform cursor-pointer">
                            <?php if ($hasImage): ?>
                                <img src="data:<?= $mime ?>;base64,<?= $profileImageData ?>"
                                     class="w-full h-full object-cover" alt="Profile">
                            <?php else: ?>
                                <?= $initials ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>


        <!-- =====================================================================
         MAIN CONTENT
        ===================================================================== -->
        <main class="flex-1 p-4 sm:p-6 space-y-5 max-w-7xl mx-auto w-full">

            <!-- ── Section Heading + Stat Cards ────────────────────────────────── -->
            <div class="anim-up d-0 section-head flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">Student Registrations</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        View and manage all student sign-ups for your events.
                    </p>
                </div>

                <!-- Stat cards — wrap on small screens -->
                <div class="stat-strip">
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-brand-500"><?= $confirmedCount ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Confirmed</p>
                    </div>
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-amber-500"><?= $pendingCount ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Pending</p>
                    </div>
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-red-500"><?= $absentCount ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Absent</p>
                    </div>
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center">
                        <p class="text-2xl font-bold text-gray-700 dark:text-white"><?= $totalRegs ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Total</p>
                    </div>
                </div>
            </div>


            <!-- ── Filter Bar ──────────────────────────────────────────────────── -->
            <div class="anim-up d-1 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4
                        flex flex-col sm:flex-row gap-3">

                <div class="flex-1 relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text"
                           id="pageSearch"
                           oninput="filterTable()"
                           placeholder="Search by name, email, QR or event…"
                           class="w-full pl-9 pr-4 py-2.5 text-sm rounded-xl
                                  bg-gray-50 dark:bg-gray-700
                                  border border-gray-200 dark:border-gray-600
                                  focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none
                                  text-gray-700 dark:text-gray-200 placeholder-gray-400 transition-colors">
                </div>

                <div class="flex gap-2">
                    <select id="statusFilter"
                            onchange="filterTable()"
                            class="flex-1 sm:flex-none px-4 py-2.5 text-sm rounded-xl
                                   bg-gray-50 dark:bg-gray-700
                                   border border-gray-200 dark:border-gray-600
                                   focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none
                                   text-gray-700 dark:text-gray-200 transition-colors appearance-none cursor-pointer">
                        <option value="">All Status</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="pending">Pending</option>
                        <option value="absent">Absent</option>
                    </select>

                    <!-- Export CSV (Mobile) -->
                    <button onclick="exportToCSV()"
                            class="sm:hidden px-4 py-2.5 rounded-xl text-sm font-semibold
                                   bg-brand-500 hover:bg-brand-600 text-white transition-colors active:scale-95">
                        <i class="fas fa-file-export"></i>
                    </button>
                </div>
            </div>


            <!-- ── EMPTY STATE ─────────────────────────────────────────────────── -->
            <?php if (empty($registrations)): ?>
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl
                            border-2 border-dashed border-gray-300 dark:border-gray-600 p-12 sm:p-16 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4 relative">
                        <span class="absolute inset-0 rounded-full bg-brand-500/10 animate-ping"></span>
                        <i class="fas fa-users text-4xl text-gray-300 dark:text-gray-600 relative z-10"></i>
                    </div>
                    <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2">No registrations yet</h3>
                    <p class="text-sm text-gray-400 mb-6 max-w-sm mx-auto">
                        Students will appear here once they register for your events.
                    </p>
                    <a href="/organizer/organizer_event.php"
                       class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-500 hover:bg-brand-600
                              text-white text-sm font-semibold rounded-xl transition-colors active:scale-95">
                        <i class="fas fa-arrow-left"></i> View My Events
                    </a>
                </div>

            <?php else: ?>

                <!-- ================================================================
                     DATA CONTAINER — holds both mobile cards & desktop table
                ================================================================ -->
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl
                            border border-gray-200 dark:border-gray-700 overflow-hidden">

                    <!-- ============================================================
                         MOBILE CARD LIST  (visible on < 768px)
                    ============================================================ -->
                    <div class="mobile-card-list divide-y divide-gray-100 dark:divide-gray-700/60" id="mobileCardList">
                        <?php foreach ($registrations as $i => $reg):
                            $isConfirmed = $reg['status'] === 'confirmed';
                            $isAbsent    = $reg['status'] === 'absent';

                            if ($isConfirmed) {
                                $pill     = 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border-brand-200 dark:border-brand-800';
                                $pillIcon = 'fa-check-circle';
                            } elseif ($isAbsent) {
                                $pill     = 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800';
                                $pillIcon = 'fa-circle-xmark';
                            } else {
                                $pill     = 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800';
                                $pillIcon = 'fa-clock';
                            }

                            $initials2 = strtoupper(substr($reg['student_name'] ?: 'U', 0, 1));
                            $grad = $gradients[ord($initials2) % count($gradients)];
                        ?>
                        <div class="reg-card p-4 flex items-start gap-3"
                             data-status="<?= $reg['status'] ?>"
                             style="animation-delay: <?= $i * 40 ?>ms">

                            <!-- Avatar -->
                            <div class="flex-shrink-0 relative mt-0.5">
                                <?php if ($reg['has_photo']): ?>
                                    <img src="data:<?= $reg['photo_mime'] ?>;base64,<?= $reg['photo_b64'] ?>"
                                         class="w-10 h-10 rounded-xl object-cover avatar-ring"
                                         alt="<?= htmlspecialchars($reg['student_name']) ?>">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?= $grad ?>
                                                flex items-center justify-center text-xs font-bold text-white avatar-ring">
                                        <?= $initials2 ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($isConfirmed): ?>
                                    <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-brand-500
                                                 border-2 border-white dark:border-gray-800 rounded-full
                                                 flex items-center justify-center">
                                        <i class="fas fa-check text-white" style="font-size:6px;"></i>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Info block -->
                            <div class="flex-1 min-w-0">
                                <!-- Student name + student number -->
                                <div class="flex items-baseline gap-2 flex-wrap">
                                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-snug">
                                        <?= htmlspecialchars($reg['student_name']) ?>
                                    </p>
                                    <?php if ($reg['student_number']): ?>
                                        <span class="text-[11px] text-gray-400 font-mono">
                                            <?= htmlspecialchars($reg['student_number']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- Event title -->
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-1">
                                    <i class="fas fa-calendar-alt text-[9px] mr-1 text-brand-400"></i>
                                    <?= htmlspecialchars($reg['event_title']) ?>
                                    <?php if ($reg['reg_source'] === 'auto'): ?>
                                        <span class="ml-1 text-gray-300 dark:text-gray-600">
                                            <i class="fas fa-robot text-[9px]"></i>
                                        </span>
                                    <?php endif; ?>
                                </p>

                                <!-- Email -->
                                <p class="text-[11px] text-gray-400 mt-0.5 truncate">
                                    <i class="fas fa-envelope text-[9px] mr-1"></i>
                                    <?= htmlspecialchars($reg['email']) ?>
                                </p>

                                <!-- Bottom row: status + date + action -->
                                <div class="flex items-center gap-2 mt-2 flex-wrap">
                                    <!-- Status pill -->
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full
                                                 text-[11px] font-semibold border capitalize <?= $pill ?>">
                                        <i class="fas <?= $pillIcon ?> text-[9px]"></i>
                                        <?= $reg['status'] ?>
                                    </span>

                                    <!-- Date -->
                                    <span class="text-[11px] text-gray-400 flex items-center gap-1">
                                        <i class="fas fa-clock text-[9px]"></i>
                                        <?= date('M j, Y', strtotime($reg['registered_at'])) ?>
                                    </span>

                                    <!-- QR copy (if present) -->
                                    <?php if (!empty($reg['qr_value'])): ?>
                                        <button onclick="copyQR(this, '<?= htmlspecialchars(addslashes($reg['qr_value'])) ?>')"
                                                title="Copy QR value"
                                                class="inline-flex items-center gap-1 text-[11px] text-violet-500 dark:text-violet-400
                                                       hover:text-violet-700 dark:hover:text-violet-300 transition-colors">
                                            <i class="fas fa-qrcode text-[9px]"></i>
                                            <span class="font-mono max-w-[80px] truncate inline-block align-bottom">
                                                <?= htmlspecialchars(substr($reg['qr_value'], 0, 12)) ?>…
                                            </span>
                                            <i class="fas fa-copy text-[9px]"></i>
                                        </button>
                                    <?php endif; ?>

                                    <!-- Delete -->
                                    <?php if (!empty($reg['reg_id'])): ?>
                                        <form method="POST" class="inline delete-form ml-auto"
                                              data-reg-id="<?= $reg['reg_id'] ?>"
                                              data-student-name="<?= htmlspecialchars($reg['student_name']) ?>"
                                              data-event-title="<?= htmlspecialchars($reg['event_title']) ?>">
                                            <input type="hidden" name="reg_id" value="<?= $reg['reg_id'] ?>">
                                            <button type="button"
                                                    class="delete-trigger w-7 h-7 rounded-lg flex items-center justify-center
                                                           bg-red-50 dark:bg-red-900/20 text-red-400 dark:text-red-400
                                                           border border-red-200 dark:border-red-800
                                                           hover:bg-red-500 hover:text-white dark:hover:bg-red-500 dark:hover:text-white
                                                           transition-all active:scale-95"
                                                    title="Cancel Registration">
                                                <i class="fas fa-trash-alt" style="font-size:10px;"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="ml-auto inline-flex items-center gap-1 text-[10px] text-gray-300 dark:text-gray-600 italic">
                                            <i class="fas fa-robot text-[9px]"></i> Auto
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div><!-- /mobile-card-list -->


                    <!-- ============================================================
                         DESKTOP TABLE  (visible on >= 768px)
                    ============================================================ -->
                    <div class="desktop-table-wrap overflow-x-auto">
                        <table class="w-full text-sm" id="regTable">

                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700
                                           text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <th class="px-5 py-3.5 text-left">Event</th>
                                    <th class="px-5 py-3.5 text-left">Student</th>
                                    <th class="px-5 py-3.5 text-left col-email">Email</th>
                                    <th class="px-5 py-3.5 text-left col-qr">
                                        <span class="flex items-center gap-1.5">
                                            <i class="fas fa-qrcode text-violet-400"></i> QR Value
                                        </span>
                                    </th>
                                    <th class="px-5 py-3.5 text-left col-registered">Registered</th>
                                    <th class="px-5 py-3.5 text-left">Status</th>
                                    <th class="px-5 py-3.5 text-left">Action</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">

                                <?php foreach ($registrations as $i => $reg):
                                    $isConfirmed = $reg['status'] === 'confirmed';
                                    $isAbsent    = $reg['status'] === 'absent';

                                    if ($isConfirmed) {
                                        $pill     = 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border-brand-200 dark:border-brand-800';
                                        $pillIcon = 'fa-check-circle';
                                    } elseif ($isAbsent) {
                                        $pill     = 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800';
                                        $pillIcon = 'fa-circle-xmark';
                                    } else {
                                        $pill     = 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800';
                                        $pillIcon = 'fa-clock';
                                    }

                                    $initials2 = strtoupper(substr($reg['student_name'] ?: 'U', 0, 1));
                                    $grad = $gradients[ord($initials2) % count($gradients)];
                                ?>
                                    <tr class="row-hover group"
                                        data-status="<?= $reg['status'] ?>"
                                        style="animation-delay: <?= $i * 40 ?>ms">

                                        <!-- CELL: Event Title -->
                                        <td class="px-5 py-4 max-w-[180px]">
                                            <span class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2
                                                         group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">
                                                <?= htmlspecialchars($reg['event_title']) ?>
                                            </span>
                                            <?php if ($reg['reg_source'] === 'auto'): ?>
                                                <span class="inline-flex items-center gap-1 mt-1 text-[10px] text-gray-400 dark:text-gray-500">
                                                    <i class="fas fa-robot text-[9px]"></i> Auto-enrolled
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- CELL: Student -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="flex-shrink-0 relative">
                                                    <?php if ($reg['has_photo']): ?>
                                                        <img src="data:<?= $reg['photo_mime'] ?>;base64,<?= $reg['photo_b64'] ?>"
                                                             class="w-9 h-9 rounded-xl object-cover avatar-ring"
                                                             alt="<?= htmlspecialchars($reg['student_name']) ?>">
                                                    <?php else: ?>
                                                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br <?= $grad ?>
                                                                    flex items-center justify-center text-xs font-bold text-white avatar-ring">
                                                            <?= $initials2 ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($isConfirmed): ?>
                                                        <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-brand-500
                                                                     border-2 border-white dark:border-gray-800 rounded-full
                                                                     flex items-center justify-center">
                                                            <i class="fas fa-check text-white" style="font-size: 6px;"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="font-semibold text-gray-900 dark:text-white text-sm truncate">
                                                        <?= htmlspecialchars($reg['student_name']) ?>
                                                    </p>
                                                    <?php if ($reg['student_number']): ?>
                                                        <p class="text-[11px] text-gray-400 mt-0.5 font-mono">
                                                            <?= htmlspecialchars($reg['student_number']) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- CELL: Email -->
                                        <td class="px-5 py-4 col-email">
                                            <span class="text-gray-500 dark:text-gray-400 text-xs truncate block max-w-[160px]">
                                                <?= htmlspecialchars($reg['email']) ?>
                                            </span>
                                        </td>

                                        <!-- CELL: QR Value -->
                                        <td class="px-5 py-4 col-qr">
                                            <?php if (!empty($reg['qr_value'])): ?>
                                                <div class="flex items-center gap-1.5">
                                                    <span class="qr-pill" title="<?= htmlspecialchars($reg['qr_value']) ?>">
                                                        <?= htmlspecialchars($reg['qr_value']) ?>
                                                    </span>
                                                    <button onclick="copyQR(this, '<?= htmlspecialchars(addslashes($reg['qr_value'])) ?>')"
                                                            title="Copy QR value"
                                                            class="w-6 h-6 rounded-md flex items-center justify-center flex-shrink-0
                                                                   text-gray-400 hover:text-violet-600 dark:hover:text-violet-400
                                                                   hover:bg-violet-50 dark:hover:bg-violet-900/20
                                                                   transition-all active:scale-90">
                                                        <i class="fas fa-copy text-[10px]"></i>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-[11px] text-gray-300 dark:text-gray-600 italic flex items-center gap-1">
                                                    <i class="fas fa-minus text-[9px]"></i> None
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- CELL: Registered Date -->
                                        <td class="px-5 py-4 whitespace-nowrap col-registered">
                                            <p class="text-gray-700 dark:text-gray-300 text-xs font-medium">
                                                <?= date('M j, Y', strtotime($reg['registered_at'])) ?>
                                            </p>
                                            <p class="text-gray-400 text-[11px]">
                                                <?= date('g:i A', strtotime($reg['registered_at'])) ?>
                                            </p>
                                        </td>

                                        <!-- CELL: Status -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full
                                                         text-xs font-semibold border capitalize <?= $pill ?>">
                                                <i class="fas <?= $pillIcon ?> text-[10px]"></i>
                                                <?= $reg['status'] ?>
                                            </span>
                                        </td>

                                        <!-- CELL: Action -->
                                        <td class="px-5 py-4">
                                            <?php if (!empty($reg['reg_id'])): ?>
                                                <form method="POST"
                                                      class="inline delete-form"
                                                      data-reg-id="<?= $reg['reg_id'] ?>"
                                                      data-student-name="<?= htmlspecialchars($reg['student_name']) ?>"
                                                      data-event-title="<?= htmlspecialchars($reg['event_title']) ?>">
                                                    <input type="hidden" name="reg_id" value="<?= $reg['reg_id'] ?>">
                                                    <button type="button"
                                                            class="delete-trigger w-8 h-8 rounded-lg flex items-center justify-center
                                                                   bg-red-50 dark:bg-red-900/20 text-red-500 dark:text-red-400
                                                                   border border-red-200 dark:border-red-800
                                                                   hover:bg-red-500 hover:text-white dark:hover:bg-red-500 dark:hover:text-white
                                                                   transition-all active:scale-95"
                                                            title="Cancel Registration">
                                                        <i class="fas fa-trash-alt text-xs"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 text-[11px] text-gray-300 dark:text-gray-600 italic">
                                                    <i class="fas fa-robot text-[10px]"></i> Auto
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div><!-- /desktop-table-wrap -->


                    <!-- ── Table/Card Footer ────────────────────────────────────── -->
                    <div class="px-4 sm:px-5 py-3 bg-gray-50 dark:bg-gray-700/30
                                border-t border-gray-200 dark:border-gray-700
                                flex flex-wrap items-center justify-between gap-2 text-xs text-gray-400">
                        <span id="rowCount">Showing <?= count($registrations) ?> of <?= $totalRegs ?> records</span>
                        <span class="hidden sm:inline">Last updated: <?= date('M j, Y g:i A') ?></span>
                    </div>

                    <!-- ── Pagination ───────────────────────────────────────────── -->
                    <?php if ($totalPages > 1): ?>
                        <div class="px-4 sm:px-5 py-3 bg-gray-50 dark:bg-gray-700/30
                                    border-t border-gray-200 dark:border-gray-700
                                    flex flex-wrap items-center justify-between gap-3">

                            <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                Page <?= $page ?> of <?= $totalPages ?>
                            </span>

                            <!-- Scrollable page number strip on tiny screens -->
                            <div class="pagination-scroll flex items-center gap-1">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>"
                                       class="px-3 py-1.5 text-xs font-medium rounded-lg whitespace-nowrap
                                              bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                                              text-gray-600 dark:text-gray-300
                                              hover:bg-brand-50 dark:hover:bg-brand-900/20 hover:text-brand-600 dark:hover:text-brand-400
                                              transition-colors">
                                        <i class="fas fa-chevron-left mr-1"></i> Prev
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i === $page): ?>
                                        <span class="px-3 py-1.5 text-xs font-bold rounded-lg bg-brand-500 text-white whitespace-nowrap">
                                            <?= $i ?>
                                        </span>
                                    <?php else: ?>
                                        <a href="?page=<?= $i ?>"
                                           class="px-3 py-1.5 text-xs font-medium rounded-lg whitespace-nowrap
                                                  bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                                                  text-gray-600 dark:text-gray-300
                                                  hover:bg-brand-50 dark:hover:bg-brand-900/20 hover:text-brand-600 dark:hover:text-brand-400
                                                  transition-colors">
                                            <?= $i ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?= $page + 1 ?>"
                                       class="px-3 py-1.5 text-xs font-medium rounded-lg whitespace-nowrap
                                              bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                                              text-gray-600 dark:text-gray-300
                                              hover:bg-brand-50 dark:hover:bg-brand-900/20 hover:text-brand-600 dark:hover:text-brand-400
                                              transition-colors">
                                        Next <i class="fas fa-chevron-right ml-1"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div><!-- /data container -->

            <?php endif; ?>
        </main>
    </div>


    <!-- ── Scroll to Top ──────────────────────────────────────────────────────── -->
    <button onclick="window.scrollTo({ top: 0, behavior: 'smooth' })"
            class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40
                   bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                   text-gray-500 dark:text-gray-400 shadow-lg
                   hover:bg-brand-500 hover:text-white hover:border-brand-500
                   transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
        <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
    </button>


    <!-- =========================================================================
     DELETE CONFIRMATION MODAL
    ========================================================================= -->
    <div id="deleteModal"
         class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/60"
         style="backdrop-filter: blur(6px)"
         onclick="if(event.target === this) closeDeleteModal()">

        <div class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-md
                    border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden">

            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <span class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/30 text-red-500
                             flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-triangle-exclamation"></i>
                </span>
                <h3 class="font-bold text-gray-900 dark:text-white">Cancel Registration</h3>
                <button onclick="closeDeleteModal()"
                        class="ml-auto w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400
                               hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors
                               flex items-center justify-center">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                    Are you sure you want to cancel the registration for
                    <strong class="text-gray-900 dark:text-white" id="modalStudentName"></strong>
                    from <strong class="text-gray-900 dark:text-white" id="modalEventTitle"></strong>?
                </p>

                <div class="flex items-start gap-2.5 bg-red-50 dark:bg-red-900/20
                            border border-red-200 dark:border-red-800 rounded-xl p-3 text-xs text-red-600 dark:text-red-400">
                    <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
                    This action cannot be undone. The student will be removed from this event.
                </div>

                <div class="flex gap-3 pt-1">
                    <button onclick="closeDeleteModal()"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold
                                   bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200
                                   hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                        No, Keep It
                    </button>
                    <button id="confirmDeleteBtn"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold
                                   bg-red-500 hover:bg-red-600 text-white
                                   shadow shadow-red-400/20 transition-all active:scale-95
                                   flex items-center justify-center gap-2">
                        <i class="fas fa-trash-alt"></i> Yes, Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ── Copy Toast ──────────────────────────────────────────────────────────── -->
    <div id="copyToast"
         class="fixed bottom-20 left-1/2 -translate-x-1/2 z-50
                px-4 py-2 rounded-xl text-xs font-semibold text-white
                bg-gray-900 dark:bg-gray-700 shadow-xl
                opacity-0 pointer-events-none transition-all duration-300 flex items-center gap-2">
        <i class="fas fa-check-circle text-brand-400"></i>
        <span id="copyToastText">QR value copied!</span>
    </div>


    <!-- ── PHP → JS data bridge ───────────────────────────────────────────────── -->
    <script>
        const SEMS_TRACKING_DATA = {
            exportDate: "<?= date('Y-m-d') ?>",
        };
    </script>

    <script src="/js/organizer_tracking.js"></script>

    <!-- ── Inline filterTable patch to also target mobile cards ──────────────── -->
    <script>
    (function () {
        /* Override filterTable so it filters BOTH the desktop tbody rows
           AND the mobile card divs at the same time. */
        window.filterTable = function () {
            const query  = (document.getElementById('pageSearch')?.value  || '').toLowerCase();
            const status = (document.getElementById('statusFilter')?.value || '').toLowerCase();

            /* ── Desktop table rows ── */
            document.querySelectorAll('#regTable tbody tr').forEach(tr => {
                const text = tr.textContent.toLowerCase();
                const s    = (tr.dataset.status || '').toLowerCase();
                const show = text.includes(query) && (!status || s === status);
                tr.style.display = show ? '' : 'none';
            });

            /* ── Mobile cards ── */
            document.querySelectorAll('#mobileCardList .reg-card').forEach(card => {
                const text = card.textContent.toLowerCase();
                const s    = (card.dataset.status || '').toLowerCase();
                const show = text.includes(query) && (!status || s === status);
                card.style.display = show ? '' : 'none';
            });

            /* ── Row count label ── */
            const visibleRows = document.querySelectorAll('#regTable tbody tr:not([style*="display: none"])').length;
            const label = document.getElementById('rowCount');
            if (label) {
                label.textContent = `Showing ${visibleRows} of <?= $totalRegs ?> records`;
            }
        };
    })();
    </script>

</body>
</html>