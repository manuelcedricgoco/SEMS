<?php

/**
 * SEMS Admin Dashboard - Enhanced UI
 * Student Event Management System - Admin Panel
 */

// ═══════════════════════════════════════════════════════════════════════════════
// ── SESSION AT AUTHENTICATION ──
// Purpose: Simulan ang session para ma-access ang login data, then i-verify kung
//          naka-login ang user at kung admin ang role niya.
// ═══════════════════════════════════════════════════════════════════════════════
session_start();

$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$adminUserId = $_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════════════════════════
// ── DATABASE QUERY HELPER FUNCTION ──
// Purpose: Reusable function para kumuha ng COUNT(*) mula sa database.
//          Gumagamit ng prepared statements para sa security (SQL injection prevention).
// ═══════════════════════════════════════════════════════════════════════════════
function getCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── FETCH ADMIN PROFILE DATA ──
// Purpose: Kunin ang profile info ng currently logged-in admin para sa header
//          (name, email, phone, avatar, etc.)
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
$adminStmt->execute(['admin_id' => $adminUserId]);
$adminData = $adminStmt->fetch(PDO::FETCH_ASSOC);

// ── PARSE ADMIN NAME PARTS ──
// Purpose: I-extract ang individual name fields; fallback to empty string kung wala
$adminFirstName  = $adminData['first_name']   ?? '';
$adminLastName   = $adminData['last_name']    ?? '';
$adminMiddleName = $adminData['middle_name']  ?? '';

// ── BUILD FULL NAME ──
// Purpose: I-concatenate ang first, middle, at last name; i-sanitize via htmlspecialchars
$adminFullName = trim($adminFirstName . ' ' . $adminMiddleName . ' ' . $adminLastName);
$adminFullName = $adminFullName !== '' ? htmlspecialchars($adminFullName) : 'Administrator';

// ── SANITIZE OTHER FIELDS ──
// Purpose: I-protect ang email at phone mula sa XSS attacks via htmlspecialchars
$adminEmail = htmlspecialchars($adminData['email'] ?? '');
$adminPhone = htmlspecialchars($adminData['phone'] ?? '');

// ── BUILD ADMIN AVATAR ──
// Purpose: I-convert ang BLOB image sa base64 para ma-display sa <img> tag;
//          Kung walang image, gagamitin ang initials para sa fallback avatar
$adminAvatar = '';
if (!empty($adminData['profile_image'])) {
    $imageData   = base64_encode($adminData['profile_image']);
    $adminAvatar = "data:image/jpeg;base64,{$imageData}";
} else {
    $initials    = strtoupper(substr($adminFirstName, 0, 1) . substr($adminLastName, 0, 1));
    $adminAvatar = null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── DASHBOARD STATISTICS ──
// Purpose: I-count ang mga key metrics para sa stat cards sa dashboard
// ═══════════════════════════════════════════════════════════════════════════════
$totalEvents        = getCount($pdo, "SELECT COUNT(*) FROM events");
$approvedEvents     = getCount($pdo, "SELECT COUNT(*) FROM events WHERE status = 'approved'");
$rejectedEvents     = getCount($pdo, "SELECT COUNT(*) FROM events WHERE status = 'rejected'");
$pendingCount       = getCount($pdo, "SELECT COUNT(*) FROM events WHERE status = 'pending'");
$registeredStudents = getCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'student'");
$activeOrganizers   = getCount($pdo, "SELECT COUNT(*) FROM users WHERE role = 'organizer'");
$totalAttendance    = getCount($pdo, "SELECT COUNT(DISTINCT CONCAT(user_id, '-', event_id)) FROM attendance");
$upcomingEvents     = getCount($pdo, "SELECT COUNT(*) FROM events WHERE start_datetime > NOW() AND status = 'approved'");

// ═══════════════════════════════════════════════════════════════════════════════
// ── CHART DATA: ATTENDANCE BY DEPARTMENT ──
// Purpose: Kunin ang attendance count per department para sa bar chart
// ═══════════════════════════════════════════════════════════════════════════════
$deptLabels = [];
$deptData   = [];

$chartSql = "
    SELECT d.dept_name, COUNT(DISTINCT a.user_id) AS count
    FROM departments d
    LEFT JOIN users u ON d.dept_id = u.dept_id
    LEFT JOIN attendance a ON u.user_id = a.user_id
    LEFT JOIN events e ON a.event_id = e.event_id AND e.status = 'approved'
    GROUP BY d.dept_id
    ORDER BY count DESC
";

$stmt = $pdo->query($chartSql);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $deptLabels[] = $row['dept_name'];
    $deptData[]   = (int) $row['count'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── CHART DATA: RECENT EVENTS ──
// Purpose: Kunin ang latest 6 events para sa timeline table at recent events list
// ═══════════════════════════════════════════════════════════════════════════════
$recentEventsQuery = "
    SELECT e.event_id, e.title, e.start_datetime, e.status, v.venue_name,
           COALESCE(o.org_name, c.club_name, 'N/A') AS organizer_name
    FROM events e
    JOIN venues v ON e.venue_id = v.venue_id
    LEFT JOIN organizations o ON e.org_id = o.org_id
    LEFT JOIN clubs c ON e.club_id = c.club_id
    ORDER BY e.start_datetime DESC
    LIMIT 6
";

$recentEvents = $pdo->query($recentEventsQuery)->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════════════════════════════════════════════════════════════════════
// ── CHART DATA: MONTHLY EVENTS COUNT ──
// Purpose: I-count ang events per month para sa line chart (current year)
// ═══════════════════════════════════════════════════════════════════════════════
$monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$monthlyData   = array_fill(0, 12, 0);

$monthlySql = "
    SELECT MONTH(start_datetime) AS month, COUNT(*) AS total
    FROM events
    WHERE YEAR(start_datetime) = YEAR(CURDATE())
    GROUP BY MONTH(start_datetime)
";

$stmt = $pdo->query($monthlySql);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $monthlyData[$row['month'] - 1] = (int) $row['total'];
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── CHART DATA: EVENT TYPE DISTRIBUTION ──
// Purpose: Kunin ang count ng events per type para sa doughnut chart
// ═══════════════════════════════════════════════════════════════════════════════
$typeLabels = [];
$typeData   = [];

$typeSql = "
    SELECT et.type_name, COUNT(e.event_id) AS total
    FROM event_types et
    LEFT JOIN events e ON e.event_type_id = et.type_id
    GROUP BY et.type_id, et.type_name
";

$stmt = $pdo->query($typeSql);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $typeLabels[] = $row['type_name'];
    $typeData[]   = (int) $row['total'];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard — SEMS</title>
    <link rel="icon" href="/assets/dashboard-icon-indigo.svg" />

    <!-- ═══════════════════════════════════════════════════════════════════════════════
         ── TAILWIND CSS CONFIGURATION ──
         Purpose: I-load ang Tailwind CDN at i-customize ang theme (colors, fonts,
                  animations) para sa SEMS design system.
         ═══════════════════════════════════════════════════════════════════════════════ -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif']
                    },
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                        },
                    },
                    animation: {
                        'fade-up': 'fadeUp .5s ease both',
                        'fade-in': 'fadeIn .4s ease both',
                        'slide-in': 'slideIn .3s ease both',
                    },
                    keyframes: {
                        fadeUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' }
                        },
                        slideIn: {
                            '0%': { opacity: '0', transform: 'translateX(-10px)' },
                            '100%': { opacity: '1', transform: 'translateX(0)' }
                        },
                    }
                }
            }
        }
    </script>

    <!-- ═══════════════════════════════════════════════════════════════════════════════
         ── EXTERNAL ASSETS ──
         Purpose: I-load ang Google Fonts (Inter), Font Awesome icons, custom CSS,
                  at Chart.js para sa mga charts.
         ═══════════════════════════════════════════════════════════════════════════════ -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/CSS/admin_dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<!-- ═══════════════════════════════════════════════════════════════════════════════
     ── DARK MODE CHECK (IIFE) ──
     Purpose: I-check agad ang localStorage bago mag-render ng page para maiwasan
              ang "flash" ng light mode kapag naka-dark mode ang user.
     ═══════════════════════════════════════════════════════════════════════════════ -->
<script>
    (function() {
        const theme = localStorage.getItem('sems-theme') || 'light';
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
</script>

<body class="bg-gray-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 font-sans min-h-screen">

    <!-- ═══════════════════════════════════════════════════════════════════════════════
         ── MOBILE OVERLAY ──
         Purpose: Ang backdrop na lumalabas kapag bukas ang sidebar sa mobile view.
                  Pag tinap, magsasara ang sidebar.
         ═══════════════════════════════════════════════════════════════════════════════ -->
    <div id="overlay" onclick="closeSidebar()"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 opacity-0 pointer-events-none lg:hidden"></div>

    <div class="flex min-h-screen">

        <!-- ═══════════════════════════════════════════════════════════════════════════════
             ── SIDEBAR NAVIGATION ──
             Purpose: Ang main navigation menu ng admin panel. May links sa iba't ibang
                      sections: Dashboard, Events, Approvals, Users, Organizations, Analytics.
             ═══════════════════════════════════════════════════════════════════════════════ -->
        <aside id="sidebar"
            class="-translate-x-full lg:translate-x-0 fixed top-0 left-0 z-50 h-full w-64 flex flex-col
                      bg-white dark:bg-slate-800 border-r border-gray-200 dark:border-slate-700 shadow-xl">

            <!-- ── Logo Area ── -->
            <!-- Purpose: Display ng SEMS brand name at "Admin Panel" subtitle -->
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

            <!-- ── Navigation Links ── -->
            <!-- Purpose: Ang main menu items na grouped by category (Overview, Management, Insights) -->
            <nav class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
                
                <!-- Overview Section -->
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 uppercase tracking-wider">Overview</p>
                <a href="/admin/admin_dashboard.php"
                    class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium">
                    <i class="fas fa-th-large w-5 text-center"></i>
                    Dashboard
                </a>

                <!-- Management Section -->
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Management</p>

                <?php
                // ── DYNAMIC NAV ITEMS ──
                // Purpose: I-loop ang management links para hindi repetitive ang code
                $navItems = [
                    ['/admin/admin_event_management.php',   'fa-calendar-alt',  'Events'],
                    ['/admin/admin_aprovals.php',           'fa-check-circle',  'Approvals'],
                    ['/admin/admin_user_management.php',    'fa-users',         'Users'],
                    ['/admin/admin_org_club_management.php', 'fa-building',     'Organizations & Clubs'],
                ];
                foreach ($navItems as [$href, $icon, $label]): ?>
                    <a href="<?= $href ?>"
                        class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                        <i class="fas <?= $icon ?> w-5 text-center"></i>
                        <?= $label ?>
                    </a>
                <?php endforeach; ?>

                <!-- Insights Section -->
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Insights</p>
                <a href="/admin/admin_insight.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                    <i class="fas fa-chart-line w-5 text-center"></i>
                    Analytics
                </a>
            </nav>

            <!-- ── Sidebar Footer ── -->
            <!-- Purpose: Settings, Theme Toggle, at Logout buttons -->
            <div class="px-4 py-4 border-t border-gray-100 dark:border-slate-700 space-y-1">
                <a href="/admin/admin_settings.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                    <i class="fas fa-cog w-5 text-center"></i>
                    Settings
                </a>

                <button onclick="toggleTheme()"
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm">
                    <i id="theme-icon" class="fas fa-moon w-5 text-center"></i>
                    <span id="theme-label">Dark Mode</span>
                </button>

                <a href="../includes/logout.php"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 font-medium text-sm">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i>
                    Logout
                </a>
            </div>
        </aside>

        <!-- ═══════════════════════════════════════════════════════════════════════════════
             ── MAIN CONTENT AREA ──
             Purpose: Dito nakalagay ang header, welcome message, stat cards, charts,
                      timeline table, at recent events table.
             ═══════════════════════════════════════════════════════════════════════════════ -->
        <main class="flex-1 lg:ml-64 min-w-0 flex flex-col">

            <!-- ── Sticky Header ── -->
            <!-- Purpose: Header na naka-stick sa top; may breadcrumb, search bar, at admin profile -->
            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border-b border-gray-200 dark:border-slate-700 px-4 sm:px-8 py-4 flex items-center gap-4">

                <!-- Mobile Menu Button -->
                <button onclick="openSidebar()"
                    class="lg:hidden w-10 h-10 flex items-center justify-center flex-shrink-0 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 hover:text-primary-500">
                    <i class="fas fa-bars text-sm"></i>
                </button>

                <!-- Breadcrumb & Date -->
                <div class="hidden sm:block mr-auto">
                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 mb-0.5">
                        <span>Admin</span>
                        <i class="fas fa-chevron-right text-xs"></i>
                        <span class="text-slate-900 dark:text-white font-medium">Dashboard</span>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500"><?= date('l, F j, Y') ?></p>
                </div>

                <!-- Search Bar -->
                <div class="flex-1 sm:flex-none sm:w-72 relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input id="tableSearch" type="text" placeholder="Search events..."
                        class="w-full pl-10 pr-4 py-2.5 text-sm rounded-xl bg-gray-100 dark:bg-slate-700 border-0 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:bg-white dark:focus:bg-slate-600 text-slate-700 dark:text-slate-200 placeholder-slate-400" />
                </div>

                <!-- Admin Profile Widget -->
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

            <!-- ── Page Body ── -->
            <!-- Purpose: Ang main content ng dashboard — welcome, stats, charts, tables -->
            <div class="flex-1 px-4 sm:px-8 py-8 space-y-8">

                <!-- Welcome Message -->
                <div class="animate-fade-up">
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white">
                        Good <?= (date('H') < 12) ? 'morning' : ((date('H') < 18) ? 'afternoon' : 'evening') ?>, <?= htmlspecialchars($adminFirstName) ?>! 👋
                    </h1>
                    <p class="text-slate-500 dark:text-slate-400 mt-2">Here's what's happening with your events today.</p>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     ── STATISTICS CARDS ──
                     Purpose: 8 cards na nagdi-display ng key metrics (events, students,
                              organizers, attendance, upcoming events).
                     ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <?php
                    // ── STAT CARDS CONFIGURATION ──
                    // Purpose: Array ng lahat ng stats para ma-loop na lang sa HTML
                    $allStats = [
                        ['label' => 'Total Events',          'value' => $totalEvents,        'icon' => 'fa-calendar',        'bg' => 'bg-purple-100',  'darkBg' => 'dark:bg-purple-500/20',  'text' => 'text-purple-700',  'darkText' => 'dark:text-purple-300',  'iconColor' => 'text-purple-500',  'darkIcon' => 'dark:text-purple-400'],
                        ['label' => 'Approved Events',       'value' => $approvedEvents,     'icon' => 'fa-check-circle',    'bg' => 'bg-emerald-100', 'darkBg' => 'dark:bg-emerald-500/20', 'text' => 'text-emerald-700', 'darkText' => 'dark:text-emerald-300', 'iconColor' => 'text-emerald-500', 'darkIcon' => 'dark:text-emerald-400'],
                        ['label' => 'Rejected Events',       'value' => $rejectedEvents,     'icon' => 'fa-times-circle',    'bg' => 'bg-rose-100',    'darkBg' => 'dark:bg-rose-500/20',    'text' => 'text-rose-700',    'darkText' => 'dark:text-rose-300',    'iconColor' => 'text-rose-500',    'darkIcon' => 'dark:text-rose-400'],
                        ['label' => 'Pending Count',         'value' => $pendingCount,       'icon' => 'fa-clock',           'bg' => 'bg-amber-100',   'darkBg' => 'dark:bg-amber-500/20',   'text' => 'text-amber-700',   'darkText' => 'dark:text-amber-300',   'iconColor' => 'text-amber-500',   'darkIcon' => 'dark:text-amber-400'],
                        ['label' => 'Registered Students',   'value' => $registeredStudents, 'icon' => 'fa-user-graduate',   'bg' => 'bg-blue-100',    'darkBg' => 'dark:bg-blue-500/20',    'text' => 'text-blue-700',    'darkText' => 'dark:text-blue-300',    'iconColor' => 'text-blue-500',    'darkIcon' => 'dark:text-blue-400'],
                        ['label' => 'Active Organizers',     'value' => $activeOrganizers,   'icon' => 'fa-users-cog',       'bg' => 'bg-cyan-100',    'darkBg' => 'dark:bg-cyan-500/20',    'text' => 'text-cyan-700',    'darkText' => 'dark:text-cyan-300',    'iconColor' => 'text-cyan-500',    'darkIcon' => 'dark:text-cyan-400'],
                        ['label' => 'Total Attendance',      'value' => $totalAttendance,    'icon' => 'fa-clipboard-check', 'bg' => 'bg-teal-100',    'darkBg' => 'dark:bg-teal-500/20',    'text' => 'text-teal-700',    'darkText' => 'dark:text-teal-300',    'iconColor' => 'text-teal-500',    'darkIcon' => 'dark:text-teal-400'],
                        ['label' => 'Upcoming Events',       'value' => $upcomingEvents,     'icon' => 'fa-calendar-day',    'bg' => 'bg-orange-100',  'darkBg' => 'dark:bg-orange-500/20',  'text' => 'text-orange-700',  'darkText' => 'dark:text-orange-300',  'iconColor' => 'text-orange-500',  'darkIcon' => 'dark:text-orange-400'],
                    ];

                    foreach ($allStats as $s): ?>
                        <div class="card-anim stat-card animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden cursor-default">
                            <div class="absolute top-4 right-4 w-12 h-12 rounded-xl <?= $s['bg'] ?> <?= $s['darkBg'] ?> flex items-center justify-center">
                                <i class="fas <?= $s['icon'] ?> <?= $s['iconColor'] ?> <?= $s['darkIcon'] ?> text-lg"></i>
                            </div>
                            <div class="pr-14">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1"><?= $s['label'] ?></p>
                                <p class="text-3xl font-bold <?= $s['text'] ?> <?= $s['darkText'] ?> leading-none"><?= $s['value'] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     ── CHARTS ROW 1 ──
                     Purpose: Bar chart (attendance by dept) at Task Status cards
                     ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Bar Chart: Attendance by Department -->
                    <div class="lg:col-span-2 stat-card animate-fade-in bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-slate-700" style="animation-delay:.3s">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <p class="font-bold text-slate-900 dark:text-white text-lg">Team Workload</p>
                                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Attendance distribution by department</p>
                            </div>
                            <button class="text-sm bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 px-4 py-1.5 rounded-full font-medium">This Year</button>
                        </div>
                        <div class="h-64 relative"><canvas id="deptChart"></canvas></div>
                    </div>

                    <!-- Task Status Cards -->
                    <div class="stat-card animate-fade-in bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-slate-700" style="animation-delay:.35s">
                        <p class="font-bold text-slate-900 dark:text-white text-lg mb-6">Task Status</p>
                        <div class="space-y-4">
                            <!-- To Do: Pending Events -->
                            <div class="flex items-center gap-4 p-4 rounded-xl bg-purple-50 dark:bg-purple-500/10">
                                <div class="w-10 h-10 rounded-lg bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
                                    <i class="fas fa-list text-purple-500 dark:text-purple-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-semibold text-slate-900 dark:text-white">To Do</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400"><?= $pendingCount ?> Pending</p>
                                </div>
                            </div>
                            <!-- In Progress: Upcoming Events -->
                            <div class="flex items-center gap-4 p-4 rounded-xl bg-amber-50 dark:bg-amber-500/10">
                                <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
                                    <i class="fas fa-spinner text-amber-500 dark:text-amber-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-semibold text-slate-900 dark:text-white">In Progress</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400"><?= $upcomingEvents ?> Upcoming</p>
                                </div>
                            </div>
                            <!-- Revisions: Rejected Events -->
                            <div class="flex items-center gap-4 p-4 rounded-xl bg-rose-50 dark:bg-rose-500/10">
                                <div class="w-10 h-10 rounded-lg bg-rose-100 dark:bg-rose-500/20 flex items-center justify-center">
                                    <i class="fas fa-exclamation-circle text-rose-500 dark:text-rose-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-semibold text-slate-900 dark:text-white">Revisions</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400"><?= $rejectedEvents ?> Rejected</p>
                                </div>
                            </div>
                            <!-- Completed: Approved Events -->
                            <div class="flex items-center gap-4 p-4 rounded-xl bg-emerald-50 dark:bg-emerald-500/10">
                                <div class="w-10 h-10 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center">
                                    <i class="fas fa-check-circle text-emerald-500 dark:text-emerald-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-semibold text-slate-900 dark:text-white">Completed</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400"><?= $approvedEvents ?> Approved</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     ── CHARTS ROW 2 ──
                     Purpose: Line chart (monthly events) at Doughnut chart (approval status)
                     ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    <!-- Line Chart: Events Over Time -->
                    <div class="lg:col-span-2 stat-card animate-fade-in bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-slate-700" style="animation-delay:.4s">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <p class="font-bold text-slate-900 dark:text-white text-lg">Events Over Time</p>
                                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Monthly event count this year</p>
                            </div>
                            <button class="text-sm bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 px-4 py-1.5 rounded-full font-medium"><?= date('Y') ?></button>
                        </div>
                        <div class="h-64 relative"><canvas id="eventsLineChart"></canvas></div>
                    </div>

                    <!-- Doughnut Chart: Approval Status -->
                    <div class="stat-card animate-fade-in bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-slate-700" style="animation-delay:.45s">
                        <p class="font-bold text-slate-900 dark:text-white text-lg mb-2">Approval Status</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">Event approval breakdown</p>
                        <div class="h-48 relative"><canvas id="approvalChart"></canvas></div>
                        <div class="flex justify-center gap-4 mt-6 flex-wrap">
                            <span class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-400"><span class="w-3 h-3 rounded-full bg-emerald-500"></span>Approved</span>
                            <span class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-400"><span class="w-3 h-3 rounded-full bg-amber-500"></span>Pending</span>
                            <span class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-400"><span class="w-3 h-3 rounded-full bg-rose-500"></span>Rejected</span>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     ── TIMELINE / GANTT TABLE ──
                     Purpose: Table na nagpapakita ng upcoming events schedule
                     ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="stat-card animate-fade-in bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-slate-700" style="animation-delay:.5s">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-lg">Timeline / Gantt Chart</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Upcoming events schedule</p>
                        </div>
                        <select class="text-sm bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 rounded-lg px-3 py-1.5 text-slate-600 dark:text-slate-300 focus:outline-none">
                            <option>This Week</option>
                            <option>This Month</option>
                            <option>This Year</option>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 dark:border-slate-700">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-500 dark:text-slate-400">Project</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-500 dark:text-slate-400">Start Date</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-500 dark:text-slate-400">End Date</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-500 dark:text-slate-400">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // ── LOOP FIRST 4 RECENT EVENTS ──
                                // Purpose: I-display ang first 4 events sa timeline table
                                foreach (array_slice($recentEvents, 0, 4) as $event):
                                    // ── STATUS BADGE STYLING ──
                                    // Purpose: I-determine ang color ng status badge gamit ang PHP 8 match expression
                                    $statusClass = match ($event['status']) {
                                        'approved' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400',
                                        'pending'  => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400',
                                        'rejected' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400',
                                        default    => 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-400',
                                    };
                                ?>
                                    <tr class="border-b border-gray-50 dark:border-slate-700/50 hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
                                        <td class="py-4 px-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                                                    <i class="fas fa-calendar text-primary-500 dark:text-primary-400 text-xs"></i>
                                                </div>
                                                <span class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($event['title']) ?></span>
                                            </div>
                                        </td>
                                        <td class="py-4 px-4 text-slate-600 dark:text-slate-400"><?= date('d-m-Y', strtotime($event['start_datetime'])) ?></td>
                                        <td class="py-4 px-4 text-slate-600 dark:text-slate-400"><?= date('d-m-Y', strtotime($event['start_datetime'] . ' +1 day')) ?></td>
                                        <td class="py-4 px-4">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold capitalize <?= $statusClass ?>">
                                                <?= htmlspecialchars($event['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ═══════════════════════════════════════════════════════════════════════════════
                     ── RECENT EVENTS TABLE ──
                     Purpose: Full table ng latest 6 events across all departments
                     ═══════════════════════════════════════════════════════════════════════════════ -->
                <div class="animate-fade-in bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden" style="animation-delay:.55s">
                    <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-lg">Recent Events</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Latest events across all departments</p>
                        </div>
                        <a href="/admin/admin_event_management.php"
                            class="text-sm font-semibold text-primary-600 dark:text-primary-400 hover:text-primary-700 flex items-center gap-1.5 group">
                            View all events <i class="fas fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
                        </a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-slate-700/50">
                                    <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Event Name</th>
                                    <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Organizer</th>
                                    <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Venue</th>
                                    <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-slate-700" id="eventsTableBody">
                                <?php foreach ($recentEvents as $event):
                                    // ── STATUS BADGE STYLING ──
                                    // Purpose: I-determine ang color ng status badge gamit ang PHP 8 match expression
                                    $badgeCls = match ($event['status']) {
                                        'approved' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-400',
                                        'pending'  => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400',
                                        'rejected' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-400',
                                        default    => 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-400',
                                    };
                                ?>
                                    <tr class="table-row hover:bg-gray-50 dark:hover:bg-slate-700/30 transition-colors">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-lg bg-primary-50 dark:bg-primary-500/10 flex items-center justify-center flex-shrink-0">
                                                    <i class="fas fa-calendar-day text-primary-500 dark:text-primary-400 text-xs"></i>
                                                </div>
                                                <span class="font-medium text-slate-900 dark:text-white"><?= htmlspecialchars($event['title']) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-slate-600 dark:text-slate-400"><?= htmlspecialchars($event['organizer_name']) ?></td>
                                        <td class="px-6 py-4 text-slate-600 dark:text-slate-400">
                                            <div class="flex items-center gap-2">
                                                <i class="fas fa-map-marker-alt text-slate-400 text-xs"></i>
                                                <?= htmlspecialchars($event['venue_name']) ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-slate-600 dark:text-slate-400 whitespace-nowrap"><?= date('M j, Y', strtotime($event['start_datetime'])) ?></td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-semibold capitalize <?= $badgeCls ?>">
                                                <?= htmlspecialchars($event['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Empty State ── -->
                    <!-- Purpose: Lumalabas lang kapag walang events na ma-display -->
                    <div id="noResults" class="hidden px-4 py-12 text-center">
                        <div class="w-16 h-16 bg-gray-100 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-search text-gray-400 text-xl"></i>
                        </div>
                        <p class="text-slate-900 dark:text-white font-medium">No events found</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Try adjusting your search terms</p>
                    </div>
                </div>

                <div class="h-4"></div>
            </div><!-- /page body -->
        </main>
    </div><!-- /flex layout -->

    <!-- ═══════════════════════════════════════════════════════════════════════════════
         ── JAVASCRIPT DATA BRIDGE ──
         Purpose: I-pass ang PHP chart data papuntang JavaScript bilang global variable.
                  Kailangang nasa unahan ng admin_dashboard.js para mabasa nito.
         ═══════════════════════════════════════════════════════════════════════════════ -->
    <script>
        const SEMS_DATA = {
            deptLabels: <?= json_encode($deptLabels) ?>,
            deptData: <?= json_encode($deptData) ?>,
            monthlyLabels: <?= json_encode($monthlyLabels) ?>,
            monthlyData: <?= json_encode($monthlyData) ?>,
            approvedEvents: <?= (int) $approvedEvents ?>,
            pendingCount: <?= (int) $pendingCount ?>,
            rejectedEvents: <?= (int) $rejectedEvents ?>,
        };
    </script>

    <!-- ═══════════════════════════════════════════════════════════════════════════════
         ── MAIN JAVASCRIPT ──
         Purpose: External JS file na nagha-handle ng chart rendering, UI interactions,
                  search filtering, at theme toggling.
         ═══════════════════════════════════════════════════════════════════════════════ -->
    <script src="../js/admin_dashboard.js"></script>

</body>
</html>