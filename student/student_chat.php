<?php
/* ============================================================
 *  student_chat.php — SEMS Messenger (Student Side)
 * ============================================================ */
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$archCheck = $pdo->prepare("SELECT deleted_at FROM users WHERE user_id = ? LIMIT 1");
$archCheck->execute([$_SESSION['user_id']]);
$archRow = $archCheck->fetch(PDO::FETCH_ASSOC);
if (!$archRow || !empty($archRow['deleted_at'])) {
    session_destroy();
    header("Location: ../includes/auth.php?error=archived");
    exit();
}

$uid = (int) $_SESSION['user_id'];

$welcomeName     = 'Student';
$profileInitials = 'S';
$imageData       = null;
$imageMime       = 'image/jpeg';

$stmt = $pdo->prepare("SELECT p.first_name, p.last_name, p.middle_name, p.profile_image FROM profiles p WHERE p.user_id = :uid");
$stmt->execute(['uid' => $uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $mn = !empty($row['middle_name']) ? ' ' . strtoupper(substr(trim($row['middle_name']), 0, 1)) . '.' : '';
    $welcomeName     = trim(($row['first_name'] ?? '') . $mn . ' ' . ($row['last_name'] ?? '')) ?: 'Student';
    $profileInitials = strtoupper(substr($row['first_name'] ?? 'S', 0, 1) . substr($row['last_name'] ?? '', 0, 1)) ?: 'S';
    if (!empty($row['profile_image'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $det   = $finfo->buffer($row['profile_image']);
        if ($det && strpos($det, 'image/') === 0) $imageMime = $det;
        $imageData = base64_encode($row['profile_image']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Messages | SEMS</title>
  <link rel="stylesheet" href="/CSS/student_chat.css">

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <!-- All styles live in student_chat.css -->
  <link rel="stylesheet" href="/CSS/student_chat.css">

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

  <!-- ═══════════════════════════════════════════════════════
       BEFORE-PAINT THEME IIFE
       Must stay inline in <head> to prevent flash of wrong theme.
       Uses the same key ('sems-dark') as student_dashboard.js
       so both pages share a single persistent preference.
  ═══════════════════════════════════════════════════════════ -->
  <script>
    (function () {
      var stored  = localStorage.getItem('sems-dark');
      var sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
      var isDark  = stored !== null ? stored === 'true' : sysDark;
      if (isDark) document.documentElement.classList.add('dark');
    })();
  </script>

</head>

<body>

<!-- Overlay -->
<div id="overlay" onclick="closeSidebar()"></div>

<!-- ═══════════════════ UNSEND MODAL ═══════════════════ -->
<div id="unsendModal" class="unsend-modal" onclick="handleUnsendBgClick(event)">
  <div class="unsend-modal-card">
    <div style="display:flex;align-items:flex-start;gap:12px;">
      <div class="unsend-modal-icon">
        <i data-lucide="trash-2" style="width:18px;height:18px;"></i>
      </div>
      <div>
        <p class="unsend-modal-title">Unsend this message?</p>
        <p class="unsend-modal-body" style="margin-top:5px;">
          This will remove the message for everyone in the conversation and cannot be undone.
        </p>
      </div>
    </div>
    <div class="unsend-modal-actions">
      <button class="unsend-cancel-btn" onclick="closeUnsendModal()">Cancel</button>
      <button class="unsend-confirm-btn" id="unsendConfirmBtn" onclick="doUnsend()">
        Unsend
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside class="sidebar" id="sidebar" aria-label="Main navigation">

  <!-- Brand -->
  <div class="sidebar-brand">
  <div class="sb-logo" aria-hidden="true"
    style="width:38px;height:38px;border-radius:10px;flex-shrink:0;
           display:flex;align-items:center;justify-content:center;
           background:linear-gradient(135deg,#6d28d9 0%,#7c3aed 40%,#a855f7 100%);
           box-shadow:0 4px 14px rgba(139,92,246,.55),0 0 0 3px rgba(167,139,250,.18);
           border:1px solid rgba(167,139,250,.3);">
    <i data-lucide="graduation-cap" style="width:18px;height:18px;color:#fff;filter:drop-shadow(0 1px 3px rgba(0,0,0,.3));"></i>
  </div>
  <div>
    <div class="brand-name">SEMS</div>
    <div class="brand-tagline">Student Portal</div>
  </div>
</div>

  <!-- Nav -->
  <nav class="sidebar-nav" aria-label="Site navigation">

    <div class="nav-group-label">Overview</div>
    <a href="student_dashboard.php" class="nav-item">
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
    <a href="student_chat.php" class="nav-item active" aria-current="page">
      <i data-lucide="message-circle" style="width:15px;height:15px;"></i>
      Messages
      <span id="sidebarBadge" class="sb-badge" style="display:none;"></span>
    </a>

    <div class="nav-group-label">Account</div>
    <a href="student_settings.php" class="nav-item">
      <i data-lucide="settings" style="width:15px;height:15px;"></i>
      Settings
    </a>

  </nav>

  <!-- Footer -->
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar avatar-sm">
        <?php if ($imageData): ?>
          <img src="data:<?= $imageMime ?>;base64,<?= $imageData ?>" alt="Profile photo">
        <?php else: ?>
          <?= htmlspecialchars($profileInitials) ?>
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

<!-- ═══════════════════ MAIN WRAPPER ═══════════════════ -->
<div class="main-wrapper">

  <!-- Topbar -->
  <header class="topbar">
    <button onclick="openSidebar()" aria-label="Open navigation"
            class="icon-btn lg:hidden">
      <i data-lucide="menu" style="width:17px;height:17px;"></i>
    </button>

    <div style="display:flex;align-items:center;gap:8px;">
      <div style="width:30px;height:30px;border-radius:8px;background:var(--purplebg);border:1px solid var(--purplebdr);display:flex;align-items:center;justify-content:center;">
        <i data-lucide="message-circle" style="width:14px;height:14px;color:var(--purple);"></i>
      </div>
      <span style="font-family:'Sora',sans-serif;font-size:14px;font-weight:700;color:var(--ink);">Messages</span>
    </div>

    <div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
      <button onclick="toggleDark()" title="Toggle theme" class="icon-btn" aria-label="Toggle dark mode">
        <i id="themeIcon" data-lucide="moon" style="width:15px;height:15px;"></i>
      </button>
      <div class="avatar avatar-sm">
        <?php if ($imageData): ?>
          <img src="data:<?= $imageMime ?>;base64,<?= $imageData ?>" alt="">
        <?php else: ?>
          <?= htmlspecialchars($profileInitials) ?>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- ── Chat Shell ── -->
  <div class="chat-shell">

    <!-- LEFT: Contact list panel -->
    <div class="chat-list-panel" id="listPanel">

      <div class="tab-bar">
        <button class="tab-btn active" id="tabConvs" onclick="switchTab('convs')">Chats</button>
        <button class="tab-btn"        id="tabNew"   onclick="switchTab('new')">New Message</button>
      </div>

      <div class="search-bar">
        <div class="search-wrap">
          <span class="s-icon">
            <i data-lucide="search" style="width:13px;height:13px;"></i>
          </span>
          <input id="searchInput" type="search" class="search-input"
                 placeholder="Search conversations…"
                 oninput="filterList(this.value)">
        </div>
      </div>

      <div class="list-scroll" id="listScrollArea">
        <div class="list-empty" id="listEmpty">Loading…</div>
      </div>

    </div>

    <!-- RIGHT: Thread panel -->
    <div class="chat-thread-panel" id="threadPanel">

      <!-- Empty state -->
      <div id="threadEmpty" class="thread-empty">
        <div class="empty-icon-box">
          <i data-lucide="message-circle" style="width:24px;height:24px;color:var(--purple);"></i>
        </div>
        <p class="thread-empty-title">Select a conversation</p>
        <p class="thread-empty-sub">Choose an organizer from the list to start messaging.</p>
      </div>

      <!-- Thread view -->
      <div id="threadView" class="hidden" style="flex-direction:column;flex:1;overflow:hidden;display:none;">

        <div class="thread-header">
          <button class="back-btn icon-btn" onclick="showList()" aria-label="Back">
            <i data-lucide="arrow-left" style="width:15px;height:15px;"></i>
          </button>

          <div class="c-avatar" id="threadAvatar" style="width:36px;height:36px;font-size:12px;flex-shrink:0;"></div>

          <div style="min-width:0;flex:1;">
            <p class="thread-name" id="threadName">—</p>
            <p class="thread-sub" id="threadSub">—</p>
          </div>
        </div>

        <div class="msgs-area" id="messagesArea"></div>

        <div class="input-bar">
          <input type="file" id="fileInput" multiple
                 accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
                 class="hidden" style="display:none;">
          <button class="attach-btn" onclick="triggerFileInput()" title="Attach file">
            <i data-lucide="paperclip" style="width:15px;height:15px;"></i>
          </button>
          <textarea id="msgInput" rows="1" class="chat-ta"
            placeholder="Type a message…"
            onkeydown="handleKey(event)"
            oninput="autoResize(this)"></textarea>
          <button class="send-btn" id="sendBtn" onclick="sendMessage()">
            <i data-lucide="send" style="width:15px;height:15px;color:#fff;"></i>
          </button>
        </div>

      </div><!-- /threadView -->

    </div><!-- /threadPanel -->

  </div><!-- /chat-shell -->

</div><!-- /main-wrapper -->

<!-- Image lightbox -->
<div class="img-lightbox" id="imgLightbox">
  <div class="lb-backdrop" onclick="closeLightbox()"></div>
  <div class="lb-inner">
    <img id="lbImg" src="" alt="Preview">
    <button class="lb-close" onclick="closeLightbox()">×</button>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     PHP → JS bridge
═══════════════════════════════════════════════════════════ -->
<script>
  const SEMS_CHAT = {
    myId:   <?= json_encode($uid) ?>,
    myName: <?= json_encode($welcomeName) ?>,
    myInit: <?= json_encode($profileInitials) ?>,
    apiUrl: '../includes/chat_api.php',
  };
</script>

<script src="/js/student_chat_ui.js"></script>
<script src="/js/sems_chat.js"></script>
<script> lucide.createIcons(); </script>

</body>
</html>