<?php
// ============================================================
//  SEMS — Student Attendance History Page
//  File: student/student_attendance.php
// ============================================================
session_start();
$pdo = require_once '../includes/db.php';

// ── AUTH GUARD ───────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

// ── PROFILE IMAGE & NAME ─────────────────────────────────────
$welcomeName      = 'Student';
$hasProfileImage  = false;
$profileMime      = 'image/jpeg';
$profileImageData = '';
$profileInitials  = 'S';

$stmtImg = $pdo->prepare("
    SELECT first_name, middle_name, last_name, profile_image
    FROM profiles WHERE user_id = :uid
");
$stmtImg->execute(['uid' => $uid]);
$profileRow = $stmtImg->fetch(PDO::FETCH_ASSOC);

if ($profileRow) {
    $fn = $profileRow['first_name']  ?? '';
    $mn = $profileRow['middle_name'] ?? '';
    $ln = $profileRow['last_name']   ?? '';
    $mi = $mn ? strtoupper(substr($mn, 0, 1)) . '.' : '';
    $welcomeName     = trim($fn . ' ' . ($mi ? $mi . ' ' : '') . $ln) ?: 'Student';
    $profileInitials = strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1));

    if (!empty($profileRow['profile_image'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $det   = $finfo->buffer($profileRow['profile_image']);
        if ($det && strpos($det, 'image/') === 0) $profileMime = $det;
        $profileImageData = base64_encode($profileRow['profile_image']);
        $hasProfileImage  = true;
    }
}

// ── SUMMARY STATISTICS ───────────────────────────────────────
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = :uid");
$stmtTotal->execute(['uid' => $uid]);
$totalAttended = (int) $stmtTotal->fetchColumn();

$stmtLogin = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = :uid AND login_time IS NOT NULL");
$stmtLogin->execute(['uid' => $uid]);
$totalWithLogin = (int) $stmtLogin->fetchColumn();

$stmtFull = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = :uid AND login_time IS NOT NULL AND logout_time IS NOT NULL");
$stmtFull->execute(['uid' => $uid]);
$totalComplete = (int) $stmtFull->fetchColumn();

$stmtReg = $pdo->prepare("
    SELECT COUNT(*) FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    WHERE r.user_id = :uid AND e.status = 'approved' AND e.start_datetime < :now
");
$stmtReg->execute(['uid' => $uid, 'now' => $now]);
$totalPastRegistered = (int) $stmtReg->fetchColumn();

$attendanceRate = $totalPastRegistered > 0
    ? round(($totalAttended / $totalPastRegistered) * 100)
    : 0;

// ── EVENT TYPES FOR FILTER ────────────────────────────────────
$stmtTypes = $pdo->prepare("
    SELECT DISTINCT et.type_name
    FROM attendance a
    JOIN events e      ON a.event_id       = e.event_id
    JOIN event_types et ON e.event_type_id = et.type_id
    WHERE a.user_id = :uid ORDER BY et.type_name ASC
");
$stmtTypes->execute(['uid' => $uid]);
$eventTypes = $stmtTypes->fetchAll(PDO::FETCH_COLUMN);

// ── FULL ATTENDANCE HISTORY ───────────────────────────────────
$stmtHistory = $pdo->prepare("
    SELECT
        a.attendance_id, a.login_time, a.logout_time, a.scan_time,
        e.event_id, e.title, e.start_datetime, e.end_datetime,
        et.type_name, v.venue_name,
        COALESCE(
            o.org_name, c.club_name,
            NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))),'')
        ) AS organizer_name
    FROM attendance a
    JOIN events      e  ON a.event_id      = e.event_id
    JOIN event_types et ON e.event_type_id = et.type_id
    JOIN venues      v  ON e.venue_id      = v.venue_id
    JOIN users       u  ON e.organizer_id  = u.user_id
    LEFT JOIN profiles      p ON u.user_id = p.user_id
    LEFT JOIN organizations o ON e.org_id  = o.org_id
    LEFT JOIN clubs         c ON e.club_id = c.club_id
    WHERE a.user_id = :uid
    ORDER BY COALESCE(a.login_time, e.start_datetime) DESC
");
$stmtHistory->execute(['uid' => $uid]);
$attendanceHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

$historyJson = json_encode(array_map(function ($r) {
    return [
        'id'        => $r['attendance_id'],
        'event_id'  => $r['event_id'],
        'title'     => $r['title'],
        'type'      => $r['type_name'],
        'venue'     => $r['venue_name'],
        'organizer' => $r['organizer_name'] ?? 'Unknown',
        'start'     => $r['start_datetime'],
        'end'       => $r['end_datetime'],
        'login'     => $r['login_time'],
        'logout'    => $r['logout_time'],
        'scan'      => $r['scan_time'],
    ];
}, $attendanceHistory));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance | SEMS</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/CSS/student_attendance.css">

    <!-- Google Fonts: Sora (headings) + Plus Jakarta Sans (body) -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/lucide@latest"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans:    ['Plus Jakarta Sans', 'sans-serif'],
                        display: ['Sora', 'sans-serif'],
                    },
                },
            },
        }
    </script>
</head>

<body>

    <!-- ════════════════════════════════════════════════
         SIDEBAR
         ════════════════════════════════════════════════ -->
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">

        <!-- Brand -->
        <div class="sb-brand">
            <div class="sb-logo" aria-hidden="true">
                <i data-lucide="graduation-cap" style="width:18px;height:18px;color:#fff;"></i>
            </div>
            <div>
                <div class="sb-name">SEMS</div>
                <div class="sb-tagline">Student Portal</div>
            </div>
        </div>

        <!-- Nav -->
        <nav aria-label="Site navigation">

            <div class="sb-section">Overview</div>
            <a href="student_dashboard.php" class="sb-link">
                <span class="sb-link-icon"><i data-lucide="layout-dashboard" style="width:15px;height:15px;"></i></span>
                Dashboard
            </a>

            <div class="sb-section">Events</div>
            <a href="student_event.php" class="sb-link">
                <span class="sb-link-icon"><i data-lucide="calendar-days" style="width:15px;height:15px;"></i></span>
                Browse Events
            </a>

            <div class="sb-section">Participation</div>
            <a href="student_attendance.php" class="sb-link active" aria-current="page">
                <span class="sb-link-icon"><i data-lucide="clipboard-list" style="width:15px;height:15px;"></i></span>
                Attendance History
            </a>
            <a href="student_myqr.php" class="sb-link">
                <span class="sb-link-icon"><i data-lucide="qr-code" style="width:15px;height:15px;"></i></span>
                My QR Code
            </a>
            <a href="student_feedback.php" class="sb-link">
                <span class="sb-link-icon"><i data-lucide="message-square" style="width:15px;height:15px;"></i></span>
                Feedback
            </a>

            <div class="sb-section">Account</div>
            <a href="student_settings.php" class="sb-link">
                <span class="sb-link-icon"><i data-lucide="settings" style="width:15px;height:15px;"></i></span>
                Settings
            </a>
        </nav>

        <!-- Footer -->
        <div class="sb-footer">
            <div class="sb-user-pill">
                <div class="avatar" style="width:32px;height:32px;font-size:.7rem;">
                    <?php if ($hasProfileImage): ?>
                        <img src="data:<?= $profileMime ?>;base64,<?= $profileImageData ?>" alt="Profile photo">
                    <?php else: ?>
                        <?= $profileInitials ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="sb-user-name"><?= htmlspecialchars($welcomeName) ?></div>
                    <div class="sb-user-role">Student</div>
                </div>
            </div>
            <a href="../includes/logout.php" class="sb-link signout">
                <span class="sb-link-icon"><i data-lucide="log-out" style="width:15px;height:15px;"></i></span>
                Sign Out
            </a>
        </div>
    </aside>

    <!-- Mobile overlay -->
    <div class="overlay" id="overlay" onclick="closeSidebar()"></div>


    <!-- ════════════════════════════════════════════════
         MOBILE TOPBAR
         ════════════════════════════════════════════════ -->
    <header class="topbar" role="banner">
        <button class="icon-btn" id="menuBtn" onclick="openSidebar()" aria-label="Open navigation">
            <i data-lucide="menu" style="width:17px;height:17px;"></i>
        </button>

        <div style="display:flex;align-items:center;gap:.5rem;">
            <div class="sb-logo" style="width:28px;height:28px;border-radius:7px;">
                <i data-lucide="graduation-cap" style="width:13px;height:13px;color:#fff;"></i>
            </div>
            <span class="sb-name" style="font-size:.9rem;">SEMS</span>
        </div>

        <div class="topbar-spacer"></div>

        <button class="icon-btn" id="darkToggleMobile" onclick="toggleDark()" aria-label="Toggle dark mode">
            <i data-lucide="sun"  style="width:15px;height:15px;display:none;" id="sunIconM"></i>
            <i data-lucide="moon" style="width:15px;height:15px;"              id="moonIconM"></i>
        </button>

        <div class="avatar avatar-sm">
            <?php if ($hasProfileImage): ?>
                <img src="data:<?= $profileMime ?>;base64,<?= $profileImageData ?>" alt="Profile photo">
            <?php else: ?>
                <?= $profileInitials ?>
            <?php endif; ?>
        </div>
    </header>


    <!-- ════════════════════════════════════════════════
         MAIN CONTENT
         ════════════════════════════════════════════════ -->
    <main class="main" id="main-content">
        <div style="max-width:1120px;margin:0 auto;">

            <!-- ── PAGE BANNER ──────────────────────────── -->
            <div class="page-banner anim" style="animation-delay:.04s;">
                <div class="banner-inner">
                    <div>
                        <div class="banner-eyebrow">Participation</div>
                        <h1 class="banner-title">My Attendance</h1>
                        <p class="banner-sub">Track your event check-ins, duration, and overall attendance rate.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                        <button id="darkModeToggle" class="theme-toggle" onclick="toggleDark()" aria-label="Toggle theme">
                            <i data-lucide="sun"  style="width:16px;height:16px;display:none;" id="sunIconD"></i>
                            <i data-lucide="moon" style="width:16px;height:16px;"              id="moonIconD"></i>
                        </button>
                        <div class="banner-date">
                            <i data-lucide="calendar" style="width:13px;height:13px;"></i>
                            <?= date('F j, Y') ?>
                        </div>
                    </div>
                </div>
            </div>


            <!-- ── STAT CARDS ────────────────────────────── -->
            <div class="stats-grid">

                <!-- Events Attended -->
                <div class="stat-card anim" style="animation-delay:.08s;--card-bar:#7c3aed;">
                    <div class="live-badge">
                        <span class="live-dot"></span> Live
                    </div>
                    <div class="stat-icon" style="background:#f5f3ff;border:1px solid #ddd6fe;">
                        <i data-lucide="clipboard-check" style="width:19px;height:19px;color:#7c3aed;"></i>
                    </div>
                    <div class="stat-value"><?= $totalAttended ?></div>
                    <div class="stat-label">Events Attended</div>
                    <div class="stat-progress">
                        <div class="stat-progress-fill" style="width:<?= min($totalAttended * 10, 100) ?>%;"></div>
                    </div>
                    <div class="stat-icon-bg">
                        <i data-lucide="clipboard-check" style="width:52px;height:52px;"></i>
                    </div>
                </div>

                <!-- Attendance Rate -->
                <div class="stat-card anim" style="animation-delay:.13s;--card-bar:#16a34a;">
                    <div class="live-badge" style="color:var(--green);">
                        <span class="live-dot" style="background:var(--green);"></span> Live
                    </div>
                    <div class="stat-icon" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                        <i data-lucide="trending-up" style="width:19px;height:19px;color:#16a34a;"></i>
                    </div>
                    <div class="stat-value"><?= $attendanceRate ?>%</div>
                    <div class="stat-label">Attendance Rate</div>
                    <div class="stat-progress">
                        <div class="stat-progress-fill" style="width:<?= $attendanceRate ?>%;background:#16a34a;"></div>
                    </div>
                    <div class="stat-icon-bg" style="color:#16a34a;">
                        <i data-lucide="trending-up" style="width:52px;height:52px;"></i>
                    </div>
                </div>

                <!-- With Check-In -->
                <div class="stat-card anim" style="animation-delay:.18s;--card-bar:#d97706;">
                    <div class="live-badge" style="color:var(--amber);">
                        <span class="live-dot" style="background:var(--amber);"></span> Live
                    </div>
                    <div class="stat-icon" style="background:#fffbeb;border:1px solid #fde68a;">
                        <i data-lucide="log-in" style="width:19px;height:19px;color:#d97706;"></i>
                    </div>
                    <div class="stat-value"><?= $totalWithLogin ?></div>
                    <div class="stat-label">With Check-In</div>
                    <div class="stat-progress">
                        <div class="stat-progress-fill" style="width:<?= $totalAttended > 0 ? round(($totalWithLogin/$totalAttended)*100) : 0 ?>%;background:#d97706;"></div>
                    </div>
                    <div class="stat-icon-bg" style="color:#d97706;">
                        <i data-lucide="log-in" style="width:52px;height:52px;"></i>
                    </div>
                </div>

                <!-- Full Sessions -->
                <div class="stat-card anim" style="animation-delay:.23s;--card-bar:#0284c7;">
                    <div class="live-badge" style="color:var(--cyan);">
                        <span class="live-dot" style="background:var(--cyan);"></span> Live
                    </div>
                    <div class="stat-icon" style="background:#f0f9ff;border:1px solid #bae6fd;">
                        <i data-lucide="timer" style="width:19px;height:19px;color:#0284c7;"></i>
                    </div>
                    <div class="stat-value"><?= $totalComplete ?></div>
                    <div class="stat-label">Full Sessions</div>
                    <div class="stat-progress">
                        <div class="stat-progress-fill" style="width:<?= $totalAttended > 0 ? round(($totalComplete/$totalAttended)*100) : 0 ?>%;background:#0284c7;"></div>
                    </div>
                    <div class="stat-icon-bg" style="color:#0284c7;">
                        <i data-lucide="timer" style="width:52px;height:52px;"></i>
                    </div>
                </div>

            </div><!-- /stats-grid -->


            <!-- ── ATTENDANCE HISTORY CARD ────────────────── -->
            <div class="history-card anim" style="animation-delay:.28s;">

                <!-- Card header -->
                <div class="card-head">
                    <div>
                        <div class="card-head-title">
                            <span class="head-icon">
                                <i data-lucide="history" style="width:14px;height:14px;"></i>
                            </span>
                            Attendance History
                        </div>
                        <div class="card-head-sub">All your event check-ins sorted by most recent</div>
                    </div>
                    <button id="exportCsvBtn" class="btn-export">
                        <i data-lucide="download" style="width:13px;height:13px;"></i>
                        <span>Export CSV</span>
                    </button>
                </div>


                <!-- ── FILTER BAR ────────────────────────────── -->
                <div class="filter-bar">
                    <div class="search-wrap">
                        <span class="si"><i data-lucide="search" style="width:13px;height:13px;"></i></span>
                        <input id="searchInput" type="text"
                            placeholder="Search event, venue, organizer…"
                            class="filter-input">
                    </div>

                    <select id="typeFilter" class="filter-select">
                        <option value="">All Types</option>
                        <?php foreach ($eventTypes as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="statusFilter" class="filter-select">
                        <option value="">All Statuses</option>
                        <option value="full">Full Session</option>
                        <option value="in_only">Check-In Only</option>
                        <option value="no_scan">No Scan Data</option>
                    </select>

                    <input id="dateFrom" type="date" class="filter-date" title="From date">
                    <input id="dateTo"   type="date" class="filter-date" title="To date">

                    <button id="clearFilters" class="btn-clear">
                        <i data-lucide="x-circle" style="width:13px;height:13px;"></i> Clear
                    </button>
                </div>


                <!-- ── RESULTS BAR ──────────────────────────── -->
                <div class="results-bar">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <span class="results-count-strong" id="resultsCount">—</span>
                        record<span id="resultsPlural">s</span> found
                        <span id="activeFilterBadge" class="filter-badge" style="display:none;"></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <span>Page</span>
                        <span class="results-count-strong" id="pageInfo">1 / 1</span>
                        <select id="perPageSelect" class="filter-select" style="padding:.3rem .5rem;font-size:.72rem;">
                            <option value="10">10 / page</option>
                            <option value="25" selected>25 / page</option>
                            <option value="50">50 / page</option>
                            <option value="100">100 / page</option>
                        </select>
                    </div>
                </div>


                <?php if (!empty($attendanceHistory)): ?>

                    <!-- ── DESKTOP TABLE ──────────────────────── -->
                    <div class="hidden md:block" style="overflow-x:auto;">
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th class="sortable-col" data-col="title">
                                        Event
                                        <i data-lucide="chevrons-up-down" style="width:11px;height:11px;display:inline;opacity:.4;"></i>
                                    </th>
                                    <th class="sortable-col" data-col="type">
                                        Type
                                        <i data-lucide="chevrons-up-down" style="width:11px;height:11px;display:inline;opacity:.4;"></i>
                                    </th>
                                    <th class="sortable-col" data-col="venue">
                                        Venue
                                        <i data-lucide="chevrons-up-down" style="width:11px;height:11px;display:inline;opacity:.4;"></i>
                                    </th>
                                    <th class="sortable-col" data-col="login">
                                        Check-In
                                        <i data-lucide="chevrons-up-down" style="width:11px;height:11px;display:inline;opacity:.4;"></i>
                                    </th>
                                    <th class="sortable-col" data-col="logout">
                                        Check-Out
                                        <i data-lucide="chevrons-up-down" style="width:11px;height:11px;display:inline;opacity:.4;"></i>
                                    </th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody"></tbody>
                        </table>

                        <div id="noResultsDesktop" style="display:none;">
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i data-lucide="search-x" style="width:24px;height:24px;"></i>
                                </div>
                                <div class="empty-title">No records match your filters</div>
                                <p class="empty-sub">Try adjusting or clearing the filters above.</p>
                                <button onclick="clearAllFilters()" class="btn-primary" style="margin-top:.5rem;">Clear Filters</button>
                            </div>
                        </div>
                    </div>

                    <!-- ── MOBILE CARD VIEW ──────────────────── -->
                    <div class="md:hidden" id="mobileCardContainer"></div>
                    <div id="noResultsMobile" class="md:hidden" style="display:none;">
                        <div class="empty-state">
                            <p class="empty-sub">No records match your filters.</p>
                            <button onclick="clearAllFilters()" class="btn-primary" style="margin-top:.5rem;">Clear Filters</button>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination-bar" id="paginationBar">
                        <p id="paginationInfo">Showing — of — records</p>
                        <div style="display:flex;align-items:center;gap:.375rem;" id="paginationControls"></div>
                    </div>

                <?php else: ?>
                    <!-- ── ZERO STATE ────────────────────────── -->
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i data-lucide="clipboard-x" style="width:24px;height:24px;"></i>
                        </div>
                        <div class="empty-title">No attendance records yet</div>
                        <p class="empty-sub">Your check-in history will appear here once you attend an event and your QR code is scanned.</p>
                        <a href="student_event.php" class="btn-primary" style="margin-top:.25rem;">
                            <i data-lucide="calendar-days" style="width:14px;height:14px;"></i>
                            Browse Events
                        </a>
                    </div>
                <?php endif; ?>

            </div><!-- /history-card -->
        </div>
    </main>


    <!-- PHP → JS Bridge -->
    <script>
        const SEMS_ATTENDANCE = {
            data:  <?= $historyJson ?>,
            types: <?= json_encode($eventTypes) ?>
        };
    </script>

    <script src="/js/student_attendance.js"></script>

</body>
</html>