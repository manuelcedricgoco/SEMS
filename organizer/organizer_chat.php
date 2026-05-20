<?php
/* ============================================================
 *  organizer_chat.php — SEMS Messenger (Organizer Side)
 * ============================================================ */
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
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

// ── Organizer profile ─────────────────────────────────────────
$profileStmt = $pdo->prepare("
    SELECT
        COALESCE(p.first_name,  o.first_name)  AS first_name,
        COALESCE(p.last_name,   o.last_name)   AS last_name,
        COALESCE(p.middle_name, o.middle_name) AS middle_name,
        COALESCE(p.profile_image, o.profile_image) AS profile_image,
        o.position,
        d.dept_name
    FROM users u
    LEFT JOIN profiles  p ON u.user_id = p.user_id
    LEFT JOIN organizer o ON u.user_id = o.user_id
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE u.user_id = ?
");
$profileStmt->execute([$uid]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

$middleName = !empty($profile['middle_name'])
    ? ' ' . strtoupper(substr($profile['middle_name'], 0, 1)) . '. '
    : ' ';
$fullName        = trim(($profile['first_name'] ?? '') . $middleName . ($profile['last_name'] ?? '')) ?: 'Organizer';
$initials        = strtoupper(substr($profile['first_name'] ?? 'O', 0, 1) . substr($profile['last_name'] ?? '', 0, 1));
$position        = $profile['position'] ?? 'Organizer';
$hasProfileImage = false;
$profileMime     = 'image/jpeg';
$profileImgData  = '';

if (!empty($profile['profile_image'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $det   = $finfo->buffer($profile['profile_image']);
    if ($det && strpos($det, 'image/') === 0) $profileMime = $det;
    $profileImgData  = base64_encode($profile['profile_image']);
    $hasProfileImage = true;
}

// ── Org / club info ───────────────────────────────────────────
$ctxStmt = $pdo->prepare("
    SELECT u.org_id, u.club_id, u.dept_id,
           o.org_name, o.scope AS org_scope, o.logo AS org_logo,
           c.club_name
    FROM users u
    LEFT JOIN organizations o ON u.org_id  = o.org_id
    LEFT JOIN clubs         c ON u.club_id = c.club_id
    WHERE u.user_id = ?
");
$ctxStmt->execute([$uid]);
$ctx = $ctxStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$orgName     = $ctx['org_name']  ?? ($ctx['club_name'] ?? 'Organizer');
$orgType     = !empty($ctx['org_name']) ? 'Organization' : (!empty($ctx['club_name']) ? 'Club' : 'Staff');
$orgLogo     = $ctx['org_logo'] ?? null;
$hasOrgLogo  = false;
$orgLogoData = '';
$orgMime     = 'image/jpeg';
if (!empty($orgLogo)) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $dt = $fi->buffer($orgLogo);
    if ($dt && strpos($dt, 'image/') === 0) $orgMime = $dt;
    $orgLogoData = base64_encode($orgLogo);
    $hasOrgLogo  = true;
}

// ── Scope label ───────────────────────────────────────────────
$scopeLabel = 'All Students';
if (!empty($ctx['org_name']) && ($ctx['org_scope'] ?? '') === 'dept') {
    $scopeLabel = $profile['dept_name'] ?? 'Department';
} elseif (!empty($ctx['club_name'])) {
    $scopeLabel = $ctx['club_name'] . ' members';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages – SEMS</title>
    <link rel="stylesheet" href="/CSS/organizer_chat.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        brand: {
                            50:'#f0fdf4', 100:'#dcfce7', 200:'#bbf7d0', 300:'#86efac',
                            400:'#4ade80', 500:'#22c55e', 600:'#16a34a', 700:'#15803d',
                            800:'#166534', 900:'#14532d',
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

<!-- Backdrop -->
<div id="sb-overlay" onclick="closeSidebar()"></div>

<!-- Hidden file input -->
<input type="file" id="fileInput" multiple accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt"
       style="display:none">

<!-- ═══════════════════ UNSEND MODAL ═══════════════════ -->
<div id="unsendModal" class="unsend-modal" onclick="handleUnsendBgClick(event)">
    <div class="unsend-modal-card">
        <div style="display:flex;align-items:flex-start;gap:.9rem;">
            <div class="unsend-modal-icon">
                <i class="fas fa-trash-alt"></i>
            </div>
            <div>
                <p class="unsend-modal-title">Unsend this message?</p>
                <p class="unsend-modal-body" style="margin-top:.35rem;">
                    This will remove the message for everyone in the conversation.
                    This action cannot be undone.
                </p>
            </div>
        </div>
        <div class="unsend-modal-actions">
            <button class="unsend-cancel-btn" onclick="closeUnsendModal()">Cancel</button>
            <button class="unsend-confirm-btn" id="unsendConfirmBtn" onclick="doUnsend()">
                <i class="fas fa-trash-alt" style="margin-right:.35rem;font-size:.8rem;"></i>Unsend
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside id="sidebar"
    class="fixed top-0 left-0 h-screen w-64 bg-white dark:bg-gray-800
           border-r border-gray-200 dark:border-gray-700
           flex flex-col z-50 -translate-x-full lg:translate-x-0">

    <!-- Brand -->
    <div class="p-5 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-3">
            <?php if ($hasOrgLogo): ?>
                <img src="data:<?= $orgMime ?>;base64,<?= $orgLogoData ?>"
                     class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                     alt="<?= htmlspecialchars($orgName) ?>">
            <?php else: ?>
                <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md">
                    <i class="fas fa-building text-white text-lg"></i>
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words"><?= htmlspecialchars($orgName) ?></p>
                <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                    <?= htmlspecialchars($orgType) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Nav links -->
    <nav class="flex-1 overflow-y-auto p-3 space-y-1">
        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>
        <a href="/organizer/organizer_panel.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center">
                <i class="fas fa-gauge-high"></i>
            </span>Dashboard
        </a>

        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>
        <a href="/organizer/organizer_event.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                <i class="fas fa-calendar-alt"></i>
            </span>Events &amp; Announcements
        </a>
        <a href="/organizer/organizer_qrscan.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                <i class="fas fa-qrcode"></i>
            </span>QR Scanner
        </a>

        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>
        <a href="/organizer/organizer_tracking.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center">
                <i class="fas fa-users"></i>
            </span>Registrations
        </a>
        <a href="/organizer/organizer_attendance.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                <i class="fas fa-user-check"></i>
            </span>Attendance
        </a>
        <a href="/organizer/organizer_analytics.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                <i class="fas fa-chart-line"></i>
            </span>Analytics
        </a>

        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Communication</p>
        <a href="/organizer/organizer_chat.php"
           class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
           aria-current="page">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-rose-100 dark:bg-rose-900/40 text-rose-500 dark:text-rose-400 flex items-center justify-center text-sm">
    <i class="fas fa-comments"></i>
</span>
            Messages
            <span id="sidebarBadge"
                  class="ml-auto text-[11px] bg-brand-500 text-white px-1.5 py-0.5 rounded-full font-semibold hidden"></span>
        </a>

        <a href="/organizer/organizer_admin_chat.php"
           class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-500 dark:text-indigo-400 flex items-center justify-center text-sm">
    <i class="fas fa-user-shield"></i>
</span>
            Admin Messages
            <span id="adminBadge" class="ml-auto hidden text-[10px] font-bold bg-brand-500 text-white rounded-full px-1.5 py-0.5"></span>
        </a>
    </nav>

    <!-- Footer -->
    <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
        <a href="/organizer/organizer_settings.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 flex items-center justify-center">
                <i class="fas fa-gear"></i>
            </span>Settings
        </a>
        <a href="../includes/logout.php"
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center">
                <i class="fas fa-right-from-bracket"></i>
            </span>Logout
        </a>

    </div>
</aside>

<!-- ═══════════════════ MAIN WRAPPER ═══════════════════ -->
<div class="lg:ml-64 flex flex-col min-h-screen">

    <!-- Topbar -->
    <header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-800/90
                   border-b border-gray-200 dark:border-gray-700 px-4 sm:px-6 py-3"
            style="backdrop-filter:blur(10px);">
        <div class="flex items-center gap-3">
            <button onclick="openSidebar()"
                    class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                           hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                <i class="fas fa-bars"></i>
            </button>
            <span class="font-semibold text-gray-900 dark:text-white">Messages</span>

            <span class="hidden sm:inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full
                         bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                <i class="fas fa-users text-[10px]"></i>
                <?= htmlspecialchars($scopeLabel) ?>
            </span>

            <div class="ml-auto flex items-center gap-2">
                <button onclick="toggleTheme()" title="Toggle theme"
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400
                               hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                    <i id="themeIcon" class="fas fa-moon text-sm"></i>
                </button>

                <!-- Name + position + avatar — mirrors organizer_qrscan.php header -->
                <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">
                    <div class="hidden sm:block text-right leading-tight">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($fullName) ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($position) ?></p>
                    </div>
                    <div class="w-8 h-8 rounded-full overflow-hidden bg-brand-500 flex items-center justify-center text-white text-xs font-semibold flex-shrink-0">
                        <?php if ($hasProfileImage): ?>
                            <img src="data:<?= $profileMime ?>;base64,<?= $profileImgData ?>" class="w-full h-full object-cover" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars($initials) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Chat shell -->
    <div class="chat-shell relative">

        <!-- LEFT: contact / conversation list -->
        <div class="chat-list-panel bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700" id="listPanel">

            <!-- Tabs -->
            <div class="flex px-1 pt-1 border-b border-gray-200 dark:border-gray-700">
                <button class="tab-btn active" id="tabConvs" onclick="switchTab('convs')">Chats</button>
                <button class="tab-btn"        id="tabNew"   onclick="switchTab('new')">New Message</button>
            </div>

            <!-- Search -->
            <div class="px-3 py-2.5 border-b border-gray-100 dark:border-gray-700">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input id="searchInput" type="search"
                           class="w-full pl-8 pr-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-gray-700
                                  border border-gray-200 dark:border-gray-600 outline-none
                                  text-gray-700 dark:text-gray-200 placeholder-gray-400
                                  focus:border-brand-400 transition-colors"
                           placeholder="Search students…"
                           oninput="filterList(this.value)">
                </div>
            </div>

            <!-- List -->
            <div class="flex-1 overflow-y-auto" id="listScrollArea">
                <div class="py-10 text-center text-sm text-gray-400" id="listEmpty">Loading…</div>
            </div>
        </div>

        <!-- RIGHT: thread -->
        <div class="chat-thread-panel" id="threadPanel">

            <!-- Empty state -->
            <div id="threadEmpty" class="flex flex-col items-center justify-center flex-1 gap-3 text-gray-400 p-8">
                <div class="w-14 h-14 rounded-2xl bg-brand-100 dark:bg-brand-900/30 flex items-center justify-center">
                    <i class="fas fa-comments text-2xl text-brand-500"></i>
                </div>
                <p class="font-semibold text-gray-700 dark:text-gray-200 text-sm">Select a student to message</p>
                <p class="text-xs text-center max-w-[200px] leading-relaxed">
                    Use the <strong>New Message</strong> tab to start a conversation, or click an existing chat.
                </p>
            </div>

            <!-- Thread view (flex-col, hidden until opened) -->
            <div id="threadView" class="hidden flex-col flex-1 overflow-hidden">

                <!-- Thread header -->
                <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <button class="back-btn p-2 rounded-lg bg-gray-100 dark:bg-gray-700
                                   text-gray-600 dark:text-gray-300 hover:bg-gray-200
                                   dark:hover:bg-gray-600 transition-colors"
                            onclick="showList()">
                        <i class="fas fa-arrow-left text-sm"></i>
                    </button>
                    <div class="c-avatar" id="threadAvatar"></div>
                    <div class="min-w-0">
                        <p class="font-semibold text-sm text-gray-900 dark:text-white truncate" id="threadName">—</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400" id="threadSub">—</p>
                    </div>
                </div>

                <!-- Messages -->
                <div class="msgs-area" id="messagesArea"></div>

                <!-- Input bar -->
                <div class="input-bar border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <!-- Attach button -->
                    <button class="attach-btn" onclick="triggerFileInput()" title="Send file or image">
                        <i class="fas fa-paperclip"></i>
                    </button>

                    <textarea id="msgInput" rows="1"
                        class="chat-ta bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600
                               text-gray-800 dark:text-gray-100 placeholder-gray-400
                               focus:border-brand-400 outline-none"
                        placeholder="Type a message…"
                        onkeydown="handleKey(event)"
                        oninput="autoResize(this)"></textarea>

                    <button class="send-btn" id="sendBtn" onclick="sendMessage()">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>

        </div><!-- /thread -->
    </div><!-- /chat-shell -->
</div><!-- /main wrapper -->

<script>
    /* ── Bootstrap data ── */
    const SEMS_CHAT = {
        myId:   <?= json_encode($uid) ?>,
        myName: <?= json_encode($fullName) ?>,
        myInit: <?= json_encode($initials) ?>,
        apiUrl: '../includes/chat_api.php',
    };

    /* ── Sidebar ── */
    function openSidebar() {
        document.getElementById('sidebar').style.transform = 'translateX(0)';
        document.getElementById('sb-overlay').classList.add('show');
    }
    function closeSidebar() {
        if (window.innerWidth < 1024)
            document.getElementById('sidebar').style.transform = 'translateX(-100%)';
        document.getElementById('sb-overlay').classList.remove('show');
    }

    /* ── Dark mode ── */
    function toggleTheme() {
        const html = document.documentElement;
        const icon = document.getElementById('themeIcon');
        if (html.classList.contains('dark')) {
            html.classList.remove('dark');
            localStorage.setItem('theme','light');
            icon.className = 'fas fa-moon text-sm';
        } else {
            html.classList.add('dark');
            localStorage.setItem('theme','dark');
            icon.className = 'fas fa-sun text-sm';
        }
    }
    (function(){
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
            const icon = document.getElementById('themeIcon');
            if (icon) icon.className = 'fas fa-sun text-sm';
        }
    })();
</script>
<script src="/js/sems_chat.js"></script>

</body>
</html>