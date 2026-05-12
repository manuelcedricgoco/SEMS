<?php
/*
 * ============================================================
 *  student_event.php — SEMS (Redesigned)
 * ============================================================
 */
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];
$now = date('Y-m-d H:i:s');

// ── EVENT REGISTRATION HANDLER ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $reg_event_id = (int) $_POST['event_id'];
    if ($reg_event_id) {
        try {
            $evChk = $pdo->prepare("
                SELECT e.event_id, v.capacity,
                       (SELECT COUNT(*) FROM registrations r2 WHERE r2.event_id = e.event_id) AS registered_count
                FROM events e JOIN venues v ON e.venue_id = v.venue_id
                WHERE e.event_id = :eid AND e.status = 'approved' AND e.end_datetime >= NOW()
            ");
            $evChk->execute(['eid' => $reg_event_id]);
            $evRow = $evChk->fetch(PDO::FETCH_ASSOC);
            if (!$evRow) { header("Location: student_event.php?error=1"); exit(); }
            if ($evRow['capacity'] > 0 && $evRow['registered_count'] >= $evRow['capacity']) {
                header("Location: student_event.php?error=full"); exit();
            }
            $dupChk = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = :uid AND event_id = :eid");
            $dupChk->execute(['uid' => $uid, 'eid' => $reg_event_id]);
            if ($dupChk->fetchColumn() > 0) { header("Location: student_event.php?error=duplicate"); exit(); }
            $ins = $pdo->prepare("INSERT INTO registrations (event_id, user_id) VALUES (:eid, :uid)");
            $ins->execute(['eid' => $reg_event_id, 'uid' => $uid]);
            header("Location: student_event.php?registered=1"); exit();
        } catch (PDOException $e) {
            header("Location: student_event.php?error=1"); exit();
        }
    }
}

// ── STUDENT NAME ─────────────────────────────────────────────
$welcomeName = 'Student';
$stmt = $pdo->prepare("SELECT first_name, middle_name, last_name FROM profiles WHERE user_id = :uid LIMIT 1");
$stmt->execute(['uid' => $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $mn = !empty($row['middle_name']) ? ' ' . strtoupper(substr(trim($row['middle_name']), 0, 1)) . '.' : '';
    $welcomeName = trim($row['first_name'] . $mn . ' ' . $row['last_name']) ?: 'Student';
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

// ── STUDENT AFFILIATIONS ──────────────────────────────────────
$stmt = $pdo->prepare("SELECT dept_id, org_id, club_id FROM users WHERE user_id = :uid LIMIT 1");
$stmt->execute(['uid' => $uid]);
$urow        = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$studentDept = !empty($urow['dept_id'])  ? (int) $urow['dept_id']  : null;
$studentOrg  = !empty($urow['org_id'])   ? (int) $urow['org_id']   : null;
$studentClub = !empty($urow['club_id'])  ? (int) $urow['club_id']  : null;


/* =============================================================================
 * ANNOUNCEMENTS — scoped visibility (same rules as dashboard)
 * ============================================================================= */
$annConditions = ["a.visibility = 'all'"];
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
");
$annStmt->execute($annParams);
$announcements = $annStmt->fetchAll(PDO::FETCH_ASSOC);
$annCount      = count($announcements);

$annVisBadge = [
    'all'  => ['label' => 'School-wide', 'dot' => '#16a34a'],
    'dept' => ['label' => 'Department',  'dot' => '#7c3aed'],
    'club' => ['label' => 'Club Only',   'dot' => '#0284c7'],
];


/* =============================================================================
 * FETCH ALL EVENTS
 * ============================================================================= */
$sql = "
SELECT
    e.event_id, e.title, e.start_datetime, e.end_datetime,
    e.is_restricted, e.club_id, e.org_id, e.dept_id,
    o.scope   AS org_scope,
    et.type_name, v.venue_name, v.capacity,
    o.org_name,
    COALESCE(o.org_name, c.club_name,
        NULLIF(TRIM(CONCAT(COALESCE(p.first_name,''),' ',COALESCE(p.last_name,''))), '')
    ) AS organizer_name,
    (SELECT COUNT(*) FROM registrations r2 WHERE r2.event_id = e.event_id) AS registered_count,
    dept_data.required_dept_ids
FROM events e
JOIN event_types et ON e.event_type_id = et.type_id
JOIN venues v       ON e.venue_id      = v.venue_id
JOIN users u        ON e.organizer_id  = u.user_id
LEFT JOIN profiles      p  ON u.user_id   = p.user_id
LEFT JOIN organizations o  ON e.org_id    = o.org_id
LEFT JOIN clubs         c  ON e.club_id   = c.club_id
LEFT JOIN (
    SELECT event_id, GROUP_CONCAT(DISTINCT dept_id) AS required_dept_ids
    FROM event_departments GROUP BY event_id
) dept_data ON e.event_id = dept_data.event_id
WHERE e.status = 'approved' AND e.end_datetime >= NOW()
ORDER BY e.start_datetime ASC
";

try {
    $stmt      = $pdo->query($sql);
    $allEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Event fetch failed: " . $e->getMessage());
    $allEvents = [];
}

/* =============================================================================
 * FILTER, AUTO-REGISTER, CLASSIFY
 * ============================================================================= */
$events = [];

foreach ($allEvents as $row) {
    $evOrgId    = !empty($row['org_id'])   ? (int) $row['org_id']   : null;
    $evClubId   = !empty($row['club_id'])  ? (int) $row['club_id']  : null;
    $evDeptId   = !empty($row['dept_id'])  ? (int) $row['dept_id']  : null;
    $evOrgScope = $row['org_scope'] ?? null;

    // ── SCOPE GATE ────────────────────────────────────────────
    if ($evOrgId !== null) {
        if ($evOrgScope === 'all') {
            // SSG / LSC — every student ✅
        } elseif ($evOrgScope === 'dept') {
            $requiredDepts = !empty($row['required_dept_ids'])
                ? array_map('intval', explode(',', $row['required_dept_ids'])) : [];
            $deptMatch = $studentDept !== null && (
                ($evDeptId !== null && $evDeptId === $studentDept) ||
                in_array($studentDept, $requiredDepts, true)
            );
            if (!$deptMatch) continue;
        } else {
            if ($studentOrg === null || $studentOrg !== $evOrgId) continue;
        }
    } elseif ($evClubId !== null) {
        if ($studentClub === null || $studentClub !== $evClubId) continue;
    } else {
        $requiredDepts = !empty($row['required_dept_ids'])
            ? array_map('intval', explode(',', $row['required_dept_ids'])) : [];
        if ($evDeptId !== null) {
            if ($studentDept === null || $studentDept !== $evDeptId) continue;
        } elseif (!empty($requiredDepts)) {
            if ($studentDept === null || !in_array($studentDept, $requiredDepts, true)) continue;
        }
    }

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM registrations WHERE user_id = :uid AND event_id = :eid");
    $stmt2->execute(['uid' => $uid, 'eid' => $row['event_id']]);
    $joined = $stmt2->fetchColumn() > 0;

    $requiredDepts = !empty($row['required_dept_ids'])
        ? array_map('intval', explode(',', $row['required_dept_ids'])) : [];
    $isRequired = $studentDept !== null && in_array($studentDept, $requiredDepts, true);

    if (!empty($row['is_restricted']) && (int) $row['is_restricted'] === 1 && !$isRequired) continue;

    $isFull = ($row['capacity'] > 0 && $row['registered_count'] >= $row['capacity']);

    if ($isRequired && !$joined && !$isFull) {
        try {
            $autoReg = $pdo->prepare("INSERT IGNORE INTO registrations (event_id, user_id) VALUES (:eid, :uid)");
            $autoReg->execute(['eid' => $row['event_id'], 'uid' => $uid]);
            $joined = true;
        } catch (PDOException $e) {
            error_log("Auto-register failed: " . $e->getMessage());
        }
    }

    if ($joined)         $status = "JOINED";
    elseif ($isFull)     $status = "FULL";
    elseif ($isRequired) $status = "REQUIRED";
    else                 $status = "OPEN";

    $row['status']      = $status;
    $row['is_required'] = $isRequired;
    $events[]           = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Browse Events | SEMS</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="icon" href="/assets/events-icon-indigo.svg">
  <link rel="stylesheet" href="/CSS/student_event.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/lucide@latest"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: { fontFamily: { sans: ['Plus Jakarta Sans','sans-serif'], display: ['Sora','sans-serif'] } } }
    }
  </script>

</head>
<body>

  <!-- SIDEBAR -->
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
        <i data-lucide="layout-dashboard" style="width:15px;height:15px;"></i> Dashboard
      </a>
      <div class="sb-section">Events</div>
      <a href="student_event.php" class="sb-link active" aria-current="page">
        <i data-lucide="calendar-days" style="width:15px;height:15px;"></i> Browse Events
      </a>
      <div class="sb-section">Participation</div>
      <a href="student_attendance.php" class="sb-link">
        <i data-lucide="clipboard-list" style="width:15px;height:15px;"></i> Attendance History
      </a>
      <a href="student_myqr.php" class="sb-link">
        <i data-lucide="qr-code" style="width:15px;height:15px;"></i> My QR Code
      </a>
      <a href="student_feedback.php" class="sb-link">
        <i data-lucide="message-square" style="width:15px;height:15px;"></i> Feedback
      </a>
      <div class="sb-section">Account</div>
      <a href="student_settings.php" class="sb-link">
        <i data-lucide="settings" style="width:15px;height:15px;"></i> Settings
      </a>
    </nav>
    <div class="sb-footer">
      <div class="sb-user-pill">
        <div class="avatar">
          <?php if ($hasProfileImage): ?>
            <img src="data:<?= $profileMime ?>;base64,<?= $profileImageData ?>" alt="Profile photo">
          <?php else: ?><?= $profileInitials ?><?php endif; ?>
        </div>
        <div>
          <div class="sb-user-name"><?= htmlspecialchars($welcomeName) ?></div>
          <div class="sb-user-role">Student</div>
        </div>
      </div>
      <a href="../includes/logout.php" class="sb-link signout">
        <i data-lucide="log-out" style="width:15px;height:15px;"></i> Sign Out
      </a>
    </div>
  </aside>

  <div class="overlay" id="overlay" onclick="closeSidebar()" aria-hidden="true"></div>

  <!-- MOBILE HEADER -->
  <header class="mob-header" role="banner">
    <div style="display:flex;align-items:center;gap:.625rem;flex-shrink:0;">
      <button class="icon-btn" id="menuBtn" onclick="openSidebar()" aria-label="Open navigation">
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
        <?php else: ?><?= $profileInitials ?><?php endif; ?>
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <main class="main">
  <div class="inner">

    <!-- HERO -->
    <div class="hero rise" style="animation-delay:.04s;">
      <div class="hero-deco2"></div>
      <div class="hero-inner">
        <div>
          <div class="hero-eyebrow"><?= date('l, F j, Y') ?></div>
          <h1 class="hero-title">Browse Events</h1>
          <p class="hero-sub">Explore events and announcements tailored for you.</p>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem;margin-top:.5rem;flex-wrap:wrap;">
          <button class="icon-btn light" onclick="toggleDark()" title="Toggle theme" id="desktopDarkBtn" style="display:none;">
            <i data-lucide="sun"  id="sunD"  style="width:15px;height:15px;display:none;"></i>
            <i data-lucide="moon" id="moonD" style="width:15px;height:15px;"></i>
          </button>
          <?php if (!empty($events)): ?>
            <div class="hero-badge">
              <i data-lucide="calendar-days" style="width:14px;height:14px;"></i>
              <span><strong><?= count($events) ?></strong> event<?= count($events) !== 1 ? 's' : '' ?> available</span>
            </div>
          <?php endif; ?>
          <?php if ($annCount > 0): ?>
            <div class="hero-badge" style="background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.35);color:#92400e;">
              <i data-lucide="megaphone" style="width:14px;height:14px;"></i>
              <span><strong><?= $annCount ?></strong> announcement<?= $annCount !== 1 ? 's' : '' ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>


    <!-- STATUS COUNT BAR -->
    <?php
      $counts = ['OPEN'=>0,'REQUIRED'=>0,'JOINED'=>0,'FULL'=>0];
      foreach ($events as $ev) { if (isset($counts[$ev['status']])) $counts[$ev['status']]++; }
    ?>
    <div class="count-bar rise" style="animation-delay:.1s;">
      <div class="sec-head">
        <i data-lucide="calendar-days"></i>
        <span>Available Events</span>
        <span class="gold-dot"></span>
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <?php if ($counts['OPEN']): ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:6px;font-size:.7rem;font-weight:700;background:var(--greenbg);color:var(--green);border:1px solid var(--greenbdr);">
            <i data-lucide="circle-dot" style="width:10px;height:10px;"></i><?= $counts['OPEN'] ?> Open
          </span>
        <?php endif; ?>
        <?php if ($counts['REQUIRED']): ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:6px;font-size:.7rem;font-weight:700;background:var(--rosebg);color:var(--rose);border:1px solid var(--rosebdr);">
            <i data-lucide="alert-circle" style="width:10px;height:10px;"></i><?= $counts['REQUIRED'] ?> Required
          </span>
        <?php endif; ?>
        <?php if ($counts['JOINED']): ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:6px;font-size:.7rem;font-weight:700;background:var(--purplebg);color:var(--purple);border:1px solid var(--purplebdr);">
            <i data-lucide="check-circle" style="width:10px;height:10px;"></i><?= $counts['JOINED'] ?> Joined
          </span>
        <?php endif; ?>
        <?php if ($annCount > 0): ?>
          <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:6px;font-size:.7rem;font-weight:700;background:#fef3c7;color:#92400e;border:1px solid #fde68a;">
            <i data-lucide="megaphone" style="width:10px;height:10px;"></i><?= $annCount ?> Announcement<?= $annCount !== 1 ? 's' : '' ?>
          </span>
        <?php endif; ?>
      </div>
    </div>


    <!-- FILTER BAR -->
    <div class="filter-bar rise" style="animation-delay:.14s;">
      <div class="search-wrap">
        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
          fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="searchInput" class="search-input"
          placeholder="Search events or announcements…"
          autocomplete="off" aria-label="Search">
        <button class="search-clear" id="clearSearch" title="Clear" aria-label="Clear search">✕</button>
      </div>

      <div class="filter-pills" role="group" aria-label="Filter">
        <!-- Event filters -->
        <button class="filter-pill active" data-filter="ALL">
          All <span class="pill-count" id="pillCount-ALL"><?= count($events) ?></span>
        </button>
        <?php if ($counts['OPEN']): ?>
          <button class="filter-pill" data-filter="OPEN">
            <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4" fill="currentColor"/></svg>
            Open <span class="pill-count" id="pillCount-OPEN"><?= $counts['OPEN'] ?></span>
          </button>
        <?php endif; ?>
        <?php if ($counts['REQUIRED']): ?>
          <button class="filter-pill" data-filter="REQUIRED">
            <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Required <span class="pill-count" id="pillCount-REQUIRED"><?= $counts['REQUIRED'] ?></span>
          </button>
        <?php endif; ?>
        <?php if ($counts['JOINED']): ?>
          <button class="filter-pill" data-filter="JOINED">
            <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Joined <span class="pill-count" id="pillCount-JOINED"><?= $counts['JOINED'] ?></span>
          </button>
        <?php endif; ?>
        <?php if ($counts['FULL']): ?>
          <button class="filter-pill" data-filter="FULL">
            <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Full <span class="pill-count" id="pillCount-FULL"><?= $counts['FULL'] ?></span>
          </button>
        <?php endif; ?>

        <!-- Announcements filter pill -->
        <button class="filter-pill" data-filter="ANNOUNCEMENTS" style="margin-left:.25rem;border-left:2px solid var(--border);padding-left:.75rem;">
          <svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l19-9-9 19-2-8-8-2z"/></svg>
          Announcements
          <span class="pill-count" id="pillCount-ANNOUNCEMENTS"><?= $annCount ?></span>
        </button>
      </div>

      <select class="sort-select" id="sortSelect" aria-label="Sort events">
        <option value="date-asc">Date ↑</option>
        <option value="date-desc">Date ↓</option>
        <option value="title-asc">Title A–Z</option>
        <option value="title-desc">Title Z–A</option>
      </select>
    </div>


    <!-- NO RESULTS (events) -->
    <div class="no-results" id="noResults">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--surface2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;margin:0 auto .875rem;">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24"
          fill="none" stroke="var(--ink3)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          <line x1="8" y1="11" x2="14" y2="11"/>
        </svg>
      </div>
      <div style="font-family:'Sora',sans-serif;font-weight:800;font-size:1rem;color:var(--ink);margin-bottom:.35rem;">No events match your search</div>
      <p style="font-size:.8rem;color:var(--ink3);margin-bottom:.875rem;">Try different keywords or clear the filter.</p>
      <button onclick="clearAllFilters()" class="modal-btn-primary" style="display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.25rem;width:auto;">
        Clear filters
      </button>
    </div>


    <!-- ══════════════════════════════════════════════════════
         ANNOUNCEMENTS PANEL — shown when ANNOUNCEMENTS pill is active
         NOTE: No duplicate search here. The main #searchInput above handles filtering.
    ══════════════════════════════════════════════════════ -->
    <div id="annPanel">

      <!-- Announcement cards — CSS grid defined in student_event.css -->
      <div id="annList">
        <?php if (!empty($announcements)): ?>
          <?php foreach ($announcements as $ann):
            $vb        = $annVisBadge[$ann['visibility']] ?? $annVisBadge['all'];
            $accentCls = $ann['is_pinned'] ? 'pinned' : $ann['visibility'];
            $author    = trim($ann['author_first'] . ' ' . $ann['author_last']) ?: 'Unknown';
            $visCls    = 'ann-vis-' . $ann['visibility'];
          ?>
            <div class="ann-card ann-item"
              data-title="<?= strtolower(htmlspecialchars($ann['title'])) ?>"
              data-body="<?= strtolower(htmlspecialchars($ann['body'])) ?>"
              data-source="<?= strtolower(htmlspecialchars($ann['source_name'])) ?>">

              <!-- Accent bar -->
              <div class="ann-accent <?= $accentCls ?>"></div>

              <div class="ann-body-inner">
                <!-- Title row -->
                <div style="display:flex;align-items:flex-start;gap:.5rem;margin-bottom:.2rem;flex-wrap:wrap;">
                  <div class="ann-title-txt" style="flex:1;min-width:0;">
                    <?php if ($ann['is_pinned']): ?>
                      <i data-lucide="pin" style="width:11px;height:11px;color:#f59e0b;margin-right:.2rem;vertical-align:middle;"></i>
                    <?php endif; ?>
                    <?= htmlspecialchars($ann['title']) ?>
                  </div>
                  <span class="ann-vis-badge <?= $visCls ?>" style="flex-shrink:0;">
                    <i data-lucide="<?= $ann['visibility'] === 'all' ? 'globe' : ($ann['visibility'] === 'dept' ? 'building-2' : 'users') ?>" style="width:9px;height:9px;"></i>
                    <?= $vb['label'] ?>
                  </span>
                </div>

                <!-- Body preview -->
                <p class="ann-preview"><?= htmlspecialchars($ann['body']) ?></p>

                <!-- Meta -->
                <div class="ann-meta-row">
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

          <!-- No match state for ann search -->
          <div id="annNoMatch" style="display:none;grid-column:1/-1;" class="ann-empty">
            <div class="ann-empty-icon">
              <i data-lucide="search-x" style="width:22px;height:22px;color:var(--ink4);"></i>
            </div>
            <div style="font-weight:600;font-size:.875rem;color:var(--ink);margin-bottom:.25rem;">No announcements match</div>
            <p style="font-size:.78rem;color:var(--ink3);">Try a different keyword.</p>
          </div>

        <?php else: ?>
          <div class="ann-empty" style="grid-column:1/-1;">
            <div class="ann-empty-icon">
              <i data-lucide="megaphone" style="width:22px;height:22px;color:var(--ink4);"></i>
            </div>
            <div style="font-weight:600;font-size:.875rem;color:var(--ink);margin-bottom:.25rem;">No announcements</div>
            <p style="font-size:.78rem;color:var(--ink3);">Announcements from your department or club will appear here.</p>
          </div>
        <?php endif; ?>
      </div>
    </div><!-- /annPanel -->


    <!-- EVENTS GRID -->
    <div id="eventsSection">
      <?php if (!empty($events)): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.125rem;" id="eventsGrid">
          <?php foreach ($events as $i => $event): ?>
            <div class="event-card"
              data-id="<?= $event['event_id'] ?>"
              data-title="<?= htmlspecialchars($event['title'], ENT_QUOTES) ?>"
              data-date="<?= date('F d, Y', strtotime($event['start_datetime'])) ?>"
              data-time="<?= date('h:i A', strtotime($event['start_datetime'])) ?> – <?= date('h:i A', strtotime($event['end_datetime'])) ?>"
              data-end="<?= date('F d, Y \– h:i A', strtotime($event['end_datetime'])) ?>"
              data-venue="<?= htmlspecialchars($event['venue_name'], ENT_QUOTES) ?>"
              data-organizer="<?= htmlspecialchars($event['organizer_name'] ?? 'Unknown Organizer', ENT_QUOTES) ?>"
              data-type="<?= htmlspecialchars($event['type_name'], ENT_QUOTES) ?>"
              data-status="<?= $event['status'] ?>"
              data-start-ts="<?= strtotime($event['start_datetime']) ?>">

              <div class="event-card-body">
                <div class="ev-header">
                  <div class="ev-title"><?= htmlspecialchars($event['title']) ?></div>
                  <?php
                    $sc = match($event['status']) { 'OPEN'=>'open','JOINED'=>'joined','REQUIRED'=>'required',default=>'full' };
                    $si = match($event['status']) { 'OPEN'=>'circle-dot','JOINED'=>'check-circle','REQUIRED'=>'alert-circle',default=>'x-circle' };
                  ?>
                  <span class="ev-status <?= $sc ?>">
                    <i data-lucide="<?= $si ?>" style="width:10px;height:10px;"></i> <?= $event['status'] ?>
                  </span>
                </div>
                <div class="ev-meta">
                  <div class="ev-meta-row"><i data-lucide="calendar" style="width:13px;height:13px;"></i><span><?= date('F d, Y', strtotime($event['start_datetime'])) ?></span></div>
                  <div class="ev-meta-row"><i data-lucide="clock" style="width:13px;height:13px;"></i><span><?= date('h:i A', strtotime($event['start_datetime'])) ?> – <?= date('h:i A', strtotime($event['end_datetime'])) ?></span></div>
                  <div class="ev-meta-row"><i data-lucide="map-pin" style="width:13px;height:13px;"></i><span><?= htmlspecialchars($event['venue_name']) ?></span></div>
                  <div class="ev-meta-row"><i data-lucide="user" style="width:13px;height:13px;"></i><span><?= htmlspecialchars($event['organizer_name'] ?? 'Unknown') ?></span></div>
                  <div class="ev-meta-row"><i data-lucide="tag" style="width:13px;height:13px;"></i><span><?= htmlspecialchars($event['type_name']) ?></span></div>
                </div>
              </div>

              <div class="event-card-footer">
                <?php if ($event['status'] === 'OPEN'): ?>
                  <button class="btn-action btn-register" onclick="openRegisterModal(this)">
                    <i data-lucide="check-circle"></i> Register
                  </button>
                <?php elseif ($event['status'] === 'REQUIRED'): ?>
                  <button class="btn-action btn-required" onclick="openRegisterModal(this)">
                    <i data-lucide="alert-circle"></i> Required — Register
                  </button>
                <?php elseif ($event['status'] === 'JOINED'): ?>
                  <button class="btn-action btn-joined" disabled>
                    <i data-lucide="check-check" style="width:14px;height:14px;"></i> Joined
                  </button>
                <?php else: ?>
                  <button class="btn-action btn-full" disabled>
                    <i data-lucide="x-circle" style="width:14px;height:14px;"></i> Full
                  </button>
                <?php endif; ?>
                <button class="btn-info" onclick="openDetailsModal(this)" title="View details">
                  <i data-lucide="info"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div id="paginator" class="paginator" role="navigation" aria-label="Events pagination"></div>
        <p class="page-info" id="pageInfo"></p>

      <?php else: ?>
        <div class="empty-state rise" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);">
          <div style="width:60px;height:60px;border-radius:14px;background:var(--purplebg);border:1px solid var(--purplebdr);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
            <i data-lucide="calendar-x" style="width:26px;height:26px;color:var(--purple);"></i>
          </div>
          <div style="font-family:'Sora',sans-serif;font-weight:800;font-size:1.2rem;color:var(--ink);margin-bottom:.5rem;">No Events Available</div>
          <div style="font-size:.84rem;color:var(--ink3);max-width:360px;margin:0 auto 1.5rem;line-height:1.6;">There are no upcoming events at the moment.</div>
          <button onclick="location.reload()" class="modal-btn-primary" style="display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.375rem;width:auto;">
            <i data-lucide="refresh-cw" style="width:14px;height:14px;"></i> Refresh
          </button>
        </div>
      <?php endif; ?>
    </div><!-- /eventsSection -->

  </div>
  </main>


  <!-- DETAILS MODAL -->
  <div class="modal-wrap" id="detailsModal">
    <div class="modal-box">
      <div class="modal-head">
        <div class="modal-title" id="detailsTitle"></div>
        <button onclick="closeModal('detailsModal')" class="icon-btn" style="width:30px;height:30px;flex-shrink:0;">
          <i data-lucide="x" style="width:15px;height:15px;"></i>
        </button>
      </div>
      <div class="modal-body" style="padding-top:1rem;padding-bottom:1rem;">
        <div style="margin-bottom:1rem;"><span id="detailsBadge" class="ev-status"></span></div>
        <div>
          <div class="modal-row"><i data-lucide="tag" style="width:16px;height:16px;"></i><span>Type: <strong id="detailsType"></strong></span></div>
          <div class="modal-row"><i data-lucide="calendar" style="width:16px;height:16px;"></i><span>Start: <strong id="detailsDate"></strong></span></div>
          <div class="modal-row"><i data-lucide="calendar-check" style="width:16px;height:16px;"></i><span>End: <strong id="detailsEnd"></strong></span></div>
          <div class="modal-row"><i data-lucide="map-pin" style="width:16px;height:16px;"></i><span id="detailsVenue"></span></div>
          <div class="modal-row"><i data-lucide="user" style="width:16px;height:16px;"></i><span>Organizer: <strong id="detailsOrganizer"></strong></span></div>
        </div>
      </div>
      <div class="modal-foot"><button onclick="closeModal('detailsModal')" class="modal-btn-primary">Close</button></div>
    </div>
  </div>

  <!-- REGISTER MODAL -->
  <div class="modal-wrap" id="registerModal">
    <div class="modal-box" style="max-width:400px;text-align:center;">
      <div style="padding:2rem 2rem 1.5rem;">
        <div style="width:56px;height:56px;border-radius:14px;background:var(--purplebg);border:1px solid var(--purplebdr);display:flex;align-items:center;justify-content:center;margin:0 auto 1.125rem;">
          <i data-lucide="calendar-plus" style="width:24px;height:24px;color:var(--purple);"></i>
        </div>
        <div style="font-family:'Sora',sans-serif;font-weight:800;font-size:1.2rem;color:var(--ink);margin-bottom:.5rem;">Confirm Registration</div>
        <p style="font-size:.84rem;color:var(--ink3);line-height:1.6;margin-bottom:1.5rem;">
          You are about to register for<br>
          <strong id="registerEventName" style="color:var(--ink);font-weight:700;"></strong>
        </p>
        <div style="display:flex;gap:.625rem;">
          <button onclick="closeModal('registerModal')" class="modal-btn-secondary">Cancel</button>
          <button id="confirmRegisterBtn" onclick="submitRegister()" class="modal-btn-confirm">Yes, Register</button>
        </div>
      </div>
    </div>
  </div>

  <!-- SUCCESS MODAL -->
  <div class="modal-wrap" id="successModal">
    <div class="modal-box success-box" style="max-width:380px;">
      <div style="padding:2.25rem 2rem;text-align:center;">
        <div style="position:relative;width:72px;height:72px;margin:0 auto 1.375rem;">
          <div style="position:absolute;inset:0;border-radius:50%;background:rgba(124,58,237,.15);animation:ping 1.2s ease-out infinite;"></div>
          <div class="check-anim" style="position:relative;width:72px;height:72px;border-radius:50%;background:var(--purplebg);border:2px solid var(--purplebdr);display:flex;align-items:center;justify-content:center;">
            <i data-lucide="check" style="width:32px;height:32px;color:var(--purple);stroke-width:3;"></i>
          </div>
        </div>
        <div style="font-family:'Sora',sans-serif;font-weight:800;font-size:1.4rem;color:var(--ink);margin-bottom:.5rem;line-height:1.2;">Registration Confirmed!</div>
        <p style="font-size:.84rem;color:var(--ink3);line-height:1.6;margin-bottom:1.375rem;">
          You have successfully joined<br>
          <strong id="successEventName" style="color:var(--ink);font-weight:700;font-size:.9rem;"></strong>
        </p>
        <div style="display:flex;align-items:center;justify-content:center;gap:.625rem;flex-wrap:wrap;margin-bottom:1.125rem;">
          <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:7px;background:var(--greenbg);color:var(--green);border:1px solid var(--greenbdr);font-size:.72rem;font-weight:700;">
            <i data-lucide="check-circle" style="width:12px;height:12px;"></i> Slot Reserved
          </span>
          <a href="student_myqr.php" style="display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:7px;background:var(--purplebg);color:var(--purple);border:1px solid var(--purplebdr);font-size:.72rem;font-weight:700;text-decoration:none;">
            <i data-lucide="qr-code" style="width:12px;height:12px;"></i> View QR Code
          </a>
        </div>
        <p style="font-size:.75rem;color:var(--ink4);margin-bottom:1.375rem;">Use this QR code to mark your attendance.</p>
        <button onclick="closeModal('successModal')" class="modal-btn-primary">Done</button>
      </div>
    </div>
  </div>

  <form id="registerForm" method="POST" action="student_event.php" style="display:none;">
    <input type="hidden" name="event_id" id="registerEventId">
  </form>

  <div class="toast" id="toast">
    <i data-lucide="alert-circle" style="width:17px;height:17px;flex-shrink:0;"></i>
    <span id="toastMsg"></span>
  </div>

  <style>
    @keyframes ping { 0% { transform:scale(1); opacity:.6 } 75%,100% { transform:scale(1.8); opacity:0 } }
    @keyframes spin  { to { transform:rotate(360deg) } }
  </style>

  <script src="/js/student_event.js"></script>
</body>
</html>