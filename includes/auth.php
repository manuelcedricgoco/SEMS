<?php
// ┌─────────────────────────────────────────────────────────────────────┐
// │ SESSION AT DB CONNECTION                                            │
// └─────────────────────────────────────────────────────────────────────┘
session_start();
$pdo = require_once '../includes/db.php';

// ┌─────────────────────────────────────────────────────────────────────┐
// │ ADMIN SECRET KEY                                                    │
// └─────────────────────────────────────────────────────────────────────┘
define('ADMIN_SECRET_KEY', 'SEMS@Admin#2025!');

// ┌─────────────────────────────────────────────────────────────────────┐
// │ MESSAGE VARIABLES                                                   │
// └─────────────────────────────────────────────────────────────────────┘
$message      = "";
$message_type = "";

// ┌─────────────────────────────────────────────────────────────────────┐
// │ ACTIVE PANEL DETECTION                                              │
// └─────────────────────────────────────────────────────────────────────┘
if (isset($_GET['mode']) && $_GET['mode'] === 'register') {
    $active_panel = "register";
} else {
    $active_panel = "login";
}

// ┌─────────────────────────────────────────────────────────────────────┐
// │ ERROR PARAMETER HANDLER                                             │
// │ Catches redirect errors from auth guards on protected pages.        │
// │ ?error=archived  → user was archived while logged in               │
// │ ?error=unauthorized → user tried to access a forbidden page        │
// └─────────────────────────────────────────────────────────────────────┘
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'archived') {
        $message      = "Your account has been archived by the administrator. You have been signed out. Please contact your admin for assistance.";
        $message_type = "error";
    } elseif ($_GET['error'] === 'unauthorized') {
        $message      = "You do not have permission to access that page.";
        $message_type = "error";
    } elseif ($_GET['error'] === 'org_archived') {
        $message      = "Your organization or club has been archived by the administrator. You have been signed out. Please contact your admin for assistance.";
        $message_type = "error";
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   REGISTRATION LOGIC
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_POST['register'])) {

    $active_panel = "register";

    // ── FORM DATA ────────────────────────────────────────────────────────
    $email      = trim($_POST['email']);
    $password   = $_POST['password'];
    $pass_hash  = password_hash($password, PASSWORD_DEFAULT);
    $role       = $_POST['role'];
    $fname      = trim($_POST['first_name']);
    $mname      = trim($_POST['middle_name'] ?? '');
    $lname      = trim($_POST['last_name']);
    $phone      = !empty($_POST['phone'])      ? trim($_POST['phone'])      : null;
    $admin_code = !empty($_POST['admin_code']) ? trim($_POST['admin_code']) : null;

    // ── ROLE-SPECIFIC FIELDS ─────────────────────────────────────────────
    $dept       = !empty($_POST['dept_id'])        ? $_POST['dept_id']                : null;
    $snum       = !empty($_POST['student_number']) ? trim($_POST['student_number'])   : null;
    $year_level = !empty($_POST['year_level'])     ? $_POST['year_level']             : null;
    $section    = !empty($_POST['section'])        ? $_POST['section']                : null;
    $position   = ($role === 'organizer' && !empty($_POST['position'])) ? $_POST['position'] : null;

    $org_id  = (in_array($role, ['student','organizer']) && !empty($_POST['org_id']))  ? $_POST['org_id']  : null;
    $club_id = (in_array($role, ['student','organizer']) && !empty($_POST['club_id'])) ? $_POST['club_id'] : null;

    // ── BASIC VALIDATION ─────────────────────────────────────────────────
    if ($role === 'organizer' && !empty($org_id) && !empty($club_id)) {
        $message      = "As an Organizer you can only choose one: an Organization OR a Club — not both.";
        $message_type = "error";

    } elseif (strlen($password) < 8 || !preg_match('/[@#_%$!]/', $password)) {
        $message      = "Password must be at least 8 characters and contain at least one special character (@#_%\$!).";
        $message_type = "error";

    } elseif ($role === 'admin' && !empty($admin_code) && $admin_code !== ADMIN_SECRET_KEY) {
        $message      = "Invalid Admin Secret Key. Access denied.";
        $message_type = "error";

    } else {

        // ── PROFILE IMAGE UPLOAD ─────────────────────────────────────────
        $img_data       = null;
        $has_file_error = false;

        if ($_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
                $message        = "Profile image must be 2 MB or smaller (server limit).";
                $message_type   = "error";
                $has_file_error = true;
            } else {
                $img_data = file_get_contents($_FILES['profile_image']['tmp_name']);
            }
        } elseif ($_FILES['profile_image']['error'] === UPLOAD_ERR_NO_FILE) {
            $message        = "Please select a profile image.";
            $message_type   = "error";
            $has_file_error = true;
        } else {
            $message        = "File upload failed (code: " . $_FILES['profile_image']['error'] . ").";
            $message_type   = "error";
            $has_file_error = true;
        }

        if (!$has_file_error) {

            // ── ORG/CLUB LOGO UPLOAD ─────────────────────────────────────
            $org_logo_data  = null;
            $club_logo_data = null;

            if (isset($_FILES['org_logo']) && $_FILES['org_logo']['error'] === UPLOAD_ERR_OK) {
                if ($_FILES['org_logo']['size'] <= 2 * 1024 * 1024) {
                    $org_logo_data = file_get_contents($_FILES['org_logo']['tmp_name']);
                }
            }

            if (isset($_FILES['club_logo']) && $_FILES['club_logo']['error'] === UPLOAD_ERR_OK) {
                if ($_FILES['club_logo']['size'] <= 2 * 1024 * 1024) {
                    $club_logo_data = file_get_contents($_FILES['club_logo']['tmp_name']);
                }
            }

            try {
                $validation_failed = false;

                // VALIDATION #1 — EMAIL UNIQUENESS
                $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                $chk->execute([$email]);
                if ($chk->fetch()) {
                    $message           = "Email already exists!";
                    $message_type      = "error";
                    $validation_failed = true;
                }

                // VALIDATION #2 — STUDENT NUMBER FORMAT + UNIQUENESS
                if (!$validation_failed && !empty($snum)) {
                    if (!preg_match('/^\d{2}-\d{1}-\d{5}$/', $snum)) {
                        $message           = "Student number must follow the format YY-N-NNNNN (e.g. 24-1-05560).";
                        $message_type      = "error";
                        $validation_failed = true;
                    } else {
                        $chk2 = $pdo->prepare("
                            SELECT 'profiles'  AS src FROM profiles  WHERE student_number = ?
                            UNION
                            SELECT 'organizer' AS src FROM organizer WHERE student_number = ?
                        ");
                        $chk2->execute([$snum, $snum]);
                        $duplicate = $chk2->fetch();

                        if ($duplicate) {
                            if ($duplicate['src'] === 'profiles') {
                                $message = "Student number already exists! It is already registered to a student account.";
                            } else {
                                $message = "Student number already exists! It is already registered to an organizer account.";
                            }
                            $message_type      = "error";
                            $validation_failed = true;
                        }
                    }
                }

                // VALIDATION #3 — PHONE NUMBER UNIQUENESS
                if (!$validation_failed && !empty($phone)) {
                    $chk3 = $pdo->prepare("
                        SELECT 'profiles' AS src FROM profiles WHERE phone = ?
                        UNION
                        SELECT 'organizer' AS src FROM organizer WHERE phone = ?
                        UNION
                        SELECT 'admin' AS src FROM admin WHERE phone = ?
                    ");
                    $chk3->execute([$phone, $phone, $phone]);
                    if ($chk3->fetch()) {
                        $message           = "Phone number already exists!";
                        $message_type      = "error";
                        $validation_failed = true;
                    }
                }

                // VALIDATION #4 — POSITION UNIQUENESS (ORGANIZER ONLY)
                if (!$validation_failed && $role === 'organizer' && !empty($position)) {
                    $multiPositions = ['Councilor', 'Board Members'];
                    $posLimit       = in_array($position, $multiPositions) ? 7 : 1;

                    $pos_chk = $pdo->prepare("
                        SELECT COUNT(*) AS cnt
                        FROM organizer o
                        JOIN users u ON o.user_id = u.user_id
                        WHERE o.position = ?
                          AND (
                              (? IS NOT NULL AND u.org_id  = ?)
                           OR (? IS NOT NULL AND u.club_id = ?)
                          )
                    ");
                    $pos_chk->execute([$position, $org_id, $org_id, $club_id, $club_id]);
                    $pos_row = $pos_chk->fetch(PDO::FETCH_ASSOC);

                    if ($pos_row && (int)$pos_row['cnt'] >= $posLimit) {
                        $groupName = !empty($org_id)
                            ? 'this organization'
                            : (!empty($club_id) ? 'this club' : 'this group');

                        if (in_array($position, $multiPositions)) {
                            $message = "The position '{$position}' in {$groupName} is already full ({$posLimit}/{$posLimit} slots taken).";
                        } else {
                            $message = "The position '{$position}' is already taken in {$groupName}.";
                        }
                        $message_type      = "error";
                        $validation_failed = true;
                    }
                }

                // ── DATABASE INSERTS ─────────────────────────────────────
                if (!$validation_failed) {
                    $pdo->beginTransaction();

                    if ($role === 'admin') {
                        $s = $pdo->prepare("INSERT INTO users (email, password, role) VALUES (?, ?, ?)");
                        $s->execute([$email, $pass_hash, $role]);
                    } else {
                        $s = $pdo->prepare("INSERT INTO users (email, password, role, dept_id, org_id, club_id) VALUES (?, ?, ?, ?, ?, ?)");
                        $s->execute([$email, $pass_hash, $role, $dept, $org_id, $club_id]);
                    }
                    $user_id = $pdo->lastInsertId();

                    if ($role === 'admin') {
                        $s = $pdo->prepare("INSERT INTO admin (user_id, first_name, middle_name, last_name, phone, profile_image) VALUES (?, ?, ?, ?, ?, ?)");
                        $s->bindValue(1, $user_id);
                        $s->bindValue(2, $fname);
                        $s->bindValue(3, $mname);
                        $s->bindValue(4, $lname);
                        $s->bindValue(5, $phone);
                        $s->bindValue(6, $img_data, PDO::PARAM_STR);
                        $s->execute();

                    } elseif ($role === 'organizer') {
                        $s = $pdo->prepare("INSERT INTO organizer (user_id, first_name, middle_name, last_name, student_number, year_level, section, position, phone, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $s->bindValue(1,  $user_id);
                        $s->bindValue(2,  $fname);
                        $s->bindValue(3,  $mname);
                        $s->bindValue(4,  $lname);
                        $s->bindValue(5,  $snum);
                        $s->bindValue(6,  $year_level);
                        $s->bindValue(7,  $section);
                        $s->bindValue(8,  $position);
                        $s->bindValue(9,  $phone);
                        $s->bindValue(10, $img_data, PDO::PARAM_STR);
                        $s->execute();

                    } elseif ($role === 'student') {
                        $s = $pdo->prepare("INSERT INTO profiles (user_id, first_name, middle_name, last_name, student_number, year_level, section, phone, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $s->bindValue(1, $user_id);
                        $s->bindValue(2, $fname);
                        $s->bindValue(3, $mname);
                        $s->bindValue(4, $lname);
                        $s->bindValue(5, $snum);
                        $s->bindValue(6, $year_level);
                        $s->bindValue(7, $section);
                        $s->bindValue(8, $phone);
                        $s->bindValue(9, $img_data, PDO::PARAM_STR);
                        $s->execute();

                        // QR CODE GENERATION
                        $qr_val = "USER_" . $user_id;
                        $s = $pdo->prepare("INSERT INTO student_qr_codes (user_id, qr_value) VALUES (?, ?)");
                        $s->execute([$user_id, $qr_val]);

                    } else {
                        throw new Exception("Invalid role specified.");
                    }

                    // ORG LOGO UPDATE
                    if ($role === 'organizer' && !empty($org_id) && $org_logo_data !== null) {
                        $upd = $pdo->prepare("UPDATE organizations SET logo = ? WHERE org_id = ?");
                        $upd->bindValue(1, $org_logo_data, PDO::PARAM_STR);
                        $upd->bindValue(2, $org_id, PDO::PARAM_INT);
                        $upd->execute();
                    }

                    // CLUB LOGO UPDATE
                    if ($role === 'organizer' && !empty($club_id) && $club_logo_data !== null) {
                        $upd = $pdo->prepare("UPDATE clubs SET logo = ? WHERE club_id = ?");
                        $upd->bindValue(1, $club_logo_data, PDO::PARAM_STR);
                        $upd->bindValue(2, $club_id, PDO::PARAM_INT);
                        $upd->execute();
                    }

                    $pdo->commit();
                    $message      = "Registration successful! You can now sign in.";
                    $message_type = "success";
                    $active_panel = "login";
                }

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();

                if (
                    $e->getCode() === '45000' ||
                    stripos($e->getMessage(), 'Student number already exists') !== false
                ) {
                    if (stripos($e->getMessage(), 'organizer') !== false) {
                        $message = "Student number already exists! It is already registered to an organizer account.";
                    } elseif (stripos($e->getMessage(), 'profiles') !== false) {
                        $message = "Student number already exists! It is already registered to a student account.";
                    } else {
                        $message = "Student number already exists! It must be unique across all students and organizers.";
                    }
                    $message_type = "error";
                } else {
                    $message      = "Registration failed: " . $e->getMessage();
                    $message_type = "error";
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message      = "Registration failed: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   LOGIN LOGIC
   ═══════════════════════════════════════════════════════════════════════ */
if (isset($_POST['login'])) {

    $email = trim($_POST['email']);
    $pass  = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
    $message      = "Your email is incorrect. No account found with that email address.";
    $message_type = "error";

} elseif (!password_verify($pass, $user['password'])) {
    $message      = "Your password is incorrect. Please try again.";
    $message_type = "error";

} else {
    if (!empty($user['deleted_at'])) {
        $message      = "Your account has been archived by the administrator. Please contact your admin for assistance.";
        $message_type = "error";

    } else {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role']    = $user['role'];

        if ($user['role'] === 'admin') {
            $target = "/admin/admin_dashboard.php";
        } elseif ($user['role'] === 'organizer') {
            $target = "/organizer/organizer_panel.php";
        } else {
            $target = "/student/student_dashboard.php";
        }

        header("Location: $target");
        exit;
    }
}
}

/* ═══════════════════════════════════════════════════════════════════════
   ORG / CLUB LOGO CHECK
   ═══════════════════════════════════════════════════════════════════════ */
$orgs_with_logo  = [];
$clubs_with_logo = [];

try {
    $org_logo_check = $pdo->query("SELECT org_id FROM organizations WHERE logo IS NOT NULL AND logo != ''");
    while ($row = $org_logo_check->fetch(PDO::FETCH_ASSOC)) {
        $orgs_with_logo[] = (int)$row['org_id'];
    }
} catch (Exception $e) {
    $orgs_with_logo = [];
}

try {
    $club_logo_check = $pdo->query("SELECT club_id FROM clubs WHERE logo IS NOT NULL AND logo != ''");
    while ($row = $club_logo_check->fetch(PDO::FETCH_ASSOC)) {
        $clubs_with_logo[] = (int)$row['club_id'];
    }
} catch (Exception $e) {
    $clubs_with_logo = [];
}

/* ═══════════════════════════════════════════════════════════════════════
   POSITION AVAILABILITY DATA
   ═══════════════════════════════════════════════════════════════════════ */
$taken_positions_by_org  = [];
$taken_positions_by_club = [];

try {
    $pos_stmt = $pdo->query("
        SELECT u.org_id, u.club_id, o.position, COUNT(*) AS cnt
        FROM organizer o
        JOIN users u ON o.user_id = u.user_id
        WHERE o.position IS NOT NULL AND o.position != ''
        GROUP BY u.org_id, u.club_id, o.position
    ");

    while ($prow = $pos_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($prow['org_id'])) {
            $taken_positions_by_org[(int)$prow['org_id']][$prow['position']] = (int)$prow['cnt'];
        }
        if (!empty($prow['club_id'])) {
            $taken_positions_by_club[(int)$prow['club_id']][$prow['position']] = (int)$prow['cnt'];
        }
    }
} catch (Exception $e) {
    $taken_positions_by_org  = [];
    $taken_positions_by_club = [];
}

/* ═══════════════════════════════════════════════════════════════════════
   FORM VALUE REPOPULATION AFTER FAILED SUBMISSION
   ═══════════════════════════════════════════════════════════════════════ */
$post_snum     = htmlspecialchars($_POST['student_number'] ?? '');
$post_role     = htmlspecialchars($_POST['role']           ?? 'student');
$post_email    = htmlspecialchars($_POST['email']          ?? '');
$post_fname    = htmlspecialchars($_POST['first_name']     ?? '');
$post_mname    = htmlspecialchars($_POST['middle_name']    ?? '');
$post_lname    = htmlspecialchars($_POST['last_name']      ?? '');
$post_phone    = htmlspecialchars($_POST['phone']          ?? '');
$post_dept     = $_POST['dept_id']    ?? '';
$post_year     = $_POST['year_level'] ?? '';
$post_section  = $_POST['section']   ?? '';
$post_position = $_POST['position']  ?? '';
$post_org      = $_POST['org_id']    ?? '';
$post_club     = $_POST['club_id']   ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEMS — Authentication</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/CSS/auth.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        primary:   '#6366f1',
                        secondary: '#8b5cf6',
                        accent:    '#10b981',
                        dark:      '#1e293b',
                    },
                    animation: {
                        'float':          'float 6s ease-in-out infinite',
                        'float-delayed':  'float 6s ease-in-out 3s infinite',
                        'pulse-slow':     'pulse 4s cubic-bezier(0.4,0,0.6,1) infinite',
                        'bounce-slow':    'bounce 3s infinite',
                        'spin-slow':      'spin 18s linear infinite',
                        'spin-slow-rev':  'spinRev 12s linear infinite',
                    },
                    keyframes: {
                        float:    { '0%,100%': { transform:'translateY(0px)' }, '50%': { transform:'translateY(-18px)' } },
                        spinRev:  { from:{ transform:'rotate(0deg)' }, to:{ transform:'rotate(-360deg)' } },
                    }
                }
            }
        }
    </script>
</head>

<body class="min-h-screen flex flex-col items-center justify-center p-4 gap-5">

    <div id="bubble-bg"></div>

    <a href="/index.php" class="back-home-btn">
        <i class="fas fa-arrow-left"></i>
        Back to Home
    </a>

    <div class="top-badge flex items-center gap-2 px-5 py-2 rounded-full text-xs font-semibold tracking-widest uppercase bg-white border border-indigo-100 text-primary shadow-sm" style="letter-spacing:.12em;">
        <span class="w-2 h-2 rounded-full bg-accent pulse-dot" style="display:inline-block;"></span>
        School Event Management System
    </div>

    <div class="auth-card" id="authCard">

        <!-- ═══ BRAND PANEL ═══ -->
        <div class="brand-panel" id="brandPanel">
            <div class="panel-blob-1"></div>
            <div class="panel-blob-2"></div>
            <div class="panel-ring panel-ring-1"></div>
            <div class="panel-ring panel-ring-2"></div>

            <div class="panel-badge">
                <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center text-white text-base">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <span style="color:rgba(255,255,255,0.92);font-size:13px;font-weight:800;letter-spacing:.06em;">SEMS</span>
            </div>

            <h2 class="panel-title" id="panelHeading">Welcome Back!</h2>
            <p class="panel-sub" id="panelSub">
                To keep connected with us please<br>login with your personal info
            </p>

            <button class="btn-ghost" id="panelBtn" onclick="switchToLogin()">Sign In</button>

            <div style="display:flex;gap:22px;margin-top:32px;">
                <div style="text-align:center;">
                    <div style="color:#fff;font-size:1.2rem;font-weight:800;line-height:1;">500+</div>
                    <div style="color:rgba(255,255,255,0.65);font-size:10px;margin-top:3px;">Events</div>
                </div>
                <div style="width:1px;background:rgba(255,255,255,0.18);"></div>
                <div style="text-align:center;">
                    <div style="color:#fff;font-size:1.2rem;font-weight:800;line-height:1;">10k+</div>
                    <div style="color:rgba(255,255,255,0.65);font-size:10px;margin-top:3px;">Users</div>
                </div>
                <div style="width:1px;background:rgba(255,255,255,0.18);"></div>
                <div style="text-align:center;">
                    <div style="color:#fff;font-size:1.2rem;font-weight:800;line-height:1;">98%</div>
                    <div style="color:rgba(255,255,255,0.65);font-size:10px;margin-top:3px;">Satisfied</div>
                </div>
            </div>
        </div>

        <!-- ═══ FORM PANEL ═══ -->
        <div class="form-panel">
            <div class="form-scroll" id="formScroll">

                <!-- Alert message -->
                <?php if ($message): ?>
                    <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>">
                        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <!-- ═══ LOGIN FORM ═══ -->
                <div id="loginSection">
                    <h1 class="form-title">Sign In</h1>

                    <div class="flex justify-center gap-3 mb-4">
                        <div class="social-btn"><i class="fa-brands fa-facebook-f"></i></div>
                        <div class="social-btn"><i class="fa-brands fa-google"></i></div>
                        <div class="social-btn"><i class="fa-brands fa-linkedin-in"></i></div>
                    </div>

                    <div class="or-divider">or use your account</div>

                    <form method="POST" class="flex flex-col gap-3 mt-1" id="loginForm" autocomplete="off">

                        <div class="email-suggest-wrap">
                            <div class="field-label">Email Address</div>
                            <div class="input-wrap">
                                <span class="icon"><i class="fa-regular fa-envelope"></i></span>
                                <input type="email" name="email" id="loginEmail" placeholder="you@university.edu" required autocomplete="off">
                            </div>
                            <div id="emailSuggestions"></div>
                        </div>

                        <div>
                            <div class="field-label">Password</div>
                            <div class="input-wrap">
                                <span class="icon"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="password" id="loginPassword" placeholder="Enter your password" required autocomplete="new-password">
                                <button type="button" class="eye-btn" onclick="toggleEye('loginPassword','loginEyeIcon')" tabindex="-1">
                                    <i class="fa-regular fa-eye" id="loginEyeIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between">
                            <div class="remember-wrap">
                                <input type="checkbox" id="rememberMe" name="remember_me">
                                <label for="rememberMe">Remember my email</label>
                            </div>
                        </div>

                        <button type="submit" name="login" class="btn-primary">
                            <i class="fa-solid fa-arrow-right-to-bracket mr-2"></i>Sign In
                        </button>
                        <input type="hidden" name="login" value="1">
                    </form>
                </div>

                <!-- ═══ REGISTRATION FORM ═══ -->
                <div id="registerSection" class="hidden">
                    <h1 class="form-title">Create Account</h1>

                    <div class="flex justify-center gap-3 mb-4">
                        <div class="social-btn"><i class="fa-brands fa-facebook-f"></i></div>
                        <div class="social-btn"><i class="fa-brands fa-google"></i></div>
                        <div class="social-btn"><i class="fa-brands fa-linkedin-in"></i></div>
                    </div>

                    <div class="or-divider">or use your email for registration</div>

                    <form method="POST" enctype="multipart/form-data" class="flex flex-col gap-3" id="registerForm">

                        <!-- SECTION: ACCOUNT INFO -->
                        <div class="section-divider"><i class="fa-solid fa-user-shield"></i> Account</div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="field-label">Role</div>
                                <div class="input-wrap select-wrap">
                                    <span class="icon"><i class="fa-solid fa-user-tag"></i></span>
                                    <select name="role" id="roleSelect" required onchange="handleRoleChange(this.value)">
                                        <option value="student"   <?= $post_role === 'student'   ? 'selected' : '' ?>>Student</option>
                                        <option value="organizer" <?= $post_role === 'organizer' ? 'selected' : '' ?>>Organizer</option>
                                        <option value="admin"     <?= $post_role === 'admin'     ? 'selected' : '' ?>>Admin</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <div class="field-label">Email Address</div>
                                <div class="input-wrap">
                                    <span class="icon"><i class="fa-regular fa-envelope"></i></span>
                                    <input type="email" name="email" placeholder="you@university.edu" required
                                           value="<?= $post_email ?>">
                                </div>
                            </div>
                        </div>

                        <div>
                            <div class="field-label">Password</div>
                            <div class="input-wrap">
                                <span class="icon"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="password" id="regPassword" placeholder="Create a strong password" required autocomplete="new-password" oninput="checkPasswordStrength(this.value)">
                                <button type="button" class="eye-btn" onclick="toggleEye('regPassword','regEyeIcon')" tabindex="-1">
                                    <i class="fa-regular fa-eye" id="regEyeIcon"></i>
                                </button>
                            </div>

                            <div class="pw-strength-bar-wrap">
                                <div class="pw-bar" id="pwBar1"></div>
                                <div class="pw-bar" id="pwBar2"></div>
                                <div class="pw-bar" id="pwBar3"></div>
                                <div class="pw-bar" id="pwBar4"></div>
                            </div>

                            <div class="pw-label" id="pwLabel" style="color:#c7d0dd;">Enter a password</div>
                            <div class="pw-req-list">
                                <div class="pw-req" id="req-len"><i class="fa-solid fa-circle-xmark"></i> At least 8 characters</div>
                                <div class="pw-req" id="req-special"><i class="fa-solid fa-circle-xmark"></i> One special character (@#_%$!)</div>
                                <div class="pw-req" id="req-upper"><i class="fa-solid fa-circle-xmark"></i> One uppercase letter</div>
                                <div class="pw-req" id="req-number"><i class="fa-solid fa-circle-xmark"></i> One number</div>
                            </div>

                            <div style="margin-top:8px; font-size:11.5px; color:#b91c1c; background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.18); border-radius:10px; padding:8px 12px; line-height:1.5;">
                                <i class="fa-solid fa-triangle-exclamation" style="margin-right:5px;"></i>
                                <strong>Note:</strong> Please remember the password you enter. There is no password recovery option if you forget it.
                            </div>
                        </div>

                        <!-- SECTION: PERSONAL INFO -->
                        <div class="section-divider mt-1"><i class="fa-solid fa-id-card"></i> Personal Info</div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="field-label">First Name</div>
                                <div class="input-wrap">
                                    <span class="icon"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" name="first_name" placeholder="Juan" required
                                           value="<?= $post_fname ?>">
                                </div>
                            </div>
                            <div>
                                <div class="field-label">Middle Name</div>
                                <div class="input-wrap">
                                    <span class="icon"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" name="middle_name" placeholder="Santos" required
                                           value="<?= $post_mname ?>">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <div class="field-label">Last Name</div>
                                <div class="input-wrap">
                                    <span class="icon"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" name="last_name" placeholder="Dela Cruz" required
                                           value="<?= $post_lname ?>">
                                </div>
                            </div>
                            <div>
                                <div class="field-label">Phone <span style="color:#ef4444">*</span></div>
                                <div class="input-wrap">
                                    <span class="icon"><i class="fa-solid fa-mobile-screen"></i></span>
                                    <input type="text" name="phone" id="phoneInput" placeholder="+63 9XX XXX XXXX" required
                                           value="<?= $post_phone ?>">
                                </div>
                            </div>
                        </div>

                        <!-- ADMIN-ONLY FIELDS -->
                        <div id="adminWarningWrap" class="role-field hidden-field">
                            <div class="admin-warning">
                                <i class="fa-solid fa-shield-halved"></i>
                                <span>You are registering as an <strong>Admin</strong>. This role has full system access. Misuse is a serious violation.</span>
                            </div>
                        </div>

                        <div id="adminKeyWrap" class="role-field hidden-field">
                            <div class="field-label">Admin Secret Key <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span></div>
                            <div class="input-wrap">
                                <span class="icon"><i class="fa-solid fa-key"></i></span>
                                <input type="password" name="admin_code" id="adminCodeInput" placeholder="Enter secret key if provided" autocomplete="new-password">
                                <button type="button" class="eye-btn" onclick="toggleEye('adminCodeInput','adminKeyEyeIcon')" tabindex="-1">
                                    <i class="fa-regular fa-eye" id="adminKeyEyeIcon"></i>
                                </button>
                            </div>
                            <p style="font-size:11px;color:#c7d0dd;margin-top:4px;"><i class="fa-solid fa-circle-info" style="color:#6366f1;"></i> Leave blank if no key was given to you.</p>
                        </div>

                        <!-- SECTION: ACADEMIC DETAILS -->
                        <div id="academicHeader" class="section-divider mt-1 role-field hidden-field"><i class="fa-solid fa-graduation-cap"></i> Academic Details</div>

                        <div id="deptSnumGrid" class="role-field hidden-field">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <div class="field-label">Department <span style="color:#ef4444">*</span></div>
                                    <div class="input-wrap select-wrap">
                                        <span class="icon"><i class="fa-solid fa-building-columns"></i></span>
                                        <select name="dept_id" id="deptSelect">
                                            <option value="" disabled <?= !$post_dept ? 'selected' : '' ?>>Select Department</option>
                                            <option value="1" <?= $post_dept === '1' ? 'selected' : '' ?>>BS Information Technology</option>
                                            <option value="2" <?= $post_dept === '2' ? 'selected' : '' ?>>BS Operational Management</option>
                                            <option value="3" <?= $post_dept === '3' ? 'selected' : '' ?>>BS Financial Management</option>
                                            <option value="4" <?= $post_dept === '4' ? 'selected' : '' ?>>BS Elementary Education</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <div class="field-label">Student Number <span style="color:#ef4444">*</span></div>
                                    <div class="input-wrap">
                                        <span class="icon"><i class="fa-solid fa-hashtag"></i></span>
                                        <input type="text" name="student_number" id="studentNumInput"
                                               placeholder="24-1-05560" maxlength="10"
                                               pattern="^\d{2}-\d{1}-\d{5}$"
                                               title="Format: YY-N-NNNNN (e.g. 24-1-05560)"
                                               value="<?= $post_snum ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="yearSectionWrap" class="role-field hidden-field">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <div class="field-label">Year Level <span style="color:#ef4444">*</span></div>
                                    <div class="input-wrap select-wrap">
                                        <span class="icon"><i class="fa-solid fa-layer-group"></i></span>
                                        <select name="year_level" id="yearLevelSelect">
                                            <option value="" disabled <?= !$post_year ? 'selected' : '' ?>>Select Year</option>
                                            <option value="1st Year" <?= $post_year === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                                            <option value="2nd Year" <?= $post_year === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                                            <option value="3rd Year" <?= $post_year === '3rd Year' ? 'selected' : '' ?>>3rd Year</option>
                                            <option value="4th Year" <?= $post_year === '4th Year' ? 'selected' : '' ?>>4th Year</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <div class="field-label">Section <span style="color:#ef4444">*</span></div>
                                    <div class="input-wrap select-wrap">
                                        <span class="icon"><i class="fa-solid fa-chalkboard"></i></span>
                                        <select name="section" id="sectionSelect">
                                            <option value="" disabled <?= !$post_section ? 'selected' : '' ?>>Select Section</option>
                                            <?php foreach (['A','B','C','D','E','F','G','H'] as $sec): ?>
                                                <option value="<?= $sec ?>" <?= $post_section === $sec ? 'selected' : '' ?>><?= $sec ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="positionWrap" class="role-field hidden-field">
                            <div class="field-label">Position <span style="color:#ef4444">*</span></div>
                            <div class="input-wrap select-wrap">
                                <span class="icon"><i class="fa-solid fa-briefcase"></i></span>
                                <select name="position" id="positionSelect">
                                    <option value="" disabled selected>Select Position</option>
                                    <option value="Governor"      data-group="ssg" <?= $post_position === 'Governor'      ? 'selected' : '' ?>>Governor</option>
                                    <option value="Vice Governor" data-group="ssg" <?= $post_position === 'Vice Governor' ? 'selected' : '' ?>>Vice Governor</option>
                                    <option value="Mayor"         data-group="std" <?= $post_position === 'Mayor'         ? 'selected' : '' ?>>Mayor</option>
                                    <option value="Vice Mayor"    data-group="std" <?= $post_position === 'Vice Mayor'    ? 'selected' : '' ?>>Vice Mayor</option>
                                    <option value="Treasurer"     data-group="shared" <?= $post_position === 'Treasurer'     ? 'selected' : '' ?>>Treasurer</option>
                                    <option value="Secretary"     data-group="shared" <?= $post_position === 'Secretary'     ? 'selected' : '' ?>>Secretary</option>
                                    <option value="Auditor"       data-group="shared" <?= $post_position === 'Auditor'       ? 'selected' : '' ?>>Auditor</option>
                                    <option value="Councilor"     data-group="std"    <?= $post_position === 'Councilor'     ? 'selected' : '' ?>>Councilor</option>
                                    <option value="Board Members" data-group="ssg"    <?= $post_position === 'Board Members' ? 'selected' : '' ?>>Board Members</option>
                                </select>
                            </div>
                            <div id="positionHintBox" style="display:none;margin-top:6px;font-size:11.5px;padding:8px 12px;border-radius:10px;background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.18);color:#4338ca;line-height:1.55;">
                                <i class="fa-solid fa-circle-info" style="margin-right:4px;"></i>
                                <span id="positionHintText"></span>
                            </div>
                        </div>

                        <!-- SECTION: MEMBERSHIP -->
                        <div id="orgClubWrap" class="role-field hidden-field">
                            <div class="section-divider mt-1"><i class="fa-solid fa-users"></i> <span id="membershipTitle">Membership</span></div>
                            <div class="grid grid-cols-1 gap-3 mt-2">

                                <div>
                                    <div class="field-label">Organization</div>
                                    <div class="input-wrap select-wrap">
                                        <span class="icon"><i class="fa-solid fa-building"></i></span>
                                        <select name="org_id" id="orgSelect">
                                            <option value="">Select Organization</option>
                                            <?php
                                            try {
                                                $org_stmt = $pdo->query("SELECT org_id, org_name FROM organizations");
                                                while ($org_row = $org_stmt->fetch()) {
                                                    $sel = ($post_org == $org_row['org_id']) ? 'selected' : '';
                                                    echo "<option value='{$org_row['org_id']}' {$sel}>" . htmlspecialchars($org_row['org_name']) . "</option>";
                                                }
                                            } catch (Exception $e) {}
                                            ?>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <div class="field-label">Club</div>
                                    <div class="input-wrap select-wrap">
                                        <span class="icon"><i class="fa-solid fa-people-group"></i></span>
                                        <select name="club_id" id="clubSelect">
                                            <option value="">Select Club</option>
                                            <?php
                                            try {
                                                $club_stmt = $pdo->query("SELECT club_id, club_name FROM clubs");
                                                while ($club_row = $club_stmt->fetch()) {
                                                    $sel = ($post_club == $club_row['club_id']) ? 'selected' : '';
                                                    echo "<option value='{$club_row['club_id']}' {$sel}>" . htmlspecialchars($club_row['club_name']) . "</option>";
                                                }
                                            } catch (Exception $e) {}
                                            ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div id="orgLogoWrap" class="role-field hidden-field mt-3">
                                <div class="field-label">Organization Logo <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span></div>
                                <div class="logo-exists-notice" id="orgLogoExistsNotice">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <span>This organization <strong>already has a logo</strong>. You can skip or upload a new one to replace it.</span>
                                </div>
                                <label class="file-upload-container" id="orgUploadContainer" for="orgLogo">
                                    <img id="orgLogoPreview" class="preview-image" alt="Org Logo" style="border-radius:12px;">
                                    <span class="file-label-text">
                                        <i class="fa-solid fa-building"></i>
                                        <span id="orgFileText">Upload organization logo<br><small>JPG, PNG (Max 2MB)</small></span>
                                    </span>
                                    <input type="file" id="orgLogo" name="org_logo" accept="image/*" onchange="handleOrgLogo(this)">
                                </label>
                            </div>

                            <div id="clubLogoWrap" class="role-field hidden-field mt-3">
                                <div class="field-label">Club Logo <span style="color:#94a3b8;font-weight:400;text-transform:none;">(Optional)</span></div>
                                <div class="logo-exists-notice" id="clubLogoExistsNotice">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <span>This club <strong>already has a logo</strong>. You can skip or upload a new one to replace it.</span>
                                </div>
                                <label class="file-upload-container" id="clubUploadContainer" for="clubLogo">
                                    <img id="clubLogoPreview" class="preview-image" alt="Club Logo" style="border-radius:12px;">
                                    <span class="file-label-text">
                                        <i class="fa-solid fa-people-group"></i>
                                        <span id="clubFileText">Upload club logo<br><small>JPG, PNG (Max 2MB)</small></span>
                                    </span>
                                    <input type="file" id="clubLogo" name="club_logo" accept="image/*" onchange="handleClubLogo(this)">
                                </label>
                            </div>

                            <p id="orgClubHint" style="display:none;margin-top:8px;" class="role-hint">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                You can only join <strong>one</strong>: an Organization <em>or</em> a Club — not both.
                            </p>
                        </div>

                        <!-- PROFILE PHOTO -->
                        <div>
                            <div class="field-label">Profile Photo <span style="color:#ef4444">*</span></div>
                            <label class="file-upload-container" id="uploadContainer" for="profileImg">
                                <img id="imagePreview" class="preview-image" alt="Preview">
                                <span class="file-label-text" id="fileLabelText">
                                    <i class="fa-solid fa-camera"></i>
                                    <span id="fileText">Click to upload photo<br><small>JPG, PNG (Max 2MB)</small></span>
                                </span>
                                <input type="file" id="profileImg" name="profile_image" accept="image/*" required onchange="handleImageSelect(this)">
                            </label>
                            <p class="upload-size-notice"><i class="fa-solid fa-circle-info"></i> Max 2MB per image (server limit)</p>
                        </div>

                        <button type="submit" name="register" class="btn-primary">
                            <i class="fa-solid fa-user-plus mr-2"></i>Sign Up
                        </button>
                        <input type="hidden" name="register" value="1">

                    </form>
                </div>

            </div><!-- /formScroll -->
        </div><!-- /formPanel -->
    </div><!-- /authCard -->

    <p class="footer-note" style="font-size:11px;color:#94a3b8;letter-spacing:.05em;">
        <i class="fa-solid fa-shield-halved" style="color:#6366f1;"></i>
        &nbsp;Secured · SEMS v2.0 &copy; 2026
    </p>

    <!-- LOADING OVERLAY -->
    <div id="pageLoader" class="loader-overlay">
        <div class="loader-card">
            <div class="loader-brand">
                <div class="w-6 h-6 rounded-lg bg-gradient-to-br from-primary to-secondary flex items-center justify-center text-white text-xs">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <span>SEMS</span>
            </div>
            <div class="loader-rings">
                <div class="ring ring-1"></div>
                <div class="ring ring-2"></div>
                <div class="ring ring-3"></div>
            </div>
            <div class="loader-text-block">
                <div class="loader-text">Redirecting</div>
                <div class="loader-sub">Taking you to your dashboard&hellip;</div>
            </div>
            <div class="loader-track"><div class="loader-track-bar"></div></div>
        </div>
    </div>

    <!-- PHP → JS DATA BRIDGE -->
    <script>
        window.SEMS_DATA = {
            initialPanel : <?php echo json_encode($active_panel); ?>,
            takenByOrg   : <?php echo json_encode($taken_positions_by_org);  ?>,
            takenByClub  : <?php echo json_encode($taken_positions_by_club); ?>,
            orgsWithLogo : <?php echo json_encode($orgs_with_logo);  ?>,
            clubsWithLogo: <?php echo json_encode($clubs_with_logo); ?>
        };
    </script>

    <script src="/js/auth.js"></script>
</body>
</html>