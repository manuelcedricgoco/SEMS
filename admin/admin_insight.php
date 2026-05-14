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

// ═══════════════════════════════════════════════════════════════════════════════
// ── FETCH ADMIN PROFILE ──
// ═══════════════════════════════════════════════════════════════════════════════
$adminFullName   = 'Admin User';
$adminFirstName  = 'Admin';
$adminMiddleName = '';
$adminLastName   = 'User';
$adminAvatar     = null;

if (isset($_SESSION['user_id'])) {
    $adminStmt = $pdo->prepare("
        SELECT first_name, middle_name, last_name, profile_image
        FROM admin WHERE user_id = :user_id
    ");
    $adminStmt->execute(['user_id' => $_SESSION['user_id']]);
    $adminData = $adminStmt->fetch(PDO::FETCH_ASSOC);

    if ($adminData) {
        $adminFirstName  = htmlspecialchars($adminData['first_name']);
        $adminMiddleName = htmlspecialchars($adminData['middle_name']);
        $adminLastName   = htmlspecialchars($adminData['last_name']);
        $adminFullName   = $adminFirstName . ($adminMiddleName ? ' ' . $adminMiddleName : '') . ' ' . $adminLastName;
        if ($adminData['profile_image']) {
            $adminAvatar = 'data:image/jpeg;base64,' . base64_encode($adminData['profile_image']);
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── AJAX: EVENT FEEDBACK DETAILS ──
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === 'event_detail') {
    header('Content-Type: application/json');
    $eventId = isset($_POST['eventId']) ? (int) $_POST['eventId'] : 0;
    if (!$eventId) { echo json_encode(['success' => false]); exit; }

    $stmt = $pdo->prepare("
        SELECT fc.category_name, ROUND(AVG(fr.rating),1) AS avg_rating, COUNT(fr.rating) AS votes
        FROM feedback_ratings fr
        JOIN feedback_categories fc ON fr.category_id = fc.category_id
        JOIN feedback f ON fr.feedback_id = f.feedback_id
        WHERE f.event_id = :event_id AND fr.rating IS NOT NULL
        GROUP BY fc.category_id ORDER BY fc.category_name
    ");
    $stmt->execute(['event_id' => $eventId]);
    $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("
        SELECT fr.comment, fc.category_name, CONCAT(p.first_name,' ',p.last_name) AS reviewer
        FROM feedback_ratings fr
        JOIN feedback_categories fc ON fr.category_id = fc.category_id
        JOIN feedback f ON fr.feedback_id = f.feedback_id
        JOIN profiles p ON f.user_id = p.user_id
        WHERE f.event_id = :event_id AND fr.comment IS NOT NULL AND fr.comment != ''
        ORDER BY fr.rating_id DESC LIMIT 5
    ");
    $stmt2->execute(['event_id' => $eventId]);
    $comments = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'categories' => $cats, 'comments' => $comments]);
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── FETCH ALL APPROVED EVENTS (includes is_restricted + type_name) ──
// ═══════════════════════════════════════════════════════════════════════════════
$now    = new DateTime();
$events = [];

$sql = "
    SELECT e.event_id, e.title, e.start_datetime, e.end_datetime,
           e.is_restricted,
           d.dept_name, COALESCE(v.capacity, 100) AS capacity,
           COALESCE(o.org_name, c.club_name, 'N/A') AS organizer,
           et.type_name,
           COUNT(DISTINCT a.user_id) AS attended,
           ROUND(AVG(fr.rating), 1) AS avg_rating,
           COUNT(DISTINCT f.feedback_id) AS feedback_count
    FROM events e
    LEFT JOIN departments d    ON e.dept_id       = d.dept_id
    LEFT JOIN venues v         ON e.venue_id      = v.venue_id
    LEFT JOIN organizations o  ON e.org_id        = o.org_id
    LEFT JOIN clubs c          ON e.club_id       = c.club_id
    LEFT JOIN event_types et   ON e.event_type_id = et.type_id
    LEFT JOIN attendance a     ON e.event_id      = a.event_id
    LEFT JOIN feedback f       ON e.event_id      = f.event_id
    LEFT JOIN feedback_ratings fr ON f.feedback_id = fr.feedback_id
    WHERE e.status = 'approved'
    GROUP BY e.event_id
    ORDER BY e.start_datetime DESC
";
$stmt = $pdo->query($sql);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $start = new DateTime($row['start_datetime']);
    $end   = new DateTime($row['end_datetime']);

    if ($now > $end)        $row['event_status'] = 'Completed';
    elseif ($now >= $start) $row['event_status'] = 'In Progress';
    else                    $row['event_status'] = 'Upcoming';

    $cap = (int) $row['capacity'];
    $att = (int) $row['attended'];
    $row['attendance_pct'] = $cap > 0 ? min(100, round(($att / $cap) * 100)) : 0;

    $events[] = $row;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── OVERALL STATISTICS ──
// ═══════════════════════════════════════════════════════════════════════════════
$totalEvents    = count($events);
$totalAttendees = array_sum(array_column($events, 'attended'));
$ratedEvents    = array_filter($events, fn($e) => $e['avg_rating'] !== null);
$overallRating  = count($ratedEvents)
    ? round(array_sum(array_column($ratedEvents, 'avg_rating')) / count($ratedEvents), 1)
    : 'N/A';
$totalCapacity = array_sum(array_column($events, 'capacity'));
$avgAttendanceRate = $totalCapacity > 0
    ? min(100, round(($totalAttendees / $totalCapacity) * 100, 1))
    : 0;

// ═══════════════════════════════════════════════════════════════════════════════
// ── APPROVAL RATE ──
// ═══════════════════════════════════════════════════════════════════════════════
$approvalStmt = $pdo->query("
    SELECT COUNT(a.approval_id) AS total_reviewed,
           SUM(CASE WHEN a.approval_status = 'approved' THEN 1 ELSE 0 END) AS total_approved
    FROM event_approvals a
    JOIN events e ON a.event_id = e.event_id
");
$approvalStats = $approvalStmt->fetch(PDO::FETCH_ASSOC);
$approvalRate  = $approvalStats['total_reviewed'] > 0
    ? round(($approvalStats['total_approved'] / $approvalStats['total_reviewed']) * 100)
    : 0;

// ═══════════════════════════════════════════════════════════════════════════════
// ── DEPARTMENTS LIST (for filter dropdown) ──
// ═══════════════════════════════════════════════════════════════════════════════
$depts    = [];
$deptStmt = $pdo->query("SELECT dept_name FROM departments ORDER BY dept_name");
while ($r = $deptStmt->fetch(PDO::FETCH_ASSOC)) {
    $depts[] = $r['dept_name'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── RECENT ACTIVITIES (with event_id for JS filtering) ──
// ═══════════════════════════════════════════════════════════════════════════════
$activityStmt = $pdo->query("
    (SELECT e.event_id, 'Event Created' AS action, e.title AS details,
            e.created_at AS date, 'fa-calendar-plus' AS icon, 'text-blue-500' AS color
     FROM events e WHERE e.status = 'approved')
    UNION ALL
    (SELECT ea.event_id, CONCAT('Event ', ea.approval_status) AS action,
            ea.remarks AS details, ea.approved_at AS date,
            'fa-check-circle' AS icon, 'text-green-500' AS color
     FROM event_approvals ea)
    UNION ALL
    (SELECT f.event_id, 'Feedback Received' AS action,
            'New rating submitted' AS details, f.created_at AS date,
            'fa-star' AS icon, 'text-yellow-500' AS color
     FROM feedback f)
    ORDER BY date DESC
    LIMIT 10
");
$recentActivities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════════
// ── REQUIRED VS GENERAL (for initial badge values) ──
// ═══════════════════════════════════════════════════════════════════════════════
$reqGenStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN e.is_restricted = 1 THEN 1 ELSE 0 END) AS required_count,
        SUM(CASE WHEN e.is_restricted = 0 OR e.is_restricted IS NULL THEN 1 ELSE 0 END) AS general_count
    FROM events e WHERE e.status = 'approved'
");
$reqGenRow  = $reqGenStmt->fetch(PDO::FETCH_ASSOC);
$reqGenData = [
    'required' => (int) ($reqGenRow['required_count'] ?? 0),
    'general'  => (int) ($reqGenRow['general_count']  ?? 0),
];

// ═══════════════════════════════════════════════════════════════════════════════
// ── JSON ENCODE FOR JS ──
// ═══════════════════════════════════════════════════════════════════════════════
$eventsJson     = json_encode($events,           JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$activitiesJson = json_encode($recentActivities, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$reqGenJson     = json_encode($reqGenData,       JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEMS Admin - Reports & Analytics</title>
    <link rel="icon" href="/assets/insights-icon-indigo.svg">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: {
                        primary: { 50:'#eff6ff',100:'#dbeafe',400:'#60a5fa',500:'#3b82f6',600:'#2563eb' }
                    }
                }
            }
        }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/CSS/admin_insight.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        (function() {
            const t = localStorage.getItem('sems-theme') || 'light';
            if (t === 'dark') document.documentElement.classList.add('dark');
        })();
    </script>
</head>

<body class="bg-gray-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 font-sans min-h-screen">

<!-- Mobile Overlay -->
<div id="sidebarOverlay" onclick="closeSidebar()"
    class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 opacity-0 pointer-events-none lg:hidden"></div>

<div class="flex min-h-screen">

    <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
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
                class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                <i class="fas fa-th-large w-5 text-center"></i> Dashboard
            </a>

            <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Management</p>
            <a href="/admin/admin_event_management.php"
                class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                <i class="fas fa-calendar-alt w-5 text-center"></i> Events
            </a>
            <a href="/admin/admin_aprovals.php"
                class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                <i class="fas fa-check-circle w-5 text-center"></i> Approvals
            </a>
            <a href="/admin/admin_user_management.php"
                class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                <i class="fas fa-users w-5 text-center"></i> Users
            </a>
            <a href="/admin/admin_org_club_management.php"
                class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                <i class="fas fa-building w-5 text-center"></i> Organizations & Clubs
            </a>

            <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Insights</p>
            <a href="/admin/admin_insight.php"
                class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium">
                <i class="fas fa-chart-line w-5 text-center"></i> Analytics
            </a>
        </nav>

        <div class="px-4 py-4 border-t border-gray-100 dark:border-slate-700 space-y-1">
            <a href="/admin/admin_settings.php"
                class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                <i class="fas fa-cog w-5 text-center"></i> Settings
            </a>
            <button onclick="toggleTheme()"
                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                <i id="theme-icon" class="fas fa-moon w-5 text-center"></i>
                <span id="theme-label">Dark Mode</span>
            </button>
            <a href="../includes/logout.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 font-medium text-sm">
                <i class="fas fa-sign-out-alt w-5 text-center"></i> Logout
            </a>
        </div>
    </aside>

    <!-- ═══════════════════ MAIN CONTENT ═══════════════════ -->
    <main class="flex-1 lg:ml-64 min-w-0 flex flex-col">

        <!-- Sticky Header (no search input) -->
        <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border-b border-gray-200 dark:border-slate-700 px-4 sm:px-8 py-4 flex items-center gap-4">

            <button onclick="openSidebar()"
                class="lg:hidden w-10 h-10 flex items-center justify-center flex-shrink-0 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 hover:text-primary-500">
                <i class="fas fa-bars text-sm"></i>
            </button>

            <div class="mr-auto">
                <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 mb-0.5">
                    <span>Admin</span>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-slate-900 dark:text-white font-medium">Reports & Analytics</span>
                </div>
                <p class="text-xs text-slate-400 dark:text-slate-500" id="current-date"><?= date('l, F j, Y') ?></p>
            </div>

            <div class="flex items-center gap-3 pl-4 border-l border-gray-200 dark:border-slate-700">
                <div class="hidden md:block text-right">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white leading-none"><?= $adminFullName ?></p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Administrator</p>
                </div>
                <div class="relative cursor-pointer">
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

        <!-- Page Body -->
        <div class="flex-1 px-4 sm:px-8 py-8 space-y-8">

            <!-- Page Title -->
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white">Reports & Analytics</h1>
                <p class="text-slate-500 dark:text-slate-400 mt-2">Attendance reports, event metrics, and engagement analytics.</p>
            </div>

            <!-- ── STICKY FILTER BAR ── -->
            <div class="filter-sticky flex flex-col sm:flex-row gap-4 p-3 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                <div class="relative flex-1">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" id="searchEvent" placeholder="Search events by title..."
                        class="w-full pl-11 pr-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700 focus:outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 text-sm dark:text-white">
                </div>
                <select id="filterStatus"
                    class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-primary-500/20 text-sm font-medium text-slate-600 dark:text-slate-200 appearance-none min-w-[160px] cursor-pointer">
                    <option value="">All Statuses</option>
                    <option value="Upcoming">Upcoming</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Completed">Completed</option>
                </select>
                <select id="filterDept"
                    class="px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-primary-500/20 text-sm font-medium text-slate-600 dark:text-slate-200 appearance-none min-w-[180px] cursor-pointer">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
                <button onclick="resetFilters()"
                    class="px-6 py-2.5 rounded-xl text-sm font-bold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-600 whitespace-nowrap">
                    Reset
                </button>
            </div>

            <!-- ── STAT CARDS (3-col, no Avg Review Time) ── -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">

                <div class="stat-card bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden">
                    <div class="absolute top-4 right-4 w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
                        <i class="fas fa-users text-blue-500 dark:text-blue-400 text-lg"></i>
                    </div>
                    <div class="pr-14">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Avg Attendance</p>
                        <p id="stat-avg-att" class="text-3xl font-bold text-slate-900 dark:text-white leading-none"><?= $avgAttendanceRate ?>%</p>
                    </div>
                </div>

                <div class="stat-card bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden">
                    <div class="absolute top-4 right-4 w-12 h-12 rounded-xl bg-yellow-100 dark:bg-yellow-500/20 flex items-center justify-center">
                        <i class="fas fa-star text-yellow-500 dark:text-yellow-400 text-lg"></i>
                    </div>
                    <div class="pr-14">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Avg Feedback</p>
                        <p id="stat-avg-rat" class="text-3xl font-bold text-slate-900 dark:text-white leading-none">
                            <?= $overallRating ?> <span class="text-sm text-slate-400 font-normal">/ 5</span>
                        </p>
                    </div>
                </div>

                <div class="stat-card bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden">
                    <div class="absolute top-4 right-4 w-12 h-12 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
                        <i class="fas fa-shield-alt text-green-500 dark:text-green-400 text-lg"></i>
                    </div>
                    <div class="pr-14">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Approval Rate</p>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white leading-none"><?= $approvalRate ?>%</p>
                    </div>
                </div>

            </div>

            <!-- ── CHARTS ROW 1 ── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="stat-card bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white mb-6">Monthly Event Trend</h3>
                    <div class="chart-container"><canvas id="monthlyTrendChart"></canvas></div>
                </div>

                <div class="stat-card bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-base font-bold text-slate-900 dark:text-white">Attendance by Department</h3>
                        <button onclick="downloadDeptCSV()"
                            class="btn-download flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-50 dark:bg-sky-500/10 text-sky-600 dark:text-sky-400 border border-sky-200 dark:border-sky-800 hover:bg-sky-100 dark:hover:bg-sky-500/20">
                            <i class="fas fa-download text-[10px]"></i> CSV
                        </button>
                    </div>
                    <div class="chart-container"><canvas id="deptAttendanceChart"></canvas></div>
                </div>

            </div>

            <!-- ── CHARTS ROW 2 ── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <div class="stat-card bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white mb-6">Event Type Distribution</h3>
                    <div class="chart-container flex justify-center"><canvas id="eventTypeChart"></canvas></div>
                </div>

                <div class="stat-card bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                    <h3 class="text-base font-bold text-slate-900 dark:text-white mb-6">Event Status Breakdown</h3>
                    <div class="chart-container flex justify-center"><canvas id="eventStatusChart"></canvas></div>
                </div>

            </div>

            <!-- ── REQUIRED VS GENERAL + RECENT ACTIVITY ── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Required vs General -->
                <div class="stat-card bg-white dark:bg-slate-800 p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700">
                    <div class="flex flex-col sm:flex-row sm:items-start gap-4 mb-6">
                        <div class="flex-1">
                            <h3 class="text-base font-bold text-slate-900 dark:text-white">Required vs General Events</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                <span class="inline-flex items-center gap-1 mr-3">
                                    <i class="fas fa-lock text-rose-400 text-[10px]"></i>
                                    Required — restricted events (is_restricted = 1)
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <i class="fas fa-unlock text-emerald-400 text-[10px]"></i>
                                    General — open/voluntary for all students
                                </span>
                            </p>
                        </div>
                        <div class="grid grid-cols-2 gap-3 flex-shrink-0">
                            <div class="px-4 py-2 rounded-xl bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-800 text-center">
                                <p class="text-xs text-rose-500 dark:text-rose-400 font-semibold">Required</p>
                                <p id="req-count" class="text-xl font-bold text-rose-600 dark:text-rose-400"><?= $reqGenData['required'] ?></p>
                            </div>
                            <div class="px-4 py-2 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-800 text-center">
                                <p class="text-xs text-emerald-500 dark:text-emerald-400 font-semibold">General</p>
                                <p id="gen-count" class="text-xl font-bold text-emerald-600 dark:text-emerald-400"><?= $reqGenData['general'] ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="chart-container" style="height:200px;">
                        <canvas id="reqGenChart"></canvas>
                    </div>
                </div>

                <!-- Recent Activity (JS-rendered) -->
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 p-6">
                    <p class="font-bold text-slate-900 dark:text-white text-lg mb-6">Recent Activity</p>
                    <div id="recentActivityList"
                        class="relative pl-4 space-y-6 before:absolute before:inset-y-0 before:left-[11px] before:w-0.5 before:bg-gray-100 dark:before:bg-slate-700">
                        <!-- rendered by JS -->
                    </div>
                </div>

            </div>

            <!-- ── EVENT PERFORMANCE TABLE ── -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden flex flex-col">
                <div class="p-6 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between">
                    <div>
                        <p class="font-bold text-slate-900 dark:text-white text-lg">Event Performance</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Top events by attendance and rating</p>
                    </div>
                    <button onclick="downloadEventsCSV()"
                        class="btn-download flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 dark:hover:bg-emerald-500/20">
                        <i class="fas fa-file-csv"></i> Download CSV
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead class="bg-gray-50 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400 font-semibold uppercase tracking-wider text-xs">
                            <tr>
                                <th class="px-6 py-4">Event Title</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Attendance</th>
                                <th class="px-6 py-4 text-center">Rating</th>
                                <th class="px-6 py-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="eventTableBody" class="divide-y divide-gray-100 dark:divide-slate-700/50 text-slate-700 dark:text-slate-300 font-medium">
                            <!-- rendered by JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="h-4"></div>
        </div>
    </main>
</div>

<!-- ── EVENT FEEDBACK MODAL ── -->
<div id="eventModal"
    class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm items-center justify-center transition-opacity duration-300 opacity-0">
    <div class="bg-white dark:bg-slate-800 rounded-2xl w-full max-w-lg mx-4 p-6 shadow-2xl transform scale-95 transition-transform duration-300 border border-gray-100 dark:border-slate-700 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100 dark:border-slate-700 sticky top-0 bg-white dark:bg-slate-800 z-10">
            <h3 class="text-lg font-extrabold text-slate-900 dark:text-white">Event Feedback Details</h3>
            <button onclick="closeEventModal()"
                class="text-slate-400 hover:text-red-500 w-8 h-8 rounded-full hover:bg-red-50 dark:hover:bg-red-500/10 flex items-center justify-center">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="modalContent" class="space-y-4">
            <div class="flex justify-center py-8"><i class="fas fa-circle-notch fa-spin text-3xl text-primary-500"></i></div>
        </div>
    </div>
</div>

<!-- ── DATA BRIDGE: PHP → JS ── -->
<script>
    const SEMS_INSIGHT_DATA = {
        events:     <?= $eventsJson ?>,
        activities: <?= $activitiesJson ?>,
        reqGen:     <?= $reqGenJson ?>,
    };
</script>

<script src="/js/admin_insight.js"></script>
</body>
</html>