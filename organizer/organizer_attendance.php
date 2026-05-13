<?php
/* ============================================================
 | FILE   : organizer_attendance.php
 | PURPOSE: Attendance tracking — now includes:
 |   • Proof image column (thumbnail → lightbox, lazy-loaded)
 |   • Archive / Unarchive individual records
 |   • Edit status (Present ↔ Absent) with side-by-side
 |     proof photo + student account photo for fraud detection
 ============================================================ */

session_start();
$pdo = require_once '../includes/db.php';

/* ── Auth ─────────────────────────────────────────────────── */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    if (!empty($_POST['action']) || !empty($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];

/* ── Org / Club context ───────────────────────────────────── */
$myCtx = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = ?");
$myCtx->execute([$uid]);
$myCtxRow = $myCtx->fetch(PDO::FETCH_ASSOC) ?: ['org_id' => null, 'club_id' => null];
$myOrgId  = !empty($myCtxRow['org_id'])  ? (int) $myCtxRow['org_id']  : null;
$myClubId = !empty($myCtxRow['club_id']) ? (int) $myCtxRow['club_id'] : null;

/* ── Shared helper ────────────────────────────────────────── */
function buildOrgEventWhere(string $prefix, int $uid, ?int $orgId, ?int $clubId, array &$params): string
{
    $p = $prefix !== '' ? $prefix . '.' : '';
    $params[] = $uid;
    if ($orgId || $clubId) {
        $orParts = [];
        if ($orgId)  { $orParts[] = "{$p}org_id = ?";  $params[] = $orgId; }
        if ($clubId) { $orParts[] = "{$p}club_id = ?"; $params[] = $clubId; }
        return "({$p}organizer_id = ? OR " . implode(' OR ', $orParts) . ")";
    }
    return "{$p}organizer_id = ?";
}

/* ── Ownership guard (reusable) ───────────────────────────── */
function ownsAttendance(PDO $pdo, int $uid, ?int $orgId, ?int $clubId, int $attId): bool
{
    $op = [];
    $ow = buildOrgEventWhere('e', $uid, $orgId, $clubId, $op);
    $op[] = $attId;
    $q = $pdo->prepare("
        SELECT 1 FROM attendance a
        JOIN   events e ON a.event_id = e.event_id
        WHERE  $ow AND a.attendance_id = ?
    ");
    $q->execute($op);
    return (bool) $q->fetch();
}


/* ============================================================
 | AJAX HANDLERS  (POST action=…  or  GET action=get_proof)
 ============================================================ */

/* ── Serve proof image blob ─────────────────────────────── */
if (isset($_GET['action']) && $_GET['action'] === 'get_proof') {
    header('Content-Type: application/json');
    $attId = (int) ($_GET['id'] ?? 0);
    if (!$attId || !ownsAttendance($pdo, $uid, $myOrgId, $myClubId, $attId)) {
        echo json_encode(['success' => false, 'error' => 'Not found or unauthorized']); exit;
    }
    $q = $pdo->prepare("SELECT proof_image FROM attendance WHERE attendance_id = ?");
    $q->execute([$attId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (empty($row['proof_image'])) {
        echo json_encode(['success' => false, 'error' => 'No proof image']); exit;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_buffer($finfo, $row['proof_image']);
    if (!$mime || strpos($mime, 'image/') !== 0) $mime = 'image/jpeg';
    echo json_encode(['success' => true, 'src' => "data:{$mime};base64," . base64_encode($row['proof_image'])]);
    exit;
}

/* ── POST actions ────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $attId  = (int) ($_POST['attendance_id'] ?? 0);

    if (!$attId) { echo json_encode(['success' => false, 'error' => 'Invalid ID']); exit; }
    if (!ownsAttendance($pdo, $uid, $myOrgId, $myClubId, $attId)) {
        echo json_encode(['success' => false, 'error' => 'Not found or unauthorized']); exit;
    }

    switch ($action) {

        /* Archive ─────────────────────────────────────────── */
        case 'archive':
            $pdo->prepare("UPDATE attendance SET archived_at = NOW() WHERE attendance_id = ?")->execute([$attId]);
            echo json_encode(['success' => true]);
            break;

        /* Unarchive ───────────────────────────────────────── */
        case 'unarchive':
            $pdo->prepare("UPDATE attendance SET archived_at = NULL WHERE attendance_id = ?")->execute([$attId]);
            echo json_encode(['success' => true]);
            break;

        /* Edit status ─────────────────────────────────────── */
        case 'edit_status':
            $ns = $_POST['new_status'] ?? '';
            if ($ns === 'present') {
                $pdo->prepare("
                    UPDATE attendance
                    SET login_time  = COALESCE(login_time,  NOW()),
                        logout_time = COALESCE(logout_time, NOW())
                    WHERE attendance_id = ?
                ")->execute([$attId]);
            } elseif ($ns === 'absent') {
                $pdo->prepare("UPDATE attendance SET logout_time = NULL WHERE attendance_id = ?")->execute([$attId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid status']); exit;
            }

            $upd = $pdo->prepare("SELECT login_time, logout_time FROM attendance WHERE attendance_id = ?");
            $upd->execute([$attId]);
            $r  = $upd->fetch(PDO::FETCH_ASSOC);
            $ip = !empty($r['login_time']) && !empty($r['logout_time']);
            $dur = 'N/A';
            if ($ip) {
                $diff = strtotime($r['logout_time']) - strtotime($r['login_time']);
                if ($diff > 0) {
                    $h = floor($diff / 3600); $m = floor(($diff % 3600) / 60);
                    $dur = $h > 0 ? "{$h}h {$m}m" : "{$m} mins";
                }
            }
            echo json_encode([
                'success'      => true,
                'status'       => $ip ? 'PRESENT' : 'ABSENT',
                'duration'     => $dur,
                'login_fmt'    => $r['login_time']  ? date('M j, Y g:i A', strtotime($r['login_time']))  : '—',
                'logout_fmt'   => $r['logout_time'] ? date('M j, Y g:i A', strtotime($r['logout_time'])) : 'Not logged out',
                'login_date'   => $r['login_time']  ? date('M j, Y', strtotime($r['login_time']))  : '',
                'login_time'   => $r['login_time']  ? date('g:i A',  strtotime($r['login_time']))  : '',
                'logout_date'  => $r['logout_time'] ? date('M j, Y', strtotime($r['logout_time'])) : '',
                'logout_time_' => $r['logout_time'] ? date('g:i A',  strtotime($r['logout_time'])) : '',
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}


/* ============================================================
 | ORG / CLUB DETAILS — sidebar logo + name
 ============================================================ */
$orgName = 'Organization'; $orgType = 'Organization';
$hasOrgLogo = false; $orgData = null; $orgMime = 'image/jpeg';

try {
    $orgQ = $pdo->prepare("SELECT o.org_name, o.logo, 'Organization' as type FROM users u LEFT JOIN organizations o ON u.org_id = o.org_id WHERE u.user_id = ? AND o.org_id IS NOT NULL");
    $orgQ->execute([$uid]);
    $orgData = $orgQ->fetch(PDO::FETCH_ASSOC);
    if ($orgData) {
        $orgName = htmlspecialchars($orgData['org_name']); $orgType = $orgData['type']; $hasOrgLogo = !empty($orgData['logo']);
    } else {
        $clubQ = $pdo->prepare("SELECT c.club_name as org_name, c.logo, 'Club' as type FROM users u LEFT JOIN clubs c ON u.club_id = c.club_id WHERE u.user_id = ? AND c.club_id IS NOT NULL");
        $clubQ->execute([$uid]);
        $orgData = $clubQ->fetch(PDO::FETCH_ASSOC);
        if ($orgData) { $orgName = htmlspecialchars($orgData['org_name']); $orgType = $orgData['type']; $hasOrgLogo = !empty($orgData['logo']); }
    }
    if ($hasOrgLogo && !empty($orgData['logo'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $d = finfo_buffer($finfo, $orgData['logo']);
        if ($d && strpos($d, 'image/') === 0) $orgMime = $d;
    }
} catch (Exception $e) {}


/* ============================================================
 | ORGANIZER PROFILE DATA
 ============================================================ */
$ps = $pdo->prepare("
    SELECT COALESCE(p.first_name,   o.first_name)    as first_name,
           COALESCE(p.last_name,    o.last_name)     as last_name,
           COALESCE(p.middle_name,  o.middle_name)   as middle_name,
           COALESCE(p.profile_image,o.profile_image) as profile_image,
           o.position, d.dept_name
    FROM   users u
    LEFT JOIN profiles    p ON u.user_id = p.user_id
    LEFT JOIN organizer   o ON u.user_id = o.user_id
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE  u.user_id = ?
");
$ps->execute([$uid]);
$profile = $ps->fetch(PDO::FETCH_ASSOC);

$middleName = !empty($profile['middle_name']) ? ' ' . strtoupper(substr($profile['middle_name'], 0, 1)) . '. ' : ' ';
$fullName   = htmlspecialchars(($profile['first_name'] ?? '') . $middleName . ($profile['last_name'] ?? ''));
$initials   = strtoupper(substr($profile['first_name'] ?? 'O', 0, 1) . substr($profile['last_name'] ?? '', 0, 1));
if (empty(trim($initials))) $initials = 'OR';
$deptName = htmlspecialchars($profile['dept_name'] ?? 'Department');

$hasImage = !empty($profile['profile_image']); $mime = 'image/jpeg'; $profileImageData = '';
if ($hasImage) {
    try {
        $finfo = finfo_open(FILEINFO_MIME_TYPE); $det = finfo_buffer($finfo, $profile['profile_image']);
        if ($det && strpos($det, 'image/') === 0) $mime = $det;
        $profileImageData = base64_encode($profile['profile_image']);
    } catch (Exception $e) { $hasImage = false; }
}


/* ============================================================
 | SIDEBAR BADGE COUNTS
 ============================================================ */
$sbEvParams = []; $sbEvWhere = buildOrgEventWhere('', $uid, $myOrgId, $myClubId, $sbEvParams);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $sbEvWhere AND status != 'rejected' AND deleted_at IS NULL");
$stmt->execute($sbEvParams); $myEvents = $stmt->fetchColumn();

$sbRegParams = []; $sbRegWhere = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $sbRegParams);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations r JOIN events e ON r.event_id = e.event_id WHERE $sbRegWhere AND e.deleted_at IS NULL");
$stmt->execute($sbRegParams); $registrations = $stmt->fetchColumn();


/* ============================================================
 | CLEANUP ORPHANED RECORDS
 ============================================================ */
try {
    $pdo->exec("DELETE FROM attendance WHERE NOT EXISTS (SELECT 1 FROM events e WHERE e.event_id = attendance.event_id)");
} catch (Exception $e) {}


/* ============================================================
 | MAIN ATTENDANCE QUERY
 | proof_image blob is NOT fetched here to keep memory lean —
 | only a boolean "has_proof" flag is fetched. The actual blob
 | is served on demand via GET ?action=get_proof&id=X (same file).
 ============================================================ */
$attParams = [];
$attWhere  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $attParams);

$attQ = $pdo->prepare("
    SELECT
        a.attendance_id,
        a.event_id,
        a.user_id,
        e.title          AS event_title,
        CONCAT(p.first_name, ' ', p.last_name) AS student_name,
        p.student_number,
        p.profile_image  AS student_profile_image,
        a.login_time,
        a.logout_time,
        a.scan_time      AS attendance_date,
        (a.proof_image IS NOT NULL AND LENGTH(a.proof_image) > 0) AS has_proof,
        a.archived_at
    FROM attendance a
    JOIN events   e ON a.event_id = e.event_id
    JOIN profiles p ON a.user_id  = p.user_id
    WHERE $attWhere
      AND e.deleted_at IS NULL
    ORDER BY a.login_time DESC, a.attendance_id DESC
");
$attQ->execute($attParams);
$rawRecords = $attQ->fetchAll(PDO::FETCH_ASSOC);


/* ============================================================
 | PRE-PROCESS — encode student profile images
 ============================================================ */
$allAttendanceRecords = [];
foreach ($rawRecords as $rec) {
    $studentImgData = ''; $studentMime = 'image/jpeg'; $hasStudentImg = false;
    if (!empty($rec['student_profile_image'])) {
        try {
            $fi = finfo_open(FILEINFO_MIME_TYPE); $d2 = finfo_buffer($fi, $rec['student_profile_image']);
            if ($d2 && strpos($d2, 'image/') === 0) $studentMime = $d2;
            $studentImgData = base64_encode($rec['student_profile_image']); $hasStudentImg = true;
        } catch (Exception $e) {}
    }
    $rec['student_img_data'] = $studentImgData;
    $rec['student_mime']     = $studentMime;
    $rec['has_student_img']  = $hasStudentImg;
    unset($rec['student_profile_image']);
    $allAttendanceRecords[] = $rec;
}


/* ============================================================
 | STATS — active (non-archived) records only
 ============================================================ */
$presentCount = 0; $absentCount = 0; $archivedCount = 0;
foreach ($allAttendanceRecords as $rec) {
    if (!empty($rec['archived_at'])) { $archivedCount++; continue; }
    ($rec['login_time'] && $rec['logout_time']) ? $presentCount++ : $absentCount++;
}
$totalAtt   = $presentCount + $absentCount;
$presentPct = $totalAtt > 0 ? round($presentCount / $totalAtt * 100) : 0;
$absentPct  = $totalAtt > 0 ? round($absentCount  / $totalAtt * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance – SEMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="/CSS/organizer_attendance.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        brand: { 50:'#f0fdf4',100:'#dcfce7',200:'#bbf7d0',300:'#86efac',400:'#4ade80',500:'#22c55e',600:'#16a34a',700:'#15803d',800:'#166534',900:'#14532d' }
                    }
                }
            }
        }
    </script>

    <style>
        /* ── Proof image thumbnail ── */
        .proof-thumb {
            width: 2.5rem; height: 2.5rem;
            object-fit: cover; border-radius: .5rem;
            cursor: zoom-in; transition: transform .15s, box-shadow .15s;
            border: 2px solid #bbf7d0;
        }
        .proof-thumb:hover { transform: scale(1.12); box-shadow: 0 4px 16px rgba(34,197,94,.35); }

        .proof-placeholder {
            width: 2.5rem; height: 2.5rem; border-radius: .5rem;
            display: flex; align-items: center; justify-content: center;
        }

        /* ── Proof lightbox ── */
        #proofLightbox { backdrop-filter: blur(8px); }
        #proofLightbox img {
            max-height: 85vh; max-width: 90vw;
            border-radius: 1rem;
            box-shadow: 0 30px 80px rgba(0,0,0,.7);
        }

        /* ── Archived row ── */
        tr.att-archived td { opacity: .5; }
        tr.att-archived {
            background: repeating-linear-gradient(
                45deg, transparent, transparent 8px,
                rgba(156,163,175,.06) 8px, rgba(156,163,175,.06) 16px
            );
        }

        /* ── Edit-status fraud panel ── */
        .side-by-side-panel {
            display: grid; grid-template-columns: 1fr 1fr; gap: .75rem;
        }
        .fraud-card {
            border-radius: .75rem; padding: .75rem;
            border: 2px solid; text-align: center;
        }
        .fraud-card img, .fraud-card .fraud-init {
            width: 5rem; height: 5rem; object-fit: cover;
            border-radius: .5rem; margin: 0 auto .5rem;
        }
        .fraud-card .fraud-init {
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; font-weight: 700;
        }
        .fraud-card.proof-card  { border-color: #86efac; background: #f0fdf4; }
        .fraud-card.account-card { border-color: #bfdbfe; background: #eff6ff; }
        html.dark .fraud-card.proof-card   { background: rgba(20,83,45,.2);  border-color: #166534; }
        html.dark .fraud-card.account-card { background: rgba(30,58,138,.2); border-color: #1d4ed8; }

        /* ── Status toggle buttons in Edit modal ── */
        .status-btn {
            flex: 1; padding: .6rem 0; border-radius: .6rem;
            border: 2px solid; font-weight: 600; font-size: .8rem;
            cursor: pointer; transition: all .2s;
        }
        .status-btn.sel-present { background:#dcfce7; color:#15803d; border-color:#86efac; }
        .status-btn.sel-absent  { background:#fee2e2; color:#b91c1c; border-color:#fca5a5; }
        .status-btn:not(.sel-present):not(.sel-absent) {
            background: transparent; color: #6b7280; border-color: #d1d5db;
        }
        html.dark .status-btn:not(.sel-present):not(.sel-absent) {
            color: #9ca3af; border-color: #374151;
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

    <div id="sb-overlay" onclick="closeSidebar()"></div>

    <!-- ═══════════════════════════════════════════════════════
         SIDEBAR
    ═══════════════════════════════════════════════════════ -->
    <aside id="sidebar"
        class="fixed top-0 left-0 h-screen w-64 z-50
               bg-white dark:bg-gray-800
               border-r border-gray-200 dark:border-gray-700
               flex flex-col transition-transform duration-300 -translate-x-full lg:translate-x-0">

        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <?php if ($hasOrgLogo): ?>
                    <img src="data:<?= $orgMime ?>;base64,<?= base64_encode($orgData['logo']) ?>"
                        class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0" alt="<?= $orgName ?>">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/30">
                        <i class="fas fa-building text-white text-lg"></i>
                    </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words"><?= $orgName ?></p>
                    <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300"><?= $orgType ?></span>
                </div>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto p-3 space-y-1">
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>
            <a href="/organizer/organizer_panel.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm"><i class="fas fa-gauge-high"></i></span> Dashboard
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>
            <a href="/organizer/organizer_event.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm"><i class="fas fa-clipboard-list"></i></span>
                <span class="flex-1">Events  & Announcements</span>
                <?php if ($myEvents > 0): ?>
                    <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?= $myEvents ?></span>
                <?php endif; ?>
            </a>
            <a href="/organizer/organizer_qrscan.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm"><i class="fas fa-qrcode"></i></span> QR Scanner
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>
            <a href="/organizer/organizer_tracking.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm"><i class="fas fa-users"></i></span>
                <span class="flex-1">Registrations</span>
                <?php if ($registrations > 0): ?>
                    <span class="text-xs bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400 px-2 py-0.5 rounded-full font-semibold"><?= $registrations ?></span>
                <?php endif; ?>
            </a>
            <a href="/organizer/organizer_attendance.php" class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm"><i class="fas fa-user-check"></i></span>
                <span class="flex-1">Attendance</span>
                <?php if ($totalAtt > 0): ?>
                    <span class="text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 px-2 py-0.5 rounded-full font-semibold"><?= $totalAtt ?></span>
                <?php endif; ?>
            </a>
            <a href="/organizer/organizer_analytics.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm"><i class="fas fa-chart-line"></i></span> Analytics
            </a>
        </nav>

        <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
            <a href="/organizer/organizer_settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 flex items-center justify-center text-sm"><i class="fas fa-gear"></i></span> Settings
            </a>
            <a href="../includes/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-sm"><i class="fas fa-right-from-bracket"></i></span> Logout
            </a>
        </div>
    </aside>


    <!-- ═══════════════════════════════════════════════════════
         MAIN CONTENT
    ═══════════════════════════════════════════════════════ -->
    <div class="lg:ml-64 min-h-screen flex flex-col">

        <!-- STICKY HEADER -->
        <header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-800/90 border-b border-gray-200 dark:border-gray-700 px-4 sm:px-6 py-3" style="backdrop-filter:blur(10px);">
            <div class="flex items-center gap-3">
                <button onclick="openSidebar()" class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">Attendance</span>
                <span class="hidden md:flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 bg-gray-100 dark:bg-gray-700 px-3 py-1.5 rounded-full border border-gray-200 dark:border-gray-600">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                    <?= $orgName ?>
                </span>

                <div class="flex-1 mx-2 sm:mx-4 max-w-xs sm:max-w-sm">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" id="headerSearch" oninput="syncSearch(this.value)" placeholder="Search attendance…"
                            class="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-gray-100 dark:bg-gray-700 border border-transparent focus:border-brand-400 dark:focus:border-brand-500 text-gray-700 dark:text-gray-200 placeholder-gray-400 outline-none transition-colors">
                    </div>
                </div>

                <div class="flex items-center gap-2 ml-auto">
                    <button onclick="exportAttendanceCSV()" class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-brand-500 hover:text-white transition-all active:scale-95">
                        <i class="fas fa-file-csv text-xs"></i>
                        <span class="hidden md:inline">Export CSV</span>
                    </button>
                    <button onclick="toggleTheme()" title="Toggle theme" class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400 hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                        <i id="themeIcon" class="fas fa-moon text-sm"></i>
                    </button>
                    <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">
                        <div class="hidden sm:block text-right leading-tight">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= $fullName ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($profile['position'] ?? $deptName) ?></p>
                        </div>
                        <div class="w-9 h-9 rounded-full overflow-hidden bg-gradient-to-br from-brand-400 to-blue-500 flex items-center justify-center text-white text-xs font-bold ring-2 ring-brand-200 dark:ring-brand-700 hover:scale-105 transition-transform cursor-pointer">
                            <?php if ($hasImage && $profileImageData): ?>
                                <img src="data:<?= $mime ?>;base64,<?= $profileImageData ?>" class="w-full h-full object-cover" alt="Profile">
                            <?php else: ?>
                                <?= $initials ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>


        <!-- MAIN PAGE -->
        <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-7xl mx-auto w-full">

            <!-- Page heading -->
            <div class="anim-up d-0 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Track Attendance</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Monitor check-in and check-out status for your events.</p>
                </div>
                <button onclick="exportAttendanceCSV()" class="sm:hidden self-start inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors active:scale-95">
                    <i class="fas fa-file-export"></i> Export
                </button>
            </div>

            <!-- ── STAT CARDS ──────────────────────────────────── -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 anim-up d-1">

                <!-- Present -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 overflow-hidden relative">
                    <div class="absolute top-0 right-0 w-28 h-28 bg-brand-500/5 rounded-bl-full -mr-6 -mt-6"></div>
                    <div class="flex items-center gap-4">
                        <span class="icon-wrap w-14 h-14 rounded-2xl bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 flex items-center justify-center text-2xl border border-brand-200 dark:border-brand-800 flex-shrink-0">
                            <i class="fas fa-user-check"></i>
                        </span>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-0.5">Total Present</p>
                            <p id="presentCount" class="text-4xl font-extrabold text-gray-900 dark:text-white"><?= $presentCount ?></p>
                        </div>
                    </div>
                    <div class="mt-4 h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-brand-400 to-emerald-500 transition-all duration-700" style="width:<?= $presentPct ?>%"></div>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1.5"><?= $presentPct ?>% of total attendance</p>
                </div>

                <!-- Absent -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 overflow-hidden relative">
                    <div class="absolute top-0 right-0 w-28 h-28 bg-red-500/5 rounded-bl-full -mr-6 -mt-6"></div>
                    <div class="flex items-center gap-4">
                        <span class="icon-wrap w-14 h-14 rounded-2xl bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 flex items-center justify-center text-2xl border border-red-200 dark:border-red-800 flex-shrink-0">
                            <i class="fas fa-user-times"></i>
                        </span>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-0.5">Incomplete / Absent</p>
                            <p id="absentCount" class="text-4xl font-extrabold text-gray-900 dark:text-white"><?= $absentCount ?></p>
                        </div>
                    </div>
                    <div class="mt-4 h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-red-400 to-rose-500 transition-all duration-700" style="width:<?= $absentPct ?>%"></div>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1.5"><?= $absentPct ?>% of total attendance</p>
                </div>

                <!-- Archived -->
                <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 overflow-hidden relative">
                    <div class="absolute top-0 right-0 w-28 h-28 bg-amber-500/5 rounded-bl-full -mr-6 -mt-6"></div>
                    <div class="flex items-center gap-4">
                        <span class="icon-wrap w-14 h-14 rounded-2xl bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 flex items-center justify-center text-2xl border border-amber-200 dark:border-amber-800 flex-shrink-0">
                            <i class="fas fa-box-archive"></i>
                        </span>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-0.5">Archived</p>
                            <p id="archivedCount" class="text-4xl font-extrabold text-gray-900 dark:text-white"><?= $archivedCount ?></p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <!-- Toggle show/hide archived rows -->
                        <label class="flex items-center gap-2 cursor-pointer select-none mt-1">
                            <div class="relative">
                                <input type="checkbox" id="showArchived" class="sr-only" onchange="filterAttendance()">
                                <div class="w-9 h-5 bg-gray-200 dark:bg-gray-600 rounded-full transition-colors peer-checked:bg-amber-400" id="toggleTrack"></div>
                                <div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform" id="toggleDot"></div>
                            </div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Show archived</span>
                        </label>
                    </div>
                </div>
            </div>


            <?php if (empty($allAttendanceRecords)): ?>
                <!-- EMPTY STATE -->
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-16 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4 relative">
                        <span class="absolute inset-0 rounded-full bg-brand-500/10 animate-ping"></span>
                        <i class="fas fa-clipboard-check text-4xl text-gray-300 dark:text-gray-600 relative z-10"></i>
                    </div>
                    <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2">No attendance records yet</h3>
                    <p class="text-sm text-gray-400 mb-6 max-w-sm mx-auto">Start scanning QR codes to see attendance data here.</p>
                    <a href="/organizer/organizer_qrscan.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold rounded-xl transition-colors active:scale-95">
                        <i class="fas fa-qrcode"></i> Go to QR Scanner
                    </a>
                </div>

            <?php else: ?>

                <!-- FILTER BAR -->
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4 flex flex-col sm:flex-row gap-3">
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" id="pageSearch" oninput="syncSearch(this.value)" placeholder="Search by student name or event…"
                            class="w-full pl-9 pr-4 py-2.5 text-sm rounded-xl bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none text-gray-700 dark:text-gray-200 placeholder-gray-400 transition-colors">
                    </div>
                    <select id="statusFilter" onchange="filterAttendance()"
                        class="px-4 py-2.5 text-sm rounded-xl bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none text-gray-700 dark:text-gray-200 transition-colors appearance-none cursor-pointer">
                        <option value="">All Status</option>
                        <option value="PRESENT">Present</option>
                        <option value="ABSENT">Absent</option>
                    </select>
                </div>

                <!-- ATTENDANCE TABLE -->
                <div class="anim-up d-3 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="attendanceTable">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700 text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <th class="px-5 py-3.5 text-left">Event</th>
                                    <th class="px-5 py-3.5 text-left">Student</th>
                                    <th class="px-5 py-3.5 text-left col-stunum">Student No.</th>
                                    <th class="px-5 py-3.5 text-left">Log In</th>
                                    <th class="px-5 py-3.5 text-left">Log Out</th>
                                    <th class="px-5 py-3.5 text-left">Status</th>
                                    <!-- NEW: Proof image column -->
                                    <th class="px-5 py-3.5 text-left">Proof</th>
                                    <th class="px-5 py-3.5 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">

                                <?php foreach ($allAttendanceRecords as $i => $rec):
                                    $hasLogin   = !empty($rec['login_time']);
                                    $hasLogout  = !empty($rec['logout_time']);
                                    $isPresent  = $hasLogin && $hasLogout;
                                    $statusText = $isPresent ? 'PRESENT' : 'ABSENT';
                                    $isArchived = !empty($rec['archived_at']);

                                    $pill     = $isPresent
                                        ? 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border-brand-200 dark:border-brand-800'
                                        : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800';
                                    $pillIcon = $isPresent ? 'fa-check-circle' : 'fa-times-circle';
                                    $avatar   = strtoupper(substr($rec['student_name'], 0, 1));

                                    $duration = 'N/A';
                                    if ($hasLogin && $hasLogout) {
                                        $diff = strtotime($rec['logout_time']) - strtotime($rec['login_time']);
                                        if ($diff > 0) {
                                            $h = floor($diff / 3600); $m = floor(($diff % 3600) / 60);
                                            $duration = $h > 0 ? "{$h}h {$m}m" : "{$m} mins";
                                        }
                                    }

                                    $loginDisplay  = $hasLogin  ? date('M j, Y g:i A', strtotime($rec['login_time']))  : '—';
                                    $logoutDisplay = $hasLogout ? date('M j, Y g:i A', strtotime($rec['logout_time'])) : 'Not logged out';

                                    $hasStudentImg  = $rec['has_student_img'];
                                    $studentImgData = $rec['student_img_data'];
                                    $studentMime    = $rec['student_mime'];
                                    $imgSrc         = $hasStudentImg ? 'data:' . $studentMime . ';base64,' . $studentImgData : '';
                                    $hasProof       = (bool) $rec['has_proof'];
                                ?>

                                <tr class="row-hover group <?= $isArchived ? 'att-archived' : '' ?>"
                                    data-att-id="<?= $rec['attendance_id'] ?>"
                                    data-student-name="<?= htmlspecialchars($rec['student_name']) ?>"
                                    data-event-title="<?= htmlspecialchars($rec['event_title']) ?>"
                                    data-login-time="<?= $loginDisplay ?>"
                                    data-logout-time="<?= $logoutDisplay ?>"
                                    data-status="<?= $statusText ?>"
                                    data-duration="<?= $duration ?>"
                                    data-student-img="<?= htmlspecialchars($imgSrc) ?>"
                                    data-student-initial="<?= $avatar ?>"
                                    data-has-proof="<?= $hasProof ? '1' : '0' ?>"
                                    data-archived="<?= $isArchived ? '1' : '0' ?>"
                                    style="animation-delay:<?= $i * 40 ?>ms; <?= $isArchived ? 'display:none;' : '' ?>">

                                    <!-- Event -->
                                    <td class="px-5 py-4">
                                        <span class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-1 group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">
                                            <?= htmlspecialchars($rec['event_title']) ?>
                                        </span>
                                        <?php if ($isArchived): ?>
                                            <span class="block text-[10px] text-amber-500 font-semibold mt-0.5"><i class="fas fa-box-archive mr-1"></i>Archived</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Student -->
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <?php if ($hasStudentImg): ?>
                                                <img src="data:<?= $studentMime ?>;base64,<?= $studentImgData ?>" alt="<?= htmlspecialchars($rec['student_name']) ?>" class="student-avatar student-avatar-img">
                                            <?php else: ?>
                                                <span class="student-avatar student-avatar-init"><?= $avatar ?></span>
                                            <?php endif; ?>
                                            <span class="font-medium text-gray-900 dark:text-white text-sm"><?= htmlspecialchars($rec['student_name']) ?></span>
                                        </div>
                                    </td>

                                    <!-- Student number -->
                                    <td class="px-5 py-4 col-stunum">
                                        <span class="text-gray-400 font-mono text-xs"><?= htmlspecialchars($rec['student_number'] ?? '—') ?></span>
                                    </td>

                                    <!-- Login -->
                                    <td class="px-5 py-4">
                                        <?php if ($hasLogin): ?>
                                            <p class="text-gray-700 dark:text-gray-300 text-xs"><?= date('M j, Y', strtotime($rec['login_time'])) ?></p>
                                            <p class="text-brand-500 dark:text-brand-400 text-[11px] font-semibold"><?= date('g:i A', strtotime($rec['login_time'])) ?></p>
                                        <?php else: ?>
                                            <span class="text-gray-300 dark:text-gray-600 text-xs">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Logout -->
                                    <td class="px-5 py-4">
                                        <?php if ($hasLogout): ?>
                                            <p class="text-gray-700 dark:text-gray-300 text-xs"><?= date('M j, Y', strtotime($rec['logout_time'])) ?></p>
                                            <p class="text-amber-500 dark:text-amber-400 text-[11px] font-semibold"><?= date('g:i A', strtotime($rec['logout_time'])) ?></p>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-xs italic">Not logged out</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status badge -->
                                    <td class="px-5 py-4">
                                        <span class="att-status-pill inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border uppercase <?= $pill ?>">
                                            <i class="fas <?= $pillIcon ?> text-[10px]"></i>
                                            <?= $statusText ?>
                                        </span>
                                    </td>

                                    <!-- ── NEW: Proof image cell ── -->
                                    <td class="px-5 py-4">
                                        <?php if ($hasProof): ?>
                                            <!-- Thumbnail — clicking loads full image via AJAX then opens lightbox -->
                                            <img src="/images/proof-placeholder.png"
                                                 alt="Proof"
                                                 class="proof-thumb"
                                                 loading="lazy"
                                                 onclick="openProofLightbox(<?= $rec['attendance_id'] ?>, this)"
                                                 data-loaded="0"
                                                 title="Click to view proof photo">
                                        <?php else: ?>
                                            <span class="proof-placeholder bg-gray-100 dark:bg-gray-700 text-gray-300 dark:text-gray-600" title="No proof image">
                                                <i class="fas fa-image text-sm"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- ── Action buttons ── -->
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-1.5">
                                            <!-- Details / Edit (opens fraud-detection edit modal) -->
                                            <button onclick="openDetailsModal(this)"
                                                class="w-8 h-8 rounded-lg flex items-center justify-center bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-600 hover:bg-brand-500 hover:text-white hover:border-brand-500 transition-all active:scale-95"
                                                title="View / Edit">
                                                <i class="fas fa-clock-rotate-left text-xs"></i>
                                            </button>

                                            <!-- Archive / Unarchive -->
                                            <?php if (!$isArchived): ?>
                                                <button onclick="archiveRecord(this)"
                                                    class="w-8 h-8 rounded-lg flex items-center justify-center bg-gray-100 dark:bg-gray-700 text-amber-500 border border-gray-200 dark:border-gray-600 hover:bg-amber-500 hover:text-white hover:border-amber-500 transition-all active:scale-95"
                                                    title="Archive">
                                                    <i class="fas fa-box-archive text-xs"></i>
                                                </button>
                                            <?php else: ?>
                                                <button onclick="unarchiveRecord(this)"
                                                    class="w-8 h-8 rounded-lg flex items-center justify-center bg-amber-100 dark:bg-amber-900/30 text-amber-600 border border-amber-200 dark:border-amber-800 hover:bg-brand-500 hover:text-white hover:border-brand-500 transition-all active:scale-95"
                                                    title="Unarchive">
                                                    <i class="fas fa-box-open text-xs"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="px-5 py-3 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-400">
                        <span id="rowCount">Showing <?= count(array_filter($allAttendanceRecords, fn($r) => empty($r['archived_at']))) ?> records</span>
                        <span>Last updated: <?= date('M j, Y g:i A') ?></span>
                    </div>
                </div>

            <?php endif; ?>
        </main>
    </div>


    <!-- ── SCROLL TO TOP ────────────────────────────────────── -->
    <button onclick="window.scrollTo({top:0,behavior:'smooth'})"
        class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 shadow-lg hover:bg-brand-500 hover:text-white hover:border-brand-500 transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
        <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
    </button>


    <!-- ═══════════════════════════════════════════════════════
         PROOF IMAGE LIGHTBOX
         Opens when clicking a proof thumbnail. Image is loaded
         via AJAX (GET ?action=get_proof&id=X) on first click.
    ═══════════════════════════════════════════════════════ -->
    <div id="proofLightbox"
        class="fixed inset-0 z-[60] hidden items-center justify-center p-4 bg-black/80"
        onclick="if(event.target===this||event.target.id==='proofLightbox')closeProofLightbox()">

        <!-- Loading spinner — visible while AJAX is in flight -->
        <div id="proofSpinner" class="hidden flex-col items-center gap-3 text-white">
            <i class="fas fa-spinner fa-spin text-3xl"></i>
            <p class="text-sm">Loading proof image…</p>
        </div>

        <!-- Actual lightbox image -->
        <div id="proofLightboxInner" class="hidden flex-col items-center gap-4">
            <div class="flex items-center justify-between w-full max-w-lg px-2">
                <p class="text-white text-sm font-semibold opacity-80">
                    <i class="fas fa-camera mr-2 text-brand-400"></i>Scan Proof Photo
                </p>
                <button onclick="closeProofLightbox()" class="w-8 h-8 rounded-lg bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>
            <img id="proofLightboxImg" src="" alt="Proof" class="">
            <p class="text-white/50 text-xs">Click outside to close &nbsp;·&nbsp; Esc to dismiss</p>
        </div>
    </div>


    <!-- ═══════════════════════════════════════════════════════
         DETAILS + EDIT MODAL
         Shows attendance details AND a fraud-detection panel:
         proof photo vs student account photo side-by-side,
         with an editable Present / Absent toggle.
    ═══════════════════════════════════════════════════════ -->
    <div id="detailsModal"
        class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/60"
        style="backdrop-filter:blur(6px)"
        onclick="if(event.target===this)closeDetailsModal()">

        <div class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg
                    border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden">

            <div class="h-1.5 bg-gradient-to-r from-brand-400 to-blue-500"></div>

            <!-- Modal header -->
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <span class="w-10 h-10 rounded-xl bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 flex items-center justify-center">
                    <i class="fas fa-user-clock"></i>
                </span>
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-gray-900 dark:text-white">Attendance Details</h3>
                    <p class="text-xs text-gray-400 mt-0.5 truncate" id="modalEventTitle">—</p>
                </div>
                <button onclick="closeDetailsModal()" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors flex items-center justify-center">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <div class="p-6 space-y-4 max-h-[80vh] overflow-y-auto">

                <!-- ── FRAUD DETECTION PANEL ─────────────────────
                     Side-by-side: Proof photo (what the camera
                     captured) vs Account photo (who is registered).
                     If faces differ → likely fraud / proxy scan.
                ──────────────────────────────────────────────── -->
                <div class="side-by-side-panel">

                    <!-- Proof photo (lazy-loaded from server) -->
                    <div class="fraud-card proof-card">
                        <p class="text-[10px] uppercase font-bold text-brand-700 dark:text-brand-300 mb-2 flex items-center justify-center gap-1">
                            <i class="fas fa-camera"></i> Scan Proof
                        </p>
                        <div id="modalProofWrap" class="flex items-center justify-center">
                            <div id="modalProofSpinner" class="fraud-init text-gray-300 dark:text-gray-600 bg-gray-100 dark:bg-gray-700 rounded-lg">
                                <i class="fas fa-spinner fa-spin text-xl"></i>
                            </div>
                            <img id="modalProofImg" src="" alt="Proof" class="fraud-img w-20 h-20 object-cover rounded-lg hidden">
                            <div id="modalNoProof" class="fraud-init text-gray-300 dark:text-gray-600 bg-gray-100 dark:bg-gray-700 rounded-lg hidden">
                                <i class="fas fa-image-slash text-xl"></i>
                            </div>
                        </div>
                        <p class="text-[11px] text-brand-600 dark:text-brand-400 mt-2 font-medium">Photo at scan time</p>
                    </div>

                    <!-- Account photo (from student profile) -->
                    <div class="fraud-card account-card">
                        <p class="text-[10px] uppercase font-bold text-blue-700 dark:text-blue-300 mb-2 flex items-center justify-center gap-1">
                            <i class="fas fa-id-card"></i> Account
                        </p>
                        <div class="flex items-center justify-center">
                            <img id="modalStudentPhoto" src="" alt="Student" class="fraud-img w-20 h-20 object-cover rounded-lg hidden border-2 border-blue-200 dark:border-blue-800">
                            <span id="modalStudentInitial" class="fraud-init w-20 h-20 rounded-lg bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-800">J</span>
                        </div>
                        <p class="font-semibold text-gray-900 dark:text-white text-sm mt-2" id="modalStudentName">—</p>
                        <div id="modalStatusBadge" class="mt-1 flex justify-center"></div>
                    </div>
                </div>

                <!-- Fraud warning banner — shown by JS if mismatch suspected -->
                <div id="fraudWarning" class="hidden items-center gap-3 px-4 py-3 rounded-xl bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 text-orange-700 dark:text-orange-400">
                    <i class="fas fa-triangle-exclamation text-lg flex-shrink-0"></i>
                    <div>
                        <p class="text-xs font-bold">Possible Mismatch Detected</p>
                        <p class="text-[11px] mt-0.5">The scan proof shows a different person than the registered account. Verify before confirming status.</p>
                    </div>
                </div>

                <!-- Login / Logout time grid -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl p-4">
                        <p class="text-[10px] uppercase font-semibold text-gray-400 mb-1 flex items-center gap-1">
                            <i class="fas fa-sign-in-alt text-brand-400"></i> Log In
                        </p>
                        <p class="font-semibold text-gray-900 dark:text-white text-sm" id="modalLoginTime">—</p>
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl p-4">
                        <p class="text-[10px] uppercase font-semibold text-gray-400 mb-1 flex items-center gap-1">
                            <i class="fas fa-sign-out-alt text-amber-400"></i> Log Out
                        </p>
                        <p class="font-semibold text-gray-900 dark:text-white text-sm" id="modalLogoutTime">—</p>
                    </div>
                </div>

                <!-- Duration -->
                <div class="flex items-center justify-between bg-gradient-to-r from-brand-50 to-blue-50 dark:from-brand-900/20 dark:to-blue-900/20 border border-brand-200 dark:border-brand-800 rounded-xl px-5 py-4">
                    <span class="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-2">
                        <i class="fas fa-clock text-brand-500"></i> Duration
                    </span>
                    <span class="text-xl font-bold text-gray-900 dark:text-white" id="modalDuration">N/A</span>
                </div>

                <!-- ── EDIT STATUS ─────────────────────────────────
                     Organizer can override Present / Absent after
                     comparing the two photos in the fraud panel.
                ──────────────────────────────────────────────── -->
                <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 space-y-3">
                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-pen text-brand-400"></i> Edit Status
                    </p>
                    <div class="flex gap-2">
                        <button id="btnSetPresent" class="status-btn" onclick="selectStatus('present')">
                            <i class="fas fa-check-circle mr-1.5"></i> Present
                        </button>
                        <button id="btnSetAbsent" class="status-btn" onclick="selectStatus('absent')">
                            <i class="fas fa-times-circle mr-1.5"></i> Absent
                        </button>
                    </div>
                    <button id="btnSaveStatus"
                        onclick="saveEditedStatus()"
                        class="w-full py-2.5 rounded-xl text-sm font-semibold bg-brand-500 hover:bg-brand-600 text-white transition-colors flex items-center justify-center gap-2 active:scale-95">
                        <i class="fas fa-save"></i> Save Status
                    </button>
                    <p id="editStatusMsg" class="text-xs text-center hidden"></p>
                </div>

                <!-- Close button -->
                <button onclick="closeDetailsModal()" class="w-full py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-2">
                    <i class="fas fa-times text-red-400"></i> Close
                </button>
            </div>
        </div>
    </div>


    <!-- ── PHP → JS DATA BRIDGE ───────────────────────────── -->
    <script>
        const SEMS_ATTENDANCE_DATA = {
            exportDate: "<?= date('Y-m-d') ?>",
        };
    </script>

    <script src="/js/organizer_attendance.js"></script>
</body>
</html>