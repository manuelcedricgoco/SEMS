<?php
/*
 * ============================================================
 *  student_myqr.php — SEMS (Redesigned)
 * ============================================================
 */
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];

// ── DEFAULT VALUES ────────────────────────────────────────────
$welcomeName     = "Student";
$studentID       = "N/A";
$departments     = "N/A";
$yearLevel       = "N/A";
$section         = "N/A";
$imageData       = null;
$imageMime       = 'image/jpeg';
$profileInitials = 'S';

// ── FETCH STUDENT INFO ────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.first_name, p.last_name, p.middle_name,
           p.student_number, p.year_level, p.section, p.profile_image,
           d.dept_name
    FROM users u
    LEFT JOIN profiles p     ON u.user_id = p.user_id
    LEFT JOIN departments d  ON u.dept_id = d.dept_id
    WHERE u.user_id = :uid
");
$stmt->execute(['uid' => $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $mn = !empty($row['middle_name'])
        ? ' ' . strtoupper(substr(trim($row['middle_name']), 0, 1)) . '.'
        : '';
    $welcomeName = trim(($row['first_name'] ?? '') . $mn . ' ' . ($row['last_name'] ?? '')) ?: 'Student';
    $studentID   = $row['student_number'] ?? "N/A";
    $departments = $row['dept_name']      ?? "N/A";
    $yearLevel   = $row['year_level']     ?? "N/A";
    $section     = $row['section']        ?? "N/A";

    if (!empty($row['profile_image'])) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($row['profile_image']);
        if ($detected && strpos($detected, 'image/') === 0) $imageMime = $detected;
        $imageData = base64_encode($row['profile_image']);
    }
}

// ── PROFILE INITIALS ──────────────────────────────────────────
$nameParts       = explode(' ', trim($welcomeName));
$profileInitials = strtoupper(
    substr($nameParts[0] ?? 'S', 0, 1) . substr($nameParts[1] ?? '', 0, 1)
) ?: 'S';

// ── QR CODE DATA ──────────────────────────────────────────────
$qrContent = "USER_ID: $uid | Student: $welcomeName | Yr/Sec: $yearLevel-$section";

$stmtQR = $pdo->prepare("SELECT qr_value FROM student_qr_codes WHERE user_id = :uid LIMIT 1");
$stmtQR->execute(['uid' => $uid]);
$rowQR = $stmtQR->fetch(PDO::FETCH_ASSOC);

if ($rowQR) {
    $qrData = $rowQR['qr_value'];
} else {
    $qrData     = $qrContent;
    $stmtInsert = $pdo->prepare("INSERT INTO student_qr_codes (user_id, qr_value) VALUES (:uid, :qr)");
    $stmtInsert->execute(['uid' => $uid, 'qr' => $qrData]);
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My QR Code | SEMS</title>

  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Sora + Plus Jakarta Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">

  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="/CSS/student_myqr.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

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
      <div class="sb-logo" aria-hidden="true"
     style="background: linear-gradient(135deg, #6d28d9 0%, #7c3aed 40%, #a855f7 100%);
            box-shadow: 0 4px 14px rgba(139,92,246,.55), 0 0 0 3px rgba(167,139,250,.18);
            border: 1px solid rgba(167,139,250,.3);">
    <i data-lucide="graduation-cap" style="width:18px;height:18px;color:#fff;filter:drop-shadow(0 1px 3px rgba(0,0,0,.3));"></i>
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
      <a href="student_myqr.php" class="sb-link active" aria-current="page">
        <i data-lucide="qr-code" style="width:15px;height:15px;"></i>
        My QR Code
      </a>
      <a href="student_feedback.php" class="sb-link">
        <i data-lucide="message-square" style="width:15px;height:15px;"></i>
        Feedback
      </a>

      <a href="student_chat.php" class="sb-link " aria-current="page">
        <i data-lucide="message-circle" style="width:15px;height:15px;"></i>
        Messages
        <span id="sidebarBadge" style="display:none;margin-left:auto;background:var(--purple);color:#fff;border-radius:999px;font-size:.65rem;font-weight:700;padding:.1rem .45rem;"></span>
      </a>

      <div class="sb-section">Account</div>
      <a href="student_settings.php" class="sb-link">
        <i data-lucide="settings" style="width:15px;height:15px;"></i>
        Settings
      </a>
    </nav>

    <div class="sb-footer">
      <div class="sb-user-pill">
        <div class="avatar">
          <?php if ($imageData): ?>
            <img src="data:<?= $imageMime ?>;base64,<?= $imageData ?>" alt="Profile photo">
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
      <div class="avatar" style="width:30px;height:30px;border-radius:8px;">
        <?php if ($imageData): ?>
          <img src="data:<?= $imageMime ?>;base64,<?= $imageData ?>" alt="Profile photo">
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

    <!-- ── PAGE HEADER ──────────────────────────────── -->
    <div class="w-full mb-8 anim-up" style="max-width:440px;animation-delay:.04s;">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;">
        <div>
          <div class="page-eyebrow mb-1"><?= date('l, F j, Y') ?></div>
          <h1 class="page-title" style="font-size:clamp(1.5rem,3.5vw,1.9rem);display:flex;align-items:center;gap:.625rem;">
            <!-- Icon badge -->
            <span style="width:36px;height:36px;border-radius:9px;background:var(--purplebg);border:1px solid var(--purplebdr);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
              <i data-lucide="qr-code" style="width:16px;height:16px;color:var(--purple);"></i>
            </span>
            My QR Code
          </h1>
          <p style="font-size:.875rem;color:var(--ink3);margin-top:.4rem;line-height:1.55;">
            Present this QR to the event organizer to mark your attendance.
          </p>
        </div>
        <button class="dark-toggle" style="flex-shrink:0;margin-top:.25rem;"
          id="darkToggleDesktop" onclick="toggleDark()">
          <i data-lucide="sun"  id="sunD"  style="width:15px;height:15px;display:none;"></i>
          <i data-lucide="moon" id="moonD" style="width:15px;height:15px;"></i>
        </button>
      </div>
    </div>


    <!-- ── QR CARD ───────────────────────────────────── -->
    <div class="qr-card w-full anim-card" style="max-width:440px;animation-delay:.12s;">

      <!-- Card Header -->
      <div class="qr-card-header">
        <div style="position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;gap:1.125rem;">

          <!-- Avatar ring -->
          <div class="avatar-ring">
            <div style="position:relative;z-index:1;width:90px;height:90px;border-radius:50%;overflow:hidden;
                        border:3px solid var(--surface);
                        background:linear-gradient(135deg,#7c3aed,#a78bfa);
                        display:flex;align-items:center;justify-content:center;
                        font-family:'Sora',sans-serif;font-weight:700;font-size:1.5rem;color:#fff;">
              <?php if ($imageData): ?>
                <img src="data:<?= $imageMime ?>;base64,<?= $imageData ?>" alt="Profile" style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <?= htmlspecialchars($profileInitials) ?>
              <?php endif; ?>
            </div>
          </div>

          <!-- Name + meta pills -->
          <div style="text-align:center;">
            <h2 style="font-family:'Sora',sans-serif;font-weight:800;font-size:1.25rem;
                       color:#f1f5f9;letter-spacing:-.02em;line-height:1.15;margin-bottom:.75rem;">
              <?= htmlspecialchars($welcomeName) ?>
            </h2>

            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.45rem;">

              <!-- Student ID -->
              <span class="meta-pill">
                <i data-lucide="id-card" style="width:11px;height:11px;"></i>
                <?= htmlspecialchars($studentID) ?>
              </span>

              <!-- Year & Section -->
              <span class="meta-pill">
                <i data-lucide="layers" style="width:11px;height:11px;"></i>
                <?= htmlspecialchars($yearLevel . ' — ' . $section) ?>
              </span>

              <!-- Department — teal accent -->
              <span class="meta-pill" style="background:rgba(13,148,136,.18);border-color:rgba(45,212,191,.3);color:#5eead4;">
                <i data-lucide="building-2" style="width:11px;height:11px;"></i>
                <?= htmlspecialchars($departments) ?>
              </span>

            </div>
          </div>

        </div>
      </div><!-- /card header -->


      <!-- Card Body -->
      <div style="padding:1.75rem 2rem 1.5rem;">

        <!-- QR code area -->
        <div class="qr-bg mb-5">
          <div id="qrCanvas" style="display:flex;align-items:center;justify-content:center;position:relative;z-index:1;"></div>
        </div>

        <!-- Hint strip -->
        <div class="hint-strip">
          <div class="hint-icon">
            <i data-lucide="scan-line" style="width:16px;height:16px;"></i>
          </div>
          <p style="font-size:.8rem;color:var(--ink2);line-height:1.55;">
            Show this QR code to the
            <strong style="color:var(--ink);font-weight:700;">event organizer</strong>
            at the entrance for quick attendance marking.
          </p>
        </div>

      </div><!-- /card body -->


      <!-- Card Footer -->
      <div class="qr-card-footer">
        <i data-lucide="shield-check" style="width:14px;height:14px;color:var(--green);"></i>
        <span>Secure &bull; Unique &bull; Personal</span>
      </div>

    </div><!-- /qr-card -->


    <!-- ── DOWNLOAD BUTTON ───────────────────────────── -->
    <button class="btn-download mt-6 anim-up"
      style="animation-delay:.24s;margin-top:1.5rem;"
      onclick="downloadQR()">
      <i data-lucide="download" style="width:17px;height:17px;"></i>
      Download QR Code
    </button>

  </main>


  <!-- QR data bridge -->
  <script>
    const SEMS_QR = {
      qrData: <?= json_encode($qrData ?? 'NO_QR') ?>
    };
  </script>
  <script src="/js/student_myqr.js"></script>

</body>
</html>