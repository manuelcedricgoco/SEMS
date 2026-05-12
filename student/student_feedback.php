<?php
/*
 * ============================================================
 *  student_feedback.php — SEMS (Redesigned)
 * ============================================================
 */
ob_start();
error_reporting(0);
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];

// ── STUDENT NAME ─────────────────────────────────────────────
$welcomeName = 'Student';
$stmt = $pdo->prepare('SELECT first_name, middle_name, last_name FROM profiles WHERE user_id = :uid LIMIT 1');
$stmt->execute(['uid' => $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $mn = !empty($row['middle_name']) ? ' ' . strtoupper(substr(trim($row['middle_name']), 0, 1)) . '.' : '';
    $welcomeName = trim(($row['first_name'] ?? '') . $mn . ' ' . ($row['last_name'] ?? '')) ?: 'Student';
}

// ── PROFILE IMAGE ─────────────────────────────────────────────
$hasProfileImage = false; $profileMime = 'image/jpeg';
$profileImageData = ''; $profileInitials = 'S';

$stmtImg = $pdo->prepare("SELECT first_name, last_name, profile_image FROM profiles WHERE user_id = :uid LIMIT 1");
$stmtImg->execute(['uid' => $uid]);
$profileRow = $stmtImg->fetch(PDO::FETCH_ASSOC);
if ($profileRow) {
    $fn = $profileRow['first_name'] ?? 'S';
    $ln = $profileRow['last_name']  ?? '';
    $profileInitials = strtoupper(substr($fn, 0, 1) . substr($ln, 0, 1));
    if (!empty($profileRow['profile_image'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $det   = $finfo->buffer($profileRow['profile_image']);
        if ($det && strpos($det, 'image/') === 0) $profileMime = $det;
        $profileImageData = base64_encode($profileRow['profile_image']);
        $hasProfileImage  = true;
    }
}

// ── DEPT & CLUB ───────────────────────────────────────────────
$stmtUser = $pdo->prepare("SELECT dept_id, club_id FROM users WHERE user_id = :uid LIMIT 1");
$stmtUser->execute(['uid' => $uid]);
$urow = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
$studentDept = $urow['dept_id'] ?? null;
$studentClub = isset($urow['club_id']) && $urow['club_id'] !== '' && $urow['club_id'] !== null
    ? (int) $urow['club_id'] : null;

// ── DEPT NAME ─────────────────────────────────────────────────
$studentDeptName = null;
if ($studentDept) {
    $stmtDn = $pdo->prepare("SELECT dept_name FROM departments WHERE dept_id = :did LIMIT 1");
    $stmtDn->execute(['did' => $studentDept]);
    $studentDeptName = $stmtDn->fetchColumn() ?: null;
}

// ── ORG WHITELIST ─────────────────────────────────────────────
$deptOrgMap = [
    'Bachelor of Elementary Education' => 'Youth Mentors Organization',
    'BS Information Technology'        => 'Programmers Animators Developers Clan',
    'BS Financial Management'          => 'Junior Financial Managers Society',
    'BS Operational Management'        => 'Junior Operations Executive Society',
];
$universalOrgs  = ['Supreme Student Government', 'Library Student Council'];
$allowedOrgNames = $universalOrgs;
if ($studentDeptName && isset($deptOrgMap[$studentDeptName])) {
    $allowedOrgNames[] = $deptOrgMap[$studentDeptName];
}

// ── EVENTS ───────────────────────────────────────────────────
$orgPlaceholders = implode(',', array_fill(0, count($allowedOrgNames), '?'));
$stmtEvents = $pdo->prepare("
    SELECT e.event_id, e.title FROM events e
    INNER JOIN registrations r ON r.event_id = e.event_id
    LEFT JOIN organizations o  ON e.org_id   = o.org_id
    LEFT JOIN clubs c          ON e.club_id  = c.club_id
    WHERE r.user_id = ?
      AND (o.org_name IN ($orgPlaceholders) OR (e.org_id IS NULL AND (e.club_id IS NULL OR e.club_id = ?)))
    ORDER BY e.start_datetime DESC
");
$stmtEvents->execute(array_merge([$uid], $allowedOrgNames, [$studentClub]));
$events = $stmtEvents->fetchAll(PDO::FETCH_ASSOC);

// ── SUBMITTED IDs ─────────────────────────────────────────────
$stmtDone = $pdo->prepare("
    SELECT DISTINCT f.event_id FROM feedback f
    INNER JOIN feedback_ratings fr ON f.feedback_id = fr.feedback_id
    WHERE f.user_id = :uid
");
$stmtDone->execute(['uid' => $uid]);
$submittedEventIds = array_map('intval', $stmtDone->fetchAll(PDO::FETCH_COLUMN));

// ── CATEGORIES ────────────────────────────────────────────────
$stmt       = $pdo->query("SELECT category_id, category_name FROM feedback_categories ORDER BY category_name ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── AJAX SUBMISSION HANDLER ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_feedback') {
    $event_id        = (int)($_POST['event_id'] ?? 0);
    $ratings         = $_POST['ratings']  ?? [];
    $comments        = $_POST['comments'] ?? [];
    $allowedEventIds = array_column($events, 'event_id');

    if (!in_array($event_id, array_map('intval', $allowedEventIds), true)) {
        ob_end_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'msg' => 'You are not allowed to submit feedback for this event.']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT f.feedback_id FROM feedback f INNER JOIN feedback_ratings fr ON f.feedback_id = fr.feedback_id WHERE f.user_id = :uid AND f.event_id = :eid LIMIT 1");
    $stmt->execute(['uid' => $uid, 'eid' => $event_id]);
    if ($stmt->fetch()) {
        ob_end_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'already_submitted', 'msg' => 'You have already submitted feedback for this event.']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM feedback WHERE user_id = :uid AND event_id = :eid");
    $stmt->execute(['uid' => $uid, 'eid' => $event_id]);
    $stmt = $pdo->prepare("INSERT INTO feedback (event_id, user_id) VALUES (:eid, :uid)");
    $stmt->execute(['eid' => $event_id, 'uid' => $uid]);
    $feedback_id = $pdo->lastInsertId();
    $stmt = $pdo->prepare("INSERT INTO feedback_ratings (feedback_id, category_id, rating, comment) VALUES (:fid, :cid, :rating, :comment)");
    foreach ($ratings as $cat_id => $rating) {
        $comment = trim($comments[$cat_id] ?? '');
        $stmt->execute(['fid' => $feedback_id, 'cid' => $cat_id, 'rating' => $rating, 'comment' => $comment]);
    }
    ob_end_clean(); header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'msg' => 'Feedback submitted successfully!']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Feedback | SEMS</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/CSS/student_feedback.css">

  <!-- Sora + Plus Jakarta Sans -->
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">

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
  <script src="https://unpkg.com/lucide@latest"></script>
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
      <a href="student_feedback.php" class="sb-link active" aria-current="page">
        <i data-lucide="message-square" style="width:15px;height:15px;"></i>
        Feedback
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
    <div style="padding:2rem 1.5rem 4rem;max-width:760px;margin:0 auto;position:relative;z-index:1;">

      <!-- ── PAGE HEADER ──────────────────────────── -->
      <div class="anim-up" style="animation-delay:.04s;margin-bottom:2rem;">
        <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;">

          <div>
            <div class="page-eyebrow" style="margin-bottom:.3rem;"><?= date('l, F j, Y') ?></div>

            <h1 class="page-title" style="font-size:clamp(1.6rem,4vw,2.1rem);display:flex;align-items:center;gap:.75rem;">
              <!-- Icon badge -->
              <span style="width:40px;height:40px;border-radius:10px;background:var(--purplebg);border:1px solid var(--purplebdr);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i data-lucide="message-square" style="width:18px;height:18px;color:var(--purple);"></i>
              </span>
              Submit Feedback
            </h1>

            <p class="page-sub">Share your experience about events you attended.</p>
          </div>

          <!-- Desktop controls -->
          <div style="display:flex;align-items:center;gap:.625rem;flex-shrink:0;padding-top:.25rem;">
            <button class="dark-toggle" id="darkToggleDesktop" onclick="toggleDark()">
              <i data-lucide="sun"  id="sunD"  style="width:14px;height:14px;display:none;"></i>
              <i data-lucide="moon" id="moonD" style="width:14px;height:14px;"></i>
              Theme
            </button>
            <div class="avatar" style="width:36px;height:36px;border-radius:9px;font-size:.82rem;display:none;" id="headerAvatar">
              <?php if ($hasProfileImage): ?>
                <img src="data:<?= $profileMime ?>;base64,<?= $profileImageData ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
              <?php else: ?>
                <?= $profileInitials ?>
              <?php endif; ?>
            </div>
          </div>

        </div>
      </div>


      <!-- ── FORM CARD ─────────────────────────────── -->
      <div class="form-card anim-up" style="animation-delay:.12s;">

        <!-- Card header -->
        <div class="form-card-head">
          <div class="form-card-head-title">
            <div class="form-card-head-icon">
              <i data-lucide="clipboard-pen" style="width:14px;height:14px;"></i>
            </div>
            Event Feedback Form
          </div>
        </div>

        <div style="padding:1.75rem 1.75rem 1.5rem;">

          <!-- ── EVENT SELECTOR ────────────────────── -->
          <div style="margin-bottom:1.75rem;">
            <div class="field-label">
              <i data-lucide="calendar-days" style="width:13px;height:13px;"></i>
              Select Event
            </div>

            <select id="eventSelect" class="field-select">
              <option value="">Choose an event…</option>
              <?php foreach ($events as $e): ?>
                <?php $done = in_array((int)$e['event_id'], $submittedEventIds, true); ?>
                <option value="<?= $e['event_id'] ?>" data-submitted="<?= $done ? '1' : '0' ?>">
                  <?= htmlspecialchars($e['title']) ?>
                  <?= $done ? ' ✓ (Feedback submitted)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>

            <!-- Already submitted alert -->
            <div id="alreadySubmittedAlert" style="display:none;margin-top:.75rem;">
              <div style="display:flex;align-items:center;gap:.625rem;padding:.75rem 1rem;
                          background:var(--greenbg);border:1px solid var(--greenbdr);
                          border-radius:var(--radius-sm);border-left:3px solid var(--green);">
                <i data-lucide="check-circle-2" style="width:16px;height:16px;color:var(--green);flex-shrink:0;"></i>
                <div>
                  <div style="font-size:.82rem;font-weight:700;color:var(--green);">Feedback Already Submitted</div>
                  <div style="font-size:.76rem;color:var(--green);opacity:.8;margin-top:.1rem;">
                    You have already submitted feedback for this event. Thank you!
                  </div>
                </div>
              </div>
            </div>

            <?php if (empty($events)): ?>
              <div style="margin-top:.625rem;">
                <span class="warn-pill">
                  <i data-lucide="alert-circle" style="width:11px;height:11px;"></i>
                  You haven't registered for any events yet.
                </span>
              </div>
            <?php endif; ?>
          </div>

          <div class="card-divider"></div>

          <!-- ── CATEGORY BLOCKS ───────────────────── -->
          <?php if (!empty($categories)): ?>
            <div style="display:flex;flex-direction:column;gap:1rem;">

              <?php foreach ($categories as $idx => $cat): ?>
                <div class="cat-block anim-up" style="animation-delay:<?= .18 + $idx * .07 ?>s;">

                  <div class="cat-name">
                    <span class="cat-num"><?= $idx + 1 ?></span>
                    <?= htmlspecialchars($cat['category_name']) ?>
                  </div>

                  <!-- Rating -->
                  <div style="margin-bottom:.75rem;">
                    <div class="field-label" style="margin-bottom:.4rem;">
                      <i data-lucide="star" style="width:12px;height:12px;"></i>
                      Rating
                    </div>
                    <select class="field-select rating-select" data-cat="<?= $cat['category_id'] ?>">
                      <option value="">Select rating…</option>
                      <option value="1">⭐ Poor</option>
                      <option value="2">⭐⭐ Fair</option>
                      <option value="3">⭐⭐⭐ Good</option>
                      <option value="4">⭐⭐⭐⭐ Very Good</option>
                      <option value="5">⭐⭐⭐⭐⭐ Excellent</option>
                    </select>
                  </div>

                  <!-- Comment -->
                  <div>
                    <div class="field-label" style="margin-bottom:.4rem;">
                      <i data-lucide="message-circle" style="width:12px;height:12px;"></i>
                      Comment
                      <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--ink4);font-size:.68rem;">(optional)</span>
                    </div>
                    <textarea
                      class="field-textarea comment-textarea"
                      data-cat="<?= $cat['category_id'] ?>"
                      placeholder="Share your thoughts on <?= htmlspecialchars($cat['category_name']) ?>…"
                      style="min-height:90px;resize:vertical;"></textarea>
                  </div>

                </div>
              <?php endforeach; ?>

            </div>

          <?php else: ?>
            <!-- No categories fallback -->
            <div style="text-align:center;padding:2.5rem 1rem;">
              <div style="width:52px;height:52px;border-radius:13px;background:var(--surface2);border:1px solid var(--border);
                          display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;color:var(--ink4);">
                <i data-lucide="inbox" style="width:22px;height:22px;"></i>
              </div>
              <p style="color:var(--ink3);font-size:.875rem;">No feedback categories available.</p>
            </div>
          <?php endif; ?>

          <!-- ── ACTION BUTTONS ────────────────────── -->
          <div class="card-divider" style="margin-top:1.75rem;"></div>
          <div style="display:flex;flex-wrap:wrap;gap:.75rem;">
            <button class="btn-clear" onclick="clearForm()">
              <i data-lucide="rotate-ccw" style="width:14px;height:14px;"></i>
              Clear
            </button>
            <button class="btn-submit" id="submitBtn"
              onclick="submitFeedback()"
              <?= empty($events) ? 'disabled' : '' ?>>
              <i data-lucide="send" style="width:14px;height:14px;"></i>
              Submit Feedback
            </button>
          </div>

        </div>
      </div><!-- /form-card -->


      <!-- Help text -->
      <div style="text-align:center;margin-top:1.25rem;padding-bottom:1rem;" class="anim-up">
        <p style="font-size:.78rem;color:var(--ink3);display:flex;align-items:center;justify-content:center;gap:.35rem;">
          <i data-lucide="info" style="width:13px;height:13px;"></i>
          Your feedback helps us improve future events.
        </p>
      </div>

    </div>
  </main>


  <!-- ════════════════════════════════════════════════
       TOAST NOTIFICATION
       ════════════════════════════════════════════════ -->
  <div id="toast" class="toast" role="alert">
    <i data-lucide="check-circle" style="width:17px;height:17px;flex-shrink:0;"></i>
    <span id="toastMsg"></span>
  </div>

  <script src="/js/student_feedback.js"></script>

</body>
</html>