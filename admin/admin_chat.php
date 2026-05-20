<?php
/* ============================================================
 *  admin/admin_chat.php — SEMS Admin ↔ Organizer Messenger
 * ============================================================ */
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$adminUserId = (int) $_SESSION['user_id'];

// ── Admin profile ──────────────────────────────────────────────
$adminStmt = $pdo->prepare("
    SELECT a.first_name, a.last_name, a.middle_name, a.profile_image, u.email
    FROM admin a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.user_id = ?
    LIMIT 1
");
$adminStmt->execute([$adminUserId]);
$adminData = $adminStmt->fetch(PDO::FETCH_ASSOC);

$adminFirstName  = $adminData['first_name']  ?? '';
$adminLastName   = $adminData['last_name']   ?? '';
$adminMiddleName = $adminData['middle_name'] ?? '';

$adminMiddleInitial = !empty($adminMiddleName) ? strtoupper(substr($adminMiddleName, 0, 1)) . '.' : '';
$adminFullName      = trim($adminFirstName . ' ' . $adminMiddleInitial . ' ' . $adminLastName) ?: 'Administrator';
$adminInitials      = strtoupper(
    substr($adminFirstName,  0, 1) .
    substr($adminMiddleName, 0, 1) .
    substr($adminLastName,   0, 1)
) ?: 'A';

$hasAvatar  = false;
$avatarMime = 'image/jpeg';
$avatarData = '';
if (!empty($adminData['profile_image'])) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $dt = $fi->buffer($adminData['profile_image']);
    if ($dt && strpos($dt, 'image/') === 0) $avatarMime = $dt;
    $avatarData = base64_encode($adminData['profile_image']);
    $hasAvatar  = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages – SEMS Admin</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        primary: {
                            50:'#eff6ff', 100:'#dbeafe', 400:'#60a5fa',
                            500:'#3b82f6', 600:'#2563eb', 700:'#1d4ed8',
                        }
                    },
                    keyframes: {
                        fadeIn:    { '0%': { opacity: '0', transform: 'translateY(8px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        slideInLeft: { '0%': { opacity: '0', transform: 'translateX(-16px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        slideInRight: { '0%': { opacity: '0', transform: 'translateX(16px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        pulseRing: { '0%,100%': { boxShadow: '0 0 0 0 rgba(59,130,246,0.4)' }, '50%': { boxShadow: '0 0 0 6px rgba(59,130,246,0)' } },
                        shimmer:   { '0%': { backgroundPosition: '-200% 0' }, '100%': { backgroundPosition: '200% 0' } },
                        scaleIn:   { '0%': { opacity: '0', transform: 'scale(0.92)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                        spin:      { '0%': { transform: 'rotate(0deg)' }, '100%': { transform: 'rotate(360deg)' } },
                    },
                    animation: {
                        'fade-in':      'fadeIn .35s ease both',
                        'slide-left':   'slideInLeft .3s ease both',
                        'slide-right':  'slideInRight .3s ease both',
                        'scale-in':     'scaleIn .25s cubic-bezier(.34,1.56,.64,1) both',
                        'pulse-ring':   'pulseRing 2s ease infinite',
                    }
                }
            }
        }
    </script>

    <style>
        /* ════════════════════════════════════════════
           BASE TRANSITIONS & FOCUS STYLES
           ════════════════════════════════════════════ */
        *, *::before, *::after {
            transition-property: background-color, border-color, color, fill, stroke, opacity, box-shadow, transform, filter;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 150ms;
        }

        :focus-visible {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
            border-radius: 6px;
        }

        /* ════════════════════════════════════════════
           SCROLLBAR STYLING
           ════════════════════════════════════════════ */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .dark ::-webkit-scrollbar-thumb { background: #334155; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #475569; }

        /* ════════════════════════════════════════════
           SIDEBAR
           ════════════════════════════════════════════ */
        #sidebar {
            transition: transform .3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        @media(min-width:1024px) { #sidebar { transform:translateX(0)!important; } }

        #overlay {
            transition: opacity .25s ease, backdrop-filter .25s ease;
        }

        /* ── Nav Items ── */
        .nav-item {
            transition: background .18s ease, color .18s ease, transform .15s ease, box-shadow .18s ease;
            position: relative;
            overflow: hidden;
            /* Default color for non-active items */
            color: #475569;
        }
        .dark .nav-item {
            color: #94a3b8;
        }
        .nav-item::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent);
            transform: translateX(-100%);
            transition: transform 0s;
        }
        .nav-item:hover::after {
            transform: translateX(100%);
            transition: transform .5s ease;
        }
        .nav-item:hover {
            background: #eff6ff;
            color: #2563eb;
            transform: translateX(2px);
        }
        .nav-item:active {
            transform: translateX(2px) scale(0.98);
        }
        .nav-item.active {
            background: #eff6ff;
            color: #2563eb;
            border-left: 3px solid #3b82f6;
            padding-left: calc(0.80rem - 3px);
            border-radius: 10px;
        }
        .dark .nav-item:hover {
            background: rgba(59,130,246,.12);
            color: #60a5fa;
        }
        .dark .nav-item.active {
            background: rgba(59,130,246,.12);
            color: #60a5fa;
            border-left: 3px solid #60a5fa;
            padding-left: calc(0.75rem - 3px);
        }

        /* ── Sidebar logo hover ── */
        .sidebar-logo {
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .sidebar-logo:hover {
            transform: rotate(-5deg) scale(1.08);
            box-shadow: 0 8px 24px rgba(59,130,246,.4);
        }

        /* ════════════════════════════════════════════
           TOPBAR / HEADER
           ════════════════════════════════════════════ */
        .topbar {
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: box-shadow .2s ease, background .2s ease;
        }
        .topbar.scrolled {
            box-shadow: 0 2px 20px rgba(0,0,0,.08);
        }

        /* ── Avatar in topbar ── */
        .topbar-avatar-wrap {
            transition: transform .2s cubic-bezier(.34,1.56,.64,1);
        }
        .topbar-avatar-wrap:hover {
            transform: scale(1.08);
        }
        .topbar-avatar-wrap:hover .online-dot {
            animation: pulseRing 1.5s ease infinite;
        }

        @keyframes pulseRing {
            0%,100% { box-shadow: 0 0 0 0 rgba(16,185,129,0.5); }
            50%      { box-shadow: 0 0 0 5px rgba(16,185,129,0); }
        }

        /* ════════════════════════════════════════════
           CHAT LAYOUT
           ════════════════════════════════════════════ */
        .chat-shell {
            display: flex;
            height: calc(100vh - 57px);
            overflow: hidden;
        }

        /* ════════════════════════════════════════════
           LEFT PANEL — CONTACT LIST
           ════════════════════════════════════════════ */
        .chat-list-panel {
            width: 300px; min-width: 300px; flex-shrink: 0;
            display: flex; flex-direction: column;
            border-right: 1px solid;
            overflow: hidden;
            transition: width .3s ease;
        }
        @media (max-width:680px) {
            .chat-list-panel { width:100%; min-width:unset; }
            .chat-list-panel.hide { display:none; }
            .chat-thread-panel { width:100%; }
        }

        /* ── Contact rows ── */
        .contact-row {
            display: flex; align-items: center; gap: .75rem;
            padding: .65rem 1rem; cursor: pointer;
            border-left: 3px solid transparent;
            transition: background .15s ease, border-color .15s ease, transform .12s ease;
            animation: fadeIn .3s ease both;
        }
        .contact-row:hover {
            background: rgba(59,130,246,.06);
            transform: translateX(2px);
        }
        .contact-row:active {
            transform: translateX(2px) scale(0.99);
        }
        .contact-row.active {
            background: rgba(59,130,246,.1);
            border-left-color: #3b82f6;
        }

        /* ── Contact avatar ── */
        .c-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: linear-gradient(135deg, #1d4ed8, #60a5fa);
            color: #fff; font-weight: 600; font-size: .8rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; overflow: hidden;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .contact-row:hover .c-avatar {
            transform: scale(1.06);
            box-shadow: 0 4px 12px rgba(59,130,246,.3);
        }

        .unread-dot {
            background: #3b82f6; color: #fff; border-radius: 999px;
            font-size: .63rem; font-weight: 700; padding: .1rem .4rem;
            min-width: 18px; text-align: center;
            animation: scaleIn .2s cubic-bezier(.34,1.56,.64,1);
        }

        @keyframes scaleIn {
            from { transform: scale(0); opacity: 0; }
            to   { transform: scale(1); opacity: 1; }
        }

        /* ── Tab buttons ── */
        .tab-btn {
            flex: 1; padding: .55rem .5rem; font-size: .78rem; font-weight: 600;
            border-bottom: 2px solid transparent;
            color: #94a3b8; cursor: pointer;
            transition: color .2s ease, border-color .2s ease, background .15s ease;
            background: none; border-top: none; border-left: none; border-right: none;
        }
        .tab-btn:hover:not(.active) {
            color: #64748b;
            background: rgba(0,0,0,.02);
        }
        .tab-btn.active {
            color: #3b82f6;
            border-bottom-color: #3b82f6;
        }
        .dark .tab-btn         { color: #64748b; }
        .dark .tab-btn:hover:not(.active) { color: #94a3b8; background: rgba(255,255,255,.03); }
        .dark .tab-btn.active  { color: #60a5fa; border-bottom-color: #60a5fa; }

        /* ── Search input ── */
        #searchInput {
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }
        #searchInput:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }

        /* ════════════════════════════════════════════
           RIGHT THREAD PANEL
           ════════════════════════════════════════════ */
        .chat-thread-panel {
            flex: 1; display: flex; flex-direction: column; overflow: hidden;
            background: #f8fafc;
            transition: background .2s ease;
        }
        .dark .chat-thread-panel { background: #0f172a; }

        .msgs-area {
            flex: 1; overflow-y: auto; padding: 1rem;
            display: flex; flex-direction: column; gap: .2rem;
        }

        /* ════════════════════════════════════════════
           MESSAGES
           ════════════════════════════════════════════ */
        .msg-row {
            display: flex;
            justify-content: flex-start;
            margin-bottom: .3rem;
            animation: fadeIn .25s ease both;
        }
        .msg-row.mine {
            justify-content: flex-end;
        }

        .bubble-outer { display: flex; flex-direction: column; max-width: 68%; }
        .bubble-outer.mine { align-items: flex-end; }

        .bubble-and-react {
            display: flex; align-items: center; gap: .35rem;
        }
        .bubble-and-react.mine { flex-direction: row-reverse; }

        .bubble {
            padding: .5rem .85rem; border-radius: 18px;
            font-size: .84rem; line-height: 1.5; word-break: break-word;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .bubble:hover {
            transform: translateY(-1px);
        }
        .bubble.mine {
            background: #3b82f6; color: #fff;
            border-bottom-right-radius: 4px;
            box-shadow: 0 2px 8px rgba(59,130,246,.25);
        }
        .bubble.mine:hover {
            box-shadow: 0 4px 16px rgba(59,130,246,.35);
        }
        .bubble.theirs {
            background: #fff; color: #1e293b;
            border: 1px solid #e2e8f0; border-bottom-left-radius: 4px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .bubble.theirs:hover {
            box-shadow: 0 3px 12px rgba(0,0,0,.1);
        }
        .dark .bubble.theirs {
            background: #1e293b; color: #e2e8f0;
            border-color: #334155;
            box-shadow: 0 1px 4px rgba(0,0,0,.2);
        }
        .dark .bubble.theirs:hover {
            box-shadow: 0 3px 12px rgba(0,0,0,.3);
        }

        .msg-ts {
            font-size: .64rem; color: #94a3b8;
            margin-top: .15rem; padding: 0 .2rem;
            transition: opacity .15s ease;
        }
        .msg-ts.mine { text-align: right; }
        .msg-row:not(:hover) .msg-ts { opacity: .6; }

        /* ── React trigger ── */
        .react-trigger {
            opacity: 0; font-size: .75rem; color: #94a3b8;
            background: none; border: none; cursor: pointer; padding: .2rem;
            transition: opacity .15s ease, color .15s ease, transform .15s ease;
            border-radius: 50%;
        }
        .react-trigger:hover {
            color: #3b82f6;
            transform: scale(1.15);
        }
        .bubble-and-react:hover .react-trigger { opacity: 1; }

        /* ── Reaction picker ── */
        .reaction-picker {
            display: flex; gap: .25rem; padding: .4rem .6rem;
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 999px;
            box-shadow: 0 8px 24px rgba(0,0,0,.14);
            opacity: 0; transform: scale(.85) translateY(6px);
            transition: opacity .18s ease, transform .18s cubic-bezier(.34,1.56,.64,1);
        }
        .dark .reaction-picker { background: #1e293b; border-color: #334155; }
        .reaction-picker.visible { opacity: 1; transform: scale(1) translateY(0); }

        .react-emoji-btn {
            font-size: 1.1rem; background: none; border: none; cursor: pointer;
            padding: .15rem; border-radius: 4px;
            transition: transform .12s cubic-bezier(.34,1.56,.64,1);
        }
        .react-emoji-btn:hover { transform: scale(1.3); }

        /* ── Reaction chips ── */
        .reactions-row {
            display: flex; flex-wrap: wrap; gap: .25rem;
            margin-top: .2rem; padding: 0 .2rem;
        }
        .reaction-chip {
            display: inline-flex; align-items: center; gap: .2rem;
            font-size: .76rem; padding: .15rem .45rem; border-radius: 999px;
            background: #f1f5f9; border: 1px solid #e2e8f0; cursor: pointer;
            transition: background .15s ease, transform .12s ease, box-shadow .15s ease;
        }
        .reaction-chip:hover {
            background: #e2e8f0;
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .dark .reaction-chip { background: #1e293b; border-color: #334155; }
        .dark .reaction-chip:hover { background: #334155; }
        .reaction-chip.mine  { background: #dbeafe; border-color: #93c5fd; }
        .dark .reaction-chip.mine { background: rgba(59,130,246,.2); border-color: #3b82f6; }
        .rcnt { font-size: .72rem; color: #64748b; }

        /* ════════════════════════════════════════════
           INPUT BAR
           ════════════════════════════════════════════ */
        .input-bar {
            display: flex; align-items: flex-end; gap: .5rem;
            padding: .75rem 1rem;
            transition: background .2s ease;
        }

        .chat-ta {
            flex: 1; resize: none; border: 1px solid;
            border-radius: 1rem; padding: .55rem .85rem;
            font-size: .84rem; font-family: inherit;
            min-height: 38px; max-height: 120px;
            transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }
        .chat-ta:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
            outline: none;
        }

        .attach-btn, .send-btn {
            width: 38px; height: 38px; border-radius: 50%; border: none;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; flex-shrink: 0; font-size: .9rem;
            transition: background .15s ease, transform .15s cubic-bezier(.34,1.56,.64,1), box-shadow .15s ease;
        }
        .attach-btn {
            background: #f1f5f9; color: #64748b;
        }
        .attach-btn:hover {
            background: #e2e8f0;
            transform: scale(1.1) rotate(-10deg);
        }
        .attach-btn:active { transform: scale(0.95); }
        .dark .attach-btn { background: #334155; color: #94a3b8; }
        .dark .attach-btn:hover { background: #475569; }

        .send-btn {
            background: #3b82f6; color: #fff;
            box-shadow: 0 2px 8px rgba(59,130,246,.3);
        }
        .send-btn:hover {
            background: #2563eb;
            transform: scale(1.1);
            box-shadow: 0 4px 16px rgba(59,130,246,.45);
        }
        .send-btn:active { transform: scale(0.95); box-shadow: none; }
        .send-btn:disabled {
            background: #93c5fd; cursor: not-allowed;
            transform: none; box-shadow: none;
        }

        /* ════════════════════════════════════════════
           FILE ATTACHMENTS
           ════════════════════════════════════════════ */
        .chat-img {
            max-width: 260px; max-height: 200px; border-radius: 10px;
            cursor: zoom-in; display: block; object-fit: cover;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .chat-img:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 24px rgba(0,0,0,.2);
        }

        .attachment-img-wrap { display: flex; flex-direction: column; gap: .3rem; }
        .img-caption { font-size: .8rem; margin-top: .2rem; }

        .file-bubble-link {
            display: flex; align-items: center; gap: .6rem;
            padding: .4rem .6rem; border-radius: 10px;
            background: rgba(0,0,0,.06); text-decoration: none; color: inherit;
            transition: background .15s ease, transform .12s ease;
        }
        .file-bubble-link:hover {
            background: rgba(0,0,0,.1);
            transform: translateX(2px);
        }
        .dark .file-bubble-link { background: rgba(255,255,255,.07); }
        .dark .file-bubble-link:hover { background: rgba(255,255,255,.11); }

        .file-icon { font-size: 1.4rem; }
        .file-info { display: flex; flex-direction: column; min-width: 0; }
        .file-name { font-size: .8rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px; }
        .file-size { font-size: .7rem; opacity: .65; }
        .file-dl   { font-size: 1rem; opacity: .6; flex-shrink: 0; transition: opacity .15s ease; }
        .file-bubble-link:hover .file-dl { opacity: 1; }

        .file-preview-bar {
            display: flex; gap: .5rem; padding: .5rem 1rem;
            border-top: 1px solid #e2e8f0; background: #f8fafc;
            overflow-x: auto; flex-shrink: 0;
            transition: background .2s ease;
        }
        .dark .file-preview-bar { border-color: #334155; background: #1e293b; }

        .fpreview-item {
            position: relative; flex-shrink: 0;
            display: flex; flex-direction: column; align-items: center; gap: .2rem;
            transition: transform .15s ease;
        }
        .fpreview-item:hover { transform: scale(1.04); }

        .fpreview-img {
            width: 60px; height: 60px; object-fit: cover;
            border-radius: 8px; border: 1px solid #e2e8f0;
        }
        .fpreview-file {
            width: 60px; height: 60px; border-radius: 8px; border: 1px solid #e2e8f0;
            background: #eff6ff; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: .2rem;
            font-size: .65rem; color: #3b82f6; text-align: center; padding: .25rem;
        }
        .fpreview-file i { font-size: 1.2rem; }
        .fpreview-remove {
            position: absolute; top: -5px; right: -5px;
            width: 18px; height: 18px; border-radius: 50%;
            background: #ef4444; color: #fff; border: none;
            font-size: .7rem; cursor: pointer; line-height: 18px; text-align: center;
            transition: background .15s ease, transform .15s cubic-bezier(.34,1.56,.64,1);
        }
        .fpreview-remove:hover {
            background: #dc2626;
            transform: scale(1.15);
        }
        .fpreview-name { font-size: .6rem; color: #64748b; max-width: 64px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* ════════════════════════════════════════════
           DATE SEPARATOR
           ════════════════════════════════════════════ */
        .date-sep {
            text-align: center; font-size: .68rem; color: #94a3b8;
            margin: .75rem 0 .25rem; letter-spacing: .05em;
            animation: fadeIn .3s ease;
        }

        /* ════════════════════════════════════════════
           LIGHTBOX
           ════════════════════════════════════════════ */
        .img-lightbox {
            display: none; position: fixed; inset: 0; z-index: 9999;
            animation: fadeIn .2s ease;
        }
        .img-lightbox.open { display: flex; align-items: center; justify-content: center; }
        .lb-backdrop {
            position: absolute; inset: 0; background: rgba(0,0,0,.85);
            backdrop-filter: blur(6px);
        }
        .lb-inner {
            position: relative; z-index: 1; max-width: 90vw; max-height: 90vh;
            animation: scaleIn .25s cubic-bezier(.34,1.56,.64,1);
        }
        .lb-inner img { max-width: 90vw; max-height: 90vh; border-radius: 12px; display: block; }
        .lb-close {
            position: absolute; top: -14px; right: -14px;
            width: 30px; height: 30px; border-radius: 50%;
            background: #fff; border: none; font-size: 1rem; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: background .15s ease, transform .15s cubic-bezier(.34,1.56,.64,1);
        }
        .lb-close:hover {
            background: #fee2e2;
            transform: scale(1.15) rotate(90deg);
        }

        /* ════════════════════════════════════════════
           BACK BUTTON (MOBILE)
           ════════════════════════════════════════════ */
        .back-btn { display: none; }
        @media(max-width:680px) { .back-btn { display: flex; } }

        /* ════════════════════════════════════════════
           THREAD HEADER
           ════════════════════════════════════════════ */
        #threadView .flex.items-center.gap-3 {
            animation: fadeIn .25s ease;
        }

        /* ════════════════════════════════════════════
           THREAD EMPTY STATE
           ════════════════════════════════════════════ */
        #threadEmpty { display: flex; flex: 1; animation: fadeIn .4s ease; }
        #threadView  { flex: 1; }

        /* ════════════════════════════════════════════
           3-DOT MESSAGE MENU
           ════════════════════════════════════════════ */
        .msg-menu-wrap {
            position: relative;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .msg-menu-btn {
            opacity: 0;
            width: 26px; height: 26px;
            border-radius: 50%;
            background: none; border: none; cursor: pointer;
            color: #94a3b8;
            display: flex; align-items: center; justify-content: center;
            transition: opacity .15s ease, background .12s ease, color .12s ease, transform .12s ease;
            font-size: .78rem;
        }
        .msg-row:hover .msg-menu-btn { opacity: 1; }
        .msg-menu-btn:focus          { opacity: 1; outline: none; }
        .msg-menu-btn:hover {
            background: rgba(0,0,0,.08);
            color: #3b82f6;
            transform: scale(1.1);
        }
        .dark .msg-menu-btn:hover {
            background: rgba(255,255,255,.1);
            color: #60a5fa;
        }

        .bubble-and-react.mine .msg-menu-wrap { order: -1; }

        .msg-menu-dropdown {
            position: absolute;
            bottom: calc(100% + 6px);
            right: 0;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 32px rgba(0,0,0,.14);
            overflow: hidden;
            z-index: 200;
            min-width: 140px;
            opacity: 0;
            transform: scale(.88) translateY(6px);
            pointer-events: none;
            transition: opacity .18s ease, transform .18s cubic-bezier(.34,1.56,.64,1);
            transform-origin: bottom right;
        }
        .msg-menu-dropdown.open {
            opacity: 1;
            transform: scale(1) translateY(0);
            pointer-events: auto;
        }
        .dark .msg-menu-dropdown {
            background: #1e293b;
            border-color: #334155;
            box-shadow: 0 12px 32px rgba(0,0,0,.4);
        }

        .msg-menu-item {
            display: flex; align-items: center; gap: .55rem;
            padding: .6rem .9rem;
            font-size: .8rem; font-weight: 500;
            cursor: pointer; background: none; border: none;
            width: 100%; text-align: left; color: #334155;
            transition: background .12s ease, color .12s ease, padding-left .12s ease;
            white-space: nowrap;
        }
        .msg-menu-item i { width: 13px; font-size: .72rem; opacity: .75; transition: transform .15s ease; }
        .msg-menu-item:hover i { transform: scale(1.15); }
        .dark .msg-menu-item { color: #cbd5e1; }
        .msg-menu-item:hover {
            background: #f1f5f9;
            padding-left: 1.1rem;
        }
        .dark .msg-menu-item:hover { background: #334155; }

        .msg-menu-item.danger { color: #ef4444; }
        .msg-menu-item.danger:hover { background: #fef2f2; }
        .dark .msg-menu-item.danger:hover { background: rgba(239,68,68,.1); }
        .msg-menu-item + .msg-menu-item.danger { border-top: 1px solid #f1f5f9; }
        .dark .msg-menu-item + .msg-menu-item.danger { border-top-color: #334155; }

        /* ════════════════════════════════════════════
           UNSENT NOTICE
           ════════════════════════════════════════════ */
        .unsent-notice {
            display: inline-flex; align-items: center; gap: .35rem;
            font-size: .8rem; color: #94a3b8; font-style: italic;
            padding: .35rem .8rem;
            border: 1px dashed #cbd5e1; border-radius: 12px;
            background: transparent; user-select: none;
            animation: fadeIn .3s ease;
        }
        .dark .unsent-notice { border-color: #475569; color: #64748b; }

        /* ════════════════════════════════════════════
           EDITED TAG
           ════════════════════════════════════════════ */
        .edited-tag {
            font-size: .64rem; opacity: .5; font-style: italic;
            margin-left: .3rem; font-weight: 400;
        }

        /* ════════════════════════════════════════════
           INLINE EDIT
           ════════════════════════════════════════════ */
        .edit-ta {
            width: 100%;
            border: 1.5px solid #3b82f6; border-radius: 10px;
            padding: .45rem .75rem;
            font-size: .84rem; font-family: inherit;
            resize: none; outline: none;
            background: #fff; color: #1e293b;
            min-height: 38px; max-height: 120px;
            box-sizing: border-box;
            transition: border-color .2s ease, box-shadow .2s ease;
            box-shadow: 0 0 0 3px rgba(59,130,246,.12);
        }
        .dark .edit-ta {
            background: #1e293b; color: #e2e8f0; border-color: #60a5fa;
            box-shadow: 0 0 0 3px rgba(96,165,250,.12);
        }
        .edit-ta:focus {
            box-shadow: 0 0 0 4px rgba(59,130,246,.2);
        }

        .edit-actions {
            display: flex; align-items: center; gap: .4rem;
            margin-top: .35rem; justify-content: flex-end;
        }
        .edit-hint { font-size: .65rem; color: #94a3b8; margin-right: auto; }

        .edit-save-btn, .edit-cancel-btn {
            font-size: .75rem; font-weight: 600;
            padding: .3rem .8rem; border-radius: 6px;
            border: none; cursor: pointer;
            transition: background .15s ease, transform .12s ease, box-shadow .12s ease;
        }
        .edit-save-btn           { background: #3b82f6; color: #fff; }
        .edit-save-btn:hover     { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,.3); }
        .edit-save-btn:active    { transform: translateY(0); box-shadow: none; }
        .edit-save-btn:disabled  { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }
        .edit-cancel-btn         { background: #f1f5f9; color: #64748b; }
        .edit-cancel-btn:hover   { background: #e2e8f0; transform: translateY(-1px); }
        .edit-cancel-btn:active  { transform: translateY(0); }
        .dark .edit-cancel-btn   { background: #334155; color: #94a3b8; }
        .dark .edit-cancel-btn:hover { background: #475569; }

        /* ════════════════════════════════════════════
           UNSEND CONFIRMATION MODAL
           ════════════════════════════════════════════ */
        #unsendModal {
            position: fixed; inset: 0; z-index: 9998;
            background: rgba(0,0,0,.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            padding: 1rem;
            opacity: 0; pointer-events: none;
            transition: opacity .2s ease;
        }
        #unsendModal.open { opacity: 1; pointer-events: auto; }

        .unsend-modal-box {
            background: #fff; border-radius: 20px;
            padding: 1.5rem 1.75rem 1.25rem;
            max-width: 340px; width: 100%;
            box-shadow: 0 32px 64px rgba(0,0,0,.22);
            transform: scale(.9) translateY(16px);
            transition: transform .28s cubic-bezier(.34,1.56,.64,1);
        }
        #unsendModal.open .unsend-modal-box { transform: scale(1) translateY(0); }
        .dark .unsend-modal-box {
            background: #1e293b;
            box-shadow: 0 32px 64px rgba(0,0,0,.5);
        }

        .unsend-modal-icon {
            width: 42px; height: 42px; border-radius: 50%;
            background: #fef2f2;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: transform .2s cubic-bezier(.34,1.56,.64,1);
        }
        #unsendModal.open .unsend-modal-icon { transform: scale(1.05); }
        .dark .unsend-modal-icon { background: rgba(239,68,68,.15); }

        .unsend-modal-title  { font-weight: 700; font-size: .97rem; line-height: 1.2; }
        .unsend-modal-sub    { font-size: .78rem; color: #64748b; margin-top: .25rem; line-height: 1.4; }
        .dark .unsend-modal-sub { color: #94a3b8; }

        .unsend-modal-actions {
            display: flex; gap: .6rem; justify-content: flex-end;
            margin-top: 1.25rem;
        }

        .unsend-cancel-btn {
            padding: .5rem 1.1rem; border-radius: 9px;
            border: 1px solid #e2e8f0; background: #f8fafc;
            font-size: .83rem; font-weight: 600; cursor: pointer;
            color: #64748b;
            transition: background .15s ease, border-color .15s ease, transform .12s ease;
        }
        .unsend-cancel-btn:hover   { background: #f1f5f9; border-color: #cbd5e1; transform: translateY(-1px); }
        .unsend-cancel-btn:active  { transform: translateY(0); }
        .dark .unsend-cancel-btn   { background: #334155; border-color: #475569; color: #94a3b8; }
        .dark .unsend-cancel-btn:hover { background: #475569; }

        .unsend-confirm-btn {
            padding: .5rem 1.1rem; border-radius: 9px;
            border: none; background: #ef4444; color: #fff;
            font-size: .83rem; font-weight: 600; cursor: pointer;
            transition: background .15s ease, transform .12s ease, box-shadow .15s ease;
            box-shadow: 0 2px 8px rgba(239,68,68,.3);
        }
        .unsend-confirm-btn:hover    { background: #dc2626; transform: translateY(-1px); box-shadow: 0 4px 16px rgba(239,68,68,.4); }
        .unsend-confirm-btn:active   { transform: translateY(0); box-shadow: none; }
        .unsend-confirm-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }

        /* ════════════════════════════════════════════
           DARK MODE — SUN ICON YELLOW
           ════════════════════════════════════════════ */
        .dark #theme-icon.fa-sun,
        .dark #topThemeIcon.fa-sun {
            color: #fbbf24;
            filter: drop-shadow(0 0 4px rgba(251,191,36,.5));
        }

        /* ════════════════════════════════════════════
           SIDEBAR BADGE ANIMATION
           ════════════════════════════════════════════ */
        #sidebarBadge:not(.hidden) {
            animation: scaleIn .3s cubic-bezier(.34,1.56,.64,1);
        }

        /* ════════════════════════════════════════════
           THREAD HEADER AVATAR HOVER
           ════════════════════════════════════════════ */
        #threadAvatar {
            transition: transform .2s cubic-bezier(.34,1.56,.64,1), box-shadow .2s ease;
        }
        #threadAvatar:hover {
            transform: scale(1.08);
            box-shadow: 0 4px 12px rgba(59,130,246,.3);
        }

        /* ════════════════════════════════════════════
           EMPTY STATE ICON FLOAT ANIMATION
           ════════════════════════════════════════════ */
        @keyframes float {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-6px); }
        }
        #threadEmpty .w-14 {
            animation: float 3s ease-in-out infinite;
        }

        /* ════════════════════════════════════════════
           MOBILE OVERLAY
           ════════════════════════════════════════════ */
        #overlay {
            transition: opacity .25s ease;
        }

        /* ════════════════════════════════════════════
           BACK BUTTON HOVER
           ════════════════════════════════════════════ */
        .back-btn {
            transition: background .15s ease, transform .15s ease !important;
        }
        .back-btn:hover {
            transform: translateX(-2px) !important;
        }
    </style>
</head>

<?php /* Dark mode flash prevention */ ?>
<script>
    (function() {
        const t = localStorage.getItem('sems-theme') || 'light';
        if (t === 'dark') document.documentElement.classList.add('dark');
    })();
</script>

<body class="bg-gray-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 font-sans">

<!-- Mobile overlay -->
<div id="overlay" onclick="closeSidebar()"
     class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 opacity-0 pointer-events-none lg:hidden"></div>

<div class="flex min-h-screen">

    <!-- ═══════════ SIDEBAR ═══════════ -->
    <aside id="sidebar"
           class="-translate-x-full lg:translate-x-0 fixed top-0 left-0 z-50 h-full w-64 flex flex-col
                  bg-white dark:bg-slate-800 border-r border-gray-200 dark:border-slate-700 shadow-xl">

        <!-- Logo -->
        <div class="px-6 py-6 border-b border-gray-100 dark:border-slate-700">
            <div class="flex items-center gap-3">
                <div class="sidebar-logo w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center shadow-lg shadow-primary-500/30 cursor-pointer">
                    <i class="fas fa-calendar-check text-white text-sm"></i>
                </div>
                <div>
                    <p class="font-bold text-slate-900 dark:text-white text-lg tracking-tight leading-none">SEMS</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Admin Panel</p>
                </div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
            <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 uppercase tracking-wider">Overview</p>
            <a href="/admin/admin_dashboard.php"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
                <i class="fas fa-th-large w-5 text-center"></i> Dashboard
            </a>

            <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Management</p>
            <?php
            $navItems = [
                ['/admin/admin_event_management.php',    'fa-calendar-alt', 'Events'],
                ['/admin/admin_aprovals.php',            'fa-check-circle', 'Approvals'],
                ['/admin/admin_user_management.php',     'fa-users',        'Users'],
                ['/admin/admin_org_club_management.php', 'fa-building',     'Organizations & Clubs'],
            ];
            foreach ($navItems as [$href, $icon, $label]): ?>
                <a href="<?= $href ?>"
                   class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
                    <i class="fas <?= $icon ?> w-5 text-center"></i> <?= $label ?>
                </a>
            <?php endforeach; ?>

            <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Communication</p>
            <a href="/admin/admin_chat.php"
               class="nav-item active flex items-center gap-3 px-5 py-2.5 rounded-x3 font-medium text-sm">
                <i class="fas fa-comments w-5 text-center"></i>
                Messages
                <span id="sidebarBadge" class="ml-auto hidden text-[10px] font-bold bg-primary-500 text-white rounded-full px-1.5 py-0.5"></span>
            </a>

            <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Insights</p>
            <a href="/admin/admin_insight.php"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
                <i class="fas fa-chart-line w-5 text-center"></i> Analytics
            </a>
        </nav>

        <!-- Footer -->
        <div class="px-4 py-4 border-t border-gray-100 dark:border-slate-700 space-y-1">
            <a href="/admin/admin_settings.php"
               class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
                <i class="fas fa-cog w-5 text-center"></i> Settings
            </a>
            <button onclick="toggleTheme()"
                    class="w-full nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-sm">
                <i id="theme-icon" class="fas fa-moon w-5 text-center"></i>
                <span id="theme-label">Dark Mode</span>
            </button>
            <a href="../includes/logout.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 font-medium text-sm transition-colors duration-150">
                <i class="fas fa-sign-out-alt w-5 text-center"></i> Logout
            </a>
        </div>
    </aside>

    <!-- ═══════════ MAIN ═══════════ -->
    <div class="lg:ml-64 flex flex-col min-h-screen flex-1 min-w-0">

        <!-- Topbar -->
        <header id="mainTopbar"
                class="topbar sticky top-0 z-30 bg-white/90 dark:bg-slate-800/90 border-b border-gray-200 dark:border-slate-700 px-4 sm:px-6 py-3">
            <div class="flex items-center gap-3">
                <button onclick="openSidebar()"
                        class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300
                               hover:bg-primary-50 dark:hover:bg-primary-500/10 hover:text-primary-500
                               active:scale-95 transition-all duration-150">
                    <i class="fas fa-bars text-sm"></i>
                </button>

                <i class="fas fa-comments text-primary-500 text-sm"></i>
                <span class="font-semibold text-slate-900 dark:text-white">Messages</span>
                <span class="text-xs text-slate-400 dark:text-slate-500 hidden sm:inline">Admin ↔ Organizer</span>

                <div class="ml-auto flex items-center gap-2">
                    <!-- Admin Profile Widget -->
                    <div class="flex items-center gap-3 pl-4 border-l border-gray-200 dark:border-slate-700">
                        <div class="hidden md:block text-right">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white leading-none"><?= htmlspecialchars($adminFullName) ?></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Administrator</p>
                        </div>
                        <div class="topbar-avatar-wrap relative cursor-pointer">
                            <?php if ($hasAvatar): ?>
                                <img src="data:<?= $avatarMime ?>;base64,<?= $avatarData ?>"
                                     alt="<?= htmlspecialchars($adminFullName) ?>"
                                     class="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-600 shadow-md ring-2 ring-transparent hover:ring-primary-400 transition-all duration-200">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-600
                                            flex items-center justify-center text-white text-sm font-bold shadow-md
                                            ring-2 ring-transparent hover:ring-primary-400 transition-all duration-200">
                                    <?= htmlspecialchars($adminInitials) ?>
                                </div>
                            <?php endif; ?>
                            <span class="online-dot absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-slate-800 rounded-full"></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Chat shell -->
        <div class="chat-shell">

            <!-- LEFT: list panel -->
            <div class="chat-list-panel bg-white dark:bg-slate-800 border-gray-200 dark:border-slate-700" id="listPanel">

                <!-- Tabs -->
                <div class="flex px-1 pt-1 border-b border-gray-200 dark:border-slate-700">
                    <button class="tab-btn active" id="tabConvs" onclick="switchTab('convs')">Chats</button>
                    <button class="tab-btn"        id="tabNew"   onclick="switchTab('new')">New Message</button>
                </div>

                <!-- Search -->
                <div class="px-3 py-2.5 border-b border-gray-100 dark:border-slate-700">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input id="searchInput" type="search"
                               class="w-full pl-8 pr-3 py-2 text-sm rounded-lg bg-gray-50 dark:bg-slate-700
                                      border border-gray-200 dark:border-slate-600 outline-none
                                      text-gray-700 dark:text-gray-200 placeholder-gray-400"
                               placeholder="Search organizers…"
                               oninput="filterList(this.value)">
                    </div>
                </div>

                <!-- List area -->
                <div class="flex-1 overflow-y-auto" id="listScrollArea">
                    <div class="py-10 text-center text-sm text-gray-400">Loading…</div>
                </div>
            </div>

            <!-- RIGHT: thread panel -->
            <div class="chat-thread-panel" id="threadPanel">

                <!-- Empty state -->
                <div id="threadEmpty" class="flex flex-col items-center justify-center flex-1 gap-3 text-gray-400 p-8">
                    <div class="w-14 h-14 rounded-2xl bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shadow-inner">
                        <i class="fas fa-comments text-2xl text-primary-500"></i>
                    </div>
                    <p class="font-semibold text-slate-700 dark:text-slate-200 text-sm">Select an organizer to message</p>
                    <p class="text-xs text-center max-w-[200px] leading-relaxed">
                        Use the <strong>New Message</strong> tab to start a conversation, or click an existing chat.
                    </p>
                </div>

                <!-- Thread view -->
                <div id="threadView" class="hidden flex-col flex-1 overflow-hidden">

                    <!-- Thread header -->
                    <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <button class="back-btn p-2 rounded-lg bg-gray-100 dark:bg-slate-700
                                       text-slate-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600"
                                onclick="showList()">
                            <i class="fas fa-arrow-left text-sm"></i>
                        </button>
                        <div class="c-avatar" id="threadAvatar"></div>
                        <div class="min-w-0">
                            <p class="font-semibold text-sm text-slate-900 dark:text-white truncate" id="threadName">—</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400" id="threadSub">—</p>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div class="msgs-area" id="messagesArea"></div>

                    <!-- File preview bar (injected by JS) -->

                    <!-- Input bar -->
                    <div class="input-bar border-t border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800">
                        <button class="attach-btn" onclick="triggerFileInput()" title="Attach file or image">
                            <i class="fas fa-paperclip"></i>
                        </button>
                        <textarea id="msgInput" rows="1"
                            class="chat-ta bg-gray-50 dark:bg-slate-700 border-gray-200 dark:border-slate-600
                                   text-gray-800 dark:text-gray-100 placeholder-gray-400"
                            placeholder="Type a message…"
                            onkeydown="handleKey(event)"
                            oninput="autoResize(this)"></textarea>
                        <button class="send-btn" id="sendBtn" onclick="sendMessage()">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Hidden file input -->
<input type="file" id="fileInput" class="hidden" multiple
       accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt">

<!-- ── Unsend confirmation modal ────────────────────────────── -->
<div id="unsendModal" onclick="handleUnsendBgClick(event)">
    <div class="unsend-modal-box">
        <div style="display:flex;align-items:flex-start;gap:.85rem;margin-bottom:.1rem;">
            <div class="unsend-modal-icon">
                <i class="fas fa-trash-alt" style="color:#ef4444;font-size:.95rem;"></i>
            </div>
            <div>
                <p class="unsend-modal-title text-slate-900 dark:text-white">
                    Unsend this message?
                </p>
                <p class="unsend-modal-sub">
                    It will be removed for everyone in this conversation and can't be undone.
                </p>
            </div>
        </div>
        <div class="unsend-modal-actions">
            <button class="unsend-cancel-btn" onclick="closeUnsendModal()">Cancel</button>
            <button class="unsend-confirm-btn" id="unsendConfirmBtn" onclick="doUnsend()">
                <i class="fas fa-trash-alt" style="margin-right:.35rem;font-size:.75rem;"></i>
                Unsend
            </button>
        </div>
    </div>
</div>

<script>
    /* ── Bootstrap ── */
    const SEMS_CHAT = {
        myId:   <?= json_encode($adminUserId) ?>,
        myName: <?= json_encode($adminFullName) ?>,
        myInit: <?= json_encode($adminInitials) ?>,
        apiUrl: '../includes/admin_chat_api.php',
    };

    /* ── Sidebar ── */
    function openSidebar() {
        document.getElementById('sidebar').style.transform = 'translateX(0)';
        const ov = document.getElementById('overlay');
        ov.style.opacity = '1';
        ov.style.pointerEvents = 'auto';
    }
    function closeSidebar() {
        if (window.innerWidth < 1024)
            document.getElementById('sidebar').style.transform = 'translateX(-100%)';
        const ov = document.getElementById('overlay');
        ov.style.opacity = '0';
        ov.style.pointerEvents = 'none';
    }

    /* ── Dark mode ── */
    function toggleTheme() {
        const html  = document.documentElement;
        const icon  = document.getElementById('theme-icon');
        const topI  = document.getElementById('topThemeIcon');
        const label = document.getElementById('theme-label');

        if (html.classList.contains('dark')) {
            html.classList.remove('dark');
            localStorage.setItem('sems-theme', 'light');
            if (icon)  { icon.className  = 'fas fa-moon w-5 text-center'; icon.style.color = ''; }
            if (topI)  { topI.className  = 'fas fa-moon text-sm'; topI.style.color = ''; }
            if (label) label.textContent = 'Dark Mode';
        } else {
            html.classList.add('dark');
            localStorage.setItem('sems-theme', 'dark');
            if (icon)  { icon.className  = 'fas fa-sun w-5 text-center'; }
            if (topI)  { topI.className  = 'fas fa-sun text-sm'; }
            if (label) label.textContent = 'Light Mode';
        }
    }

    /* ── Init icon state on load ── */
    (function(){
        if (document.documentElement.classList.contains('dark')) {
            const icon  = document.getElementById('theme-icon');
            const topI  = document.getElementById('topThemeIcon');
            const label = document.getElementById('theme-label');
            if (icon)  icon.className  = 'fas fa-sun w-5 text-center';
            if (topI)  topI.className  = 'fas fa-sun text-sm';
            if (label) label.textContent = 'Light Mode';
        }
    })();

    /* ── Topbar scroll shadow ── */
    (function() {
        const header = document.getElementById('mainTopbar');
        window.addEventListener('scroll', () => {
            header.classList.toggle('scrolled', window.scrollY > 4);
        }, { passive: true });
    })();
</script>
<script src="/js/sems_chat.js"></script>

</body>
</html>