<?php
// ============================================================
//  SEMS — Admin User Management Page
//  File: admin/admin_user_management.php
// ============================================================

session_start();
$pdo = require_once '../includes/db.php';

// ── AUTH GUARD ───────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];

// ── AJAX REQUEST HANDLER ─────────────────────────────────────
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
) {
    header('Content-Type: application/json');

    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $userId = (int) ($input['userId'] ?? 0);

    if (!$userId || ($action === 'delete' && $userId === $uid)) {
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        exit();
    }

    try {
        // ── ARCHIVE (SOFT-DELETE) ────────────────────────────
        if ($action === 'delete') {
            $stmt = $pdo->prepare("
                UPDATE users
                SET deleted_at = NOW(), deleted_by = :deleted_by
                WHERE user_id = :user_id AND deleted_at IS NULL
            ");
            $stmt->execute(['deleted_by' => $uid, 'user_id' => $userId]);
            echo json_encode(['success' => $stmt->rowCount() > 0]);
            exit();
        }

        // ── RESTORE ──────────────────────────────────────────
        if ($action === 'restore') {
            $stmt = $pdo->prepare("
                UPDATE users
                SET deleted_at = NULL, deleted_by = NULL
                WHERE user_id = :user_id AND deleted_at IS NOT NULL
            ");
            $stmt->execute(['user_id' => $userId]);
            echo json_encode(['success' => $stmt->rowCount() > 0]);
            exit();
        }

        // ── PERMANENT DELETE ─────────────────────────────────
        if ($action === 'permanent_delete') {
            $pdo->prepare("DELETE FROM profiles WHERE user_id = :user_id")
                ->execute(['user_id' => $userId]);
            $stmt = $pdo->prepare("
                DELETE FROM users WHERE user_id = :user_id AND deleted_at IS NOT NULL
            ");
            $stmt->execute(['user_id' => $userId]);
            echo json_encode(['success' => $stmt->rowCount() > 0]);
            exit();
        }

        // ── EDIT ROLE ────────────────────────────────────────
        if ($action === 'edit_role') {
            $role   = $input['role']   ?? '';
            $deptId = (int) ($input['deptId'] ?? 0);
            $email  = $input['email']  ?? '';

            $stmt = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = :user_id");
            $stmt->execute(['user_id' => $userId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!empty($email)) {
                $pdo->prepare("UPDATE users SET email = :email WHERE user_id = :user_id")
                    ->execute(['email' => $email, 'user_id' => $userId]);
            }

            if ($role === 'student') {
                $clubId = (int) ($input['clubId'] ?? $current['club_id'] ?? 0);
                $pdo->prepare("
                    UPDATE users SET role=:role, dept_id=:dept_id, org_id=NULL, club_id=:club_id WHERE user_id=:user_id
                ")->execute(['role'=>$role,'dept_id'=>$deptId?:null,'club_id'=>$clubId?:null,'user_id'=>$userId]);

            } elseif ($role === 'organizer') {
                $orgId = (int) ($input['orgId'] ?? $current['org_id'] ?? 0);
                $pdo->prepare("
                    UPDATE users SET role=:role, dept_id=:dept_id, org_id=:org_id, club_id=NULL WHERE user_id=:user_id
                ")->execute(['role'=>$role,'dept_id'=>$deptId?:null,'org_id'=>$orgId?:null,'user_id'=>$userId]);

            } elseif ($role === 'admin') {
                $pdo->prepare("
                    UPDATE users SET role=:role, dept_id=:dept_id, org_id=NULL, club_id=NULL WHERE user_id=:user_id
                ")->execute(['role'=>$role,'dept_id'=>$deptId?:null,'user_id'=>$userId]);
            }

            echo json_encode(['success' => true]);
            exit();
        }

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }

    exit();
}

// ── FETCH ADMIN PROFILE DATA ──────────────────────────────────
$adminStmt = $pdo->prepare("
    SELECT a.first_name, a.last_name, a.middle_name, a.profile_image, u.email
    FROM   admin a JOIN users u ON a.user_id = u.user_id
    WHERE  a.user_id = :admin_id LIMIT 1
");
$adminStmt->execute(['admin_id' => $uid]);
$adminData = $adminStmt->fetch(PDO::FETCH_ASSOC);

$adminFirstName  = $adminData['first_name']  ?? '';
$adminLastName   = $adminData['last_name']   ?? '';
$adminMiddleName = $adminData['middle_name'] ?? '';

$adminMiddleInitial = !empty($adminMiddleName) ? strtoupper(substr($adminMiddleName, 0, 1)) . '.' : '';
$adminFullName      = trim($adminFirstName . ' ' . $adminMiddleInitial . ' ' . $adminLastName) ?: 'Administrator';
$adminInitials      = strtoupper(substr($adminFirstName, 0, 1) . substr($adminMiddleName, 0, 1) . substr($adminLastName, 0, 1)) ?: 'A';

$adminAvatar = '';
if (!empty($adminData['profile_image'])) {
    $fi       = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fi->buffer($adminData['profile_image']);
    if (!$mimeType || strpos($mimeType, 'image/') !== 0) $mimeType = 'image/jpeg';
    $adminAvatar = 'data:' . $mimeType . ';base64,' . base64_encode($adminData['profile_image']);
}

// ── FETCH ACTIVE USERS ────────────────────────────────────────
$sql = "
    SELECT
        u.user_id, u.email, u.role, u.org_id, u.club_id, u.dept_id, u.created_at,
        CASE WHEN u.role='organizer' THEN o2.profile_image ELSE p.profile_image END AS profile_image,
        COALESCE(TRIM(CONCAT(
            CASE WHEN u.role='organizer' THEN o2.first_name  ELSE p.first_name  END, ' ',
            CASE WHEN u.role='organizer' THEN o2.middle_name ELSE p.middle_name END, ' ',
            CASE WHEN u.role='organizer' THEN o2.last_name   ELSE p.last_name   END
        )), 'No Name') AS full_name,
        d.dept_name, o.org_name, c.club_name
    FROM   users u
    LEFT JOIN profiles      p  ON u.user_id = p.user_id
    LEFT JOIN organizer     o2 ON u.user_id = o2.user_id
    LEFT JOIN departments   d  ON u.dept_id = d.dept_id
    LEFT JOIN organizations o  ON u.org_id  = o.org_id
    LEFT JOIN clubs         c  ON u.club_id = c.club_id
    WHERE u.role != 'admin' AND u.deleted_at IS NULL
    ORDER BY u.created_at DESC
";

$users = [];
foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['joined'] = date('M Y', strtotime($row['created_at']));
    if (!empty($row['profile_image'])) $row['profile_image'] = base64_encode($row['profile_image']);
    $users[] = $row;
}

// ── STATISTICS ────────────────────────────────────────────────
$totalUsers     = count($users);
$studentCount   = count(array_filter($users, fn($u) => $u['role'] === 'student'));
$organizerCount = count(array_filter($users, fn($u) => $u['role'] === 'organizer'));

// ── DROPDOWN DATA ─────────────────────────────────────────────
$depts = $pdo->query("SELECT dept_id, dept_name FROM departments")->fetchAll(PDO::FETCH_ASSOC);
$orgs  = $pdo->query("SELECT org_id,  org_name  FROM organizations")->fetchAll(PDO::FETCH_ASSOC);
$clubs = $pdo->query("SELECT club_id, club_name FROM clubs")->fetchAll(PDO::FETCH_ASSOC);

// ── FETCH ARCHIVED USERS ──────────────────────────────────────
$archiveSql = "
    SELECT
        u.user_id, u.email, u.role, u.dept_id, u.deleted_at, u.deleted_by,
        CASE WHEN u.role='organizer' THEN o2.profile_image ELSE p.profile_image END AS profile_image,
        COALESCE(TRIM(CONCAT(
            CASE WHEN u.role='organizer' THEN o2.first_name  ELSE p.first_name  END, ' ',
            CASE WHEN u.role='organizer' THEN o2.middle_name ELSE p.middle_name END, ' ',
            CASE WHEN u.role='organizer' THEN o2.last_name   ELSE p.last_name   END
        )), 'No Name') AS full_name,
        d.dept_name, del.email AS deleted_by_email
    FROM   users u
    LEFT JOIN profiles      p   ON u.user_id    = p.user_id
    LEFT JOIN organizer     o2  ON u.user_id    = o2.user_id
    LEFT JOIN departments   d   ON u.dept_id    = d.dept_id
    LEFT JOIN users         del ON u.deleted_by = del.user_id
    WHERE u.role != 'admin' AND u.deleted_at IS NOT NULL
    ORDER BY u.deleted_at DESC
";

$archivedUsers = [];
foreach ($pdo->query($archiveSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $row['archived_on'] = date('M j, Y', strtotime($row['deleted_at']));
    if (!empty($row['profile_image'])) $row['profile_image'] = base64_encode($row['profile_image']);
    $archivedUsers[] = $row;
}
$archivedCount = count($archivedUsers);

// ── PHP → JS DATA BRIDGE ─────────────────────────────────────
$userDataJson = json_encode($users         ?: [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$deptsJson    = json_encode($depts         ?: [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$orgsJson     = json_encode($orgs          ?: [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$clubsJson    = json_encode($clubs         ?: [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$archivedJson = json_encode($archivedUsers ?: [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SEMS Admin — User Management</title>
    <link rel="icon" href="/assets/user-management-icon-indigo.svg" />

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        primary: { 50:'#eff6ff', 100:'#dbeafe', 400:'#60a5fa', 500:'#3b82f6', 600:'#2563eb' },
                    },
                    animation: {
                        'fade-up':  'fadeUp .5s ease both',
                        'fade-in':  'fadeIn .4s ease both',
                        'slide-in': 'slideIn .3s ease both',
                    },
                    keyframes: {
                        fadeUp:  { '0%':{'opacity':'0','transform':'translateY(20px)'}, '100%':{'opacity':'1','transform':'translateY(0)'} },
                        fadeIn:  { '0%':{'opacity':'0'}, '100%':{'opacity':'1'} },
                        slideIn: { '0%':{'opacity':'0','transform':'translateX(-10px)'}, '100%':{'opacity':'1','transform':'translateX(0)'} },
                    },
                },
            },
        }
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="/CSS/admin_user_management.css">
</head>

<script>
    (function () {
        const t = localStorage.getItem('sems-theme') || 'light';
        if (t === 'dark') document.documentElement.classList.add('dark');
    })();
</script>

<body class="bg-gray-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 font-sans min-h-screen">

    <div id="overlay" onclick="closeSidebar()"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 opacity-0 pointer-events-none lg:hidden"></div>

    <div class="flex min-h-screen">

        <!-- ════ SIDEBAR ════ -->
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
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-calendar-alt w-5 text-center"></i> Events
                </a>
                <a href="/admin/admin_aprovals.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-check-circle w-5 text-center"></i> Approvals
                </a>
                <a href="/admin/admin_user_management.php"
                    class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200">
                    <i class="fas fa-users w-5 text-center"></i> Users
                </a>
                <a href="/admin/admin_org_club_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-building w-5 text-center"></i> Organizations &amp; Clubs
                </a>

                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Communication</p>
            <a href="/admin/admin_chat.php"
               class="nav-item  flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
                <i class="fas fa-comments w-5 text-center"></i>
                Messages
                <span id="sidebarBadge" class="ml-auto hidden text-[10px] font-bold bg-primary-500 text-white rounded-full px-1.5 py-0.5"></span>
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


        <!-- ════ MAIN CONTENT ════ -->
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
                        <span class="text-slate-900 dark:text-white font-medium">Users</span>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500"><?= date('l, F j, Y') ?></p>
                </div>

                <div class="flex-1 sm:flex-none sm:w-72 relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input id="userSearch" type="text" placeholder="Search users..."
                        oninput="applyFilters()"
                        class="w-full pl-10 pr-4 py-2.5 text-sm rounded-xl bg-gray-100 dark:bg-slate-700 border-0 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:bg-white dark:focus:bg-slate-600 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all duration-200" />
                </div>

                <div class="flex items-center gap-3 pl-4 border-l border-gray-200 dark:border-slate-700">
                    <div class="hidden md:block text-right">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white leading-none"><?= $adminFullName ?></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Administrator</p>
                    </div>
                    <div class="relative group cursor-pointer">
    <?php if ($adminAvatar): ?>
        <img src="<?= $adminAvatar ?>" alt="<?= htmlspecialchars($adminFullName) ?>"
            class="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-600 shadow-md">
    <?php else: ?>
        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white text-sm font-bold shadow-md">
            <?= $adminInitials ?>
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
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white">User Management</h1>
                    <p class="text-slate-500 dark:text-slate-400 mt-2">Manage students, organizers, and admin accounts.</p>
                </div>

                <!-- ── STATISTICS CARDS ── -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <?php
                    $allStats = [
                        ['label'=>'Total Users',    'value'=>$totalUsers,     'id'=>'total',     'slug'=>'all',       'icon'=>'fa-users',         'bg'=>'bg-primary-100', 'darkBg'=>'dark:bg-primary-500/20', 'text'=>'text-primary-700', 'darkText'=>'dark:text-primary-300', 'iconColor'=>'text-primary-500', 'darkIcon'=>'dark:text-primary-400'],
                        ['label'=>'Students',       'value'=>$studentCount,   'id'=>'student',   'slug'=>'student',   'icon'=>'fa-user-graduate', 'bg'=>'bg-blue-100',    'darkBg'=>'dark:bg-blue-500/20',    'text'=>'text-blue-700',    'darkText'=>'dark:text-blue-300',    'iconColor'=>'text-blue-500',    'darkIcon'=>'dark:text-blue-400'],
                        ['label'=>'Organizers',     'value'=>$organizerCount, 'id'=>'organizer', 'slug'=>'organizer', 'icon'=>'fa-user-tie',      'bg'=>'bg-violet-100',  'darkBg'=>'dark:bg-violet-500/20',  'text'=>'text-violet-700',  'darkText'=>'dark:text-violet-300',  'iconColor'=>'text-violet-500',  'darkIcon'=>'dark:text-violet-400'],
                        ['label'=>'Archived Users', 'value'=>$archivedCount,  'id'=>'archived',  'slug'=>'archived',  'icon'=>'fa-archive',       'bg'=>'bg-slate-100',   'darkBg'=>'dark:bg-slate-500/20',   'text'=>'text-slate-700',   'darkText'=>'dark:text-slate-300',   'iconColor'=>'text-slate-500',   'darkIcon'=>'dark:text-slate-400'],
                    ];
                    foreach ($allStats as $s): ?>
                        <div class="card-anim stat-card animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden cursor-pointer"
                            onclick="<?= $s['slug'] === 'archived' ? 'showArchivedView()' : "setRoleFilter('" . $s['slug'] . "')" ?>">
                            <div class="absolute top-4 right-4 w-12 h-12 rounded-xl <?= $s['bg'] ?> <?= $s['darkBg'] ?> flex items-center justify-center">
                                <i class="fas <?= $s['icon'] ?> <?= $s['iconColor'] ?> <?= $s['darkIcon'] ?> text-lg"></i>
                            </div>
                            <div class="pr-14">
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1"><?= $s['label'] ?></p>
                                <p id="stat-<?= $s['id'] ?>" class="text-3xl font-bold <?= $s['text'] ?> <?= $s['darkText'] ?> leading-none"><?= $s['value'] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- ── VIEW TOGGLE ── -->
                <div class="flex items-center gap-3 animate-fade-up" style="animation-delay:.05s">
                    <div class="flex rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden bg-white dark:bg-slate-800 shadow-sm">
                        <button id="view-active-btn" onclick="showActiveView()"
                            class="px-4 py-2.5 text-xs font-semibold flex items-center gap-2 bg-primary-500 text-white transition-all duration-200">
                            <i class="fas fa-users"></i> Active Users
                        </button>
                        <button id="view-archived-btn" onclick="showArchivedView()"
                            class="px-4 py-2.5 text-xs font-semibold flex items-center gap-2 text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700 transition-all duration-200">
                            <i class="fas fa-archive"></i> Archived
                            <?php if ($archivedCount > 0): ?>
                                <!-- ── FIX: Added 'archived-badge-live' class so JS reuses this element
                                          instead of creating a second duplicate badge. ── -->
                                <span class="archived-badge-live ml-0.5 min-w-[1.25rem] h-5 px-1 rounded-full bg-amber-400 text-amber-900 text-[10px] font-bold inline-flex items-center justify-center">
                                    <?= $archivedCount ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    </div>
                </div>


                <!-- ════════════════════════════════════════
                     ACTIVE USERS SECTION
                     ════════════════════════════════════════ -->
                <div id="active-section">

                    <!-- Filter Tabs -->
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 animate-fade-up" style="animation-delay:.1s">
                        <div class="flex gap-2 flex-wrap">
                            <button id="tab-all" onclick="setRoleFilter('all')"
                                class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-primary-500 text-white shadow-sm shadow-primary-500/30 transition-all duration-200">
                                All Roles
                            </button>
                            <button id="tab-student" onclick="setRoleFilter('student')"
                                class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-blue-400 hover:text-blue-600 transition-all duration-200">
                                <i class="fas fa-user-graduate mr-1 text-blue-500"></i> Student
                            </button>
                            <button id="tab-organizer" onclick="setRoleFilter('organizer')"
                                class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-violet-400 hover:text-violet-600 transition-all duration-200">
                                <i class="fas fa-user-tie mr-1 text-violet-500"></i> Organizer
                            </button>
                        </div>
                        <div class="sm:ml-auto">
                            <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400">
                                <i class="fas fa-users mr-1.5 text-primary-500"></i>
                                <span id="result-num" class="mr-1">0</span> accounts
                            </span>
                        </div>
                    </div>

                    <!-- Users Table -->
                    <div class="animate-fade-up mt-5 bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden" style="animation-delay:.15s">
                        <div class="px-6 py-5 border-b border-gray-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-3">
                            <div>
                                <p class="font-bold text-slate-900 dark:text-white text-lg">All Users</p>
                                <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Manage user accounts and permissions</p>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[640px]">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-slate-700/50">
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Email</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Role</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Department</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Joined</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="userTableBody" class="divide-y divide-gray-100 dark:divide-slate-700"></tbody>
                            </table>
                        </div>
                        <div id="empty-state" class="hidden px-4 py-12 text-center">
                            <div class="w-16 h-16 bg-gray-100 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-users-slash text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-slate-900 dark:text-white font-medium">No users found</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Try a different filter or search term</p>
                        </div>
                        <div id="paginationContainer"></div>
                    </div>
                </div><!-- /#active-section -->


                <!-- ════════════════════════════════════════
                     ARCHIVED USERS SECTION (hidden by default)
                     ════════════════════════════════════════ -->
                <div id="archived-section" style="display:none" class="space-y-5">

                    <!-- Archived header row -->
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 animate-fade-up">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl bg-amber-100 dark:bg-amber-500/10 flex items-center justify-center">
                                <i class="fas fa-archive text-amber-500 text-sm"></i>
                            </div>
                            <p class="font-bold text-slate-900 dark:text-white">Archived Users</p>
                        </div>
                        <div class="sm:ml-auto flex items-center gap-3">
                            <div class="relative">
                                <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                                <input id="archiveSearch" type="text" placeholder="Search archived..."
                                    oninput="filterArchived()"
                                    class="pl-9 pr-4 py-2 text-sm rounded-xl bg-gray-100 dark:bg-slate-700 border-0 focus:outline-none focus:ring-2 focus:ring-primary-500/30 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all duration-200 w-52" />
                            </div>
                            <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400">
                                <i class="fas fa-archive mr-1.5 text-amber-500"></i>
                                <span id="archive-result-num" class="mr-1">0</span> archived
                            </span>
                        </div>
                    </div>

                    <?php if ($archivedCount > 0): ?>
                    <div class="flex items-center gap-3 px-4 py-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl text-sm text-amber-700 dark:text-amber-300">
                        <i class="fas fa-info-circle flex-shrink-0"></i>
                        <span>Archived users are hidden from the system. Restore them to reactivate their accounts, or permanently delete to erase all data.</span>
                    </div>
                    <?php endif; ?>

                    <!-- Archived Table -->
                    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm min-w-[700px]">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-slate-700/50">
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Role</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Archived On</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Archived By</th>
                                        <th class="px-6 py-4 text-left font-semibold text-slate-500 dark:text-slate-400 text-xs uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="archiveTableBody" class="divide-y divide-gray-100 dark:divide-slate-700"></tbody>
                            </table>
                        </div>
                        <div id="archiveEmpty" class="hidden px-4 py-12 text-center">
                            <div class="w-16 h-16 bg-gray-100 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-box-open text-gray-400 text-2xl"></i>
                            </div>
                            <p class="text-slate-900 dark:text-white font-medium">Archive is empty</p>
                            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">No archived users yet.</p>
                        </div>
                    </div>
                </div><!-- /#archived-section -->

                <div class="h-4"></div>
            </div>
        </main>
    </div>


    <!-- ════ MODAL: VIEW USER ════ -->
    <div id="viewModal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md border border-gray-200 dark:border-slate-700 my-8 max-h-[88vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-blue-100 dark:bg-blue-500/10 flex items-center justify-center">
                            <i class="fas fa-user text-blue-500 text-sm"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-sm">User Details</p>
                            <p class="text-xs text-slate-400">Full account information</p>
                        </div>
                    </div>
                    <button onclick="closeViewModal()"
                        class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 flex items-center justify-center text-slate-500 transition-all duration-200">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4" id="viewModalBody"></div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-slate-700 flex justify-end flex-shrink-0">
                    <button onclick="closeViewModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ════ MODAL: EDIT USER ════ -->
    <div id="editModal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md border border-gray-200 dark:border-slate-700 my-8 max-h-[88vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-500/10 flex items-center justify-center">
                            <i class="fas fa-pencil-alt text-violet-500 text-sm"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-sm">Edit User</p>
                            <p class="text-xs text-slate-400">Modify role and department</p>
                        </div>
                    </div>
                    <button onclick="closeEditModal()"
                        class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 flex items-center justify-center text-slate-500 transition-all duration-200">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4" id="editModalBody"></div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-slate-700 flex gap-3 justify-end flex-shrink-0">
                    <button onclick="closeEditModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Cancel
                    </button>
                    <button onclick="saveUserEdit()" id="saveEditBtn"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-violet-500 hover:bg-violet-600 text-white shadow-sm shadow-violet-500/30 transition-all duration-200">
                        Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ════ MODAL: ARCHIVE CONFIRMATION ════ -->
    <div id="deleteModal" class="fixed inset-0 z-[200] hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm border border-gray-200 dark:border-slate-700 text-center p-6">
                <div class="w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-500/10 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-archive text-amber-500 text-xl"></i>
                </div>
                <p class="font-bold text-slate-900 dark:text-white mb-2 text-lg">Archive User?</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">
                    This user will be hidden from active lists. You can restore them anytime from the <strong class="text-slate-700 dark:text-slate-300">Archived</strong> view.
                </p>
                <div class="flex gap-3 justify-center">
                    <button onclick="closeDeleteModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Cancel
                    </button>
                    <button onclick="confirmDelete()" id="confirmDeleteBtn"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-amber-500 hover:bg-amber-600 text-white shadow-sm shadow-amber-500/30 transition-all duration-200 flex items-center gap-2">
                        <i class="fas fa-archive"></i> Archive User
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ════ MODAL: PERMANENT DELETE ════ -->
    <div id="permDeleteModal" class="fixed inset-0 z-[200] hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closePermDeleteModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm border border-rose-200 dark:border-rose-500/40 text-center p-6">
                <div class="w-14 h-14 rounded-full bg-rose-100 dark:bg-rose-500/10 flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-skull-crossbones text-rose-500 text-xl"></i>
                </div>
                <p class="font-bold text-slate-900 dark:text-white mb-1 text-lg">Permanently Delete?</p>
                <p class="text-xs font-semibold text-rose-500 uppercase tracking-wider mb-3">This cannot be undone</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed">
                    All profile data, attendance records, and history for this user will be
                    <strong class="text-rose-500">erased forever</strong>.
                </p>
                <div class="flex gap-3 justify-center">
                    <button onclick="closePermDeleteModal()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 transition-all duration-200">
                        Cancel
                    </button>
                    <button onclick="confirmPermDelete()" id="confirmPermDeleteBtn"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-rose-600 hover:bg-rose-700 text-white shadow-sm shadow-rose-500/30 transition-all duration-200 flex items-center gap-2">
                        <i class="fas fa-trash-alt"></i> Delete Forever
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ════ PHP → JS DATA BRIDGE ════ -->
    <script>
        const SEMS_USER_DATA = {
            users:    <?= $userDataJson ?>,
            depts:    <?= $deptsJson ?>,
            orgs:     <?= $orgsJson ?>,
            clubs:    <?= $clubsJson ?>,
            archived: <?= $archivedJson ?>,
        };
    </script>
    <script src="/js/admin_user_manage.js"></script>

</body>
</html>