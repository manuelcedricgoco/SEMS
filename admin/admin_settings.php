<?php
// ============================================================
//  SEMS — Admin Settings Page
//  File: admin/admin_settings.php
// ============================================================

// Sisimulan ang session para ma-access ang login data ng user
session_start();

// I-import ang database connection — nagre-return ng PDO instance
$pdo = require_once '../includes/db.php';


// ── AUTH GUARD ───────────────────────────────────────────────
// Sinisigurado na admin lang ang makakapasok sa page na ito
// Kung hindi admin o hindi naka-login, ire-redirect sa auth page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

// Kinukuha ang user_id mula sa session at ini-cast bilang integer para sa seguridad
$uid = (int) $_SESSION['user_id'];


// ── HELPER FUNCTIONS ─────────────────────────────────────────

// Ginagamit para makuha ang global $pdo sa loob ng mga function
function getDB(): PDO
{
    global $pdo;
    return $pdo;
}

// Kinukuhang lahat ng impormasyon ng admin user mula sa database
// Gina-join ang users at admin tables para makita ang email, pangalan, phone, at profile image
function fetchUser(int $uid): array
{
    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.role,
               a.first_name, a.middle_name, a.last_name, a.phone, a.profile_image
        FROM   users u
        LEFT JOIN admin a ON a.user_id = u.user_id
        WHERE  u.user_id = :uid
        LIMIT  1
    ");
    $stmt->execute([':uid' => $uid]);

    // Nagre-return ng empty array kung walang nahanap
    return $stmt->fetch() ?: [];
}


// ── MESSAGE VARIABLES ─────────────────────────────────────────
// Mag-iimbak ng success o error messages na ipapakita sa user
$successMsg = '';
$errorMsg   = '';


// ── FORM SUBMISSION HANDLER ───────────────────────────────────
// Pinoproseso ang lahat ng form submissions kapag POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── ACTION: UPDATE PERSONAL INFORMATION ──────────────────
    // Ina-update ang pangalan, email, at phone number ng admin
    if ($_POST['action'] === 'update_info') {

        // Kinukuha at nililinis ang mga input mula sa form
        $firstName  = trim($_POST['first_name']  ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $lastName   = trim($_POST['last_name']   ?? '');
        $email      = trim($_POST['email']       ?? '');
        $phone      = trim($_POST['phone']       ?? '');

        // Bine-validate ang mga required fields bago i-save sa database
        if ($firstName === '' || $lastName === '' || $email === '') {
            $errorMsg = 'First name, last name, and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Sinisigurado na valid ang format ng email address
            $errorMsg = 'Please enter a valid email address.';
        } else {
            try {
                $pdo = getDB();

                // Una, ina-update ang email sa users table
                $pdo->prepare("UPDATE users SET email = :email WHERE user_id = :uid")
                    ->execute([':email' => $email, ':uid' => $uid]);

                // Sinisigurado kung mayroon nang admin record ang user
                $check = $pdo->prepare("SELECT admin_id FROM admin WHERE user_id = :uid");
                $check->execute([':uid' => $uid]);

                if ($check->fetch()) {
                    // Kung mayroon nang record, i-UPDATE na lang ang existing data
                    $pdo->prepare("
                        UPDATE admin
                        SET first_name = :fn, middle_name = :mn, last_name = :ln, phone = :ph
                        WHERE user_id = :uid
                    ")->execute([
                        ':fn'  => $firstName,
                        ':mn'  => $middleName,
                        ':ln'  => $lastName,
                        ':ph'  => $phone,
                        ':uid' => $uid,
                    ]);
                } else {
                    // Kung wala pang admin record, mag-INSERT ng bago
                    $pdo->prepare("
                        INSERT INTO admin (user_id, first_name, middle_name, last_name, phone, profile_image)
                        VALUES (:uid, :fn, :mn, :ln, :ph, '')
                    ")->execute([
                        ':uid' => $uid,
                        ':fn'  => $firstName,
                        ':mn'  => $middleName,
                        ':ln'  => $lastName,
                        ':ph'  => $phone,
                    ]);
                }

                $successMsg = 'Personal information updated successfully.';
            } catch (PDOException $e) {
                // Kung may database error, ipapakita ang mensahe sa user
                $errorMsg = 'Database error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }


    // ── ACTION: UPLOAD PROFILE PHOTO ─────────────────────────
    // Hina-handle ang pag-upload ng profile picture ng admin
    if ($_POST['action'] === 'upload_photo') {

        // Sinisigurado na may na-upload na file at walang error sa pag-upload
        if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'No file uploaded or an upload error occurred.';
        } else {
            $file    = $_FILES['profile_image'];
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            // Ginagamit ang mime_content_type para matiyak ang tunay na file type
            // Hindi lang basta basta tinitiwala ang extension na ibinibigay ng user
            $mimeType = mime_content_type($file['tmp_name']);

            if (!in_array($mimeType, $allowed, true)) {
                // Tinatanggihan ang file kung hindi image format
                $errorMsg = 'Only JPEG, PNG, GIF, and WEBP images are allowed.';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                // Tinatanggihan kung higit sa 2MB ang file size
                $errorMsg = 'Image must be smaller than 2 MB.';
            } else {
                try {
                    // Binabasa ang raw binary data ng image para i-store sa database bilang BLOB
                    $imageData = file_get_contents($file['tmp_name']);
                    $pdo = getDB();

                    // Sinisigurado kung mayroon nang admin record
                    $check = $pdo->prepare("SELECT admin_id FROM admin WHERE user_id = :uid");
                    $check->execute([':uid' => $uid]);

                    if ($check->fetch()) {
                        // UPDATE kung may existing admin record na
                        $stmt = $pdo->prepare("UPDATE admin SET profile_image = :img WHERE user_id = :uid");
                    } else {
                        // INSERT kung walang admin record pa
                        $stmt = $pdo->prepare("
                            INSERT INTO admin (user_id, profile_image, first_name, last_name, middle_name, phone)
                            VALUES (:uid, :img, '', '', '', '')
                        ");
                    }

                    // Ginagamit ang PDO::PARAM_LOB para sa tamang pag-store ng binary image data
                    $stmt->bindParam(':img', $imageData, PDO::PARAM_LOB);
                    $stmt->bindParam(':uid', $uid, PDO::PARAM_INT);
                    $stmt->execute();

                    $successMsg = 'Profile photo updated successfully.';
                } catch (PDOException $e) {
                    $errorMsg = 'Database error: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }


    // ── ACTION: CHANGE PASSWORD ───────────────────────────────
    // Hina-handle ang pagpapalit ng password ng admin
    if ($_POST['action'] === 'change_password') {

        // Kinukuha ang mga password values mula sa form
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password']     ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';

        // ── VALIDATION ────────────────────────────────────────

        if ($newPw !== $confirmPw) {
            // Sinisigurado na magkatugma ang bagong password at confirmation
            $errorMsg = 'New passwords do not match.';
        } elseif (strlen($newPw) < 8) {
            // Minimum na 8 characters ang required para sa password
            $errorMsg = 'New password must be at least 8 characters.';
        } elseif ($currentPw === $newPw) {
            // Hindi pwedeng katulad ng lumang password ang bagong password
            $errorMsg = 'New password must be different from current password.';
        } else {
            try {
                $pdo = getDB();

                // Sinimulan ang transaction para matiyak na lahat ng operations ay magtagumpay
                // Kung may error, lahat ay ire-rollback para maiwasan ang inconsistent data
                $pdo->beginTransaction();

                // Kinukuha ang current password hash mula sa database para i-verify
                $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = :uid");
                $stmt->execute([':uid' => $uid]);
                $row  = $stmt->fetch();

                if (!$row || !password_verify($currentPw, $row['password'])) {
                    // Kung mali ang kasalukuyang password, hindi itutuloy ang pagpapalit
                    $errorMsg = 'Current password is incorrect.';
                    $pdo->rollBack();
                } else {
                    // ── PASSWORD HISTORY CHECK ────────────────
                    // Kinukuha ang huling 5 passwords para maiwasan ang pag-reuse
                    $historyStmt = $pdo->prepare("
                        SELECT password_hash FROM password_history
                        WHERE user_id = :uid
                        ORDER BY changed_at DESC
                        LIMIT 5
                    ");
                    $historyStmt->execute([':uid' => $uid]);
                    $historyRows = $historyStmt->fetchAll();

                    // Sinisigurado na hindi naabused ang password history
                    $isReused = false;
                    foreach ($historyRows as $hist) {
                        if (password_verify($newPw, $hist['password_hash'])) {
                            $isReused = true;
                            break;
                        }
                    }

                    if ($isReused) {
                        // Hindi pwedeng gamitin ang kamakailang password na nagamit na
                        $errorMsg = 'Cannot reuse a recent password. Please choose a different one.';
                        $pdo->rollBack();
                    } else {
                        // Gina-hash ang bagong password gamit ang bcrypt para sa seguridad
                        $hash = password_hash($newPw, PASSWORD_BCRYPT);

                        // Ina-update ang password sa users table
                        $updateStmt = $pdo->prepare("UPDATE users SET password = :pw WHERE user_id = :uid");
                        $updateStmt->execute([':pw' => $hash, ':uid' => $uid]);

                        // Sinisigurado na talagang na-update ang row
                        if ($updateStmt->rowCount() === 0) {
                            throw new PDOException("Password update failed - no rows affected.");
                        }

                        // Ini-insert ang bagong password sa history para sa susunod na check
                        $histStmt = $pdo->prepare("
                            INSERT INTO password_history (user_id, password_hash, changed_at)
                            VALUES (:uid, :hash, NOW())
                        ");
                        $histStmt->execute([':uid' => $uid, ':hash' => $hash]);

                        // Kino-commit ang transaction — tapos na lahat ng operations
                        $pdo->commit();
                        $successMsg = 'Password changed successfully.';
                    }
                }
            } catch (PDOException $e) {
                // Kung may error na nangyari, ire-rollback ang lahat para maiwasan ang corrupt na data
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMsg = 'Database error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}


// ── FETCH CURRENT USER DATA ───────────────────────────────────
// Kinukuha ang updated na user data pagkatapos ng lahat ng possible na updates
$user = fetchUser($uid);


// ── AVATAR / PROFILE IMAGE SETUP ─────────────────────────────
// Kino-convert ang binary BLOB data ng profile image papuntang base64 data URI
// para magamit bilang src ng <img> tag sa HTML
$avatarSrc = '';
if (!empty($user['profile_image'])) {
    $avatarSrc = 'data:image/jpeg;base64,' . base64_encode($user['profile_image']);
}


// ── HTML ESCAPE HELPER ────────────────────────────────────────
// Shortcut function para maiwasan ang XSS attacks sa pag-output ng data sa HTML
function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}


// ── DISPLAY NAME & ROLE BADGE ─────────────────────────────────
// Pinagsama-sama ang buong pangalan ng user para sa display
// Ginagamit ang 'Administrator' bilang fallback kung walang pangalan
$fullName = trim(
    ($user['first_name']  ?? '') . ' ' .
    ($user['middle_name'] ?? '') . ' ' .
    ($user['last_name']   ?? '')
) ?: 'Administrator';

// Kinukuha ang tamang label at kulay ng role badge batay sa role ng user
$roleBadge = [
    'admin'     => ['Admin',     '#3b82f6', '#eff6ff'],
    'organizer' => ['Organizer', '#8b5cf6', '#f5f3ff'],
    'student'   => ['Student',   '#0ea5e9', '#f0f9ff'],
][$user['role'] ?? 'admin'] ?? ['Admin', '#3b82f6', '#eff6ff'];
?>
<!DOCTYPE html>
<html lang="en" class="antialiased">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>SEMS — Admin Settings</title>
    <link rel="icon" href="/assets/settings-icon-indigo.svg" />

    <!-- Tailwind CSS — ginagamit para sa utility-first styling ng buong page -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts — DM Sans para sa body text, Plus Jakarta Sans para sa headings -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet" />

    <!-- Icon libraries — Font Awesome at Feather Icons para sa mga icon sa UI -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>

    <!-- Custom CSS para sa mga animation at specific styles na hindi covered ng Tailwind -->
    <link rel="stylesheet" href="/CSS/admin_settings.css" />

    <!-- Tailwind config — idinaragdag ang custom fonts at colors na ginagamit sa buong app -->
    <script>
        tailwind.config = {
            darkMode: 'class', // Dark mode ay naka-toggle gamit ang 'dark' class sa html element
            theme: {
                extend: {
                    fontFamily: {
                        sans:    ['DM Sans', 'sans-serif'],
                        display: ['Plus Jakarta Sans', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                        },
                    },
                },
            },
        }
    </script>
</head>

<!-- Body — may transition para sa smooth na light/dark mode switching -->
<body class="bg-gray-50 text-slate-900 dark:bg-[#0f1117] dark:text-slate-100 transition-colors duration-300">

    <!-- Main layout wrapper — flex para malagay nang tabi-tabi ang sidebar at main content -->
    <div class="flex min-h-screen">

        <!-- ════════════════════════════════════════════════
             SIDEBAR — Navigation panel ng admin
             Naka-fixed sa kaliwa, nakatago sa mobile (-translate-x-full)
             at laging visible sa large screens (lg:translate-x-0)
             ════════════════════════════════════════════════ -->
        <aside id="sidebar"
            class="-translate-x-full lg:translate-x-0
                   fixed top-0 left-0 z-50 h-full w-64
                   flex flex-col
                   bg-white dark:bg-slate-900
                   border-r border-gray-200 dark:border-slate-700
                   shadow-xl transition-colors duration-300">

            <!-- Logo section — nagpapakita ng SEMS branding sa itaas ng sidebar -->
            <div class="px-6 py-6 border-b border-gray-100 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center shadow-lg shadow-blue-500/30">
                        <i class="fas fa-calendar-check text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-bold text-slate-900 dark:text-white text-lg tracking-tight leading-none">SEMS</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Admin Panel</p>
                    </div>
                </div>
            </div>

            <!-- Navigation links — mga link papunta sa iba't ibang section ng admin panel -->
            <nav class="flex-1 overflow-y-auto px-4 py-6 space-y-1">

                <!-- Overview section label -->
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 uppercase tracking-wider">Overview</p>

                <a href="/admin/admin_dashboard.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-th-large w-5 text-center"></i>
                    Dashboard
                </a>

                <!-- Management section label -->
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Management</p>

                <a href="/admin/admin_event_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-calendar-alt w-5 text-center"></i>
                    Events
                </a>

                <a href="/admin/admin_aprovals.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-check-circle w-5 text-center"></i>
                    Approvals
                </a>

                <a href="/admin/admin_user_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-users w-5 text-center"></i>
                    Users
                </a>

                <a href="/admin/admin_org_club_management.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-building w-5 text-center"></i>
                    Organizations & Clubs
                </a>

                <!-- Insights section label -->
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Insights</p>

                <a href="/admin/admin_insight.php"
                    class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-blue-600 dark:hover:text-blue-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-chart-line w-5 text-center"></i>
                    Analytics
                </a>
            </nav>

            <!-- Sidebar footer — settings, dark mode toggle, at logout button -->
            <div class="px-4 py-4 border-t border-gray-100 dark:border-slate-700 space-y-1">

                <!-- Settings link — active state dahil nandito na tayo ngayon -->
                <a href="/admin/admin_settings.php"
                    class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200">
                    <i class="fas fa-cog w-5 text-center"></i>
                    Settings
                </a>

                <!-- Dark mode toggle — tinatawagan ang toggleTheme() JS function -->
                <button onclick="toggleTheme()"
                    class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i id="theme-icon" class="fas fa-moon w-5 text-center"></i>
                    <span id="theme-label">Dark Mode</span>
                </button>

                <!-- Logout button — nagre-redirect sa logout handler -->
                <a href="../includes/logout.php"
                    class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i>
                    Logout
                </a>
            </div>
        </aside>


        <!-- ════════════════════════════════════════════════
             MAIN CONTENT AREA
             lg:ml-64 para mag-offset sa sidebar width
             ════════════════════════════════════════════════ -->
        <div class="flex-1 flex flex-col min-h-screen lg:ml-64 transition-colors duration-300">

            <!-- ── TOP HEADER BAR ─────────────────────────────
                 Sticky header na nagpapakita ng breadcrumb, search bar,
                 at profile info ng naka-login na admin
                 ──────────────────────────────────────────── -->
            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border-b border-gray-200 dark:border-slate-700 px-4 sm:px-8 py-4 flex items-center gap-4 transition-colors duration-300">

                <!-- Mobile hamburger button — ipinakikita lang sa mobile para buksan ang sidebar -->
                <button onclick="openSidebar()"
                    class="lg:hidden w-10 h-10 flex items-center justify-center flex-shrink-0 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-blue-50 dark:hover:bg-blue-500/10 hover:text-blue-500 transition-all duration-200">
                    <i class="fas fa-bars text-sm"></i>
                </button>

                <!-- Breadcrumb at current date — itinatago sa mobile para makatipid ng espasyo -->
                <div class="hidden sm:block mr-auto">
                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 mb-0.5">
                        <span>Admin</span>
                        <i class="fas fa-chevron-right text-xs"></i>
                        <span class="text-slate-900 dark:text-white font-medium">Account Settings</span>
                    </div>
                    <!-- Dynamic na petsa mula sa PHP — nagpapakita ng araw, buwan, at taon ngayon -->
                    <p class="text-xs text-slate-400 dark:text-slate-500" id="current-date"><?= date('l, F j, Y') ?></p>
                </div>

                <!-- Search input — para hanapin ang mga settings na kailangan ng user -->
                <div class="flex-1 sm:flex-none sm:w-72 relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input
                        id="searchInput"
                        type="text"
                        placeholder="Search settings..."
                        onkeyup="handleSearch()"
                        class="w-full pl-10 pr-4 py-2.5 text-sm rounded-xl bg-gray-100 dark:bg-slate-700 border-0 focus:outline-none focus:ring-2 focus:ring-blue-500/30 focus:bg-white dark:focus:bg-slate-600 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all duration-200" />
                </div>

                <!-- Admin profile display sa header — nagpapakita ng pangalan at avatar -->
                <div class="flex items-center gap-3 pl-4 border-l border-gray-200 dark:border-slate-700">

                    <!-- Pangalan at role — nakatago sa mobile -->
                    <div class="hidden md:block text-right">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white leading-none"><?= e($fullName) ?></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Administrator</p>
                    </div>

                    <!-- Avatar — nagpapakita ng profile image kung mayroon, o initials kung wala -->
                    <div class="relative group cursor-pointer">
                        <?php if ($avatarSrc): ?>
                            <!-- May profile image — ipinapakita ang uploaded photo -->
                            <img src="<?= e($avatarSrc) ?>" alt="<?= e($fullName) ?>"
                                class="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-600 shadow-md">
                        <?php else: ?>
                            <!-- Walang profile image — ipinapakita ang gradient avatar na may initials -->
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-sm font-bold shadow-md">
                                <?= e(strtoupper(
                                    substr($user['first_name']  ?? 'A', 0, 1) .
                                    substr($user['middle_name'] ?? '',  0, 1) .
                                    substr($user['last_name']   ?? 'D', 0, 1)
                                )) ?>
                            </div>
                        <?php endif; ?>
                        <!-- Online indicator — berdeng dot na nagpapakita na naka-online ang admin -->
                        <span class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-slate-800 rounded-full"></span>
                    </div>
                </div>
            </header>


            <!-- ── PAGE MAIN CONTENT ──────────────────────────
                 Lahat ng settings forms ay nandito
                 Max width na 900px para hindi masyadong malawak sa malalaking screen
                 ──────────────────────────────────────────── -->
            <main class="p-6 sm:p-8 pb-12 max-w-[900px]">

                <!-- Page title at subtitle -->
                <div class="flex items-start justify-between mb-8 gap-4">
                    <div>
                        <h1 class="font-display font-extrabold text-2xl tracking-tight leading-tight text-slate-900 dark:text-white">Account Settings</h1>
                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Manage your profile, contact details, and security</p>
                    </div>
                </div>

                <!-- ── SUCCESS ALERT ───────────────────────────
                     Ipinakikita kung matagumpay ang isang form action
                     animate-slide-down para sa smooth na animation -->
                <?php if ($successMsg !== ''): ?>
                    <div class="flex items-start gap-3 px-5 py-4 rounded-2xl text-sm font-medium mb-6
                                bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400
                                border border-green-200 dark:border-green-800 animate-slide-down" role="alert">
                        <i data-feather="check-circle" class="w-[18px] h-[18px] flex-shrink-0 mt-0.5"></i>
                        <span><?= e($successMsg) ?></span>
                    </div>
                <?php endif; ?>

                <!-- ── ERROR ALERT ─────────────────────────────
                     Ipinakikita kung may validation o database error na nangyari -->
                <?php if ($errorMsg !== ''): ?>
                    <div class="flex items-start gap-3 px-5 py-4 rounded-2xl text-sm font-medium mb-6
                                bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400
                                border border-red-200 dark:border-red-800 animate-slide-down" role="alert">
                        <i data-feather="alert-circle" class="w-[18px] h-[18px] flex-shrink-0 mt-0.5"></i>
                        <span><?= e($errorMsg) ?></span>
                    </div>
                <?php endif; ?>


                <!-- ── SETTINGS CARD ───────────────────────────
                     Pangunahing container ng lahat ng settings
                     May tabs para paghiwalayin ang Personal Info at Security
                     ──────────────────────────────────────────── -->
                <div class="bg-white dark:bg-slate-800 rounded-[20px]
                            shadow-[0_4px_12px_rgba(0,0,0,0.08),0_2px_4px_rgba(0,0,0,0.04)]
                            border border-gray-200 dark:border-slate-700 overflow-hidden mb-6
                            transition-colors duration-300">

                    <!-- Tab navigation — nagbibigay ng paraan para lumipat sa pagitan ng Personal Info at Security -->
                    <div class="px-6 pt-5 border-b border-gray-200 dark:border-slate-700 transition-colors duration-300">
                        <div class="flex gap-1" role="tablist">
                            <!-- Personal Info tab — active by default -->
                            <button class="card-tab active px-5 py-2.5 text-sm font-semibold border-b-2 transition-colors relative -mb-px text-blue-600 dark:text-blue-400 border-blue-600 dark:border-blue-400"
                                    role="tab" aria-selected="true" data-tab="personal">
                                Personal Info
                            </button>
                            <!-- Security tab — para sa password change -->
                            <button class="card-tab px-5 py-2.5 text-sm font-semibold text-slate-500 dark:text-slate-400 border-b-2 border-transparent transition-colors relative -mb-px hover:text-slate-900 dark:hover:text-white"
                                    role="tab" aria-selected="false" data-tab="security">
                                Security
                            </button>
                        </div>
                    </div>

                    <div class="p-6 sm:p-7">

                        <!-- ════════════════════════════════════
                             TAB PANEL: PERSONAL INFO
                             Nagpapakita ng photo upload at info update forms
                             ════════════════════════════════════ -->
                        <div class="tab-panel active" id="tab-personal">

                            <!-- ── PROFILE PHOTO SECTION ──────
                                 Nagpapakita ng kasalukuyang avatar at upload button
                                 ──────────────────────────────── -->
                            <div class="flex items-center gap-6 pb-7 border-b border-gray-200 dark:border-slate-700 mb-7 transition-colors duration-300">

                                <!-- Malaking avatar — nagpapakita ng preview ng profile photo -->
                                <div id="bigAvatar"
                                    class="w-[90px] h-[90px] rounded-full bg-gradient-to-br from-blue-500 to-purple-600
                                           flex items-center justify-center text-white text-2xl font-extrabold
                                           shadow-lg shadow-blue-500/25 overflow-hidden relative flex-shrink-0">
                                    <?php if ($avatarSrc): ?>
                                        <!-- Kung may saved na profile image sa database -->
                                        <img src="<?= e($avatarSrc) ?>" alt="Profile photo" id="avatarPreview" class="w-full h-full object-cover" />
                                    <?php else: ?>
                                        <!-- Fallback initials avatar kapag walang profile image -->
                                        <span id="avatarInitials">
                                            <?= e(strtoupper(
                                                substr($user['first_name']  ?? 'A', 0, 1) .
                                                substr($user['middle_name'] ?? '',  0, 1) .
                                                substr($user['last_name']   ?? 'D', 0, 1)
                                            )) ?>
                                        </span>
                                        <!-- Hidden img — gagamitin ng JS para sa live preview bago mag-upload -->
                                        <img src="" alt="" id="avatarPreview" class="hidden w-full h-full object-cover" />
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <h3 class="font-display font-bold text-[1.05rem] mb-1 text-slate-900 dark:text-white"><?= e($fullName) ?></h3>
                                    <p class="text-sm text-slate-500 dark:text-slate-400 mb-3">JPG, PNG or WEBP — max 2 MB</p>

                                    <!-- Photo upload form — gumagamit ng enctype multipart para sa file upload -->
                                    <form method="POST" enctype="multipart/form-data" id="photoForm">
                                        <input type="hidden" name="action" value="upload_photo" />
                                        <!-- Hidden file input — tina-trigger ng button sa ibaba para mas maganda ang UI -->
                                        <input type="file" name="profile_image" id="photoInput" class="hidden"
                                               accept="image/jpeg,image/png,image/gif,image/webp" />
                                        <!-- Button na nag-o-open ng file picker sa pag-click -->
                                        <button type="button"
                                            class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-xl text-sm font-semibold shadow-md shadow-blue-500/30 transition-all hover:bg-blue-700 hover:-translate-y-0.5 active:translate-y-0"
                                            onclick="document.getElementById('photoInput').click()">
                                            <i data-feather="upload" class="w-3.5 h-3.5"></i> Upload Photo
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- ── PERSONAL INFO FORM ──────────
                                 Nagbibigay ng paraan para i-update ang pangalan, email, at phone
                                 ──────────────────────────────── -->
                            <form method="POST" id="infoForm">
                                <input type="hidden" name="action" value="update_info" />

                                <!-- Section divider label -->
                                <div class="flex items-center gap-4 mb-6">
                                    <div class="flex-1 h-px bg-gray-200 dark:bg-slate-700 transition-colors duration-300"></div>
                                    <span class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-widest whitespace-nowrap">Basic Information</span>
                                    <div class="flex-1 h-px bg-gray-200 dark:bg-slate-700 transition-colors duration-300"></div>
                                </div>

                                <!-- 3-column grid para sa mga input fields ng pangalan -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

                                    <!-- First name field — required -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="first_name">
                                            First Name <span class="text-red-500 ml-0.5">*</span>
                                        </label>
                                        <input type="text" id="first_name" name="first_name"
                                            class="w-full px-4 py-2.5 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                            value="<?= e($user['first_name'] ?? '') ?>"
                                            placeholder="e.g. Juan" required />
                                    </div>

                                    <!-- Middle name field — optional -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="middle_name">
                                            Middle Name
                                        </label>
                                        <input type="text" id="middle_name" name="middle_name"
                                            class="w-full px-4 py-2.5 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                            value="<?= e($user['middle_name'] ?? '') ?>"
                                            placeholder="e.g. Marie" />
                                    </div>

                                    <!-- Last name field — required -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="last_name">
                                            Last Name <span class="text-red-500 ml-0.5">*</span>
                                        </label>
                                        <input type="text" id="last_name" name="last_name"
                                            class="w-full px-4 py-2.5 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                            value="<?= e($user['last_name'] ?? '') ?>"
                                            placeholder="e.g. dela Cruz" required />
                                    </div>

                                    <!-- Email field — required, dapat valid ang format -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="email">
                                            Email Address <span class="text-red-500 ml-0.5">*</span>
                                        </label>
                                        <input type="email" id="email" name="email"
                                            class="w-full px-4 py-2.5 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                            value="<?= e($user['email'] ?? '') ?>"
                                            placeholder="you@example.com" required />
                                    </div>

                                    <!-- Phone number field — optional -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="phone">
                                            Phone Number
                                        </label>
                                        <input type="tel" id="phone" name="phone"
                                            class="w-full px-4 py-2.5 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                            value="<?= e($user['phone'] ?? '') ?>"
                                            placeholder="+63 9XX XXX XXXX" />
                                    </div>

                                    <!-- Role display — read-only, hindi mababago dito ang role -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                                            Account Role
                                        </label>
                                        <div class="flex items-center gap-2.5 px-4 py-2.5 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-sm text-slate-500 dark:text-slate-400 italic transition-colors duration-300">
                                            <!-- Kulay ng badge ay depende sa role ng user -->
                                            <span class="not-italic text-xs font-semibold px-2.5 py-1 rounded-full"
                                                  style="background:<?= e($roleBadge[2]) ?>;color:<?= e($roleBadge[1]) ?>;">
                                                <?= e($roleBadge[0]) ?>
                                            </span>
                                            Role cannot be changed here
                                        </div>
                                    </div>
                                </div>

                                <!-- Form action buttons — Reset para ibalik sa original, Save para i-submit -->
                                <div class="flex items-center justify-end gap-3 mt-7 pt-5 border-t border-gray-200 dark:border-slate-700 transition-colors duration-300">
                                    <!-- Reset button — ibinabalik ang form sa original na values -->
                                    <button type="reset"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all bg-transparent text-slate-500 dark:text-slate-400 border-[1.5px] border-gray-200 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700 hover:text-slate-900 dark:hover:text-white">
                                        <i data-feather="rotate-ccw" class="w-4 h-4"></i> Reset
                                    </button>
                                    <!-- Save button — ino-submit ang form para i-update ang info sa database -->
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all bg-blue-600 text-white shadow-md shadow-blue-500/30 hover:bg-blue-700 hover:-translate-y-0.5 active:translate-y-0">
                                        <i data-feather="save" class="w-4 h-4"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div><!-- /tab-personal -->


                        <!-- ════════════════════════════════════
                             TAB PANEL: SECURITY
                             Para sa pagpapalit ng password ng admin
                             Nakatago by default — lalabas kapag na-click ang Security tab
                             ════════════════════════════════════ -->
                        <div class="tab-panel" id="tab-security">

                            <!-- Section divider label -->
                            <div class="flex items-center gap-4 mb-6">
                                <div class="flex-1 h-px bg-gray-200 dark:bg-slate-700 transition-colors duration-300"></div>
                                <span class="text-xs font-semibold text-slate-400 dark:text-slate-500 uppercase tracking-widest whitespace-nowrap">Change Password</span>
                                <div class="flex-1 h-px bg-gray-200 dark:bg-slate-700 transition-colors duration-300"></div>
                            </div>

                            <!-- Password change form -->
                            <form method="POST" id="pwForm">
                                <input type="hidden" name="action" value="change_password" />

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                                    <!-- Current password field — full width, para i-verify ang identity ng user -->
                                    <div class="flex flex-col gap-1.5 md:col-span-2">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="current_password">
                                            Current Password <span class="text-red-500 ml-0.5">*</span>
                                        </label>
                                        <div class="relative">
                                            <input type="password" id="current_password" name="current_password"
                                                class="w-full px-4 py-2.5 pr-12 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                                placeholder="Enter your current password" required
                                                autocomplete="new-password"
                                                readonly onfocus="this.removeAttribute('readonly')" />
                                            <!-- Toggle button para ipakita/itago ang password text -->
                                            <button type="button"
                                                onclick="togglePwVisibility('current_password', this)"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors p-1" tabindex="-1">
                                                <i data-feather="eye"     class="w-4 h-4 eye-show"></i>
                                                <i data-feather="eye-off" class="w-4 h-4 eye-hide hidden"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- New password field — may password strength indicator sa ibaba -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="new_password">
                                            New Password <span class="text-red-500 ml-0.5">*</span>
                                        </label>
                                        <div class="relative">
                                            <input type="password" id="new_password" name="new_password"
                                                class="w-full px-4 py-2.5 pr-12 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                                placeholder="Min. 8 characters" required
                                                oninput="checkPwStrength(this.value)" />
                                            <button type="button"
                                                onclick="togglePwVisibility('new_password', this)"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors p-1" tabindex="-1">
                                                <i data-feather="eye"     class="w-4 h-4 eye-show"></i>
                                                <i data-feather="eye-off" class="w-4 h-4 eye-hide hidden"></i>
                                            </button>
                                        </div>
                                        <!-- Password strength progress bar — ina-animate ng JS base sa lakas ng password -->
                                        <div class="h-1 rounded-full bg-gray-200 dark:bg-slate-700 mt-2 overflow-hidden transition-colors duration-300">
                                            <div class="pw-strength-bar" id="strengthBar"></div>
                                        </div>
                                        <!-- Text label na nagpapakita kung gaano ka-strong ang password -->
                                        <span class="text-xs text-slate-400 dark:text-slate-500 mt-1" id="strengthLabel">Enter a new password</span>
                                    </div>

                                    <!-- Confirm password field — sinisigurado na magkatugma ang dalawang passwords -->
                                    <div class="flex flex-col gap-1.5">
                                        <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider" for="confirm_password">
                                            Confirm Password <span class="text-red-500 ml-0.5">*</span>
                                        </label>
                                        <div class="relative">
                                            <input type="password" id="confirm_password" name="confirm_password"
                                                class="w-full px-4 py-2.5 pr-12 rounded-xl border-[1.5px] border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700/50 text-slate-900 dark:text-slate-100 text-sm outline-none transition-all focus:border-blue-500 focus:ring-[3px] focus:ring-blue-500/10 focus:bg-white dark:focus:bg-slate-700 placeholder:text-slate-400"
                                                placeholder="Re-enter new password" required />
                                            <button type="button"
                                                onclick="togglePwVisibility('confirm_password', this)"
                                                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors p-1" tabindex="-1">
                                                <i data-feather="eye"     class="w-4 h-4 eye-show"></i>
                                                <i data-feather="eye-off" class="w-4 h-4 eye-hide hidden"></i>
                                            </button>
                                        </div>
                                        <!-- Live match feedback — JS ang mag-u-update nito habang nagta-type ang user -->
                                        <span class="text-xs text-slate-400 dark:text-slate-500 mt-1" id="matchLabel"></span>
                                    </div>
                                </div>

                                <!-- Form action buttons — Clear para i-reset ang form, Update para i-submit -->
                                <div class="flex items-center justify-end gap-3 mt-7 pt-5 border-t border-gray-200 dark:border-slate-700 transition-colors duration-300">
                                    <!-- Clear button — nililinis ang lahat ng password fields -->
                                    <button type="reset"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all bg-transparent text-slate-500 dark:text-slate-400 border-[1.5px] border-gray-200 dark:border-slate-600 hover:bg-gray-50 dark:hover:bg-slate-700 hover:text-slate-900 dark:hover:text-white">
                                        <i data-feather="rotate-ccw" class="w-4 h-4"></i> Clear
                                    </button>
                                    <!-- Update Password button — ino-submit ang form papunta sa PHP handler -->
                                    <button type="submit"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all bg-blue-600 text-white shadow-md shadow-blue-500/30 hover:bg-blue-700 hover:-translate-y-0.5 active:translate-y-0">
                                        <i data-feather="lock" class="w-4 h-4"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        </div><!-- /tab-security -->

                    </div><!-- /card-body -->
                </div><!-- /settings-card -->

            </main>
        </div><!-- /main-wrap -->
    </div><!-- /layout -->

    <!-- External JS file — hina-handle ang tabs, dark mode, avatar preview, at password strength -->
    <script src="/js/admin_settings.js"></script>

</body>

</html>