<?php
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── SESSION & DATABASE CONNECTION ──
// ═══════════════════════════════════════════════════════════════════════════════
session_start();
$pdo = require_once '../includes/db.php';
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── AUTH GUARD ──
// ═══════════════════════════════════════════════════════════════════════════════
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}
 
$currentAdminId = (int) $_SESSION['user_id'];
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── ARCHIVE EVENT HANDLER (replaces hard-delete) ──
// Purpose: Soft-delete by setting deleted_at + deleted_by instead of removing
//          the row. The event becomes "archived" and can be restored later.
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_event_id'])) {
    header('Content-Type: application/json');
 
    $event_id = intval($_POST['archive_event_id']);
 
    if ($event_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid event ID.']);
        exit();
    }
 
    try {
        $stmt = $pdo->prepare("
            UPDATE events
            SET deleted_at = NOW(),
                deleted_by = :admin_id
            WHERE event_id  = :event_id
              AND deleted_at IS NULL
        ");
        $stmt->execute([':admin_id' => $currentAdminId, ':event_id' => $event_id]);
 
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Event archived successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found or already archived.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
 
    exit();
}
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── RESTORE EVENT HANDLER ──
// Purpose: Undo the archive — clears deleted_at and deleted_by so the event
//          reappears in the active events list.
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_event_id'])) {
    header('Content-Type: application/json');
 
    $event_id = intval($_POST['restore_event_id']);
 
    if ($event_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid event ID.']);
        exit();
    }
 
    try {
        $stmt = $pdo->prepare("
            UPDATE events
            SET deleted_at = NULL,
                deleted_by = NULL
            WHERE event_id  = :event_id
              AND deleted_at IS NOT NULL
        ");
        $stmt->execute([':event_id' => $event_id]);
 
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Event restored successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found or not archived.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
 
    exit();
}
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── PERMANENT DELETE HANDLER ──
// Purpose: Hard-delete only events that are already archived (deleted_at IS NOT NULL).
//          This is a second-step safety net — users must archive first.
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['permanent_delete_event_id'])) {
    header('Content-Type: application/json');
 
    $event_id = intval($_POST['permanent_delete_event_id']);
 
    if ($event_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid event ID.']);
        exit();
    }
 
    try {
        $stmt = $pdo->prepare("
            DELETE FROM events
            WHERE event_id  = :event_id
              AND deleted_at IS NOT NULL
        ");
        $stmt->execute([':event_id' => $event_id]);
 
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Event permanently deleted.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Event not found or not in archive.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
 
    exit();
}
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── FETCH ADMIN PROFILE DATA ──
// ═══════════════════════════════════════════════════════════════════════════════
$adminProfileQuery = "
    SELECT a.first_name, a.last_name, a.middle_name, a.phone, a.profile_image,
           u.email
    FROM admin a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.user_id = :admin_id
    LIMIT 1
";
 
$adminStmt = $pdo->prepare($adminProfileQuery);
$adminStmt->execute(['admin_id' => $currentAdminId]);
$adminData = $adminStmt->fetch(PDO::FETCH_ASSOC);
 
$adminFirstName  = $adminData['first_name']  ?? '';
$adminLastName   = $adminData['last_name']   ?? '';
$adminMiddleName = $adminData['middle_name'] ?? '';
$adminFullName   = trim($adminFirstName . ' ' . $adminMiddleName . ' ' . $adminLastName);
$adminFullName   = $adminFullName !== '' ? htmlspecialchars($adminFullName) : 'Administrator';
 
$adminAvatar = '';
if (!empty($adminData['profile_image'])) {
    $imageData   = base64_encode($adminData['profile_image']);
    $adminAvatar = "data:image/jpeg;base64,{$imageData}";
}
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── FETCH ACTIVE EVENTS (deleted_at IS NULL) ──
// ═══════════════════════════════════════════════════════════════════════════════
$sqlActive = "
    SELECT
        e.event_id AS id,
        e.title,
        e.description,
        e.status,
        DATE_FORMAT(e.start_datetime, '%M %e, %Y') AS date,
        e.start_datetime,
        e.end_datetime,
        COALESCE(o.org_name, c.club_name, 'N/A') AS org
    FROM events e
    LEFT JOIN organizations o ON e.org_id = o.org_id
    LEFT JOIN clubs c         ON e.club_id = c.club_id
    WHERE e.deleted_at IS NULL
    ORDER BY e.created_at DESC
";
 
$stmtActive  = $pdo->query($sqlActive);
$events      = $stmtActive->fetchAll(PDO::FETCH_ASSOC);
 
// ═══════════════════════════════════════════════════════════════════════════════
// ── FETCH ARCHIVED EVENTS (deleted_at IS NOT NULL) ──
// ═══════════════════════════════════════════════════════════════════════════════
$sqlArchived = "
    SELECT
        e.event_id AS id,
        e.title,
        e.description,
        e.status,
        DATE_FORMAT(e.start_datetime, '%M %e, %Y')  AS date,
        DATE_FORMAT(e.deleted_at,     '%M %e, %Y')  AS archived_date,
        e.start_datetime,
        e.end_datetime,
        e.deleted_at,
        COALESCE(o.org_name, c.club_name, 'N/A')    AS org,
        CONCAT(a.first_name, ' ', a.last_name)       AS archived_by_name
    FROM events e
    LEFT JOIN organizations o ON e.org_id    = o.org_id
    LEFT JOIN clubs c         ON e.club_id   = c.club_id
    LEFT JOIN admin a         ON e.deleted_by = a.user_id
    WHERE e.deleted_at IS NOT NULL
    ORDER BY e.deleted_at DESC
";
 
$stmtArchived   = $pdo->query($sqlArchived);
$archivedEvents = $stmtArchived->fetchAll(PDO::FETCH_ASSOC);
 
// ── STATISTICS (active only) ──
$totalEvents    = count($events);
$approvedEvents = count(array_filter($events, fn($e) => $e['status'] === 'approved'));
$pendingEvents  = count(array_filter($events, fn($e) => $e['status'] === 'pending'));
$rejectedEvents = count(array_filter($events, fn($e) => $e['status'] === 'rejected'));
$archivedCount  = count($archivedEvents);
 
// ── JSON BRIDGE ──
$eventDataJson         = json_encode($events,         JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$archivedEventDataJson = json_encode($archivedEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
 
<!DOCTYPE html>
<html lang="en">
 
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SEMS Admin — Event Management</title>
    <link rel="icon" href="/assets/event-icon-indigo.svg" />
 
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        primary: { 50:'#eff6ff', 100:'#dbeafe', 400:'#60a5fa', 500:'#3b82f6', 600:'#2563eb' }
                    },
                    animation: {
                        'fade-up':  'fadeUp .5s ease both',
                        'fade-in':  'fadeIn .4s ease both',
                        'slide-in': 'slideIn .3s ease both',
                    },
                    keyframes: {
                        fadeUp:   { '0%':{'opacity':'0','transform':'translateY(20px)'}, '100%':{'opacity':'1','transform':'translateY(0)'} },
                        fadeIn:   { '0%':{'opacity':'0'}, '100%':{'opacity':'1'} },
                        slideIn:  { '0%':{'opacity':'0','transform':'translateX(-10px)'}, '100%':{'opacity':'1','transform':'translateX(0)'} },
                    }
                }
            }
        }
    </script>
 
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/CSS/admin_event_management.css">
</head>
 
<script>
    (function() {
        const t = localStorage.getItem('sems-theme') || 'light';
        if (t === 'dark') document.documentElement.classList.add('dark');
    })();
</script>
 
<body class="bg-gray-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 font-sans min-h-screen">
 
    <div id="overlay" onclick="closeSidebar()"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 opacity-0 pointer-events-none lg:hidden"></div>
 
    <div class="flex min-h-screen">
 
        <!-- ── SIDEBAR ── -->
        <aside id="sidebar"
            class="-translate-x-full lg:translate-x-0 fixed top-0 left-0 z-50 h-full w-64 flex flex-col
                   bg-white dark:bg-slate-800 border-r border-gray-200 dark:border-slate-700 shadow-xl">
 
            <div class="px-6 py-6 border-b border-gray-100 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center shadow-lg shadow-primary-500/30">
                        <i class="fas fa-calendar-check text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-bold text-slate-900 dark:text-white text-lg tracking-tight leading-none">SEMS</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Admin Panel</p>
                    </div>
                </div>
            </div>
 
            <nav class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 uppercase tracking-wider">Overview</p>
                <a href="/admin/admin_dashboard.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-th-large w-5 text-center"></i> Dashboard
                </a>
 
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Management</p>
                <a href="/admin/admin_event_management.php"
                    class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200">
                    <i class="fas fa-calendar-alt w-5 text-center"></i> Events
                </a>
                <a href="/admin/admin_aprovals.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-check-circle w-5 text-center"></i> Approvals
                </a>
                <a href="/admin/admin_user_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-users w-5 text-center"></i> Users
                </a>
                <a href="/admin/admin_org_club_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-building w-5 text-center"></i> Organizations &amp; Clubs
                </a>
 
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Insights</p>
                <a href="/admin/admin_insight.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-chart-line w-5 text-center"></i> Analytics
                </a>
            </nav>
 
            <div class="px-4 py-4 border-t border-gray-100 dark:border-slate-700 space-y-1">
                <a href="/admin/admin_settings.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-cog w-5 text-center"></i> Settings
                </a>
                <button onclick="toggleTheme()"
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i id="theme-icon" class="fas fa-moon w-5 text-center"></i>
                    <span id="theme-label">Dark Mode</span>
                </button>
                <a href="../includes/logout.php"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i> Logout
                </a>
            </div>
        </aside>
 
        <!-- ── MAIN CONTENT ── -->
        <main class="flex-1 lg:ml-64 min-w-0 flex flex-col">
 
            <!-- ── Sticky Header ── -->
            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border-b border-gray-200 dark:border-slate-700 px-4 sm:px-8 py-4 flex items-center gap-4 transition-colors duration-300">
                <button onclick="openSidebar()"
                    class="lg:hidden w-10 h-10 flex items-center justify-center flex-shrink-0 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 hover:text-primary-500 transition-all duration-200">
                    <i class="fas fa-bars text-sm"></i>
                </button>
 
                <div class="hidden sm:block mr-auto">
                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 mb-0.5">
                        <span>Admin</span>
                        <i class="fas fa-chevron-right text-xs"></i>
                        <span class="text-slate-900 dark:text-white font-medium">Events</span>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500"><?= date('l, F j, Y') ?></p>
                </div>
 
                <div class="flex-1 sm:flex-none sm:w-72 relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input id="liveSearch" type="text" placeholder="Search events..."
                        class="w-full pl-10 pr-4 py-2.5 text-sm rounded-xl bg-gray-100 dark:bg-slate-700 border-0 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:bg-white dark:focus:bg-slate-600 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all duration-200" />
                </div>
 
                <div class="flex items-center gap-3 pl-4 border-l border-gray-200 dark:border-slate-700">
                    <div class="hidden md:block text-right">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white leading-none"><?= $adminFullName ?></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Administrator</p>
                    </div>
                    <div class="relative group cursor-pointer">
                        <?php if ($adminAvatar): ?>
                            <img src="<?= $adminAvatar ?>" alt="<?= $adminFullName ?>"
                                class="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-600 shadow-md">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-sm font-bold shadow-md">
                                <?= strtoupper(substr($adminFirstName, 0, 1) . substr($adminLastName, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <span class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-slate-800 rounded-full"></span>
                    </div>
                </div>
            </header>
 
            <!-- ── Page Body ── -->
            <div class="flex-1 px-4 sm:px-8 py-8 space-y-8">
 
                <!-- Title -->
                <div class="animate-fade-up">
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white">Event Management</h1>
                    <p class="text-slate-500 dark:text-slate-400 mt-2">Monitor and manage all event statuses across the system.</p>
                </div>
 
                <!-- ── STATISTICS CARDS ── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5">
                    <?php
                    $allStats = [
                        ['label'=>'Total Events',    'value'=>$totalEvents,    'slug'=>'total',    'icon'=>'fa-calendar',     'bg'=>'bg-purple-100',  'darkBg'=>'dark:bg-purple-500/20',  'text'=>'text-purple-700',  'darkText'=>'dark:text-purple-300',  'iconColor'=>'text-purple-500',  'darkIcon'=>'dark:text-purple-400'],
                        ['label'=>'Approved Events', 'value'=>$approvedEvents, 'slug'=>'approved', 'icon'=>'fa-check-circle', 'bg'=>'bg-emerald-100', 'darkBg'=>'dark:bg-emerald-500/20', 'text'=>'text-emerald-700', 'darkText'=>'dark:text-emerald-300', 'iconColor'=>'text-emerald-500', 'darkIcon'=>'dark:text-emerald-400'],
                        ['label'=>'Pending Events',  'value'=>$pendingEvents,  'slug'=>'pending',  'icon'=>'fa-clock',        'bg'=>'bg-amber-100',   'darkBg'=>'dark:bg-amber-500/20',   'text'=>'text-amber-700',   'darkText'=>'dark:text-amber-300',   'iconColor'=>'text-amber-500',   'darkIcon'=>'dark:text-amber-400'],
                        ['label'=>'Rejected Events', 'value'=>$rejectedEvents, 'slug'=>'rejected', 'icon'=>'fa-times-circle', 'bg'=>'bg-rose-100',    'darkBg'=>'dark:bg-rose-500/20',    'text'=>'text-rose-700',    'darkText'=>'dark:text-rose-300',    'iconColor'=>'text-rose-500',    'darkIcon'=>'dark:text-rose-400'],
                        ['label'=>'Archived Events', 'value'=>$archivedCount,  'slug'=>'archived', 'icon'=>'fa-archive',      'bg'=>'bg-slate-100',   'darkBg'=>'dark:bg-slate-500/20',   'text'=>'text-slate-700',   'darkText'=>'dark:text-slate-300',   'iconColor'=>'text-slate-500',   'darkIcon'=>'dark:text-slate-400'],
                    ];
                    foreach ($allStats as $s): ?>
                        <div class="card-anim stat-card animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden cursor-pointer"
                            onclick="<?= $s['slug'] === 'archived' ? 'showArchivedView()' : "setFilter('" . ($s['slug'] === 'total' ? 'all' : $s['slug']) . "')" ?>">
                            <div class="absolute top-4 right-4 w-12 h-12 rounded-xl <?= $s['bg'] ?> <?= $s['darkBg'] ?> flex items-center justify-center">
                                <i class="fas <?= $s['icon'] ?> <?= $s['iconColor'] ?> <?= $s['darkIcon'] ?> text-lg"></i>
                            </div>
                            <div class="pr-14">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1"><?= $s['label'] ?></p>
                                <p id="stat-<?= $s['slug'] ?>"
                                   class="text-3xl font-bold <?= $s['text'] ?> <?= $s['darkText'] ?> leading-none">
                                    <?= $s['value'] ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
 
                <!-- ── VIEW TOGGLE ── -->
                <div class="flex items-center gap-3 animate-fade-up" style="animation-delay:.05s">
                    <div class="flex rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden bg-white dark:bg-slate-800 shadow-sm">
                        <button id="view-active-btn" onclick="showActiveView()"
                            class="px-4 py-2.5 text-xs font-semibold flex items-center gap-2 bg-primary-500 text-white transition-all duration-200">
                            <i class="fas fa-calendar-alt"></i> Active Events
                        </button>
                        <button id="view-archived-btn" onclick="showArchivedView()"
                            class="px-4 py-2.5 text-xs font-semibold flex items-center gap-2 text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all duration-200">
                            <i class="fas fa-archive"></i>
                            Archived
                            <?php if ($archivedCount > 0): ?>
                                <span class="ml-1 w-5 h-5 rounded-full bg-slate-200 dark:bg-slate-600 text-slate-700 dark:text-slate-300 text-[10px] font-bold flex items-center justify-center">
                                    <?= $archivedCount ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    </div>
                </div>
 
                <!-- ── ACTIVE EVENTS SECTION ── -->
                <div id="active-section">
 
                    <!-- Filter Tabs -->
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 animate-fade-up" style="animation-delay:.1s">
                        <div class="flex gap-2 flex-wrap">
                            <button id="tab-all" onclick="setFilter('all')"
                                class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-primary-500 text-white shadow-sm shadow-primary-500/30 transition-all duration-200">
                                All Events
                            </button>
                            <button id="tab-approved" onclick="setFilter('approved')"
                                class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-emerald-400 hover:text-emerald-600 transition-all duration-200">
                                <i class="fas fa-check-circle mr-1 text-emerald-500"></i> Approved
                            </button>
                            <button id="tab-pending" onclick="setFilter('pending')"
                                class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-amber-400 hover:text-amber-600 transition-all duration-200">
                                <i class="fas fa-clock mr-1 text-amber-500"></i> Pending
                            </button>
                            <button id="tab-rejected" onclick="setFilter('rejected')"
                                class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-rose-400 hover:text-rose-600 transition-all duration-200">
                                <i class="fas fa-times-circle mr-1 text-rose-500"></i> Rejected
                            </button>
                        </div>
                        <div class="sm:ml-auto">
                            <span id="result-count" class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400">
                                <i class="fas fa-table mr-1.5 text-primary-500"></i>
                                <span id="result-num">0</span> results
                            </span>
                        </div>
                    </div>
 
                    <!-- Active Events Table -->
                    <div class="animate-fade-up bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden" style="animation-delay:.15s">
                        <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
                            <div>
                                <p class="font-bold text-slate-900 dark:text-white text-lg">All Events</p>
                                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Sorted by most recent</p>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[640px]">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-slate-700/50">
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Event Name</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Organizer</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="event-table-body" class="divide-y divide-gray-100 dark:divide-slate-700">
                                    <!-- JS rendered by admin_event_manage.js -->
                                </tbody>
                            </table>
                        </div>
                        <div id="empty-state" class="hidden px-4 py-12 text-center">
                            <div class="w-16 h-16 bg-gray-100 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-calendar-times text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-slate-900 dark:text-white font-medium">No events found</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Try a different filter or search term</p>
                        </div>
                    </div>
                </div>
 
                <!-- ── ARCHIVED EVENTS SECTION ── -->
                <div id="archived-section" class="hidden space-y-5">
 
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 animate-fade-up">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-700 flex items-center justify-center">
                                <i class="fas fa-archive text-slate-500 dark:text-slate-400 text-sm"></i>
                            </div>
                            <p class="font-bold text-slate-900 dark:text-white">Archived Events</p>
                        </div>
                        <div class="sm:ml-auto flex items-center gap-3">
                            <div class="relative">
                                <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                                <input id="archiveSearch" type="text" placeholder="Search archived..."
                                    oninput="filterArchived()"
                                    class="pl-9 pr-4 py-2 text-sm rounded-xl bg-gray-100 dark:bg-slate-700 border-0 focus:outline-none focus:ring-2 focus:ring-primary-500/30 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all duration-200 w-52" />
                            </div>
                            <span id="archive-result-count" class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400">
                                <i class="fas fa-archive mr-1.5 text-slate-500"></i>
                                <span id="archive-result-num">0</span> archived
                            </span>
                        </div>
                    </div>
 
                    <?php if ($archivedCount > 0): ?>
                    <div class="flex items-center gap-3 px-4 py-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl text-sm text-amber-700 dark:text-amber-300 animate-fade-up">
                        <i class="fas fa-info-circle flex-shrink-0"></i>
                        <span>Archived events are hidden from students and organizers. Restore them to make them active again, or permanently delete to remove all data.</span>
                    </div>
                    <?php endif; ?>
 
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[700px]">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-slate-700/50">
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Event Name</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Organizer</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Original Date</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Archived On</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">By</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="archive-table-body" class="divide-y divide-gray-100 dark:divide-slate-700">
                                    <!-- populated by renderArchivedTable() -->
                                </tbody>
                            </table>
                        </div>
                        <div id="archive-empty-state" class="hidden px-4 py-12 text-center">
                            <div class="w-16 h-16 bg-gray-100 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-box-open text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-slate-900 dark:text-white font-medium">Archive is empty</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">No archived events yet.</p>
                        </div>
                    </div>
                </div>
 
                <div class="h-4"></div>
            </div>
        </main>
    </div>
 
    <!-- ── VIEW EVENT MODAL ── -->
    <div id="eventModal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg border border-gray-200 dark:border-slate-700 my-8 max-h-[88vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-primary-50 dark:bg-primary-500/10 flex items-center justify-center">
                            <i class="fas fa-calendar-alt text-primary-500 text-sm"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-sm">Event Details</p>
                            <p class="text-xs text-slate-400">Full event information</p>
                        </div>
                    </div>
                    <button onclick="closeModal()"
                        class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 flex items-center justify-center text-slate-500 transition-all duration-200">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4" id="modalBody"></div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-slate-700 flex justify-end flex-shrink-0">
                    <button onclick="closeModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
 
    <!-- ── ARCHIVE CONFIRMATION MODAL ── -->
    <div id="archiveModal" class="fixed inset-0 z-[200] hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeArchiveModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm border border-gray-200 dark:border-slate-700 text-center p-6">
                <div class="w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-500/10 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-archive text-amber-500 text-xl"></i>
                </div>
                <p class="font-bold text-slate-900 dark:text-white mb-2 text-lg">Archive Event?</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-2 leading-relaxed">
                    <strong id="archiveEventTitle" class="text-slate-900 dark:text-white"></strong>
                    will be hidden from all users but can be restored at any time.
                </p>
                <div class="flex gap-3 justify-center mt-5">
                    <button onclick="closeArchiveModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Cancel
                    </button>
                    <button id="confirmArchiveBtn" onclick="archiveEvent()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-amber-500 hover:bg-amber-600 text-white shadow-sm shadow-amber-500/30 transition-all duration-200 flex items-center gap-2">
                        <i class="fas fa-archive"></i> Archive
                    </button>
                </div>
            </div>
        </div>
    </div>
 
    <!-- ── RESTORE CONFIRMATION MODAL ── -->
    <div id="restoreModal" class="fixed inset-0 z-[200] hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeRestoreModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm border border-gray-200 dark:border-slate-700 text-center p-6">
                <div class="w-14 h-14 rounded-full bg-emerald-100 dark:bg-emerald-500/10 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-undo-alt text-emerald-500 text-xl"></i>
                </div>
                <p class="font-bold text-slate-900 dark:text-white mb-2 text-lg">Restore Event?</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-2 leading-relaxed">
                    <strong id="restoreEventTitle" class="text-slate-900 dark:text-white"></strong>
                    will become visible to users again.
                </p>
                <div class="flex gap-3 justify-center mt-5">
                    <button onclick="closeRestoreModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Cancel
                    </button>
                    <button id="confirmRestoreBtn" onclick="restoreEvent()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-emerald-500 hover:bg-emerald-600 text-white shadow-sm shadow-emerald-500/30 transition-all duration-200 flex items-center gap-2">
                        <i class="fas fa-undo-alt"></i> Restore
                    </button>
                </div>
            </div>
        </div>
    </div>
 
    <!-- ── PERMANENT DELETE CONFIRMATION MODAL ── -->
    <div id="permDeleteModal" class="fixed inset-0 z-[300] hidden">
        <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closePermDeleteModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm border border-rose-200 dark:border-rose-500/40 text-center p-6">
                <div class="w-14 h-14 rounded-full bg-rose-100 dark:bg-rose-500/10 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-skull-crossbones text-rose-500 text-xl"></i>
                </div>
                <p class="font-bold text-slate-900 dark:text-white mb-1 text-lg">Permanently Delete?</p>
                <p class="text-xs font-semibold text-rose-500 uppercase tracking-wider mb-3">This cannot be undone</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-2 leading-relaxed">
                    All data for <strong id="permDeleteEventTitle" class="text-slate-900 dark:text-white"></strong>
                    — including registrations, attendance, and feedback — will be erased forever.
                </p>
                <div class="flex gap-3 justify-center mt-5">
                    <button onclick="closePermDeleteModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Cancel
                    </button>
                    <button id="confirmPermDeleteBtn" onclick="permanentDeleteEvent()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-rose-600 hover:bg-rose-700 text-white shadow-sm shadow-rose-500/30 transition-all duration-200 flex items-center gap-2">
                        <i class="fas fa-trash-alt"></i> Delete Forever
                    </button>
                </div>
            </div>
        </div>
    </div>
 
    <!-- ── TOAST ── -->
    <div id="toast"
        class="fixed bottom-6 right-6 z-[999] hidden items-center gap-3 px-4 py-3 rounded-xl shadow-xl text-sm font-semibold text-white max-w-xs transition-all duration-300"
        style="animation: fadeUp .3s ease both">
        <i id="toast-icon" class="fas fa-check-circle text-base"></i>
        <span id="toast-msg"></span>
    </div>
 
    <!-- ── DATA BRIDGE: PHP → JS ── -->
    <script>
        const SEMS_EVENT_DATA = {
            events:         <?= $eventDataJson ?>,
            archivedEvents: <?= $archivedEventDataJson ?>,
        };
    </script>
    <script src="/js/admin_event_manage.js"></script>
</body>
</html>