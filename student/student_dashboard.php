<?php
// ============================================================
// STUDENT DASHBOARD — SEMS
// ============================================================
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

// ── ARCHIVED SESSION GUARD — add this below the existing guard ──
$archivedCheck = $pdo->prepare("SELECT deleted_at FROM users WHERE user_id = ? LIMIT 1");
$archivedCheck->execute([$_SESSION['user_id']]);
$archivedRow = $archivedCheck->fetch(PDO::FETCH_ASSOC);

if (!$archivedRow || !empty($archivedRow['deleted_at'])) {
    session_destroy();
    header("Location: ../includes/auth.php?error=archived");
    exit();
}

// ── ORG/CLUB ARCHIVED GUARD ──────────────────────────────────
$orgClubArchivedCheck = $pdo->prepare("
    SELECT
        o.deleted_at AS org_deleted,
        c.deleted_at AS club_deleted
    FROM users u
    LEFT JOIN organizations o ON u.org_id  = o.org_id
    LEFT JOIN clubs         c ON u.club_id = c.club_id
    WHERE u.user_id = ?
    LIMIT 1
");
$orgClubArchivedCheck->execute([$_SESSION['user_id']]);
$orgClubRow = $orgClubArchivedCheck->fetch(PDO::FETCH_ASSOC);

if ($orgClubRow && (!empty($orgClubRow['org_deleted']) || !empty($orgClubRow['club_deleted']))) {
    session_destroy();
    header("Location: ../includes/auth.php?error=org_archived");
    exit();
}

$uid = (int) $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

// ── STUDENT NAME ─────────────────────────────────────────────
$welcomeName = 'Student';
$stmt = $pdo->prepare("SELECT first_name, middle_name, last_name FROM profiles WHERE user_id = :uid");
$stmt->execute(['uid' => $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $mn = !empty($row['middle_name'])
        ? ' ' . strtoupper(substr(trim($row['middle_name']), 0, 1)) . '.'
        : '';
    $welcomeName = trim($row['first_name'] . $mn . ' ' . $row['last_name']) ?: 'Student';
}

// ── PROFILE IMAGE ─────────────────────────────────────────────
$hasProfileImage = false;
$profileMime = 'image/jpeg';
$profileImageData = '';
$profileInitials = 'S';

$stmtImg = $pdo->prepare("SELECT first_name, last_name, profile_image FROM profiles WHERE user_id = :uid");
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

// ── AFFILIATIONS ──────────────────────────────────────────────
$stmtUser = $pdo->prepare("SELECT dept_id, org_id, club_id FROM users WHERE user_id = :uid");
$stmtUser->execute(['uid' => $uid]);
$userInfo    = $stmtUser->fetch(PDO::FETCH_ASSOC);
$studentDept = $userInfo['dept_id'] ?? null;
$studentOrg  = $userInfo['org_id']  ?? null;
$studentClub = $userInfo['club_id'] ?? null;


/* =============================================================================
 * ANNOUNCEMENTS — scoped visibility (same logic as events)
 *
 *   visibility = 'all'  → SSG / LSC posts → every student sees it
 *   visibility = 'dept' → only if student's dept_id matches announcement's dept_id
 *                         (PAD Clan→BSIT, JFMS→BSFM, YMO→BEd, JOES→BSOM)
 *   visibility = 'club' → only if student's club_id matches announcement's club_id
 *                         (Sci-Math, P2P, English Club, Samfilko, UMSO, CYMA)
 * ============================================================================= */
$annConditions = ["a.visibility = 'all'"]; // always show school-wide (SSG, LSC)
$annParams     = [];

if ($studentDept) {
    $annConditions[] = "(a.visibility = 'dept' AND a.dept_id = ?)";
    $annParams[]     = $studentDept;
}
if ($studentClub) {
    $annConditions[] = "(a.visibility = 'club' AND a.club_id = ?)";
    $annParams[]     = $studentClub;
}

$annWhere = implode(' OR ', $annConditions);

$annStmt = $pdo->prepare("
    SELECT
        a.announcement_id,
        a.title,
        a.body,
        a.visibility,
        a.is_pinned,
        a.created_at,
        COALESCE(o.org_name, c.club_name, 'General') AS source_name,
        COALESCE(org_t.first_name, prof.first_name, u.email) AS author_first,
        COALESCE(org_t.last_name,  prof.last_name,  '')      AS author_last
    FROM announcements a
    JOIN users u              ON a.organizer_id = u.user_id
    LEFT JOIN organizer org_t ON u.user_id = org_t.user_id
    LEFT JOIN profiles  prof  ON u.user_id = prof.user_id
    LEFT JOIN organizations o ON a.org_id   = o.org_id
    LEFT JOIN clubs         c ON a.club_id  = c.club_id
    WHERE a.deleted_at IS NULL
      AND ($annWhere)
    ORDER BY a.is_pinned DESC, a.created_at DESC
    LIMIT 5
");
$annStmt->execute($annParams);
$announcements = $annStmt->fetchAll(PDO::FETCH_ASSOC);
$annCount      = count($announcements);

// Announcement visibility badge map
$annVisBadge = [
    'all'  => ['label' => 'School-wide', 'cls' => 'pill-green'],
    'dept' => ['label' => 'Department',  'cls' => 'pill-purple'],
    'club' => ['label' => 'Club',        'cls' => 'pill-amber'],
];


// ── STATS ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) AS total_registered FROM registrations WHERE user_id = :uid");
$stmt->execute(['uid' => $uid]);
$totalRegistered = $stmt->fetch(PDO::FETCH_ASSOC)['total_registered'] ?? 0;

$weekEnd = date('Y-m-d H:i:s', strtotime('+7 days'));
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS upcoming FROM events e
    JOIN registrations r ON e.event_id = r.event_id
    WHERE r.user_id = :uid AND e.status = 'approved'
      AND e.start_datetime BETWEEN :now AND :weekEnd
");
$stmt->execute(['uid' => $uid, 'now' => $now, 'weekEnd' => $weekEnd]);
$upcomingWeek = $stmt->fetch(PDO::FETCH_ASSOC)['upcoming'] ?? 0;

// ── REGISTERED EVENTS ─────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT e.event_id, e.title, e.start_datetime, e.end_datetime,
           et.type_name, v.venue_name,
           COALESCE(o.org_name, c.club_name,
               NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,''))), '')
           ) AS organizer_name
    FROM registrations r
    JOIN events      e  ON r.event_id       = e.event_id
    JOIN event_types et ON e.event_type_id  = et.type_id
    JOIN venues      v  ON e.venue_id       = v.venue_id
    JOIN users       u  ON e.organizer_id   = u.user_id
    LEFT JOIN profiles      p ON u.user_id  = p.user_id
    LEFT JOIN organizations o ON e.org_id   = o.org_id
    LEFT JOIN clubs         c ON e.club_id  = c.club_id
    WHERE r.user_id = :uid AND e.status = 'approved'
    ORDER BY e.start_datetime ASC
");
$stmt->execute(['uid' => $uid]);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── PRE-FETCH ATTENDED IDs ────────────────────────────────────
$attendedIds = [];
if (!empty($events)) {
    $eventIds     = array_column($events, 'event_id');
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $attAll = $pdo->prepare("SELECT event_id FROM attendance WHERE user_id = ? AND event_id IN ($placeholders)");
    $attAll->execute(array_merge([$uid], $eventIds));
    $attendedIds = array_flip($attAll->fetchAll(PDO::FETCH_COLUMN));
}

// ── ATTENDANCE SUMMARY ────────────────────────────────────────
$totalEvents = count($events);
$attended = $absent = $upcoming = 0;
foreach ($events as $e) {
    $didAttend = isset($attendedIds[$e['event_id']]);
    if ($didAttend)                         $attended++;
    elseif ($e['start_datetime'] > $now)    $upcoming++;
    else                                     $absent++;
}
$pastEvents     = $attended + $absent;
$attendanceRate = $pastEvents > 0 ? round(($attended / $pastEvents) * 100) : 0;

// ── FEEDBACK COUNT ────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) AS feedback_count FROM feedback WHERE user_id = :uid");
$stmt->execute(['uid' => $uid]);
$feedbackCount = $stmt->fetch(PDO::FETCH_ASSOC)['feedback_count'] ?? 0;

// ── ATTENDANCE HISTORY ────────────────────────────────────────
$stmtHistory = $pdo->prepare("
    SELECT a.login_time, a.logout_time, a.scan_time,
           e.event_id, e.title, e.start_datetime, e.end_datetime,
           et.type_name, v.venue_name,
           COALESCE(o.org_name, c.club_name,
               NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,''))), '')
           ) AS organizer_name
    FROM attendance   a
    JOIN events      e  ON a.event_id      = e.event_id
    JOIN event_types et ON e.event_type_id = et.type_id
    JOIN venues      v  ON e.venue_id      = v.venue_id
    JOIN users       u  ON e.organizer_id  = u.user_id
    LEFT JOIN profiles      p ON u.user_id = p.user_id
    LEFT JOIN organizations o ON e.org_id  = o.org_id
    LEFT JOIN clubs         c ON e.club_id = c.club_id
    WHERE a.user_id = :uid ORDER BY a.login_time DESC
");
$stmtHistory->execute(['uid' => $uid]);
$attendanceHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

// ── UPCOMING EVENTS (same scoped logic as before) ─────────────
$upcomingSql = "
    SELECT e.event_id, e.title, e.start_datetime, e.end_datetime,
           e.club_id AS event_club_id, e.org_id AS event_org_id,
           et.type_name, v.venue_name,
           COALESCE(o.org_name, c.club_name,
               NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,''))), '')
           ) AS organizer_name,
           CASE WHEN r.event_id IS NOT NULL THEN 1 ELSE 0 END AS is_registered
    FROM events      e
    JOIN event_types et ON e.event_type_id = et.type_id
    JOIN venues      v  ON e.venue_id      = v.venue_id
    JOIN users       u  ON e.organizer_id  = u.user_id
    LEFT JOIN profiles       p  ON u.user_id  = p.user_id
    LEFT JOIN organizations  o  ON e.org_id   = o.org_id
    LEFT JOIN clubs          c  ON e.club_id  = c.club_id
    LEFT JOIN event_departments ed ON e.event_id = ed.event_id
    LEFT JOIN registrations  r  ON e.event_id = r.event_id AND r.user_id = :uid_reg
    WHERE e.status = 'approved' AND e.end_datetime > :now
      AND (
    (o.scope = 'all')   -- ← SSG and LSC events visible to everyone
    OR (:student_org  IS NOT NULL AND e.org_id  = :student_org2)
    OR (:student_club IS NOT NULL AND e.club_id = :student_club2)
    OR (:student_dept IS NOT NULL AND (e.dept_id = :student_dept2 OR ed.dept_id = :student_dept3))
)
    GROUP BY e.event_id ORDER BY e.start_datetime ASC LIMIT 20
";
$stmtUpcoming = $pdo->prepare($upcomingSql);
$stmtUpcoming->execute([
    'now'           => $now,
    'uid_reg'       => $uid,
    'student_org'   => $studentOrg,  'student_org2'   => $studentOrg,
    'student_club'  => $studentClub, 'student_club2'  => $studentClub,
    'student_dept'  => $studentDept, 'student_dept2'  => $studentDept, 'student_dept3' => $studentDept,
]);
$upcomingEvents = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);

// ── EVENT TYPE OPTIONS ────────────────────────────────────────
$eventTypeOptions = [];
foreach ($events as $e) {
    $t = htmlspecialchars($e['type_name']);
    if (!in_array($t, $eventTypeOptions)) $eventTypeOptions[] = $t;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Dashboard | SEMS</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" href="/assets/dashboard-icon-indigo.svg">
  <link rel="stylesheet" href="/CSS/student_dashboard.css">

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
        }
      }
    }
  </script>

</head>

<body>

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
      <div class="brand-logo" aria-hidden="true">
        <i data-lucide="graduation-cap" style="width:18px;height:18px;color:#fff;"></i>
      </div>
      <div>
        <div class="brand-name">SEMS</div>
        <div class="brand-tagline">Student Portal</div>
      </div>
    </div>

    <nav class="sidebar-nav" aria-label="Site navigation">
      <div class="nav-group-label">Overview</div>
      <a href="student_dashboard.php" class="nav-item active" aria-current="page">
        <i data-lucide="layout-dashboard" style="width:15px;height:15px;"></i>
        Dashboard
      </a>
      <div class="nav-group-label">Events</div>
      <a href="student_event.php" class="nav-item">
        <i data-lucide="calendar-days" style="width:15px;height:15px;"></i>
        Browse Events
      </a>
      <div class="nav-group-label">Participation</div>
      <a href="student_attendance.php" class="nav-item">
        <i data-lucide="clipboard-list" style="width:15px;height:15px;"></i>
        Attendance History
      </a>
      <a href="student_myqr.php" class="nav-item">
        <i data-lucide="qr-code" style="width:15px;height:15px;"></i>
        My QR Code
      </a>
      <a href="student_feedback.php" class="nav-item">
        <i data-lucide="message-square" style="width:15px;height:15px;"></i>
        Feedback
      </a>
      <div class="nav-group-label">Account</div>
      <a href="student_settings.php" class="nav-item">
        <i data-lucide="settings" style="width:15px;height:15px;"></i>
        Settings
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="user-chip">
        <div class="avatar avatar-sm">
          <?php if ($hasProfileImage): ?>
            <img src="data:<?= $profileMime ?>;base64,<?= $profileImageData ?>" alt="Profile photo">
          <?php else: ?>
            <?= $profileInitials ?>
          <?php endif; ?>
        </div>
        <div class="user-chip-info">
          <div class="user-chip-name"><?= htmlspecialchars($welcomeName) ?></div>
          <div class="user-chip-role">Student</div>
        </div>
      </div>
      <a href="../includes/logout.php" class="nav-item danger">
        <i data-lucide="log-out" style="width:15px;height:15px;"></i>
        Sign Out
      </a>
    </div>
  </aside>

  <div class="overlay" id="overlay" onclick="closeSidebar()" aria-hidden="true"></div>

  <!-- MOBILE TOPBAR -->
  <header class="topbar" role="banner">
    <button class="icon-btn" id="menuBtn" onclick="openSidebar()" aria-label="Open navigation" aria-expanded="false" aria-controls="sidebar">
      <i data-lucide="menu" style="width:17px;height:17px;"></i>
    </button>
    <div style="display:flex;align-items:center;gap:.5rem;">
      <div class="brand-logo" style="width:28px;height:28px;border-radius:7px;">
        <i data-lucide="graduation-cap" style="width:13px;height:13px;color:#fff;"></i>
      </div>
      <span class="brand-name" style="font-size:.9rem;">SEMS</span>
    </div>
    <div class="topbar-spacer"></div>
    <button class="icon-btn" id="darkToggleMobile" onclick="toggleDark()" aria-label="Toggle dark mode">
      <i data-lucide="sun"  style="width:15px;height:15px;display:none;" id="sunIconM"></i>
      <i data-lucide="moon" style="width:15px;height:15px;"              id="moonIconM"></i>
    </button>
    <div class="avatar avatar-sm" style="width:30px;height:30px;border-radius:8px;">
      <?php if ($hasProfileImage): ?>
        <img src="data:<?= $profileMime ?>;base64,<?= $profileImageData ?>" alt="Profile photo">
      <?php else: ?>
        <?= $profileInitials ?>
      <?php endif; ?>
    </div>
  </header>


  <!-- MAIN CONTENT -->
  <main class="main" id="main-content">

    <!-- PAGE HEADER -->
    <div class="anim" style="animation-delay:.05s;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.75rem;position:relative;z-index:1;">
      <div>
        <div class="page-eyebrow"><?= date('l, F j, Y') ?></div>
        <h1 class="page-title">Hello, <?= htmlspecialchars(explode(' ', $welcomeName)[0]) ?> 👋</h1>
        <p class="page-sub">Here's your activity overview for this semester.</p>
      </div>
      <div style="display:flex;align-items:center;gap:.625rem;padding-top:.25rem;flex-shrink:0;">
        <button class="icon-btn" id="darkToggle" onclick="toggleDark()" aria-label="Toggle dark mode" style="display:none;">
          <i data-lucide="sun"  style="width:15px;height:15px;display:none;" id="sunIcon"></i>
          <i data-lucide="moon" style="width:15px;height:15px;"              id="moonIcon"></i>
        </button>
        <a href="student_event.php" class="btn-primary">
          <i data-lucide="search" style="width:14px;height:14px;"></i>
          Browse Events
        </a>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stats-grid anim" style="animation-delay:.1s;margin-bottom:1.75rem;position:relative;z-index:1;">

      <div class="card card-hover stat-card" style="--card-accent:#7c3aed;">
        <div class="live-badge"><span class="live-dot"></span> Live</div>
        <div class="stat-label">Registered</div>
        <div class="stat-value"><?= $totalRegistered ?></div>
        <div class="stat-sub">Total events</div>
        <div class="stat-progress"><div class="stat-progress-fill" style="width:<?= min($totalRegistered * 12, 100) ?>%;"></div></div>
        <div class="stat-icon-bg"><i data-lucide="clipboard-list" style="width:54px;height:54px;"></i></div>
      </div>

      <div class="card card-hover stat-card" style="--card-accent:#d97706;">
        <div class="live-badge" style="color:var(--amber);"><span class="live-dot" style="background:var(--amber);"></span> Live</div>
        <div class="stat-label">This Week</div>
        <div class="stat-value"><?= $upcomingWeek ?></div>
        <div class="stat-sub">Upcoming events</div>
        <div class="stat-progress"><div class="stat-progress-fill" style="width:<?= min($upcomingWeek * 20, 100) ?>%;background:#d97706;"></div></div>
        <div class="stat-icon-bg" style="color:#d97706;"><i data-lucide="calendar-clock" style="width:54px;height:54px;"></i></div>
      </div>

      <div class="card card-hover stat-card" style="--card-accent:#16a34a;">
        <div class="live-badge" style="color:var(--green);"><span class="live-dot" style="background:var(--green);"></span> Live</div>
        <div class="stat-label">Attendance</div>
        <div class="stat-value"><?= $attendanceRate ?>%</div>
        <div class="stat-sub">Overall rate</div>
        <div class="stat-progress"><div class="stat-progress-fill" style="width:<?= $attendanceRate ?>%;background:#16a34a;"></div></div>
        <div class="stat-icon-bg" style="color:#16a34a;"><i data-lucide="check-circle-2" style="width:54px;height:54px;"></i></div>
      </div>

      <div class="card card-hover stat-card" style="--card-accent:#0284c7;">
        <div class="live-badge" style="color:#0284c7;"><span class="live-dot" style="background:#0284c7;"></span> Live</div>
        <div class="stat-label">Feedback</div>
        <div class="stat-value"><?= $feedbackCount ?></div>
        <div class="stat-sub">Submitted</div>
        <div class="stat-progress"><div class="stat-progress-fill" style="width:<?= min($feedbackCount * 15, 100) ?>%;background:#0284c7;"></div></div>
        <div class="stat-icon-bg" style="color:#0284c7;"><i data-lucide="message-circle" style="width:54px;height:54px;"></i></div>
      </div>

    </div>


    <!-- ══════════════════════════════════════════════
         ANNOUNCEMENTS SECTION
         Same scoped logic as events:
           'all'  → every student (SSG, LSC)
           'dept' → only matching dept students
           'club' → only matching club members
    ══════════════════════════════════════════════ -->
    <div class="anim" style="animation-delay:.13s;margin-bottom:1.75rem;position:relative;z-index:1;">

      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <div class="section-heading">
          <span class="sh-icon">
            <i data-lucide="megaphone" style="width:14px;height:14px;"></i>
          </span>
          Announcements
          <?php if ($annCount > 0): ?>
            <span class="pill pill-rose" style="margin-left:.4rem;font-size:.68rem;">
              <?= $annCount ?>
            </span>
          <?php endif; ?>
        </div>
        <a href="student_announcements.php" class="btn-ghost">
          View all <i data-lucide="arrow-right" style="width:13px;height:13px;"></i>
        </a>
      </div>

      <?php if (!empty($announcements)): ?>
        <div style="display:flex;flex-direction:column;gap:.625rem;">
          <?php foreach ($announcements as $ann):
            $vb        = $annVisBadge[$ann['visibility']] ?? $annVisBadge['all'];
            $accentCls = $ann['is_pinned'] ? 'pinned' : $ann['visibility'];
            $author    = trim($ann['author_first'] . ' ' . $ann['author_last']) ?: 'Unknown';
          ?>
            <div class="ann-card">
              <!-- Left accent: amber=pinned, green=all, purple=dept, blue=club -->
              <div class="ann-accent <?= $accentCls ?>"></div>
              <div class="ann-body">

                <!-- Title + visibility badge -->
                <div style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.25rem;flex-wrap:wrap;">
                  <div class="ann-title" style="flex:1;min-width:0;">
                    <?php if ($ann['is_pinned']): ?>
                      <i data-lucide="pin" style="width:11px;height:11px;color:#f59e0b;margin-right:.2rem;vertical-align:middle;"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($ann['title']) ?>
                  </div>
                  <span class="pill <?= $vb['cls'] ?>" style="flex-shrink:0;font-size:.68rem;">
                    <?= $vb['label'] ?>
                  </span>
                </div>

                <!-- Body preview -->
                <p class="ann-preview"><?= htmlspecialchars($ann['body']) ?></p>

                <!-- Meta -->
                <div class="ann-meta">
                  <span class="ann-meta-item">
                    <i data-lucide="building-2" style="width:11px;height:11px;"></i>
                    <?= htmlspecialchars($ann['source_name']) ?>
                  </span>
                  <span class="ann-meta-item">
                    <i data-lucide="user-circle" style="width:11px;height:11px;"></i>
                    <?= htmlspecialchars($author) ?>
                  </span>
                  <span class="ann-meta-item">
                    <i data-lucide="clock" style="width:11px;height:11px;"></i>
                    <?= date('M j, Y', strtotime($ann['created_at'])) ?>
                  </span>
                </div>

              </div>
            </div>
          <?php endforeach; ?>
        </div>

      <?php else: ?>
        <div class="card">
          <div class="empty-state">
            <div class="empty-icon"><i data-lucide="megaphone" style="width:24px;height:24px;"></i></div>
            <div class="empty-title">No announcements</div>
            <p class="empty-sub">Announcements from your department, organization, or club will appear here.</p>
          </div>
        </div>
      <?php endif; ?>

    </div><!-- /announcements -->


    <!-- UPCOMING EVENTS + DONUT -->
    <div class="two-col-grid anim" style="animation-delay:.18s;margin-bottom:1.75rem;position:relative;z-index:1;">

      <div class="two-col-main">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;gap:.5rem;flex-wrap:wrap;">
          <div class="section-heading">
            <span class="sh-icon"><i data-lucide="calendar-days" style="width:14px;height:14px;"></i></span>
            Upcoming Events
          </div>
          <a href="student_event.php" class="btn-ghost">
            View all <i data-lucide="arrow-right" style="width:13px;height:13px;"></i>
          </a>
        </div>

        <?php if (!empty($upcomingEvents)): ?>
          <div class="events-rail" id="eventsRail">
            <?php foreach ($upcomingEvents as $event):
              $isRegistered = (int)($event['is_registered'] ?? 0) === 1;
              $isRequired   = ($studentClub && (int)($event['event_club_id'] ?? 0) === (int)$studentClub)
                           || ($studentOrg  && (int)($event['event_org_id']  ?? 0) === (int)$studentOrg);
              $eventDate  = date('M d, Y', strtotime($event['start_datetime']));
              $eventTime  = date('g:i A',  strtotime($event['start_datetime']));
              $daysUntil  = ceil((strtotime($event['start_datetime']) - time()) / 86400);
              $dayLabel   = $daysUntil <= 0 ? 'Today' : ($daysUntil == 1 ? 'Tomorrow' : $daysUntil . 'd away');
            ?>
              <div class="card card-hover event-card">
                <div class="event-card-badges">
                  <?php if ($isRequired): ?>
                    <span class="pill pill-amber"><i data-lucide="alert-circle" style="width:10px;height:10px;"></i> Required</span>
                  <?php else: ?>
                    <span class="pill pill-purple"><i data-lucide="users" style="width:10px;height:10px;"></i> Open</span>
                  <?php endif; ?>
                  <span class="event-day-tag"><?= $dayLabel ?></span>
                </div>
                <div class="event-title"><?= htmlspecialchars($event['title']) ?></div>
                <div class="event-meta-row">
                  <div class="event-meta-item"><i data-lucide="calendar" style="width:12px;height:12px;"></i><?= $eventDate ?> · <?= $eventTime ?></div>
                  <div class="event-meta-item"><i data-lucide="map-pin" style="width:12px;height:12px;"></i><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($event['venue_name']) ?></span></div>
                  <div class="event-meta-item"><i data-lucide="user" style="width:12px;height:12px;"></i><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($event['organizer_name'] ?? 'Unknown') ?></span></div>
                </div>
                <?php if ($isRegistered): ?>
                  <a href="student_event.php?id=<?= $event['event_id'] ?>" class="btn-join is-joined">
                    <i data-lucide="check-circle" style="width:13px;height:13px;"></i> Joined
                  </a>
                <?php else: ?>
                  <a href="student_event.php?id=<?= $event['event_id'] ?>" class="btn-join <?= $isRequired ? 'is-required' : '' ?>">
                    <i data-lucide="<?= $isRequired ? 'alert-circle' : 'plus-circle' ?>" style="width:13px;height:13px;"></i>
                    <?= $isRequired ? 'Register Now' : 'Join Event' ?>
                  </a>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="empty-state">
              <div class="empty-icon"><i data-lucide="calendar-x" style="width:22px;height:22px;"></i></div>
              <div class="empty-title">No upcoming events</div>
              <p class="empty-sub">New events matching your department or organization will appear here.</p>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Attendance Donut -->
      <div>
        <div class="section-heading" style="margin-bottom:1rem;">
          <span class="sh-icon"><i data-lucide="pie-chart" style="width:14px;height:14px;"></i></span>
          Attendance
        </div>
        <div class="card" style="padding:1.75rem 1.5rem 1.5rem;">
          <div style="display:flex;flex-direction:column;align-items:center;">
            <div class="donut-container" style="width:148px;height:148px;position:relative;">
              <svg viewBox="0 0 100 100" style="width:148px;height:148px;transform:rotate(-90deg);" aria-hidden="true">
                <defs>
                  <linearGradient id="donutGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%"   stop-color="#7c3aed" />
                    <stop offset="50%"  stop-color="#2563eb" />
                    <stop offset="100%" stop-color="#16a34a" />
                  </linearGradient>
                </defs>
                <circle cx="50" cy="50" r="40" fill="none" stroke="currentColor" stroke-width="10" style="color:var(--border);" />
                <circle id="progressArc" cx="50" cy="50" r="40" fill="none" stroke="url(#donutGrad)" stroke-width="10" stroke-linecap="round" stroke-dasharray="251.33" stroke-dashoffset="251.33" />
              </svg>
              <div class="donut-label">
                <span class="donut-pct" id="donutPct">0%</span>
                <span class="donut-pct-label">Rate</span>
              </div>
            </div>
            <div class="att-stat-grid">
              <div class="att-stat-cell" style="background:var(--purplebg);border-color:var(--purplebdr);"><span class="att-stat-num" style="color:var(--purple);"><?= $totalEvents ?></span><span class="att-stat-label">Total</span></div>
              <div class="att-stat-cell" style="background:var(--greenbg);border-color:var(--greenbdr);"><span class="att-stat-num" style="color:var(--green);"><?= $attended ?></span><span class="att-stat-label">Attended</span></div>
              <div class="att-stat-cell" style="background:var(--rosebg);border-color:var(--rosebdr);"><span class="att-stat-num" style="color:var(--rose);"><?= $absent ?></span><span class="att-stat-label">Absent</span></div>
              <div class="att-stat-cell" style="background:var(--amberbg);border-color:var(--amberbdr);"><span class="att-stat-num" style="color:var(--amber);"><?= $upcoming ?></span><span class="att-stat-label">Upcoming</span></div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /two-col-grid -->


    <!-- MY REGISTERED EVENTS TABLE -->
    <div class="anim" style="animation-delay:.25s;margin-bottom:1.75rem;position:relative;z-index:1;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <div class="section-heading">
          <span class="sh-icon"><i data-lucide="list-checks" style="width:14px;height:14px;"></i></span>
          My Registered Events
        </div>
        <span id="activeFilterBadge" class="filter-badge"></span>
      </div>

      <div class="card" style="overflow:hidden;">
        <div class="filter-bar">
          <div class="search-field">
            <span class="si"><i data-lucide="search" style="width:13px;height:13px;"></i></span>
            <input type="text" id="eventSearch" class="input-field" placeholder="Search events, venues, organizers…" oninput="filterEvents()" autocomplete="off">
          </div>
          <select id="statusFilter" class="select-field" onchange="filterEvents()">
            <option value="all">All Statuses</option>
            <option value="Upcoming">Upcoming</option>
            <option value="Ongoing">Ongoing</option>
            <option value="Attended">Attended</option>
            <option value="Absent">Absent</option>
          </select>
          <select id="typeFilter" class="select-field" onchange="filterEvents()">
            <option value="all">All Types</option>
            <?php foreach ($eventTypeOptions as $t): ?>
              <option value="<?= $t ?>"><?= $t ?></option>
            <?php endforeach; ?>
          </select>
          <button id="clearFilters" class="clear-btn" onclick="clearFilters()" type="button">
            <i data-lucide="x" style="width:12px;height:12px;"></i> Clear
          </button>
        </div>

        <div class="results-meta">
          <span style="display:flex;align-items:center;gap:.4rem;">
            <i data-lucide="table-2" style="width:11px;height:11px;"></i>
            Showing&nbsp;<strong id="visibleCount" style="color:var(--ink);"><?= $totalEvents ?></strong>&nbsp;of <?= $totalEvents ?> event<?= $totalEvents !== 1 ? 's' : '' ?>
          </span>
          <span style="display:flex;align-items:center;gap:.3rem;">
            <i data-lucide="hand-pointer" style="width:11px;height:11px;"></i> Click row for details
          </span>
        </div>

        <?php if (!empty($events)): ?>
          <div id="eventList">
            <?php foreach ($events as $event):
              $didAttend = isset($attendedIds[$event['event_id']]);
              if ($event['start_datetime'] > $now) {
                  $status = 'Upcoming'; $pillClass = 'pill-muted'; $icon = 'calendar';
              } elseif ($event['end_datetime'] > $now) {
                  $status = 'Ongoing';  $pillClass = 'pill-amber'; $icon = 'activity';
              } elseif ($didAttend) {
                  $status = 'Attended'; $pillClass = 'pill-green'; $icon = 'check-circle';
              } else {
                  $status = 'Absent';   $pillClass = 'pill-rose';  $icon = 'x-circle';
              }
            ?>
              <div class="event-row event-row-item"
                data-title="<?= strtolower(htmlspecialchars($event['title'])) ?>"
                data-venue="<?= strtolower(htmlspecialchars($event['venue_name'])) ?>"
                data-organizer="<?= strtolower(htmlspecialchars($event['organizer_name'] ?? '')) ?>"
                data-type="<?= htmlspecialchars($event['type_name']) ?>"
                data-status="<?= $status ?>"
                onclick="window.location='student_event.php?id=<?= $event['event_id'] ?>'">
                <div class="event-row-body">
                  <div class="event-row-title"><?= htmlspecialchars($event['title']) ?></div>
                  <div class="event-row-meta">
                    <span class="event-row-meta-item"><i data-lucide="tag"     style="width:10px;height:10px;"></i><?= htmlspecialchars($event['type_name']) ?></span>
                    <span class="event-row-meta-item"><i data-lucide="map-pin" style="width:10px;height:10px;"></i><?= htmlspecialchars($event['venue_name']) ?></span>
                    <span class="event-row-meta-item"><i data-lucide="user"    style="width:10px;height:10px;"></i><?= htmlspecialchars($event['organizer_name'] ?? 'Unknown') ?></span>
                  </div>
                </div>
                <div class="event-row-aside">
                  <div>
                    <div class="event-row-date"><?= date('M d, Y', strtotime($event['start_datetime'])) ?></div>
                    <div class="event-row-time"><?= date('g:i A',  strtotime($event['start_datetime'])) ?></div>
                  </div>
                  <span class="pill <?= $pillClass ?>">
                    <i data-lucide="<?= $icon ?>" style="width:9px;height:9px;"></i> <?= $status ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>

            <div id="noMatchState" class="no-match">
              <div class="no-match-icon"><i data-lucide="search-x" style="width:18px;height:18px;"></i></div>
              <div style="font-weight:600;font-size:.875rem;color:var(--ink);margin-bottom:.25rem;">No events match your filters</div>
              <div style="font-size:.78rem;color:var(--ink3);">Try adjusting your search or filter criteria.</div>
              <button onclick="clearFilters()" style="margin-top:.625rem;font-size:.78rem;font-weight:700;color:var(--purple);background:none;border:none;cursor:pointer;text-decoration:underline;">Clear filters</button>
            </div>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="empty-icon"><i data-lucide="calendar-x" style="width:24px;height:24px;"></i></div>
            <div class="empty-title">No events yet</div>
            <p class="empty-sub">You haven't registered for any events. Browse available events to get started.</p>
            <a href="student_event.php" class="btn-primary" style="margin-top:.25rem;">
              <i data-lucide="plus" style="width:13px;height:13px;"></i> Browse Events
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>


    <!-- ATTENDANCE HISTORY -->
    <div class="anim" style="animation-delay:.32s;position:relative;z-index:1;">
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <div class="section-heading">
          <span class="sh-icon"><i data-lucide="history" style="width:14px;height:14px;"></i></span>
          Attendance History
        </div>
        <?php if (!empty($attendanceHistory)): ?>
          <span class="pill pill-green">
            <i data-lucide="check-circle" style="width:9px;height:9px;"></i>
            <?= count($attendanceHistory) ?> record<?= count($attendanceHistory) !== 1 ? 's' : '' ?>
          </span>
        <?php endif; ?>
      </div>

      <?php if (!empty($attendanceHistory)): ?>
        <div style="margin-bottom:.75rem;max-width:340px;">
          <div class="search-field">
            <span class="si"><i data-lucide="search" style="width:13px;height:13px;"></i></span>
            <input type="text" id="historySearch" class="input-field" placeholder="Search attendance history…" oninput="filterHistory()" autocomplete="off">
          </div>
        </div>
      <?php endif; ?>

      <div class="card" style="overflow:hidden;">
        <?php if (!empty($attendanceHistory)): ?>
          <div class="desktop-only" style="overflow-x:auto;">
            <table class="htable" role="table">
              <thead>
                <tr><th>Event</th><th>Type</th><th>Venue</th><th>Date</th><th>Check-In</th><th>Check-Out</th><th>Duration</th></tr>
              </thead>
              <tbody id="historyTableBody">
                <?php foreach ($attendanceHistory as $h):
                  $durationLabel = '—';
                  if (!empty($h['login_time']) && !empty($h['logout_time'])) {
                      $diff = strtotime($h['logout_time']) - strtotime($h['login_time']);
                      if ($diff > 0) { $hrs = floor($diff/3600); $mins = floor(($diff%3600)/60); $durationLabel = ($hrs>0?$hrs.'h ':'').$mins.'m'; }
                  }
                  $checkIn   = !empty($h['login_time'])  ? date('g:i A', strtotime($h['login_time']))  : '—';
                  $checkOut  = !empty($h['logout_time']) ? date('g:i A', strtotime($h['logout_time'])) : '—';
                  $eventDate = date('M d, Y', strtotime($h['start_datetime']));
                ?>
                  <tr class="history-row-item"
                    data-title="<?= strtolower(htmlspecialchars($h['title'])) ?>"
                    data-venue="<?= strtolower(htmlspecialchars($h['venue_name'])) ?>"
                    data-type="<?= strtolower(htmlspecialchars($h['type_name'])) ?>">
                    <td>
                      <div style="font-weight:600;font-size:.84rem;color:var(--ink);max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($h['title']) ?></div>
                      <div style="font-size:.72rem;color:var(--ink3);margin-top:.15rem;"><?= htmlspecialchars($h['organizer_name'] ?? 'Unknown') ?></div>
                    </td>
                    <td><span class="pill pill-purple"><?= htmlspecialchars($h['type_name']) ?></span></td>
                    <td><span style="font-size:.8rem;max-width:140px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($h['venue_name']) ?>"><?= htmlspecialchars($h['venue_name']) ?></span></td>
                    <td><div style="font-weight:600;font-size:.82rem;color:var(--ink);"><?= $eventDate ?></div><div style="font-size:.72rem;color:var(--ink3);"><?= date('g:i A', strtotime($h['start_datetime'])) ?></div></td>
                    <td><?php if ($checkIn!=='—'): ?><span class="time-pill time-pill-green"><i data-lucide="log-in" style="width:11px;height:11px;"></i> <?= $checkIn ?></span><?php else: ?><span style="color:var(--ink4);">—</span><?php endif; ?></td>
                    <td><?php if ($checkOut!=='—'): ?><span class="time-pill" style="background:var(--rosebg);color:var(--rose);border:1px solid var(--rosebdr);"><i data-lucide="log-out" style="width:11px;height:11px;"></i> <?= $checkOut ?></span><?php else: ?><span style="color:var(--ink4);">—</span><?php endif; ?></td>
                    <td><?php if ($durationLabel!=='—'): ?><span class="time-pill time-pill-dur"><i data-lucide="timer" style="width:11px;height:11px;"></i> <?= $durationLabel ?></span><?php else: ?><span style="color:var(--ink4);">—</span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr id="historyNoMatch" style="display:none;">
                  <td colspan="7" style="padding:2.5rem;text-align:center;">
                    <div style="display:flex;flex-direction:column;align-items:center;gap:.5rem;">
                      <i data-lucide="search-x" style="width:20px;height:20px;color:var(--ink4);"></i>
                      <span style="font-size:.82rem;color:var(--ink3);">No records match "<span id="historySearchTerm" style="font-weight:700;color:var(--ink);"></span>"</span>
                      <button onclick="clearHistorySearch()" style="font-size:.75rem;font-weight:700;color:var(--purple);background:none;border:none;cursor:pointer;text-decoration:underline;">Clear search</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mobile-only" id="historyMobileList">
            <?php foreach ($attendanceHistory as $h):
              $durationLabel = '—';
              if (!empty($h['login_time']) && !empty($h['logout_time'])) {
                  $diff = strtotime($h['logout_time']) - strtotime($h['login_time']);
                  if ($diff > 0) { $hrs=floor($diff/3600); $mins=floor(($diff%3600)/60); $durationLabel=($hrs>0?$hrs.'h ':'').$mins.'m'; }
              }
              $checkIn  = !empty($h['login_time'])  ? date('g:i A', strtotime($h['login_time']))  : null;
              $checkOut = !empty($h['logout_time']) ? date('g:i A', strtotime($h['logout_time'])) : null;
            ?>
              <div class="history-mobile-item" style="padding:1rem 1.125rem;border-bottom:1px solid var(--border);"
                data-title="<?= strtolower(htmlspecialchars($h['title'])) ?>"
                data-venue="<?= strtolower(htmlspecialchars($h['venue_name'])) ?>"
                data-type="<?= strtolower(htmlspecialchars($h['type_name'])) ?>">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;margin-bottom:.5rem;">
                  <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:.875rem;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($h['title']) ?></div>
                    <div style="font-size:.72rem;color:var(--ink3);margin-top:.15rem;"><?= htmlspecialchars($h['venue_name']) ?> &middot; <?= htmlspecialchars($h['type_name']) ?></div>
                  </div>
                  <?php if ($durationLabel !== '—'): ?><span class="time-pill time-pill-dur" style="flex-shrink:0;"><i data-lucide="timer" style="width:10px;height:10px;"></i> <?= $durationLabel ?></span><?php endif; ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:.375rem .75rem;font-size:.72rem;">
                  <span style="display:flex;align-items:center;gap:.3rem;color:var(--ink3);"><i data-lucide="calendar" style="width:11px;height:11px;"></i><?= date('M d, Y', strtotime($h['start_datetime'])) ?></span>
                  <?php if ($checkIn): ?><span class="time-pill time-pill-green" style="padding:.15rem .45rem;"><i data-lucide="log-in" style="width:10px;height:10px;"></i> <?= $checkIn ?></span><?php endif; ?>
                  <?php if ($checkOut): ?><span class="time-pill" style="padding:.15rem .45rem;background:var(--rosebg);color:var(--rose);border:1px solid var(--rosebdr);"><i data-lucide="log-out" style="width:10px;height:10px;"></i> <?= $checkOut ?></span><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
            <div id="historyMobileNoMatch" style="display:none;padding:2rem;text-align:center;font-size:.82rem;color:var(--ink3);">No matching records found.</div>
          </div>

        <?php else: ?>
          <div class="empty-state">
            <div class="empty-icon"><i data-lucide="clipboard-x" style="width:24px;height:24px;"></i></div>
            <div class="empty-title">No attendance records</div>
            <p class="empty-sub">Your check-in and check-out history will appear here once you attend an event.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </main>

  <script>
    const SEMS_DASHBOARD = {
      attendanceRate: <?= $attendanceRate ?>,
      totalEvents:    <?= $totalEvents ?>
    };
  </script>
  <script src="/js/student_dashboard.js"></script>

</body>
</html>