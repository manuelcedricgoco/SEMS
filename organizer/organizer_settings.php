<?php
// ============================================================
// SIMULAN ANG SESSION at i-load ang database connection
// ============================================================
session_start();
$pdo = require_once '../includes/db.php';

// ============================================================
// I-CHECK kung naka-login ang user at organizer ang role niya
// Kung hindi, i-redirect sa auth page with unauthorized error
// ============================================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

// ============================================================
// I-STORE ang user ID mula sa session at i-initialize ang
// message variable para sa success/error notifications
// ============================================================
$uid     = (int) $_SESSION['user_id'];
$message = '';

// ============================================================
// KUNIN ANG LAHAT NG USER DATA mula sa database
// Ginagamit ang COALESCE para makuha ang data mula sa
// profiles table o organizer table (kahit alin ang may laman)
// ============================================================
$sql = "
    SELECT u.email, u.dept_id, u.org_id, u.club_id,
           COALESCE(p.first_name,  o.first_name)  as first_name,
           COALESCE(p.middle_name, o.middle_name) as middle_name,
           COALESCE(p.last_name,   o.last_name)   as last_name,
           COALESCE(p.phone,       o.phone)       as phone,
           COALESCE(p.profile_image, o.profile_image) as profile_image,
           COALESCE(p.student_number, o.student_number) as student_number,
           p.year_level, p.section,
           d.dept_name, o.position
    FROM users u
    LEFT JOIN profiles p  ON u.user_id = p.user_id
    LEFT JOIN organizer o ON u.user_id = o.user_id
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE u.user_id = :uid
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['uid' => $uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// ============================================================
// KUNIN ANG ORGANIZATION O CLUB INFO ng organizer
// I-check kung may org_id o club_id ang user
// ============================================================
$orgInfo  = null;
$clubInfo = null;

if (!empty($user['org_id'])) {
    // Kung may organization ang organizer, kunin ang org details
    $orgStmt = $pdo->prepare("SELECT org_id, org_name, logo FROM organizations WHERE org_id = ?");
    $orgStmt->execute([$user['org_id']]);
    $orgInfo = $orgStmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($user['club_id'])) {
    // Kung wala sa org pero may club naman, kunin ang club details
    $clubStmt = $pdo->prepare("SELECT club_id, club_name, logo FROM clubs WHERE club_id = ?");
    $clubStmt->execute([$user['club_id']]);
    $clubInfo = $clubStmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================================
// I-DETERMINE ang entity type (org o club), pangalan, logo,
// at ID para magamit sa buong page
// May name mapping para sa mga common abbreviations (SSC, SSG, CSG)
// ============================================================
$entityType        = $orgInfo  ? 'organization' : ($clubInfo ? 'club' : null);
$entityName        = $orgInfo  ? ($orgInfo['org_name']   ?? '') : ($clubInfo['club_name'] ?? '');
$nameMap           = [
    'Supreme Student Council'    => 'SSC',
    'Supreme Student Government' => 'SSG',
    'Central Student Government' => 'CSG'
];
$entityDisplayName = $nameMap[$entityName] ?? $entityName;
$entityLogo        = $orgInfo  ? ($orgInfo['logo']  ?? null) : ($clubInfo['logo']    ?? null);
$entityId          = $orgInfo  ? ($orgInfo['org_id'] ?? null) : ($clubInfo['club_id'] ?? null);

// ============================================================
// I-DETECT ang MIME type ng entity logo para ma-render
// ng tama ang image sa browser (base64 display)
// ============================================================
$hasLogo  = !empty($entityLogo) && strlen($entityLogo) > 0;
$logoMime = 'image/jpeg'; // Default MIME type

if ($hasLogo) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $det   = $finfo->buffer($entityLogo);
    if ($det && strpos($det, 'image/') === 0) $logoMime = $det;
}

// ============================================================
// I-DETECT ang MIME type ng profile image ng user
// at i-generate ang initials bilang fallback kung walang photo
// ============================================================
$hasImage = !empty($user['profile_image']) && strlen($user['profile_image']) > 0;
$mime     = 'image/jpeg'; // Default MIME type

if ($hasImage) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $det   = $finfo->buffer($user['profile_image']);
    if ($det && strpos($det, 'image/') === 0) $mime = $det;
}

// Kukunin ang unang letra ng first at last name para sa avatar initials
$initials = strtoupper(
    substr($user['first_name'] ?? 'O', 0, 1) .
    substr($user['last_name']  ?? '',  0, 1)
);

// ============================================================
// KUNIN ANG BADGE COUNTS para sa sidebar navigation
// Bilang ng events at registrations ng organizer
// ============================================================

// Bilang ng events ng organizer (hindi kasama ang rejected)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE organizer_id = ? AND status != 'rejected'");
$stmt->execute([$uid]);
$myEvents = $stmt->fetchColumn();

// Bilang ng registrations sa lahat ng events ng organizer
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    WHERE e.organizer_id = ?
");
$stmt->execute([$uid]);
$registrations = $stmt->fetchColumn();


// ============================================================
// HANDLE PROFILE UPDATE — kapag nag-submit ang form na
// update_settings, i-process ang pagbabago sa profile info
// at optional na bagong profile photo
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {

    // I-sanitize ang inputs mula sa form
    $fname = trim($_POST['first_name']  ?? '');
    $mname = trim($_POST['middle_name'] ?? '');
    $lname = trim($_POST['last_name']   ?? '');
    $phone = trim($_POST['phone']       ?? '');

    try {
        // Kung may na-upload na bagong profile photo, i-process muna
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {

            // I-validate ang file size — maximum 2MB lang
            if ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
                $message = ['type' => 'error', 'text' => 'Image must be 2 MB or smaller.'];
            } else {
                // Basahin ang binary data ng uploaded image
                $imgData = file_get_contents($_FILES['profile_image']['tmp_name']);

                if ($imgData === false) {
                    $message = ['type' => 'error', 'text' => 'Failed to read image.'];
                } else {
                    // I-update ang profile kasama ang bagong photo (stored as binary sa DB)
                    $pdo->prepare("
                        UPDATE profiles
                        SET first_name=:fn, middle_name=:mn, last_name=:ln,
                            phone=:ph, profile_image=:img
                        WHERE user_id=:uid
                    ")->execute([
                        'fn'  => $fname,
                        'mn'  => $mname,
                        'ln'  => $lname,
                        'ph'  => $phone,
                        'img' => $imgData,
                        'uid' => $uid
                    ]);
                    $message = ['type' => 'success', 'text' => 'Profile updated with new photo!'];
                }
            }

        } else {
            // Walang bagong photo — i-update lang ang profile info fields
            $pdo->prepare("
                UPDATE profiles
                SET first_name=:fn, middle_name=:mn, last_name=:ln, phone=:ph
                WHERE user_id=:uid
            ")->execute([
                'fn'  => $fname,
                'mn'  => $mname,
                'ln'  => $lname,
                'ph'  => $phone,
                'uid' => $uid
            ]);
            $message = ['type' => 'success', 'text' => 'Profile updated successfully!'];
        }

        // Kung successful ang update, i-refresh ang $user data
        // para updated ang display ng page nang hindi mag-redirect
        if (isset($message['type']) && $message['type'] === 'success') {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['uid' => $uid]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // I-re-detect ang MIME type ng bagong profile image
            $hasImage = !empty($user['profile_image']) && strlen($user['profile_image']) > 0;
            if ($hasImage) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $det   = $finfo->buffer($user['profile_image']);
                if ($det && strpos($det, 'image/') === 0) $mime = $det;
            }
        }

    } catch (PDOException $e) {
        $message = ['type' => 'error', 'text' => $e->getMessage()];
    }
}


// ============================================================
// HANDLE LOGO UPDATE — kapag nag-submit ng update_logo form,
// i-upload ang bagong logo para sa organization o club
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_logo'])) {

    // I-validate: dapat may linked entity ang organizer
    if (!$entityType || !$entityId) {
        $message = ['type' => 'error', 'text' => 'No organization or club linked to your account.'];

    // I-validate: dapat may na-upload na file
    } elseif (!isset($_FILES['org_logo']) || $_FILES['org_logo']['error'] === UPLOAD_ERR_NO_FILE) {
        $message = ['type' => 'error', 'text' => 'Please select a logo image to upload.'];

    // I-validate: walang upload error
    } elseif ($_FILES['org_logo']['error'] !== UPLOAD_ERR_OK) {
        $message = ['type' => 'error', 'text' => 'Logo upload failed. Please try again.'];

    // I-validate: maximum 5MB ang file size
    } elseif ($_FILES['org_logo']['size'] > 5 * 1024 * 1024) {
        $message = ['type' => 'error', 'text' => 'Logo must be 5 MB or smaller.'];

    } else {
        // I-validate ang file type — tanggap lang ang image formats
        $allowed   = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        $finfo     = new finfo(FILEINFO_MIME_TYPE);
        $mimeCheck = $finfo->file($_FILES['org_logo']['tmp_name']);

        if (!in_array($mimeCheck, $allowed)) {
            $message = ['type' => 'error', 'text' => 'Invalid file type. Use JPG, PNG, GIF, WEBP, or SVG.'];
        } else {
            try {
                // Basahin ang binary data ng logo file
                $logoData = file_get_contents($_FILES['org_logo']['tmp_name']);

                if ($logoData === false) {
                    $message = ['type' => 'error', 'text' => 'Failed to read logo file.'];
                } else {
                    // I-determine kung anong table at column ang ia-update
                    // depende kung organization o club ang entity
                    $tbl = $entityType === 'organization' ? 'organizations' : 'clubs';
                    $col = $entityType === 'organization' ? 'org_id'        : 'club_id';

                    // I-update ang logo sa tamang table
                    $pdo->prepare("UPDATE $tbl SET logo=:logo WHERE $col=:id")
                        ->execute(['logo' => $logoData, 'id' => $entityId]);

                    // I-refresh ang logo variables para updated ang display
                    $entityLogo = $logoData;
                    $hasLogo    = true;
                    $logoMime   = $mimeCheck;

                    $label   = $entityType === 'organization' ? 'Organization' : 'Club';
                    $message = ['type' => 'success', 'text' => "$label logo updated successfully!"];
                }
            } catch (PDOException $e) {
                $message = ['type' => 'error', 'text' => $e->getMessage()];
            }
        }
    }
}


// ============================================================
// HANDLE PASSWORD CHANGE — may rate limiting para mapigilan
// ang brute force. Maximum 3 attempts bago i-lock ng 10 minuto
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {

    // Session keys para sa rate limiting (per user ID)
    $attKey  = 'pwd_attempts_'     . $uid;
    $lastKey = 'pwd_last_attempt_' . $uid;

    $attempts    = $_SESSION[$attKey]  ?? 0;
    $lastAttempt = $_SESSION[$lastKey] ?? 0;

    // I-check kung naka-rate limit na ang user
    if ($attempts >= 3 && (time() - $lastAttempt) < 600) {
        $message = ['type' => 'error', 'text' => 'Too many attempts. Please try again in 10 minutes.'];

    } else {
        // Kunin ang mga values mula sa form
        $currentPwd = $_POST['current_password'] ?? '';
        $newPwd     = $_POST['new_password']     ?? '';
        $confirmPwd = $_POST['confirm_password'] ?? '';

        // I-validate: lahat ng fields ay required
        if (empty($currentPwd) || empty($newPwd) || empty($confirmPwd)) {
            $message = ['type' => 'error', 'text' => 'All fields are required.'];

        // I-validate: dapat magkapareho ang new at confirm password
        } elseif ($newPwd !== $confirmPwd) {
            $message = ['type' => 'error', 'text' => 'New passwords do not match.'];
            $_SESSION[$attKey]  = ++$attempts;
            $_SESSION[$lastKey] = time();

        // I-validate: password complexity requirements
        // Kailangan: 8+ chars, isang uppercase, isang number, isang special char
        } elseif (
            strlen($newPwd) < 8 ||
            !preg_match('/[A-Z]/', $newPwd) ||
            !preg_match('/[0-9]/', $newPwd) ||
            !preg_match('/[^A-Za-z0-9]/', $newPwd)
        ) {
            $message = ['type' => 'error', 'text' => 'Password does not meet requirements.'];
            $_SESSION[$attKey]  = ++$attempts;
            $_SESSION[$lastKey] = time();

        } else {
            try {
                // Kunin ang current hashed password mula sa database
                $pwdStmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
                $pwdStmt->execute([$uid]);
                $currentHash = $pwdStmt->fetchColumn();

                // I-verify kung tama ang current password na ni-input
                if (!password_verify($currentPwd, $currentHash)) {
                    $message = ['type' => 'error', 'text' => 'Current password is incorrect.'];
                    $_SESSION[$attKey]  = ++$attempts;
                    $_SESSION[$lastKey] = time();

                } else {
                    // I-check ang password history — hindi pwedeng i-reuse ang
                    // kahit alin sa nakaraang 3 passwords
                    $historyStmt = $pdo->prepare("
                        SELECT password_hash FROM password_history
                        WHERE user_id = ?
                        ORDER BY changed_at DESC
                        LIMIT 3
                    ");
                    $historyStmt->execute([$uid]);

                    $isReused = false;
                    foreach ($historyStmt->fetchAll(PDO::FETCH_COLUMN) as $oldHash) {
                        if (password_verify($newPwd, $oldHash)) {
                            $isReused = true;
                            break;
                        }
                    }

                    if ($isReused) {
                        $message = ['type' => 'error', 'text' => 'Cannot reuse a recent password. Please choose a new one.'];
                        $_SESSION[$attKey]  = ++$attempts;
                        $_SESSION[$lastKey] = time();

                    } else {
                        // Hash ang bagong password gamit ang bcrypt
                        $newHash = password_hash($newPwd, PASSWORD_BCRYPT);

                        // I-start ang transaction para ma-ensure ang data integrity
                        // Kung may error sa kahit isang query, lahat ay i-rollback
                        $pdo->beginTransaction();

                        // I-update ang password ng user sa users table
                        $pdo->prepare("UPDATE users SET password=? WHERE user_id=?")
                            ->execute([$newHash, $uid]);

                        // I-save ang lumang password sa history para sa reuse check
                        $pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?,?)")
                            ->execute([$uid, $currentHash]);

                        // I-cleanup ang history — itago lang ang pinakabagong 5 records
                        $pdo->prepare("
                            DELETE FROM password_history
                            WHERE user_id = ?
                              AND history_id NOT IN (
                                  SELECT history_id FROM (
                                      SELECT history_id FROM password_history
                                      WHERE user_id = ?
                                      ORDER BY changed_at DESC
                                      LIMIT 5
                                  ) as t
                              )
                        ")->execute([$uid, $uid]);

                        // I-record ang timestamp ng password change sa session at DB
                        $_SESSION['password_changed_at'] = time();
                        $pdo->prepare("UPDATE users SET password_changed_at=NOW() WHERE user_id=?")
                            ->execute([$uid]);

                        $pdo->commit();

                        // Magpadala ng security notification email sa user
                        $to      = $user['email'];
                        $subject = "Security Alert: Password Changed";
                        $body    = "<html><body><p>Your SEMS password was changed on "
                                 . date('F j, Y \a\t g:i A')
                                 . ". If this wasn't you, contact your administrator.</p></body></html>";
                        $headers = "MIME-Version: 1.0\r\n"
                                 . "Content-type:text/html;charset=UTF-8\r\n"
                                 . "From: SEMS Security <noreply@yourdomain.com>\r\n";
                        @mail($to, $subject, $body, $headers);

                        // I-clear ang rate limiting attempts at i-redirect sa login
                        unset($_SESSION[$attKey], $_SESSION[$lastKey]);
                        $message = ['type' => 'success', 'text' => 'Password updated successfully! Redirecting to login…'];
                        header("Refresh: 2; URL=/logout.php");
                    }
                }

            } catch (PDOException $e) {
                // Kung may database error, i-rollback ang lahat ng changes
                $pdo->rollBack();
                $message = ['type' => 'error', 'text' => 'Database error. Please try again.'];
            }
        }
    }
}

// ============================================================
// KALKULAHIN ANG RATE LIMIT STATUS para ma-display
// sa password change form kung naka-lock na ang user
// ============================================================
$attKey      = 'pwd_attempts_'     . $uid;
$lastKey     = 'pwd_last_attempt_' . $uid;
$attempts    = $_SESSION[$attKey]  ?? 0;
$lastAttempt = $_SESSION[$lastKey] ?? 0;
$timeLeft    = max(0, 600 - (time() - $lastAttempt)); // Remaining lockout time in seconds
$rateLimited = $attempts >= 3 && $timeLeft > 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings – SEMS</title>

    <!-- ============================================================
     I-LOAD ANG MGA EXTERNAL LIBRARIES AT STYLESHEETS
     - Tailwind CSS para sa utility-first styling
     - Font Awesome para sa mga icons
     - Google Fonts (Poppins) para sa typography
     - Custom CSS para sa organizer settings page
    ============================================================ -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="/CSS/organizer_settings.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- ============================================================
     I-CONFIGURE ANG TAILWIND — dark mode, custom fonts,
     at brand color palette (green-based) para sa buong system
    ============================================================ -->
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif']
                    },
                    colors: {
                        brand: {
                            50:  '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

    <!-- ============================================================
     MOBILE SIDEBAR OVERLAY — itong dark transparent background
     na lumalabas kapag bukas ang sidebar sa mobile devices
    ============================================================ -->
    <div id="sb-overlay" onclick="closeSidebar()"></div>

    <!-- ============================================================
     SIDEBAR NAVIGATION — nakalagay sa kaliwa, fixed position
     Hidden sa mobile (-translate-x-full), visible sa large screens
    ============================================================ -->
    <aside id="sidebar"
        class="fixed top-0 left-0 h-screen w-64 z-50
              bg-white dark:bg-gray-800
              border-r border-gray-200 dark:border-gray-700
              flex flex-col transition-transform duration-300
              -translate-x-full lg:translate-x-0">

        <!-- ── HEADER NG SIDEBAR: Logo at pangalan ng org/club ── -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <?php if ($hasLogo): ?>
                    <!-- Ipakita ang logo ng org/club kung mayroon -->
                    <img src="data:<?= $logoMime ?>;base64,<?= base64_encode($entityLogo) ?>"
                        class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                        alt="<?= htmlspecialchars($entityDisplayName) ?>">
                <?php else: ?>
                    <!-- Fallback icon kung walang logo -->
                    <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/30">
                        <i class="fas fa-building text-white text-lg"></i>
                    </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <!-- Pangalan ng org/club (may abbreviation mapping) -->
                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words">
                        <?= htmlspecialchars($entityDisplayName) ?>
                    </p>
                    <!-- Badge para sa entity type (Organization o Club) -->
                    <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full
                             bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                        <?= $entityType ? ucfirst($entityType) : 'Organizer' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── NAVIGATION LINKS ng sidebar ── -->
        <nav class="flex-1 overflow-y-auto p-3 space-y-1">

            <!-- Overview Section -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>
            <a href="/organizer/organizer_panel.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                    <i class="fas fa-gauge-high"></i>
                </span>
                Dashboard
            </a>

            <!-- Events Section -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>

            <!-- My Events — may badge counter kung may events -->
            <a href="/organizer/organizer_event.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                    <i class="fas fa-clipboard-list"></i>
                </span>
                <span class="flex-1">My Events</span>
                <?php if ($myEvents > 0): ?>
                    <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?= $myEvents ?></span>
                <?php endif; ?>
            </a>

            <!-- QR Scanner para sa event attendance -->
            <a href="/organizer/organizer_qrscan.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm">
                    <i class="fas fa-qrcode"></i>
                </span>
                QR Scanner
            </a>

            <!-- Tracking Section -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>

            <!-- Registrations — may badge counter para sa bilang ng registered students -->
            <a href="/organizer/organizer_tracking.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm">
                    <i class="fas fa-users"></i>
                </span>
                <span class="flex-1">Registrations</span>
                <?php if ($registrations > 0): ?>
                    <span class="text-xs bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400 px-2 py-0.5 rounded-full font-semibold"><?= $registrations ?></span>
                <?php endif; ?>
            </a>

            <!-- Attendance tracking -->
            <a href="/organizer/organizer_attendance.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fas fa-user-check"></i>
                </span>
                Attendance
            </a>

            <!-- Analytics para sa event performance data -->
            <a href="/organizer/organizer_analytics.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                    <i class="fas fa-chart-line"></i>
                </span>
                Analytics
            </a>
        </nav>

        <!-- ── BOTTOM NG SIDEBAR: Settings at Logout buttons ── -->
        <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">

            <!-- Settings — active state kasi nandito na tayo ngayon -->
            <a href="/organizer/organizer_settings.php"
                class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                    <i class="fas fa-gear"></i>
                </span>
                Settings
            </a>

            <!-- Logout button — red color para madaling makita -->
            <a href="../includes/logout.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-sm">
                    <i class="fas fa-right-from-bracket"></i>
                </span>
                Logout
            </a>
        </div>
    </aside>

    <!-- ============================================================
     MAIN CONTENT WRAPPER — naka-offset sa kaliwa (lg:ml-64)
     para hindi matakpan ng sidebar sa large screens
    ============================================================ -->
    <div class="lg:ml-64 min-h-screen flex flex-col">

        <!-- ============================================================
         STICKY HEADER — nananatili sa taas kahit mag-scroll
         May backdrop blur para sa frosted glass effect
        ============================================================ -->
        <header class="sticky top-0 z-30
                   bg-white/90 dark:bg-gray-800/90
                   border-b border-gray-200 dark:border-gray-700
                   px-4 sm:px-6 py-3"
            style="backdrop-filter:blur(10px);">
            <div class="flex items-center gap-3">

                <!-- Hamburger button — visible lang sa mobile para buksan ang sidebar -->
                <button onclick="openSidebar()"
                    class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-bars"></i>
                </button>

                <!-- Page title -->
                <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">Settings</span>

                <!-- Org name badge na may animated green dot indicator -->
                <span class="hidden md:flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500
                         bg-gray-100 dark:bg-gray-700 px-3 py-1.5 rounded-full border border-gray-200 dark:border-gray-600">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                    <?= $entityName ? htmlspecialchars($entityName) : 'Manage Profile' ?>
                </span>

                <!-- Right side ng header: dark mode toggle at profile info -->
                <div class="flex items-center gap-2 ml-auto">

                    <!-- Dark mode toggle button — nag-iikot ang icon kapag hover -->
                    <button onclick="toggleTheme()" title="Toggle theme"
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400
                               hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                        <i id="themeIcon" class="fas fa-moon text-sm"></i>
                    </button>

                    <!-- Profile info at avatar ng kasalukuyang naka-login na organizer -->
                    <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">

                        <!-- Pangalan at position ng organizer — hidden sa mobile -->
                        <div class="hidden sm:block text-right leading-tight">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                <?= htmlspecialchars(trim(
                                    ($user['first_name'] ?? '') . ' ' .
                                    (!empty(trim($user['middle_name'] ?? ''))
                                        ? strtoupper(trim($user['middle_name'])[0]) . '.'
                                        : '') . ' ' .
                                    ($user['last_name'] ?? '')
                                )) ?>
                            </p>
                            <p class="text-xs text-gray-400">
                                <?= htmlspecialchars($user['position'] ?? $user['dept_name'] ?? 'Organizer') ?>
                            </p>
                        </div>

                        <!-- Profile avatar — nagpapakita ng photo o initials kung walang photo -->
                        <div class="w-9 h-9 rounded-full overflow-hidden
                                bg-gradient-to-br from-brand-400 to-blue-500
                                flex items-center justify-center text-white text-xs font-bold
                                ring-2 ring-brand-200 dark:ring-brand-700
                                hover:scale-105 transition-transform cursor-pointer">
                            <?php if ($hasImage): ?>
                                <img src="data:<?= $mime ?>;base64,<?= base64_encode($user['profile_image']) ?>"
                                    class="w-full h-full object-cover" alt="Profile">
                            <?php else: ?>
                                <?= $initials ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ============================================================
         MAIN CONTENT AREA — lahat ng forms at cards ay nandito
        ============================================================ -->
        <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-6xl mx-auto w-full pb-12">

            <!-- Page heading at subtitle -->
            <div class="anim-up d-0">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Account Settings</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Manage your profile information and preferences.</p>
            </div>

            <!-- ============================================================
             ALERT / NOTIFICATION BANNER — nagpapakita ng success o error
             message pagkatapos ng form submission
            ============================================================ -->
            <?php if ($message): ?>
                <div class="anim-up d-0 flex items-start gap-3 px-5 py-4 rounded-2xl text-sm font-medium
                    <?= $message['type'] === 'success'
                        ? 'bg-brand-50 dark:bg-brand-900/20 text-brand-700 dark:text-brand-400 border border-brand-200 dark:border-brand-800'
                        : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800' ?>">
                    <i class="fas <?= $message['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> mt-0.5 flex-shrink-0"></i>
                    <span class="flex-1"><?= htmlspecialchars($message['text']) ?></span>
                    <!-- X button para manually i-dismiss ang alert -->
                    <button onclick="this.parentElement.remove()" class="text-current opacity-60 hover:opacity-100 ml-auto flex-shrink-0">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- ============================================================
             DALAWANG-COLUMN LAYOUT GRID
             - Left (1/3): Profile card na nagpapakita ng info ng user
             - Right (2/3): Mga forms para sa pag-edit ng settings
            ============================================================ -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

                <!-- ============================================================
                 LEFT COLUMN: Profile Summary Card
                 Nagpapakita ng avatar, pangalan, at basic info ng organizer
                ============================================================ -->
                <div class="anim-up d-1 card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6 flex flex-col items-center text-center h-fit">

                    <!-- Avatar — clickable para mag-trigger ng file upload -->
                    <div class="relative w-24 h-24 mb-4 cursor-pointer group"
                        onclick="document.getElementById('profile_image').click()">
                        <div class="w-full h-full rounded-2xl overflow-hidden bg-gradient-to-br from-brand-400 to-blue-500
                                ring-4 ring-white dark:ring-gray-800 shadow-lg shadow-brand-300/20
                                flex items-center justify-center" id="avatarWrap">
                            <?php if ($hasImage): ?>
                                <!-- Ipakita ang profile photo kung mayroon -->
                                <img src="data:<?= $mime ?>;base64,<?= base64_encode($user['profile_image']) ?>"
                                    class="w-full h-full object-cover" id="avatarImg" alt="Profile">
                            <?php else: ?>
                                <!-- Fallback: initials ng user kung walang photo -->
                                <span class="text-2xl font-bold text-white" id="avatarInitials"><?= $initials ?></span>
                                <img src="" id="avatarImg" class="hidden w-full h-full object-cover" alt="">
                            <?php endif; ?>
                            <!-- Camera icon overlay na lumalabas kapag hover -->
                            <div class="absolute inset-0 bg-black/50 rounded-2xl flex items-center justify-center
                                    opacity-0 group-hover:opacity-100 transition-opacity">
                                <i class="fas fa-camera text-white text-xl"></i>
                            </div>
                        </div>
                        <!-- Green online indicator dot -->
                        <span class="absolute -bottom-1 -right-1 w-5 h-5 bg-brand-500 border-2 border-white dark:border-gray-800 rounded-full"></span>
                    </div>

                    <!-- Buong pangalan ng organizer -->
                    <h3 class="font-bold text-gray-900 dark:text-white text-base">
                        <?= htmlspecialchars(trim(
                            ($user['first_name'] ?? '') . ' ' .
                            ($user['middle_name'] ?? '') . ' ' .
                            ($user['last_name'] ?? '')
                        )) ?>
                    </h3>

                    <!-- Department badge -->
                    <span class="text-xs text-brand-600 dark:text-brand-400 font-semibold mt-1 px-3 py-1 rounded-full
                             bg-brand-50 dark:bg-brand-900/30 border border-brand-200 dark:border-brand-800">
                        <?= htmlspecialchars($user['dept_name'] ?? 'General') ?>
                    </span>

                    <!-- Email address -->
                    <p class="text-xs text-gray-400 mt-2 break-all"><?= htmlspecialchars($user['email'] ?? '') ?></p>

                    <!-- Info rows para sa additional details ng user -->
                    <div class="mt-5 w-full space-y-2.5">

                        <!-- Student number row -->
                        <div class="flex justify-between items-center text-xs bg-gray-50 dark:bg-gray-700/50
                                rounded-xl px-4 py-2.5 border border-gray-100 dark:border-gray-600">
                            <span class="text-gray-400 flex items-center gap-1.5"><i class="fas fa-id-card text-[11px]"></i> Student No.</span>
                            <span class="font-semibold text-gray-900 dark:text-white font-mono"><?= htmlspecialchars($user['student_number'] ?? '—') ?></span>
                        </div>

                        <!-- Phone number row -->
                        <div class="flex justify-between items-center text-xs bg-gray-50 dark:bg-gray-700/50
                                rounded-xl px-4 py-2.5 border border-gray-100 dark:border-gray-600">
                            <span class="text-gray-400 flex items-center gap-1.5"><i class="fas fa-phone text-[11px]"></i> Phone</span>
                            <span class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($user['phone'] ?? 'Not set') ?></span>
                        </div>

                        <!-- ── Entity logo section — lumalabas lang kung may org/club ── -->
                        <?php if ($entityType): ?>
                            <div class="flex flex-col items-center gap-2 bg-gray-50 dark:bg-gray-700/50
                                rounded-xl px-4 py-4 border border-gray-100 dark:border-gray-600">

                                <!-- Header ng section na may ORG o CLUB badge -->
                                <div class="flex items-center justify-between w-full">
                                    <span class="text-xs text-gray-400 flex items-center gap-1.5">
                                        <i class="fas <?= $entityType === 'organization' ? 'fa-building' : 'fa-people-group' ?> text-[11px]"></i>
                                        <?= $entityType === 'organization' ? 'Org' : 'Club' ?> Logo
                                    </span>
                                    <!-- Iba ang kulay ng badge depende kung org o club -->
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full
                                         <?= $entityType === 'organization'
                                                ? 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800'
                                                : 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-800' ?>">
                                        <?= $entityType === 'organization' ? 'ORG' : 'CLUB' ?>
                                    </span>
                                </div>

                                <?php if ($hasLogo): ?>
                                    <!-- Ipakita ang kasalukuyang logo kung mayroon -->
                                    <div class="w-14 h-14 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 overflow-hidden flex items-center justify-center">
                                        <img src="data:<?= $logoMime ?>;base64,<?= base64_encode($entityLogo) ?>"
                                            class="w-full h-full object-cover" alt="Logo">
                                    </div>
                                <?php else: ?>
                                    <!-- Placeholder kung wala pang logo -->
                                    <div class="w-14 h-14 rounded-xl border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                        <i class="fas fa-image text-gray-300 dark:text-gray-500 text-xl"></i>
                                    </div>
                                <?php endif; ?>

                                <!-- Pangalan ng org/club -->
                                <p class="text-xs font-semibold text-gray-700 dark:text-gray-300 truncate w-full text-center">
                                    <?= htmlspecialchars($entityName) ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ============================================================
                 RIGHT COLUMN: Settings Forms
                 Naglalaman ng 3 sections: Edit Profile, Logo Upload, at Password
                ============================================================ -->
                <div class="lg:col-span-2 space-y-5">

                    <!-- ============================================================
                     EDIT PROFILE FORM — para sa pagbabago ng pangalan,
                     phone number, at profile photo ng organizer
                    ============================================================ -->
                    <div class="anim-up d-2 card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">

                        <!-- Form section header -->
                        <h3 class="font-bold text-gray-900 dark:text-white flex items-center gap-3 mb-6">
                            <span class="icon-wrap w-10 h-10 rounded-xl bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 flex items-center justify-center border border-brand-200 dark:border-brand-800 flex-shrink-0">
                                <i class="fas fa-pen-to-square"></i>
                            </span>
                            Edit Profile
                        </h3>

                        <form method="POST" enctype="multipart/form-data" class="space-y-5">

                            <!-- ── PHOTO UPLOAD ZONE ── -->
                            <!-- Drag and drop o click-to-upload area para sa profile picture -->
                            <div class="upload-zone" id="uploadZone"
                                onclick="document.getElementById('profile_image').click()">
                                <label class="block text-xs font-semibold text-brand-600 dark:text-brand-400 uppercase tracking-wide mb-3">
                                    Profile Picture
                                </label>

                                <!-- Preview container — hidden hanggang may pinili na image -->
                                <div id="photoPreviewWrap" class="hidden mb-3">
                                    <div class="w-20 h-20 rounded-2xl overflow-hidden border-2 border-brand-400 mx-auto">
                                        <img id="photoPreviewImg" src="" class="w-full h-full object-cover" alt="">
                                    </div>
                                </div>

                                <!-- Default placeholder text at icon -->
                                <div id="uploadPlaceholder">
                                    <i class="fas fa-cloud-arrow-up text-3xl text-brand-400 mb-2 block"></i>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Click to upload or drag & drop</p>
                                    <p class="text-xs text-gray-400 mt-1">JPG, PNG up to 2 MB</p>
                                </div>

                                <!-- Filename label na lumalabas kapag pumili na ng file -->
                                <p class="text-xs text-brand-600 dark:text-brand-400 font-medium mt-2 hidden" id="photoFileName"></p>

                                <!-- Hidden file input — ini-trigger ng click sa upload zone -->
                                <input type="file" name="profile_image" id="profile_image" accept="image/*" onchange="handlePhotoSelect(this)">
                            </div>

                            <!-- ── NAME FIELDS — 3 columns: First, Middle, Last ── -->
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-user text-brand-400 mr-1"></i> First Name <span class="text-red-400">*</span>
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                        <input type="text" name="first_name" required
                                            value="<?= htmlspecialchars($user['first_name'] ?? '') ?>"
                                            class="field bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-800 dark:text-white placeholder-gray-400">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-user text-brand-400 mr-1"></i> Middle Name
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                        <input type="text" name="middle_name"
                                            value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>"
                                            class="field bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-800 dark:text-white placeholder-gray-400">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-user text-brand-400 mr-1"></i> Last Name <span class="text-red-400">*</span>
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                        <input type="text" name="last_name" required
                                            value="<?= htmlspecialchars($user['last_name'] ?? '') ?>"
                                            class="field bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-800 dark:text-white placeholder-gray-400">
                                    </div>
                                </div>
                            </div>

                            <!-- ── READ-ONLY FIELDS — email at student number ── -->
                            <!-- Hindi pwedeng baguhin ng user ang mga ito -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-lock text-gray-400 mr-1"></i> Email
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-300 text-xs pointer-events-none"></i>
                                        <input type="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" readonly
                                            class="field field-ro bg-gray-100 dark:bg-gray-700/50 border-gray-200 dark:border-gray-600 text-gray-500 dark:text-gray-400">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-lock text-gray-400 mr-1"></i> Student No.
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-id-card absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-300 text-xs pointer-events-none"></i>
                                        <input type="text" value="<?= htmlspecialchars($user['student_number'] ?? '—') ?>" readonly
                                            class="field field-ro bg-gray-100 dark:bg-gray-700/50 border-gray-200 dark:border-gray-600 text-gray-500 dark:text-gray-400 font-mono">
                                    </div>
                                </div>
                            </div>

                            <!-- ── PHONE NUMBER FIELD ── -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                    <i class="fas fa-phone text-brand-400 mr-1"></i> Phone Number
                                </label>
                                <div class="relative">
                                    <i class="fas fa-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                    <input type="text" name="phone"
                                        value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="09xxxxxxxxx"
                                        class="field bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-800 dark:text-white placeholder-gray-400">
                                </div>
                            </div>

                            <!-- ── FORM ACTION BUTTONS ── -->
                            <div class="flex flex-col sm:flex-row gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <!-- Reset button para i-clear ang mga binago -->
                                <button type="reset" onclick="resetPhotoPreview()"
                                    class="sm:w-auto px-5 py-2.5 rounded-xl text-sm font-semibold
                                           border border-gray-200 dark:border-gray-600
                                           text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                                           transition-colors active:scale-95">
                                    <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Reset
                                </button>
                                <!-- Submit button para i-save ang mga pagbabago -->
                                <button type="submit" name="update_settings"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-bold
                                           bg-brand-500 hover:bg-brand-600 text-white
                                           shadow shadow-brand-400/30 transition-all active:scale-95
                                           flex items-center justify-center gap-2">
                                    <i class="fas fa-floppy-disk text-xs"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- ============================================================
                     ORG/CLUB LOGO UPLOAD SECTION
                     Lumalabas lang kung may linked organization o club ang organizer
                    ============================================================ -->
                    <?php if ($entityType): ?>
                        <div class="anim-up d-3 card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">

                            <!-- Section header na may entity type badge -->
                            <h3 class="font-bold text-gray-900 dark:text-white flex items-center gap-3 mb-2">
                                <span class="icon-wrap w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex items-center justify-center border border-indigo-200 dark:border-indigo-800 flex-shrink-0">
                                    <i class="fas <?= $entityType === 'organization' ? 'fa-building' : 'fa-people-group' ?>"></i>
                                </span>
                                <?= $entityType === 'organization' ? 'Organization' : 'Club' ?> Logo
                                <!-- Pangalan ng org/club bilang badge sa header -->
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full
                                     <?= $entityType === 'organization'
                                            ? 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-800'
                                            : 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 border border-amber-200 dark:border-amber-800' ?>">
                                    <?= htmlspecialchars($entityName) ?>
                                </span>
                            </h3>
                            <p class="text-xs text-gray-400 mb-5">
                                This logo appears on your <?= $entityType === 'organization' ? 'organization' : 'club' ?>'s events and public profile.
                            </p>

                            <form method="POST" enctype="multipart/form-data" class="space-y-4">

                                <!-- Logo upload zone — clickable area para mag-upload ng bagong logo -->
                                <div class="logo-zone <?= $hasLogo ? 'has-file' : '' ?>" id="logoZone"
                                    onclick="document.getElementById('org_logo').click()">

                                    <!-- Kasalukuyang logo — may hover overlay para mag-replace -->
                                    <div id="currentLogoBox" class="<?= $hasLogo ? '' : 'hidden' ?> mb-2">
                                        <?php if ($hasLogo): ?>
                                            <div class="w-16 h-16 rounded-xl overflow-hidden border border-indigo-200 dark:border-indigo-800 mx-auto group relative">
                                                <img src="data:<?= $logoMime ?>;base64,<?= base64_encode($entityLogo) ?>"
                                                    class="w-full h-full object-cover" alt="Logo">
                                                <div class="absolute inset-0 bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity rounded-xl">
                                                    <i class="fas fa-camera text-white"></i>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Preview ng bagong logo bago i-submit — hidden sa simula -->
                                    <div id="logoPreviewWrap" class="hidden mb-2">
                                        <div class="w-16 h-16 rounded-xl overflow-hidden border-2 border-indigo-400 mx-auto">
                                            <img id="logoPreviewImg" src="" class="w-full h-full object-cover" alt="">
                                        </div>
                                    </div>

                                    <!-- Upload placeholder icon — hidden kapag may existing logo -->
                                    <div id="logoPlaceholder" class="<?= $hasLogo ? 'hidden' : '' ?>">
                                        <i class="fas fa-cloud-arrow-up text-2xl text-indigo-400 mb-1.5 block"></i>
                                    </div>

                                    <!-- Dynamic label na nagbabago depende kung may logo na o wala -->
                                    <p class="text-sm font-semibold text-indigo-500 dark:text-indigo-400 mt-1">
                                        <?= $hasLogo ? 'Click to replace logo' : 'Click to upload logo' ?>
                                    </p>
                                    <p class="text-xs text-gray-400 mt-0.5">PNG, JPG, SVG, WEBP — max 5 MB</p>

                                    <!-- Filename na lumalabas kapag pumili ng bagong logo -->
                                    <p class="text-xs text-indigo-500 dark:text-indigo-400 font-medium mt-2 hidden" id="logoFileName"></p>

                                    <!-- Hidden file input para sa logo -->
                                    <input type="file" name="org_logo" id="org_logo" accept="image/*" onchange="handleLogoSelect(this)">
                                </div>

                                <!-- Logo form action buttons -->
                                <div class="flex flex-col sm:flex-row gap-3 pt-2 border-t border-gray-200 dark:border-gray-700">
                                    <button type="button" onclick="resetLogoPreview()"
                                        class="sm:w-auto px-5 py-2.5 rounded-xl text-sm font-semibold
                                           border border-gray-200 dark:border-gray-600
                                           text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                                           transition-colors active:scale-95">
                                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Reset
                                    </button>
                                    <!-- Submit button — nagbabago ang text depende kung may logo na o wala -->
                                    <button type="submit" name="update_logo"
                                        class="flex-1 py-2.5 rounded-xl text-sm font-bold
                                           bg-indigo-500 hover:bg-indigo-600 text-white
                                           shadow shadow-indigo-400/30 transition-all active:scale-95
                                           flex items-center justify-center gap-2">
                                        <i class="fas fa-image text-xs"></i>
                                        <?= $hasLogo ? 'Replace Logo' : 'Upload Logo' ?>
                                    </button>
                                </div>
                            </form>
                        </div>

                    <?php else: ?>
                        <!-- Kung walang org/club ang organizer, nagpapakita ng notice -->
                        <div class="anim-up d-3 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
                            <div class="flex items-center gap-4">
                                <span class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-400 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-building"></i>
                                </span>
                                <div>
                                    <p class="font-semibold text-gray-700 dark:text-gray-300 text-sm">No Organization or Club Linked</p>
                                    <p class="text-xs text-gray-400 mt-0.5">Your account is not associated with any organization or club.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ============================================================
                     CHANGE PASSWORD SECTION — may rate limiting display
                     at real-time validation ng password strength
                    ============================================================ -->
                    <div class="anim-up d-4 card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">

                        <!-- Security section header -->
                        <div class="flex items-center gap-3 mb-2">
                            <span class="icon-wrap w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/30 text-red-500 dark:text-red-400 flex items-center justify-center border border-red-200 dark:border-red-800 flex-shrink-0">
                                <i class="fas fa-lock"></i>
                            </span>
                            <div>
                                <h3 class="font-bold text-gray-900 dark:text-white">Security</h3>
                            </div>
                            <!-- Protected badge -->
                            <span class="ml-auto text-[10px] font-bold px-2 py-0.5 rounded-full
                                     bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400
                                     border border-red-200 dark:border-red-800">PROTECTED</span>
                        </div>
                        <p class="text-xs text-gray-400 mb-5">
                            Change your password. You'll be logged out of all other devices afterward.
                        </p>

                        <?php if ($rateLimited): ?>
                            <!-- ── RATE LIMIT MESSAGE — lumalabas kapag 3x na nag-fail ang attempts ── -->
                            <div class="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 text-sm">
                                <i class="fas fa-ban text-red-500 text-lg flex-shrink-0"></i>
                                <div>
                                    <p class="font-semibold text-red-700 dark:text-red-400">Too many attempts</p>
                                    <p class="text-xs text-red-500 dark:text-red-400 mt-0.5">
                                        Try again in <?= ceil($timeLeft / 60) ?> minute<?= ceil($timeLeft / 60) > 1 ? 's' : '' ?>
                                    </p>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- ── PASSWORD CHANGE FORM ── -->
                            <form method="POST" id="pwForm" class="space-y-4">

                                <!-- Current password field na may show/hide toggle -->
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-key text-amber-400 mr-1"></i> Current Password <span class="text-red-400">*</span>
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-key absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                        <input type="password" name="current_password" id="current_password" required
                                            class="field bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-800 dark:text-white"
                                            style="padding-left:2.75rem; padding-right:3rem">
                                        <!-- Toggle button para ipakita/itago ang password text -->
                                        <button type="button" tabindex="-1" onclick="togglePw('current_password',this)"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                            <i class="fas fa-eye eye-show text-sm"></i>
                                            <i class="fas fa-eye-slash eye-hide hidden text-sm"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- New password field na may real-time strength checker -->
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-lock text-red-400 mr-1"></i> New Password <span class="text-red-400">*</span>
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                        <input type="password" name="new_password" id="new_password" required
                                            oninput="checkStrength(this.value)"
                                            class="field bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-800 dark:text-white"
                                            style="padding-left:2.75rem; padding-right:3rem">
                                        <button type="button" tabindex="-1" onclick="togglePw('new_password',this)"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                            <i class="fas fa-eye eye-show text-sm"></i>
                                            <i class="fas fa-eye-slash eye-hide hidden text-sm"></i>
                                        </button>
                                    </div>

                                    <!-- Password strength bar — nagbabago ang width at kulay batay sa kalakasan -->
                                    <div class="h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 mt-2 overflow-hidden">
                                        <div id="strengthBar" class="pw-bar" style="width:0"></div>
                                    </div>
                                    <div class="flex justify-between mt-1">
                                        <span class="text-[11px] text-gray-400">Strength</span>
                                        <span id="strengthLabel" class="text-[11px] font-semibold"></span>
                                    </div>

                                    <!-- Password requirements checklist — nagbabago kapag natutugunan na ang bawat requirement -->
                                    <div class="grid grid-cols-2 gap-1.5 mt-2.5">
                                        <div class="req-item text-gray-400" id="req-len"><i class="fas fa-circle text-[6px]"></i> 8+ characters</div>
                                        <div class="req-item text-gray-400" id="req-up"><i class="fas fa-circle text-[6px]"></i> Uppercase letter</div>
                                        <div class="req-item text-gray-400" id="req-num"><i class="fas fa-circle text-[6px]"></i> Number</div>
                                        <div class="req-item text-gray-400" id="req-sym"><i class="fas fa-circle text-[6px]"></i> Special character</div>
                                    </div>
                                </div>

                                <!-- Confirm password field na may real-time match checker -->
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                                        <i class="fas fa-check-double text-red-400 mr-1"></i> Confirm Password <span class="text-red-400">*</span>
                                    </label>
                                    <div class="relative">
                                        <i class="fas fa-check-double absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                                        <input type="password" name="confirm_password" id="confirm_password" required
                                            oninput="checkMatch()"
                                            class="field bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-800 dark:text-white"
                                            style="padding-left:2.75rem; padding-right:3rem">
                                        <button type="button" tabindex="-1" onclick="togglePw('confirm_password',this)"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                                            <i class="fas fa-eye eye-show text-sm"></i>
                                            <i class="fas fa-eye-slash eye-hide hidden text-sm"></i>
                                        </button>
                                    </div>
                                    <!-- Match indicator — lumalabas kung magkapareho o hindi ang passwords -->
                                    <p id="matchText" class="text-[11px] mt-1 hidden"></p>
                                </div>

                                <!-- Security notice box para ipaalam sa user ang consequences ng pagbabago -->
                                <div class="flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 text-xs text-amber-700 dark:text-amber-400">
                                    <i class="fas fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>
                                    <div>
                                        <p class="font-semibold mb-1">Security Notice</p>
                                        <ul class="space-y-0.5 list-disc list-inside">
                                            <li>You will be logged out of all other devices</li>
                                            <li>Cannot reuse your last 3 passwords</li>
                                            <li>A notification will be sent to <?= htmlspecialchars($user['email'] ?? '') ?></li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Password form action buttons -->
                                <div class="flex flex-col sm:flex-row gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                                    <button type="reset" onclick="resetPwForm()"
                                        class="sm:w-auto px-5 py-2.5 rounded-xl text-sm font-semibold
                                           border border-gray-200 dark:border-gray-600
                                           text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700
                                           transition-colors active:scale-95">
                                        <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear
                                    </button>
                                    <!-- Submit button — nadi-disable kapag invalid ang form (ginagawa sa JS) -->
                                    <button type="submit" name="change_password" id="pwSubmitBtn"
                                        class="flex-1 py-2.5 rounded-xl text-sm font-bold
                                           bg-red-500 hover:bg-red-600 text-white
                                           shadow shadow-red-400/20 transition-all active:scale-95
                                           flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <i class="fas fa-shield-halved text-xs"></i> Update Password
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                </div><!-- /right column -->
            </div><!-- /grid -->

        </main>
    </div>

    <!-- ============================================================
     SCROLL TO TOP BUTTON — lumalabas sa ibaba-kanan ng screen,
     nagre-redirect sa taas ng page kapag na-click
    ============================================================ -->
    <button onclick="window.scrollTo({top:0,behavior:'smooth'})"
        class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40
               bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
               text-gray-500 dark:text-gray-400 shadow-lg
               hover:bg-brand-500 hover:text-white hover:border-brand-500
               transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
        <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
    </button>

    <!-- ============================================================
     EXTERNAL JAVASCRIPT — i-load ang custom JS para sa:
     - Sidebar toggle (mobile)
     - Dark mode toggle
     - Photo/logo preview
     - Password strength checker
     - Password show/hide toggle
    ============================================================ -->
    <script src="/js/organizer_settings.js"></script>
</body>

</html>