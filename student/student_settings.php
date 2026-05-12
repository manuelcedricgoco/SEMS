<?php
/*
 * ============================================================
 *  student_settings.php — SEMS (Redesigned)
 * ============================================================
 */
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid       = (int) $_SESSION['user_id'];
$message   = "";
$error     = "";
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'profile';

// ── HELPER FUNCTIONS ──────────────────────────────────────────
function checkRateLimit(\PDO $pdo, int $user_id): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) as attempt_count FROM password_history WHERE user_id = ? AND changed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC)['attempt_count'] < 3;
}

function isPasswordInHistory(\PDO $pdo, int $user_id, string $new_password): bool {
    $stmt = $pdo->prepare("SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY changed_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        if (password_verify($new_password, $record['password_hash'])) return true;
    }
    return false;
}

function getUserEmail(\PDO $pdo, int $user_id): ?string {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC)['email'] ?? null;
}

function sendPasswordChangeNotification(string $email): bool {
    $subject = "Password Changed - SEMS Security Alert";
    $message = "<html><body><h2>Security Notification</h2><p>Your password was successfully changed on " . date('F j, Y, g:i a') . ".</p><p>If you did not make this change, please contact the administrator immediately.</p><br><p>Best regards,<br>SEMS Security Team</p></body></html>";
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: noreply@sems.edu\r\n";
    return mail($email, $subject, $message, $headers);
}

function getImageMimeType(string $binaryData): string {
    if (empty($binaryData)) return 'image/jpeg';
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($binaryData);
    return in_array($mimeType, ['image/jpeg','image/png','image/gif','image/webp']) ? $mimeType : 'image/jpeg';
}

// ── FETCH USER DATA ───────────────────────────────────────────
$stmt = $pdo->prepare("SELECT p.*, d.dept_name, u.email FROM profiles p JOIN users u ON p.user_id = u.user_id JOIN departments d ON u.dept_id = d.dept_id WHERE p.user_id = :uid");
$stmt->execute(['uid' => $uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $user = ['first_name'=>'','middle_name'=>'','last_name'=>'','student_number'=>'N/A','dept_name'=>'N/A','year_level'=>'','section'=>'','phone'=>'','profile_image'=>null,'email'=>''];
}

// ── PROFILE UPDATE ────────────────────────────────────────────
if (isset($_POST['update_profile'])) {
    $fname = $_POST['first_name'];
    $mname = $_POST['middle_name'] ?? '';
    $lname = $_POST['last_name'];
    $phone = $_POST['phone'];
    $year  = $_POST['year_level'];
    $sect  = $_POST['section'];

    if (!empty($_FILES['profile_image']['tmp_name'])) {
        $img  = file_get_contents($_FILES['profile_image']['tmp_name']);
        $stmt = $pdo->prepare("UPDATE profiles SET first_name=:fname,middle_name=:mname,last_name=:lname,phone=:phone,year_level=:year,section=:sect,profile_image=:img WHERE user_id=:uid");
        $stmt->execute(['fname'=>$fname,'mname'=>$mname,'lname'=>$lname,'phone'=>$phone,'year'=>$year,'sect'=>$sect,'img'=>$img,'uid'=>$uid]);
    } else {
        $stmt = $pdo->prepare("UPDATE profiles SET first_name=:fname,middle_name=:mname,last_name=:lname,phone=:phone,year_level=:year,section=:sect WHERE user_id=:uid");
        $stmt->execute(['fname'=>$fname,'mname'=>$mname,'lname'=>$lname,'phone'=>$phone,'year'=>$year,'sect'=>$sect,'uid'=>$uid]);
    }
    $message = "Settings updated successfully!";
    header("Refresh:1");
}

// ── PASSWORD CHANGE ───────────────────────────────────────────
if (isset($_POST['change_password'])) {
    $activeTab        = 'security';
    $current_password = $_POST['current_password'];
    $new_password     = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (!checkRateLimit($pdo, $uid)) {
        $error = "Too many password change attempts. Please try again after 1 hour.";
    } else {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->execute([$uid]);
        $user_pass = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!password_verify($current_password, $user_pass['password'])) {
            $error = "Current password is incorrect.";
            $stmt  = $pdo->prepare("INSERT INTO password_history (user_id, password_hash, changed_at) VALUES (?, 'FAILED_ATTEMPT', NOW())");
            $stmt->execute([$uid]);
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (
            strlen($new_password) < 8 ||
            !preg_match('/[A-Z]/', $new_password) ||
            !preg_match('/[a-z]/', $new_password) ||
            !preg_match('/[0-9]/', $new_password) ||
            !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)
        ) {
            $error = "Password must be at least 8 characters and include uppercase, lowercase, number, and special character.";
        } elseif (isPasswordInHistory($pdo, $uid, $new_password)) {
            $error = "Cannot reuse any of your last 5 passwords. Please choose a different password.";
        } else {
            try {
                $pdo->beginTransaction();
                $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt->execute([$new_hash, $uid]);
                $stmt = $pdo->prepare("INSERT INTO password_history (user_id, password_hash, changed_at) VALUES (?, ?, NOW())");
                $stmt->execute([$uid, $new_hash]);
                $stmt = $pdo->prepare("DELETE FROM password_history WHERE user_id = ? AND history_id NOT IN (SELECT history_id FROM (SELECT history_id FROM password_history WHERE user_id = ? ORDER BY changed_at DESC LIMIT 10) as recent)");
                $stmt->execute([$uid, $uid]);
                $pdo->commit();
                $email = getUserEmail($pdo, $uid);
                if ($email) sendPasswordChangeNotification($email);
                $_SESSION['password_changed'] = time();
                $message = "Password changed successfully! An email notification has been sent.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "An error occurred. Please try again.";
            }
        }
    }
}

// ── PROFILE IMAGE FOR SIDEBAR ─────────────────────────────────
$hasProfileImage = false; $profileMime = 'image/jpeg';
$profileImageData = ''; $profileInitials = 'S';

if (!empty($user['profile_image'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $det   = $finfo->buffer($user['profile_image']);
    if ($det && strpos($det, 'image/') === 0) $profileMime = $det;
    $profileImageData = base64_encode($user['profile_image']);
    $hasProfileImage  = true;
}

$fn              = $user['first_name'] ?? 'S';
$ln              = $user['last_name']  ?? '';
$profileInitials = strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1)) ?: 'S';
$mn              = !empty($user['middle_name']) ? ' ' . strtoupper(substr(trim($user['middle_name']), 0, 1)) . '.' : '';
$welcomeName     = trim($fn . $mn . ' ' . $ln) ?: 'Student';
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Settings | SEMS</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/CSS/student_settings.css">

  <!-- Sora + Plus Jakarta Sans -->
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
          }
        }
      }
    }
  </script>
</head>
<body>

  <!-- ════════════════════════════════════════════════
       SIDEBAR
       ════════════════════════════════════════════════ -->
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">

    <div class="sb-brand">
      <div class="sb-logo" aria-hidden="true">
        <i data-lucide="graduation-cap" style="width:18px;height:18px;color:#fff;"></i>
      </div>
      <div>
        <div class="sb-name">SEMS</div>
        <div class="sb-tagline">Student Portal</div>
      </div>
    </div>

    <nav aria-label="Site navigation">

      <div class="sb-section">Overview</div>
      <a href="student_dashboard.php" class="sb-link">
        <i data-lucide="layout-dashboard" style="width:15px;height:15px;"></i>
        Dashboard
      </a>

      <div class="sb-section">Events</div>
      <a href="student_event.php" class="sb-link">
        <i data-lucide="calendar-days" style="width:15px;height:15px;"></i>
        Browse Events
      </a>

      <div class="sb-section">Participation</div>
      <a href="student_attendance.php" class="sb-link">
        <i data-lucide="clipboard-list" style="width:15px;height:15px;"></i>
        Attendance History
      </a>
      <a href="student_myqr.php" class="sb-link">
        <i data-lucide="qr-code" style="width:15px;height:15px;"></i>
        My QR Code
      </a>
      <a href="student_feedback.php" class="sb-link">
        <i data-lucide="message-square" style="width:15px;height:15px;"></i>
        Feedback
      </a>

      <div class="sb-section">Account</div>
      <a href="student_settings.php" class="sb-link active" aria-current="page">
        <i data-lucide="settings" style="width:15px;height:15px;"></i>
        Settings
      </a>
    </nav>

    <div class="sb-footer">
      <div class="sb-user-pill">
        <div class="avatar">
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
        <i data-lucide="log-out" style="width:15px;height:15px;"></i>
        Sign Out
      </a>
    </div>

  </aside>

  <div class="overlay" id="overlay" onclick="closeSidebar()" aria-hidden="true"></div>


  <!-- ════════════════════════════════════════════════
       MOBILE HEADER
       ════════════════════════════════════════════════ -->
  <header class="mob-header" role="banner">
    <div style="display:flex;align-items:center;gap:.625rem;flex-shrink:0;">
      <button class="icon-btn" id="menuBtn" onclick="openSidebar()"
        aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar">
        <i data-lucide="menu" style="width:17px;height:17px;"></i>
      </button>
      <div class="sb-logo" style="width:28px;height:28px;border-radius:7px;">
        <i data-lucide="graduation-cap" style="width:13px;height:13px;color:#fff;"></i>
      </div>
      <span class="sb-name" style="font-size:.9rem;">SEMS</span>
    </div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0;">
      <button class="icon-btn" id="darkToggleMobile" onclick="toggleDark()" aria-label="Toggle dark mode">
        <i data-lucide="sun"  style="width:15px;height:15px;display:none;" id="sunIconM"></i>
        <i data-lucide="moon" style="width:15px;height:15px;"              id="moonIconM"></i>
      </button>
      <div class="avatar" style="width:32px;height:32px;border-radius:8px;">
        <?php if ($hasProfileImage): ?>
          <img src="data:<?= $profileMime ?>;base64,<?= $profileImageData ?>" alt="Profile photo">
        <?php else: ?>
          <?= $profileInitials ?>
        <?php endif; ?>
      </div>
    </div>
  </header>


  <!-- ════════════════════════════════════════════════
       MAIN CONTENT
       ════════════════════════════════════════════════ -->
  <main class="main">
    <div style="padding:2rem 1.5rem 4rem;max-width:900px;margin:0 auto;position:relative;z-index:1;">

      <!-- ── PAGE HEADER ──────────────────────────── -->
      <div class="anim-up" style="animation-delay:.04s;display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
        <div>
          <div class="page-eyebrow"><?= date('l, F j, Y') ?></div>
          <h1 class="page-title" style="font-size:clamp(1.75rem,4vw,2.2rem);">Account Settings</h1>
          <p style="font-size:.875rem;color:var(--ink3);margin-top:.35rem;">Manage your profile information and account security.</p>
        </div>
        <button class="dark-toggle" id="darkToggleDesktop" onclick="toggleDark()" style="flex-shrink:0;margin-top:.25rem;">
          <i data-lucide="sun"  id="sunD"  style="width:14px;height:14px;display:none;"></i>
          <i data-lucide="moon" id="moonD" style="width:14px;height:14px;"></i>
          <span>Theme</span>
        </button>
      </div>


      <!-- ── TAB BAR ────────────────────────────────── -->
      <div class="anim-up" style="animation-delay:.1s;margin-bottom:1.5rem;">
        <div class="tab-bar">
          <a href="?tab=profile" class="tab-btn <?= $activeTab === 'profile' ? 'active' : '' ?>">
            <i data-lucide="user" style="width:14px;height:14px;"></i>
            Profile
          </a>
          <a href="?tab=security" class="tab-btn <?= $activeTab === 'security' ? 'active' : '' ?>">
            <i data-lucide="shield" style="width:14px;height:14px;"></i>
            Security
          </a>
        </div>
      </div>


      <!-- ── ALERTS ─────────────────────────────────── -->
      <?php if ($message): ?>
        <div class="alert alert-success anim-up" style="animation-delay:.14s;">
          <i data-lucide="check-circle" style="width:18px;height:18px;flex-shrink:0;"></i>
          <?= htmlspecialchars($message) ?>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error anim-up" style="animation-delay:.14s;">
          <i data-lucide="alert-circle" style="width:18px;height:18px;flex-shrink:0;"></i>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>


      <!-- ════════════════════════════════════════════
           PROFILE TAB
           ════════════════════════════════════════════ -->
      <?php if ($activeTab === 'profile'): ?>

        <div class="card anim-up" style="animation-delay:.18s;">

          <div class="card-head">
            <div class="card-head-icon">
              <i data-lucide="user" style="width:14px;height:14px;"></i>
            </div>
            <div style="position:relative;z-index:1;">
              <div class="card-head-title">Profile Information</div>
              <div class="card-head-sub">Update your personal details</div>
            </div>
          </div>

          <form method="POST" enctype="multipart/form-data" style="padding:1.75rem;">

            <!-- Avatar upload -->
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:1.5rem;padding-bottom:1.5rem;margin-bottom:1.5rem;border-bottom:1px solid var(--border);">

              <div class="avatar-wrap" style="position:relative;flex-shrink:0;">
                <?php if (!empty($user['profile_image'])): ?>
                  <?php $mt3 = getImageMimeType($user['profile_image']); $b643 = base64_encode($user['profile_image']); ?>
                  <div style="width:96px;height:96px;border-radius:50%;overflow:hidden;border:3px solid var(--purple);box-shadow:0 4px 16px rgba(124,58,237,.2);">
                    <img src="data:<?= $mt3 ?>;base64,<?= $b643 ?>" style="width:100%;height:100%;object-fit:cover;" alt="Profile" id="avatarPreview">
                  </div>
                <?php else: ?>
                  <div class="avatar" id="avatarPreview" style="width:96px;height:96px;border-radius:50%;font-size:1.5rem;border:3px solid var(--purple);box-shadow:0 4px 16px rgba(124,58,237,.2);">
                    <?= strtoupper(substr($user['first_name'] ?? 'S', 0, 1) . substr($user['last_name'] ?? '', 0, 1)) ?>
                  </div>
                <?php endif; ?>
                <div class="avatar-overlay" onclick="document.getElementById('profile-input').click()">
                  <i data-lucide="camera" style="width:22px;height:22px;color:#fff;"></i>
                </div>
              </div>

              <div style="flex:1;min-width:200px;">
                <div style="font-weight:600;font-size:.875rem;color:var(--ink);margin-bottom:.5rem;">Profile Picture</div>
                <input type="file" id="profile-input" name="profile_image"
                  accept="image/jpeg,image/png,image/gif,image/webp" class="file-input">
                <p style="font-size:.72rem;color:var(--ink3);margin-top:.375rem;display:flex;align-items:center;gap:.3rem;">
                  <i data-lucide="info" style="width:11px;height:11px;"></i>
                  JPG, PNG, GIF, WebP — max 2 MB
                </p>
              </div>
            </div>

            <!-- Fields grid -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1.125rem;">

              <div>
                <div class="field-label"><i data-lucide="id-card" style="width:12px;height:12px;"></i>Student Number</div>
                <input class="field-input" type="text" value="<?= htmlspecialchars($user['student_number'] ?? 'N/A') ?>" readonly>
              </div>

              <div>
                <div class="field-label"><i data-lucide="building-2" style="width:12px;height:12px;"></i>Department</div>
                <input class="field-input" type="text" value="<?= htmlspecialchars($user['dept_name'] ?? 'N/A') ?>" readonly>
              </div>

              <div>
                <div class="field-label"><i data-lucide="mail" style="width:12px;height:12px;"></i>Email Address</div>
                <input class="field-input" type="email" value="<?= htmlspecialchars($user['email'] ?? 'N/A') ?>" disabled>
              </div>

              <div>
                <div class="field-label"><i data-lucide="user" style="width:12px;height:12px;"></i>First Name</div>
                <input class="field-input" type="text" name="first_name"
                  value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
              </div>

              <div>
                <div class="field-label"><i data-lucide="user" style="width:12px;height:12px;"></i>Middle Name</div>
                <input class="field-input" type="text" name="middle_name"
                  value="<?= htmlspecialchars($user['middle_name'] ?? '') ?>" placeholder="Optional">
              </div>

              <div>
                <div class="field-label"><i data-lucide="user" style="width:12px;height:12px;"></i>Last Name</div>
                <input class="field-input" type="text" name="last_name"
                  value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
              </div>

              <div>
                <div class="field-label"><i data-lucide="graduation-cap" style="width:12px;height:12px;"></i>Year Level</div>
                <select class="field-select" name="year_level">
                  <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $y): ?>
                    <option value="<?= $y ?>" <?= ($user['year_level'] ?? '') === $y ? 'selected' : '' ?>><?= $y ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div>
                <div class="field-label"><i data-lucide="users" style="width:12px;height:12px;"></i>Section</div>
                <input class="field-input" type="text" name="section"
                  value="<?= htmlspecialchars($user['section'] ?? '') ?>">
              </div>

              <div>
                <div class="field-label"><i data-lucide="phone" style="width:12px;height:12px;"></i>Phone Number</div>
                <div style="position:relative;">
                  <span class="phone-prefix">+63</span>
                  <input class="field-input" type="text" name="phone"
                    value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                    placeholder="9XX XXX XXXX" style="padding-left:2.75rem;">
                </div>
              </div>

            </div>

            <div class="card-divider" style="margin-top:1.5rem;"></div>
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;">
              <p style="font-size:.75rem;color:var(--ink3);display:flex;align-items:center;gap:.3rem;">
                <i data-lucide="clock" style="width:11px;height:11px;"></i>
                Last updated: <?= date('F j, Y') ?>
              </p>
              <button type="submit" name="update_profile" class="btn-save">
                <i data-lucide="save" style="width:15px;height:15px;"></i>
                Save Changes
              </button>
            </div>

          </form>
        </div>


      <?php else: ?>
      <!-- ════════════════════════════════════════════
           SECURITY TAB
           ════════════════════════════════════════════ -->

        <div style="display:flex;flex-direction:column;gap:1.25rem;">

          <!-- Security status banner -->
          <div class="sec-banner anim-up" style="animation-delay:.18s;display:flex;flex-wrap:wrap;align-items:center;gap:1.25rem;">
            <div class="sec-banner-icon">
              <i data-lucide="shield-check" style="width:26px;height:26px;"></i>
            </div>
            <div style="flex:1;min-width:0;">
              <div style="font-family:'Sora',sans-serif;font-weight:700;font-size:1rem;color:var(--green);margin-bottom:.375rem;">
                Account Security
              </div>
              <p style="font-size:.8rem;color:var(--ink2);">Your account is protected with advanced security features.</p>
              <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.5rem;margin-top:.625rem;">
                <span class="sec-check"><i data-lucide="check-circle" style="width:13px;height:13px;"></i>Password encryption enabled</span>
                <span class="sec-check"><i data-lucide="check-circle" style="width:13px;height:13px;"></i>Rate limiting active</span>
                <span class="sec-check"><i data-lucide="check-circle" style="width:13px;height:13px;"></i>Email notifications enabled</span>
              </div>
            </div>
          </div>


          <!-- Change Password card -->
          <div class="card anim-up" style="animation-delay:.24s;">

            <div class="card-head">
              <div class="card-head-icon">
                <i data-lucide="lock" style="width:14px;height:14px;"></i>
              </div>
              <div style="position:relative;z-index:1;">
                <div class="card-head-title">Change Password</div>
                <div class="card-head-sub">Update your password to keep your account secure</div>
              </div>
            </div>

            <form method="POST" id="passwordForm" style="padding:1.75rem;display:flex;flex-direction:column;gap:1.25rem;">

              <!-- Current password -->
              <div>
                <div class="field-label"><i data-lucide="key" style="width:12px;height:12px;"></i>Current Password</div>
                <div style="position:relative;">
                  <input class="field-input" type="password" name="current_password" id="current_password"
                    required placeholder="Enter your current password" autocomplete="off" style="padding-right:3rem;">
                  <button type="button" onclick="togglePassword('current_password')"
                    style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink3);padding:.25rem;border-radius:5px;transition:color .15s;"
                    onmouseover="this.style.color='var(--purple)'" onmouseout="this.style.color='var(--ink3)'">
                    <svg id="icon-current_password" xmlns="http://www.w3.org/2000/svg" width="17" height="17"
                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                  </button>
                </div>
              </div>

              <!-- New password -->
              <div>
                <div class="field-label"><i data-lucide="lock" style="width:12px;height:12px;"></i>New Password</div>
                <div style="position:relative;">
                  <input class="field-input" type="password" name="new_password" id="new_password"
                    required placeholder="Enter new password" autocomplete="new-password" style="padding-right:3rem;">
                  <button type="button" onclick="togglePassword('new_password')"
                    style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink3);padding:.25rem;border-radius:5px;transition:color .15s;"
                    onmouseover="this.style.color='var(--purple)'" onmouseout="this.style.color='var(--ink3)'">
                    <svg id="icon-new_password" xmlns="http://www.w3.org/2000/svg" width="17" height="17"
                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                  </button>
                </div>
                <!-- Strength meter -->
                <div style="margin-top:.625rem;">
                  <div class="strength-track"><div id="strength-bar"></div></div>
                  <p id="strength-text" style="font-size:.72rem;margin-top:.35rem;font-weight:600;color:var(--ink3);">Enter a password to see strength</p>
                </div>
              </div>

              <!-- Confirm password -->
              <div>
                <div class="field-label"><i data-lucide="check-circle" style="width:12px;height:12px;"></i>Confirm New Password</div>
                <div style="position:relative;">
                  <input class="field-input" type="password" name="confirm_password" id="confirm_password"
                    required placeholder="Confirm new password" autocomplete="new-password" style="padding-right:3rem;">
                  <button type="button" onclick="togglePassword('confirm_password')"
                    style="position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--ink3);padding:.25rem;border-radius:5px;transition:color .15s;"
                    onmouseover="this.style.color='var(--purple)'" onmouseout="this.style.color='var(--ink3)'">
                    <svg id="icon-confirm_password" xmlns="http://www.w3.org/2000/svg" width="17" height="17"
                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                  </button>
                </div>
                <p id="match-text" style="font-size:.72rem;margin-top:.3rem;font-weight:600;display:none;"></p>
              </div>

              <!-- Requirements checklist -->
              <div class="req-block">
                <div class="req-title">
                  <i data-lucide="info" style="width:12px;height:12px;"></i>
                  Password Requirements
                </div>
                <div style="display:flex;flex-direction:column;gap:.45rem;">
                  <div id="req-length" class="req-item">
                    <span class="req-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg></span>
                    At least 8 characters
                  </div>
                  <div id="req-uppercase" class="req-item">
                    <span class="req-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg></span>
                    One uppercase letter (A-Z)
                  </div>
                  <div id="req-lowercase" class="req-item">
                    <span class="req-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg></span>
                    One lowercase letter (a-z)
                  </div>
                  <div id="req-number" class="req-item">
                    <span class="req-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg></span>
                    One number (0-9)
                  </div>
                  <div id="req-special" class="req-item">
                    <span class="req-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg></span>
                    One special character (!@#$%^&amp;*)
                  </div>
                  <div class="req-item" style="color:var(--ink2);">
                    <i data-lucide="shield" style="width:13px;height:13px;flex-shrink:0;"></i>
                    Must not be one of your last 5 passwords
                  </div>
                </div>
              </div>

              <div class="card-divider" style="margin:0;"></div>
              <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;">
                <p style="font-size:.72rem;color:var(--ink3);display:flex;align-items:center;gap:.3rem;">
                  <i data-lucide="clock" style="width:11px;height:11px;"></i>
                  Limited to 3 changes per hour for security
                </p>
                <button type="submit" name="change_password" id="submitBtn" disabled class="btn-save">
                  <i data-lucide="save" style="width:15px;height:15px;"></i>
                  Update Password
                </button>
              </div>

            </form>
          </div>


          <!-- Security tips -->
          <div class="tips-block anim-up" style="animation-delay:.3s;display:flex;align-items:flex-start;gap:1rem;">
            <div class="tips-icon">
              <i data-lucide="lightbulb" style="width:19px;height:19px;"></i>
            </div>
            <div>
              <div class="tips-title">Security Tips</div>
              <div style="display:flex;flex-direction:column;gap:.5rem;">
                <div class="tips-item">
                  <i data-lucide="check" style="width:13px;height:13px;flex-shrink:0;margin-top:.1rem;color:var(--amber);"></i>
                  Use a unique password that you don't use on other websites
                </div>
                <div class="tips-item">
                  <i data-lucide="check" style="width:13px;height:13px;flex-shrink:0;margin-top:.1rem;color:var(--amber);"></i>
                  Consider using a passphrase (e.g., "Correct-Horse-Battery-Staple!")
                </div>
                <div class="tips-item">
                  <i data-lucide="check" style="width:13px;height:13px;flex-shrink:0;margin-top:.1rem;color:var(--amber);"></i>
                  Never share your password with anyone, including administrators
                </div>
              </div>
            </div>
          </div>

        </div>

      <?php endif; ?>

    </div>
  </main>

  <script src="/js/student_settings.js"></script>

</body>
</html>