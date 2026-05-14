<?php
// ═══════════════════════════════════════════════════════════════════════════════
// ── SESSION AT DATABASE CONNECTION ──
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

$uid = (int) $_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════════════════════════
// ── AJAX HANDLER: APPROVE / REJECT ──
// ═══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $input   = json_decode(file_get_contents('php://input'), true);
    $eventId = isset($input['eventId']) ? (int) $input['eventId'] : 0;
    $status  = isset($input['status'])  ? trim($input['status'])  : '';
    $remarks = isset($input['remarks']) ? trim($input['remarks']) : '';

    // ── VALIDATION ──
    if (!$eventId || !in_array($status, ['approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit();
    }

    // ── REMARKS REQUIRED ──
    if (empty($remarks)) {
        echo json_encode(['success' => false, 'message' => 'Remarks are required for all decisions.']);
        exit();
    }

    try {
        $pdo->beginTransaction();

        // ── UPDATE EVENT STATUS ──
        $stmt = $pdo->prepare("UPDATE events SET status = ? WHERE event_id = ?");
        $stmt->execute([$status, $eventId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Event not found or already updated']);
            exit();
        }

        // ── LOG THE DECISION ──
        $stmt2 = $pdo->prepare(
            "INSERT INTO event_approvals (event_id, admin_id, approval_status, remarks)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 approval_status = VALUES(approval_status),
                 remarks         = VALUES(remarks),
                 approved_at     = NOW()"
        );
        $stmt2->execute([$eventId, $uid, $status, $remarks]);

        // ══════════════════════════════════════════════════════════════════════
        // ── AUTO-REGISTER STUDENTS ON APPROVAL ──
        // Runs ONLY when admin approves. Handles three cases:
        //   1. Club-only event  → register all members of that club
        //   2. Dept-restricted  → register students from each linked department
        //   3. General event    → voluntary registration, nothing to do here
        // ══════════════════════════════════════════════════════════════════════
        if ($status === 'approved') {

            $evRow = $pdo->prepare("SELECT club_id, is_restricted FROM events WHERE event_id = ?");
            $evRow->execute([$eventId]);
            $ev = $evRow->fetch(PDO::FETCH_ASSOC);

            if (!empty($ev['club_id'])) {
                // ── CASE 1: CLUB-ONLY EVENT ──
                // Register every student who belongs to that club
                $pdo->prepare("
                    INSERT IGNORE INTO registrations (event_id, user_id, registered_at)
                    SELECT ?, u.user_id, NOW()
                    FROM users u
                    WHERE u.club_id = ? AND u.role = 'student'
                ")->execute([$eventId, (int)$ev['club_id']]);

            } elseif (!empty($ev['is_restricted'])) {
                // ── CASE 2: DEPARTMENT-RESTRICTED EVENT ──
                // Get all departments linked to this event, then register
                // students from each one
                $deptStmt = $pdo->prepare(
                    "SELECT dept_id FROM event_departments WHERE event_id = ?"
                );
                $deptStmt->execute([$eventId]);
                $deptIds = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

                foreach ($deptIds as $dId) {
                    $pdo->prepare("
                        INSERT IGNORE INTO registrations (event_id, user_id, registered_at)
                        SELECT ?, u.user_id, NOW()
                        FROM users u
                        WHERE u.dept_id = ? AND u.role = 'student'
                    ")->execute([$eventId, (int)$dId]);
                }
            }
            // ── CASE 3: GENERAL EVENT ──
            // is_restricted = 0 and no club_id → open to all, students
            // register voluntarily; nothing to do here.
        }
        // ══════════════════════════════════════════════════════════════════════

        $pdo->commit();

        // ── REFRESH STATS ──
        $freshStats = [
            'total'    => (int) $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn(),
            'approved' => (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'approved'")->fetchColumn(),
            'rejected' => (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'rejected'")->fetchColumn(),
            'pending'  => (int) $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'pending'")->fetchColumn(),
        ];

        echo json_encode(['success' => true, 'stats' => $freshStats]);
        exit();

    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── ADMIN PROFILE FETCH ──
// ═══════════════════════════════════════════════════════════════════════════════
$adminStmt = $pdo->prepare("
    SELECT a.first_name, a.last_name, a.middle_name, a.phone, a.profile_image,
           u.email
    FROM admin a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.user_id = ?
    LIMIT 1
");
$adminStmt->execute([$uid]);
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
// ── PENDING EVENTS QUERY ──
// ═══════════════════════════════════════════════════════════════════════════════
$sql = "
    SELECT
        e.event_id AS id,
        e.title,
        e.status,
        e.start_datetime,
        e.end_datetime,
        e.description,
        DATE_FORMAT(e.start_datetime, '%M %e, %Y') AS start_date,
        DATE_FORMAT(e.end_datetime,   '%M %e, %Y') AS end_date,
        TIME_FORMAT(e.start_datetime, '%h:%i %p')  AS start_time,
        TIME_FORMAT(e.end_datetime,   '%h:%i %p')  AS end_time,
        COALESCE(o.org_name, c.club_name, 'N/A')   AS org,
        v.venue_name                                AS venue,
        u.email                                     AS organizer_email,
        org.position                                AS organizer_position,
        COALESCE(org.first_name, p2.first_name)     AS organizer_first,
        COALESCE(org.last_name,  p2.last_name)      AS organizer_last,
        org.profile_image                           AS organizer_image,
        ea.remarks                                  AS existing_remarks
    FROM events e
    LEFT JOIN organizations  o   ON e.org_id       = o.org_id
    LEFT JOIN clubs          c   ON e.club_id      = c.club_id
    LEFT JOIN venues         v   ON e.venue_id     = v.venue_id
    LEFT JOIN users          u   ON e.organizer_id = u.user_id
    LEFT JOIN profiles       p2  ON u.user_id      = p2.user_id
    LEFT JOIN `organizer`    org ON u.user_id      = org.user_id
    LEFT JOIN event_approvals ea ON e.event_id     = ea.event_id
    WHERE e.status = 'pending'
    ORDER BY e.created_at DESC
";

try {
    $stmt = $pdo->query($sql);
    $pendingEvents = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['organizer_name'] = trim($row['organizer_first'] . ' ' . $row['organizer_last']);

        if (!empty($row['organizer_image'])) {
            $row['organizer_image'] = 'data:image/jpeg;base64,' . base64_encode($row['organizer_image']);
        } else {
            $row['organizer_image'] = '';
        }

        $pendingEvents[] = $row;
    }
} catch (PDOException $e) {
    die('Query failed: ' . $e->getMessage());
}

$pendingDataJson = json_encode($pendingEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// ═══════════════════════════════════════════════════════════════════════════════
// ── DASHBOARD STATISTICS ──
// ═══════════════════════════════════════════════════════════════════════════════
$totalEvents    = $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn();
$approvedEvents = $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'approved'")->fetchColumn();
$rejectedEvents = $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'rejected'")->fetchColumn();
$pendingCount   = $pdo->query("SELECT COUNT(*) FROM events WHERE status = 'pending'")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEMS Admin - Event Approvals</title>
    <link rel="icon" href="/assets/approvals-icon-indigo.svg">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        primary: {
                            50: '#eff6ff', 100: '#dbeafe',
                            400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb',
                        }
                    },
                    animation: {
                        'fade-up':  'fadeUp .5s ease both',
                        'fade-in':  'fadeIn .3s ease both',
                        'scale-in': 'scaleIn .3s cubic-bezier(0.34,1.56,0.64,1) both',
                    },
                    keyframes: {
                        fadeUp:   { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        fadeIn:   { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        scaleIn:  { '0%': { opacity: '0', transform: 'scale(0.9)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                    }
                }
            }
        }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/CSS/admin_aprovals.css" />
</head>

<script>
    (function() {
        const theme = localStorage.getItem('sems-theme') || 'light';
        if (theme === 'dark') document.documentElement.classList.add('dark');
    })();
</script>

<body class="bg-gray-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 font-sans min-h-screen">

    <div id="overlay" onclick="closeSidebar()"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 opacity-0 pointer-events-none lg:hidden"></div>

    <div class="flex min-h-screen">

        <!-- ═══════ SIDEBAR ══════════════════════════════════════════════════════ -->
        <aside id="sidebar" class="-translate-x-full lg:translate-x-0 fixed top-0 left-0 z-50 h-full w-64 flex flex-col
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
                <a href="admin_dashboard.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-th-large w-5 text-center"></i> Dashboard
                </a>

                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Management</p>
                <a href="admin_event_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-calendar-alt w-5 text-center"></i> Events
                </a>

                <a href="admin_aprovals.php"
                    class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200">
                    <i class="fas fa-check-circle w-5 text-center"></i> Approvals
                    <span id="sidebar-badge"
                        class="ml-auto bg-amber-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= $pendingCount ?></span>
                </a>

                <a href="admin_user_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-users w-5 text-center"></i> Users
                </a>
                <a href="admin_org_club_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-building w-5 text-center"></i> Organizations &amp; Clubs
                </a>

                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Insights</p>
                <a href="admin_insight.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-chart-line w-5 text-center"></i> Analytics
                </a>
            </nav>

            <div class="px-4 py-4 border-t border-gray-100 dark:border-slate-700 space-y-1">
                <a href="admin_settings.php"
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

        <!-- ═══════ MAIN CONTENT ═════════════════════════════════════════════════ -->
        <main class="flex-1 lg:ml-64 min-w-0 flex flex-col">

            <!-- TOP HEADER -->
            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border-b border-gray-200 dark:border-slate-700 px-4 sm:px-8 py-4 flex items-center gap-4 transition-colors duration-300">

                <button onclick="openSidebar()"
                    class="lg:hidden w-10 h-10 flex items-center justify-center flex-shrink-0 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 hover:text-primary-500 transition-all duration-200">
                    <i class="fas fa-bars text-sm"></i>
                </button>

                <div class="hidden sm:block mr-auto">
                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 mb-0.5">
                        <span>Admin</span>
                        <i class="fas fa-chevron-right text-xs"></i>
                        <span class="text-slate-900 dark:text-white font-medium">Approvals</span>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500"><?= date('l, F j, Y') ?></p>
                </div>

                <div class="flex-1 sm:flex-none sm:w-72 relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input type="text" id="search-input" placeholder="Search events..."
                        oninput="filterCards(this.value)"
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

            <!-- PAGE CONTENT -->
            <div class="flex-1 px-4 sm:px-8 py-8 space-y-8">

                <div class="animate-fade-up">
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white">Event Approvals</h1>
                    <p class="text-slate-500 dark:text-slate-400 mt-2" id="count-label">Loading pending events...</p>
                </div>

                <!-- STAT CARDS -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <?php
                    $allStats = [
                        ['label'=>'Total Events',    'value'=>$totalEvents,    'id'=>'stat-total',    'icon'=>'fa-calendar',      'bg'=>'bg-purple-100', 'darkBg'=>'dark:bg-purple-500/20', 'text'=>'text-purple-700', 'darkText'=>'dark:text-purple-300', 'iconColor'=>'text-purple-500', 'darkIcon'=>'dark:text-purple-400'],
                        ['label'=>'Approved Events', 'value'=>$approvedEvents, 'id'=>'stat-approved', 'icon'=>'fa-check-circle',  'bg'=>'bg-emerald-100','darkBg'=>'dark:bg-emerald-500/20','text'=>'text-emerald-700','darkText'=>'dark:text-emerald-300','iconColor'=>'text-emerald-500','darkIcon'=>'dark:text-emerald-400'],
                        ['label'=>'Rejected Events', 'value'=>$rejectedEvents, 'id'=>'stat-rejected', 'icon'=>'fa-times-circle', 'bg'=>'bg-rose-100',   'darkBg'=>'dark:bg-rose-500/20',   'text'=>'text-rose-700',   'darkText'=>'dark:text-rose-300',   'iconColor'=>'text-rose-500',   'darkIcon'=>'dark:text-rose-400'],
                        ['label'=>'Pending Count',   'value'=>$pendingCount,   'id'=>'stat-pending',  'icon'=>'fa-clock',         'bg'=>'bg-amber-100',  'darkBg'=>'dark:bg-amber-500/20',  'text'=>'text-amber-700',  'darkText'=>'dark:text-amber-300',  'iconColor'=>'text-amber-500',  'darkIcon'=>'dark:text-amber-400'],
                    ];
                    foreach ($allStats as $s): ?>
                        <div class="card-anim stat-card animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden cursor-default">
                            <div class="absolute top-4 right-4 w-12 h-12 rounded-xl <?= $s['bg'] ?> <?= $s['darkBg'] ?> flex items-center justify-center">
                                <i class="fas <?= $s['icon'] ?> <?= $s['iconColor'] ?> <?= $s['darkIcon'] ?> text-lg"></i>
                            </div>
                            <div class="pr-14">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1"><?= $s['label'] ?></p>
                                <p id="<?= $s['id'] ?>" class="text-3xl font-bold <?= $s['text'] ?> <?= $s['darkText'] ?> leading-none"><?= $s['value'] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- EVENT CARDS CONTAINER -->
                <div id="approvals-container" class="approvals-grid"></div>

                <!-- EMPTY STATE -->
                <div id="empty-state" class="hidden">
                    <div class="bg-white dark:bg-slate-800 rounded-2xl p-12 text-center border border-gray-100 dark:border-slate-700 shadow-sm">
                        <div class="w-20 h-20 bg-emerald-100 dark:bg-emerald-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-clipboard-check text-emerald-500 dark:text-emerald-400 text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2">All Caught Up!</h3>
                        <p class="text-slate-500 dark:text-slate-400">No pending event approvals at the moment.</p>
                    </div>
                </div>

                <div class="h-4"></div>
            </div>
        </main>
    </div>


    <!-- ═══════ VIEW EVENT MODAL ════════════════════════════════════════════════ -->
    <div id="view-modal" class="fixed inset-0 z-50 modal-hidden modal-wrap">
        <div class="modal-backdrop absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewModal()"></div>
        <div class="relative z-10 flex items-center justify-center min-h-screen p-4">
            <div class="modal-box bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto border border-gray-100 dark:border-slate-700">

                <div class="sticky top-0 bg-white dark:bg-slate-800 border-b border-gray-100 dark:border-slate-700 px-6 py-4 flex items-center justify-between rounded-t-2xl z-10">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-calendar-alt text-amber-500 dark:text-amber-400 text-lg"></i>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-lg font-bold text-slate-900 dark:text-white truncate" id="view-title">Event Title</h3>
                            <span class="inline-block mt-0.5 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400">Pending</span>
                        </div>
                    </div>
                    <button onclick="closeViewModal()"
                        class="w-8 h-8 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 flex items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors flex-shrink-0">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="p-6 space-y-5">
                    <div class="flex items-center gap-3 bg-gray-50 dark:bg-slate-700/30 rounded-xl p-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-lg flex-shrink-0 overflow-hidden" id="view-organizer-avatar">U</div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white truncate" id="view-organizer-name">Unknown</p>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                <span class="px-2 py-0.5 rounded-md bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 text-[11px] font-semibold" id="view-organizer-position">Position</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400 truncate" id="view-organizer-email"></span>
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="bg-gray-50 dark:bg-slate-700/30 rounded-xl p-3 text-center">
                            <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center mx-auto mb-2"><i class="fas fa-calendar text-blue-500 dark:text-blue-400 text-xs"></i></div>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-semibold">Start Date</p>
                            <p class="text-xs font-medium text-slate-900 dark:text-white mt-0.5" id="view-start-date">-</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-slate-700/30 rounded-xl p-3 text-center">
                            <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-500/20 flex items-center justify-center mx-auto mb-2"><i class="fas fa-calendar-check text-indigo-500 dark:text-indigo-400 text-xs"></i></div>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-semibold">End Date</p>
                            <p class="text-xs font-medium text-slate-900 dark:text-white mt-0.5" id="view-end-date">-</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-slate-700/30 rounded-xl p-3 text-center">
                            <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center mx-auto mb-2"><i class="fas fa-clock text-purple-500 dark:text-purple-400 text-xs"></i></div>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-semibold">Time</p>
                            <p class="text-xs font-medium text-slate-900 dark:text-white mt-0.5" id="view-time">-</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-slate-700/30 rounded-xl p-3 text-center">
                            <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center mx-auto mb-2"><i class="fas fa-map-marker-alt text-emerald-500 dark:text-emerald-400 text-xs"></i></div>
                            <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-semibold">Venue</p>
                            <p class="text-xs font-medium text-slate-900 dark:text-white mt-0.5" id="view-venue">-</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300 bg-gray-50 dark:bg-slate-700/30 rounded-xl p-3">
                        <i class="fas fa-building text-slate-400 w-5 text-center"></i>
                        <span class="font-medium" id="view-org">Organization</span>
                    </div>

                    <div id="view-description-section">
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-semibold mb-2">Description</p>
                        <div class="bg-gray-50 dark:bg-slate-700/30 rounded-xl p-4 text-sm text-slate-700 dark:text-slate-300 leading-relaxed" id="view-description">No description provided.</div>
                    </div>

                    <div id="view-remarks-section" class="hidden">
                        <p class="text-[10px] text-slate-500 dark:text-slate-400 uppercase font-semibold mb-2">Admin Remarks</p>
                        <div class="bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl p-4 flex gap-3">
                            <i class="fas fa-comment-alt text-amber-500 dark:text-amber-400 mt-0.5 flex-shrink-0 text-sm"></i>
                            <p class="text-sm text-amber-800 dark:text-amber-300 leading-relaxed" id="view-remarks-text"></p>
                        </div>
                    </div>
                </div>

                <div class="sticky bottom-0 bg-white dark:bg-slate-800 border-t border-gray-100 dark:border-slate-700 px-6 py-4 rounded-b-2xl flex gap-3">
                    <button onclick="closeViewModal()"
                        class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-medium text-sm hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">Close</button>
                    <button onclick="confirmFromView('approved')"
                        class="flex-1 px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-sm shadow-lg shadow-emerald-500/30 transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button onclick="confirmFromView('rejected')"
                        class="flex-1 px-4 py-2.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-semibold text-sm shadow-lg shadow-rose-500/30 transition-colors flex items-center justify-center gap-2">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══════ CONFIRM DECISION MODAL ══════════════════════════════════════════ -->
    <div id="confirm-modal" class="fixed inset-0 z-50 modal-hidden modal-wrap">
        <div class="modal-backdrop absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeConfirmModal()"></div>
        <div class="relative z-10 flex items-center justify-center min-h-screen p-4">
            <div class="modal-box bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full border border-gray-100 dark:border-slate-700 overflow-hidden">

                <div class="px-6 pt-8 pb-6 text-center">
                    <div id="confirm-icon-bg" class="w-16 h-16 rounded-full bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center mx-auto mb-4">
                        <i id="confirm-icon" class="fas fa-check text-2xl text-emerald-500 dark:text-emerald-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-1" id="confirm-title">Approve Event?</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400" id="confirm-subtitle">Are you sure you want to approve this event?</p>
                </div>

                <div class="px-6 pb-2">
                    <div class="bg-gray-50 dark:bg-slate-700/30 rounded-xl p-3 mb-4 flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-calendar-alt text-amber-500 dark:text-amber-400 text-xs"></i>
                        </div>
                        <p class="text-sm font-semibold text-slate-900 dark:text-white truncate" id="confirm-event-title">Event Title</p>
                    </div>

                    <div class="mb-1">
                        <label class="text-xs text-slate-500 dark:text-slate-400 uppercase font-semibold mb-1.5 flex items-center gap-1">
                            Remarks <span class="text-rose-500 font-bold">*</span>
                            <span id="remarks-required-label" class="text-rose-500 text-[10px] font-normal normal-case hidden ml-1">This field is required</span>
                        </label>
                        <textarea id="confirm-remarks" rows="3"
                            placeholder="Add your remarks for the organizer (required)..." oninput="clearRemarksError()"
                            class="w-full px-4 py-3 text-sm rounded-xl bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-400 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all duration-200 resize-none"></textarea>
                        <p class="text-[10px] text-slate-400 mt-1 text-right"><span id="remarks-char-count">0</span> characters</p>
                    </div>
                </div>

                <div class="px-6 py-5 flex gap-3">
                    <button onclick="closeConfirmModal()"
                        class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-medium text-sm hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">Cancel</button>
                    <button id="confirm-btn" onclick="executeDecision()"
                        class="flex-1 px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-semibold text-sm shadow-lg transition-colors flex items-center justify-center gap-2">
                        <span>Confirm</span>
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- DATA BRIDGE: PHP → JS -->
    <script>
        const SEMS_APPROVALS_DATA = {
            pendingEvents: <?= $pendingDataJson ?>,
        };
    </script>

    <script src="../js/admin_aprovals.js"></script>
</body>
</html>