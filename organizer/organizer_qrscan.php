<?php
// ============================================================
// SIMULAN ANG SESSION at i-load ang database connection
// ============================================================
session_start();
$pdo = require_once '../includes/db.php';

// ============================================================
// I-CHECK kung naka-login ang user at organizer ang role niya
// ============================================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$uid = (int) $_SESSION['user_id'];

// ============================================================
// KUNIN ANG ORG/CLUB CONTEXT ng organizer
// ============================================================
$myCtx = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = ?");
$myCtx->execute([$uid]);
$myCtxRow = $myCtx->fetch(PDO::FETCH_ASSOC) ?: ['org_id' => null, 'club_id' => null];
$myOrgId  = !empty($myCtxRow['org_id'])  ? (int)$myCtxRow['org_id']  : null;
$myClubId = !empty($myCtxRow['club_id']) ? (int)$myCtxRow['club_id'] : null;


// ============================================================
// HELPER: buildOrgEventWhere()
// ============================================================
function buildOrgEventWhere(string $prefix, int $uid, ?int $orgId, ?int $clubId, array &$params): string
{
    $p = $prefix !== '' ? $prefix . '.' : '';
    $params[] = $uid;

    if ($orgId || $clubId) {
        $orParts = [];
        if ($orgId) {
            $orParts[] = "{$p}org_id = ?";
            $params[]  = $orgId;
        }
        if ($clubId) {
            $orParts[] = "{$p}club_id = ?";
            $params[]  = $clubId;
        }
        return "({$p}organizer_id = ? OR " . implode(' OR ', $orParts) . ")";
    }

    return "{$p}organizer_id = ?";
}


// ============================================================
// KUNIN ANG ORGANIZATION O CLUB INFO ng organizer
// ============================================================
$orgName    = 'Organization';
$orgType    = 'Organization';
$hasOrgLogo = false;
$orgData    = null;
$orgMime    = 'image/jpeg';

try {
    $orgQ = $pdo->prepare("
        SELECT o.org_name, o.logo, 'Organization' as type
        FROM users u
        LEFT JOIN organizations o ON u.org_id = o.org_id
        WHERE u.user_id = ? AND o.org_id IS NOT NULL
    ");
    $orgQ->execute([$uid]);
    $orgData = $orgQ->fetch(PDO::FETCH_ASSOC);

    if ($orgData) {
        $orgName    = htmlspecialchars($orgData['org_name']);
        $orgType    = $orgData['type'];
        $hasOrgLogo = !empty($orgData['logo']);
    } else {
        $clubQ = $pdo->prepare("
            SELECT c.club_name as org_name, c.logo, 'Club' as type
            FROM users u
            LEFT JOIN clubs c ON u.club_id = c.club_id
            WHERE u.user_id = ? AND c.club_id IS NOT NULL
        ");
        $clubQ->execute([$uid]);
        $orgData = $clubQ->fetch(PDO::FETCH_ASSOC);

        if ($orgData) {
            $orgName    = htmlspecialchars($orgData['org_name']);
            $orgType    = $orgData['type'];
            $hasOrgLogo = !empty($orgData['logo']);
        }
    }

    if ($hasOrgLogo && !empty($orgData['logo'])) {
        $finfo           = finfo_open(FILEINFO_MIME_TYPE);
        $detectedOrgMime = finfo_buffer($finfo, $orgData['logo']);
        if ($detectedOrgMime && strpos($detectedOrgMime, 'image/') === 0) {
            $orgMime = $detectedOrgMime;
        }
    }
} catch (Exception $e) {
    // Silent fail
}


// ============================================================
// KUNIN ANG PROFILE INFO ng organizer
// ============================================================
$profileStmt = $pdo->prepare("
    SELECT 
        COALESCE(p.first_name,    o.first_name)    as first_name,
        COALESCE(p.last_name,     o.last_name)     as last_name,
        COALESCE(p.middle_name,   o.middle_name)   as middle_name,
        COALESCE(p.profile_image, o.profile_image) as profile_image,
        o.position,
        d.dept_name
    FROM users u
    LEFT JOIN profiles p    ON u.user_id = p.user_id
    LEFT JOIN organizer o   ON u.user_id = o.user_id
    LEFT JOIN departments d ON u.dept_id = d.dept_id
    WHERE u.user_id = ?
");
$profileStmt->execute([$uid]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);

$middleName = !empty($profile['middle_name'])
    ? ' ' . strtoupper(substr($profile['middle_name'], 0, 1)) . '. '
    : ' ';
$fullName = htmlspecialchars(
    ($profile['first_name'] ?? '') . $middleName . ($profile['last_name'] ?? '')
);

$initials = strtoupper(
    substr($profile['first_name'] ?? 'O', 0, 1) .
    substr($profile['last_name']  ?? '',  0, 1)
);

$hasImage = !empty($profile['profile_image']);
$mime     = 'image/jpeg';

if ($hasImage) {
    $finfo        = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_buffer($finfo, $profile['profile_image']);
    if ($detectedMime && strpos($detectedMime, 'image/') === 0) {
        $mime = $detectedMime;
    }
}


// ============================================================
// SIDEBAR BADGE COUNTS
// ============================================================
$sbEvParams = [];
$sbEvWhere  = buildOrgEventWhere('', $uid, $myOrgId, $myClubId, $sbEvParams);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $sbEvWhere AND status != 'rejected'");
$stmt->execute($sbEvParams);
$myEvents = $stmt->fetchColumn();

$sbRegParams = [];
$sbRegWhere  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $sbRegParams);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations r JOIN events e ON r.event_id=e.event_id WHERE $sbRegWhere");
$stmt->execute($sbRegParams);
$registrations = $stmt->fetchColumn();


// ============================================================
// AJAX HANDLER: QR SCAN SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_scan'])) {
    header('Content-Type: application/json');

    $qrValue  = trim($_POST['qr_value']  ?? '');
    $eventId  = (int)($_POST['event_id'] ?? 0);
    $scanType = $_POST['scan_type']       ?? 'login';

    $proofImageB64 = trim($_POST['proof_image'] ?? '');
    $proofImageBin = '';
    if ($proofImageB64 !== '') {
        $decoded = base64_decode($proofImageB64, true);
        if ($decoded !== false) {
            $finfo     = finfo_open(FILEINFO_MIME_TYPE);
            $proofMime = finfo_buffer($finfo, $decoded);
            if ($proofMime && strpos($proofMime, 'image/') === 0) {
                $proofImageBin = $decoded;
            }
        }
    }

    if (!$qrValue || !$eventId) {
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit();
    }

    $archiveCheckQ = $pdo->prepare("
        SELECT status, deleted_at FROM events WHERE event_id = ?
    ");
    $archiveCheckQ->execute([$eventId]);
    $archiveCheckRow = $archiveCheckQ->fetch(PDO::FETCH_ASSOC);

    if (!$archiveCheckRow || !empty($archiveCheckRow['deleted_at'])) {
        echo json_encode([
            'success' => false,
            'message' => 'This event is archived and no longer accepts attendance.'
        ]);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $qrQ = $pdo->prepare("SELECT user_id FROM student_qr_codes WHERE qr_value = ?");
        $qrQ->execute([$qrValue]);
        $qrRow = $qrQ->fetch(PDO::FETCH_ASSOC);

        if (!$qrRow) {
            $snQ = $pdo->prepare("
                SELECT u.user_id FROM users u
                JOIN profiles p ON u.user_id = p.user_id
                WHERE p.student_number = ?
            ");
            $snQ->execute([$qrValue]);
            $snRow = $snQ->fetch(PDO::FETCH_ASSOC);
            if ($snRow) $qrRow = $snRow;

            if (!$qrRow) {
                $onQ = $pdo->prepare("
                    SELECT u.user_id FROM users u
                    JOIN organizer o ON u.user_id = o.user_id
                    WHERE o.student_number = ?
                ");
                $onQ->execute([$qrValue]);
                $onRow = $onQ->fetch(PDO::FETCH_ASSOC);
                if ($onRow) $qrRow = $onRow;
            }
        }

        if (!$qrRow) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'QR code / Student number not recognized']);
            exit();
        }

        $studentId = $qrRow['user_id'];

        $spQ = $pdo->prepare("
            SELECT CONCAT(first_name,' ',last_name) AS name, year_level, section
            FROM profiles WHERE user_id = ?
        ");
        $spQ->execute([$studentId]);
        $student = $spQ->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            $spQ2 = $pdo->prepare("
                SELECT CONCAT(first_name,' ',last_name) AS name, year_level, section
                FROM organizer WHERE user_id = ?
            ");
            $spQ2->execute([$studentId]);
            $student = $spQ2->fetch(PDO::FETCH_ASSOC);
        }

        if (!$student) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            exit();
        }

        $checkQ = $pdo->prepare("
            SELECT attendance_id, login_time, logout_time
            FROM attendance
            WHERE event_id = ? AND user_id = ? AND scan_time = CURDATE()
        ");
        $checkQ->execute([$eventId, $studentId]);
        $existing = $checkQ->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($scanType === 'login' && !empty($existing['login_time'])) {
                $pdo->rollBack();
                $loggedAt = date('g:i A', strtotime($existing['login_time']));
                echo json_encode([
                    'success' => false,
                    'already' => true,
                    'state'   => 'login',
                    'name'    => $student['name'],
                    'time'    => $loggedAt,
                    'message' => "{$student['name']} already logged in at {$loggedAt}",
                ]);
                exit();
            }

            if ($scanType === 'logout' && !empty($existing['logout_time'])) {
                $pdo->rollBack();
                $loggedAt = date('g:i A', strtotime($existing['logout_time']));
                echo json_encode([
                    'success' => false,
                    'already' => true,
                    'state'   => 'logout',
                    'name'    => $student['name'],
                    'time'    => $loggedAt,
                    'message' => "{$student['name']} already logged out at {$loggedAt}",
                ]);
                exit();
            }
        }

        if ($existing) {
            if ($scanType === 'login') {
                $attIns  = $pdo->prepare("
                    UPDATE attendance
                    SET login_time  = NOW(),
                        proof_image = ?,
                        verified_by = ?
                    WHERE attendance_id = ?
                ");
                $success = $attIns->execute([$proofImageBin, $uid, $existing['attendance_id']]);
            } else {
                $attIns  = $pdo->prepare("
                    UPDATE attendance
                    SET logout_time = NOW(),
                        verified_by = ?
                    WHERE attendance_id = ?
                ");
                $success = $attIns->execute([$uid, $existing['attendance_id']]);
            }
        } else {
            if ($scanType === 'login') {
                $attIns = $pdo->prepare("
                    INSERT INTO attendance
                        (event_id, user_id, year_level, section, proof_image, verified_by, login_time, logout_time, scan_time)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL, CURDATE())
                ");
            } else {
                $attIns = $pdo->prepare("
                    INSERT INTO attendance
                        (event_id, user_id, year_level, section, proof_image, verified_by, login_time, logout_time, scan_time)
                    VALUES (?, ?, ?, ?, ?, ?, NULL, NOW(), CURDATE())
                ");
            }
            $success = $attIns->execute([
                $eventId,
                $studentId,
                $student['year_level'],
                $student['section'],
                $proofImageBin,
                $uid,
            ]);
        }

        if ($success) {
            $pdo->commit();
            $profImgB64 = '';
$piQ = $pdo->prepare("SELECT profile_image FROM profiles WHERE user_id = ?");
$piQ->execute([$studentId]);
$piRow = $piQ->fetch(PDO::FETCH_ASSOC);
if (!empty($piRow['profile_image'])) {
    $profImgB64 = base64_encode($piRow['profile_image']);
}
echo json_encode([
    'success'           => true,
    'name'              => $student['name'],
    'scan_type'         => $scanType,
    'profile_image_b64' => $profImgB64,
]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Failed to record']);
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}


// ============================================================
// KUNIN ANG LAHAT NG APPROVED AT HINDI PA DELETED NA EVENTS
// ============================================================
$evParams = [];
$evWhere  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $evParams);

$evQ = $pdo->prepare("
    SELECT e.event_id, e.title, e.status,
           e.deleted_at, e.deleted_by,
           e.start_datetime, e.end_datetime,
           v.venue_name,
           COUNT(DISTINCT a.attendance_id) AS scanned
    FROM events e
    LEFT JOIN venues v     ON e.venue_id = v.venue_id
    LEFT JOIN attendance a ON e.event_id = a.event_id
    WHERE $evWhere
      AND e.status    = 'approved'
      AND e.deleted_at IS NULL
    GROUP BY e.event_id
    ORDER BY e.start_datetime DESC
");
$evQ->execute($evParams);
$events = $evQ->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
foreach ($events as &$ev) {
    $start = date('Y-m-d', strtotime($ev['start_datetime']));
    $end   = !empty($ev['end_datetime'])
        ? date('Y-m-d', strtotime($ev['end_datetime']))
        : $start;

    if ($today >= $start && $today <= $end) $ev['category'] = 'ongoing';
    elseif ($today < $start)               $ev['category'] = 'upcoming';
    else                                    $ev['category'] = 'ended';
}
unset($ev);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Scanner – SEMS</title>
    <link rel="icon" href="/assets/qrcode-icon-indigo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="/CSS/organizer_qrscan.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        brand: {
                            50:  '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d'
                        }
                    }
                }
            }
        }
    </script>

    <style>
        /* ── Proof Capture Zone (Manual Modal) ── */
        .proof-zone {
            border: 1.5px dashed rgba(255,255,255,.15);
            border-radius: 12px;
            background: rgba(255,255,255,.03);
            cursor: pointer;
            transition: border-color .2s, background .2s;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 80px;
            position: relative;
        }
        .proof-zone:hover {
            border-color: rgba(34,197,94,.4);
            background: rgba(34,197,94,.04);
        }
        .proof-zone.captured {
            border-color: rgba(34,197,94,.5);
            background: rgba(34,197,94,.05);
        }
        .proof-zone img {
            max-height: 88px;
            border-radius: 8px;
            object-fit: cover;
        }
        .proof-retake-btn {
            position: absolute;
            top: 6px; right: 6px;
            background: rgba(0,0,0,.55);
            border: none;
            border-radius: 6px;
            color: rgba(255,255,255,.7);
            font-size: 10px;
            padding: 3px 7px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: background .15s;
        }
        .proof-retake-btn:hover { background: rgba(239,68,68,.6); color:#fff; }

        /* Light-mode manual modal overrides */
        .manual-modal-inner .proof-zone {
            border-color: rgba(0,0,0,.12);
            background: rgba(0,0,0,.02);
        }
        .manual-modal-inner .proof-zone:hover {
            border-color: rgba(34,197,94,.5);
        }

        /* ── Proof Camera Overlay ── */
        #proofCameraOverlay .cam-modal {
            background: #0f1117;
            border: 1px solid rgba(255,255,255,.08);
            box-shadow: 0 32px 80px rgba(0,0,0,.7);
        }
        #proofCameraVideo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .cam-corner {
            position: absolute;
            width: 18px;
            height: 18px;
        }
        .cam-corner-tl { top: -1px; left: -1px;  border-top: 2px solid #a855f7; border-left:  2px solid #a855f7; border-radius: 4px 0 0 0; }
        .cam-corner-tr { top: -1px; right: -1px; border-top: 2px solid #a855f7; border-right: 2px solid #a855f7; border-radius: 0 4px 0 0; }
        .cam-corner-bl { bottom: -1px; left: -1px;  border-bottom: 2px solid #a855f7; border-left:  2px solid #a855f7; border-radius: 0 0 0 4px; }
        .cam-corner-br { bottom: -1px; right: -1px; border-bottom: 2px solid #a855f7; border-right: 2px solid #a855f7; border-radius: 0 0 4px 0; }
        #proofCaptureBtn:not(:disabled) {
            background: linear-gradient(135deg, #a855f7, #7c3aed);
            box-shadow: 0 4px 16px rgba(168,85,247,.4);
        }
        #proofCaptureBtn:not(:disabled):hover {
            box-shadow: 0 6px 20px rgba(168,85,247,.55);
            transform: translateY(-1px);
        }
        #proofCaptureBtn:not(:disabled):active {
            transform: scale(.97);
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

    <div id="sb-overlay" onclick="closeSidebar()"></div>

    <!-- ============================================================
     SIDEBAR NAVIGATION
    ============================================================ -->
    <aside id="sidebar"
        class="fixed top-0 left-0 h-screen w-64 z-50
              bg-white dark:bg-gray-800
              border-r border-gray-200 dark:border-gray-700
              flex flex-col transition-transform duration-300
              -translate-x-full lg:translate-x-0">

        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <?php if ($hasOrgLogo): ?>
                    <img src="data:<?= $orgMime ?>;base64,<?= base64_encode($orgData['logo']) ?>"
                        class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                        alt="<?= $orgName ?>">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/30">
                        <i class="fas fa-building text-white text-lg"></i>
                    </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words"><?= $orgName ?></p>
                    <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full
                             bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                        <?= $orgType ?>
                    </span>
                </div>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto p-3 space-y-1">

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>
            <a href="/organizer/organizer_panel.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                    <i class="fas fa-gauge-high"></i>
                </span>
                Dashboard
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>

            <a href="/organizer/organizer_event.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                    <i class="fas fa-clipboard-list"></i>
                </span>
                <span class="flex-1">Events & Announcements</span>
                <?php if ($myEvents > 0): ?>
                    <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?= $myEvents ?></span>
                <?php endif; ?>
            </a>

            <a href="/organizer/organizer_qrscan.php"
                class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm">
                    <i class="fas fa-qrcode"></i>
                </span>
                QR Scanner
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>

            <a href="/organizer/organizer_tracking.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm">
                    <i class="fas fa-users"></i>
                </span>
                <span class="flex-1">Registrations</span>
                <?php if ($registrations > 0): ?>
                    <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?= $registrations ?></span>
                <?php endif; ?>
            </a>

            <a href="/organizer/organizer_attendance.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fas fa-user-check"></i>
                </span>
                Attendance
            </a>

            <a href="/organizer/organizer_analytics.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                    <i class="fas fa-chart-line"></i>
                </span>
                Analytics
            </a>

            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Communication</p>

            <a href="/organizer/organizer_chat.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors"
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

        <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
            <a href="/organizer/organizer_settings.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 flex items-center justify-center text-sm">
                    <i class="fas fa-gear"></i>
                </span>
                Settings
            </a>
            <a href="../includes/logout.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-sm">
                    <i class="fas fa-right-from-bracket"></i>
                </span>
                Logout
            </a>
        </div>
    </aside>

    <!-- ============================================================
     MAIN CONTENT WRAPPER
    ============================================================ -->
    <div class="lg:ml-64 min-h-screen flex flex-col">

        <!-- STICKY HEADER -->
        <header class="sticky top-0 z-30
                   bg-white/90 dark:bg-gray-800/90
                   border-b border-gray-200 dark:border-gray-700
                   px-4 sm:px-6 py-3"
            style="backdrop-filter:blur(10px);">
            <div class="flex items-center gap-3">
                <button onclick="openSidebar()"
                    class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">QR Scanner</span>

                <div class="flex items-center gap-2 ml-auto">
                    <button onclick="toggleTheme()" title="Toggle theme"
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400
                               hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                        <i id="themeIcon" class="fas fa-moon text-sm"></i>
                    </button>
                    <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">
                        <div class="hidden sm:block text-right leading-tight">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= $fullName ?></p>
                            <p class="text-xs text-gray-400">
                                <?= htmlspecialchars($profile['position'] ?? ($profile['dept_name'] ?? 'Organizer')) ?>
                            </p>
                        </div>
                        <div class="w-9 h-9 rounded-full overflow-hidden
                                bg-gradient-to-br from-brand-400 to-blue-500
                                flex items-center justify-center text-white text-xs font-bold
                                ring-2 ring-brand-200 dark:ring-brand-700
                                hover:scale-105 transition-transform cursor-pointer">
                            <?php if ($hasImage): ?>
                                <img src="data:<?= $mime ?>;base64,<?= base64_encode($profile['profile_image']) ?>"
                                    class="w-full h-full object-cover" alt="Profile">
                            <?php else: ?>
                                <?= $initials ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- MAIN CONTENT AREA -->
        <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-7xl mx-auto w-full">

            <div class="anim-up d-0 flex flex-col sm:flex-row sm:items-end justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Event Attendance Scanner</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        Scan student QR codes or enter IDs manually to record attendance.
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400
                        bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                        px-3 py-2 rounded-xl self-start sm:self-auto">
                    <i class="fas fa-circle-info text-brand-500"></i>
                    <?= count($events) ?> approved event<?= count($events) !== 1 ? 's' : '' ?> available
                </div>
            </div>

            <!-- FILTER BAR -->
            <div class="anim-up d-1 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4
                    flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex flex-wrap gap-2" id="filterBtns">
                    <?php
                    $filters = [
                        ['id' => 'all',      'label' => 'All',      'icon' => 'fa-layer-group',  'color' => 'brand'],
                        ['id' => 'ongoing',  'label' => 'Ongoing',  'icon' => 'fa-circle',       'color' => 'brand'],
                        ['id' => 'upcoming', 'label' => 'Upcoming', 'icon' => 'fa-calendar-day', 'color' => 'amber'],
                        ['id' => 'ended',    'label' => 'Ended',    'icon' => 'fa-history',      'color' => 'gray'],
                    ];
                    foreach ($filters as $f): ?>
                        <button onclick="setFilter('<?= $f['id'] ?>')"
                            data-filter="<?= $f['id'] ?>"
                            class="filter-btn <?= $f['id'] === 'all' ? 'active' : '' ?>
                               flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium
                               border border-transparent
                               text-gray-500 dark:text-gray-400
                               hover:bg-gray-100 dark:hover:bg-gray-700 transition-all">
                            <i class="fas <?= $f['icon'] ?> text-xs
                               <?= $f['id'] === 'ongoing'  ? 'text-brand-500 animate-pulse'
                                   : ($f['id'] === 'upcoming' ? 'text-amber-500'
                                   : ($f['id'] === 'all'      ? 'text-blue-500' : 'text-gray-400')) ?>"></i>
                            <?= $f['label'] ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="sm:ml-auto relative w-full sm:w-64">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" id="pageSearch" oninput="applyFilters()"
                        placeholder="Search events…"
                        class="w-full pl-9 pr-4 py-2 text-sm rounded-lg
                              bg-gray-50 dark:bg-gray-700
                              border border-gray-200 dark:border-gray-600
                              focus:border-brand-400 dark:focus:border-brand-500
                              text-gray-700 dark:text-gray-200 placeholder-gray-400
                              outline-none transition-colors">
                </div>
            </div>

            <?php if (empty($events)): ?>
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-16 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4 relative">
                        <span class="absolute inset-0 rounded-full bg-brand-500/10 animate-ping"></span>
                        <i class="fas fa-qrcode text-4xl text-gray-300 dark:text-gray-600 relative z-10"></i>
                    </div>
                    <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2">No approved events found</h3>
                    <p class="text-sm text-gray-400 mb-6 max-w-sm mx-auto">
                        Events must be approved before you can scan attendance. Check back once yours are approved.
                    </p>
                    <a href="/organizer/organizer_event.php"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-500 hover:bg-brand-600
                      text-white text-sm font-semibold rounded-xl transition-colors active:scale-95">
                        <i class="fas fa-arrow-left"></i> View My Events
                    </a>
                </div>

            <?php else: ?>
                <!-- EVENT CARDS GRID -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5" id="eventsGrid">
                    <?php foreach ($events as $i => $ev):
                        $cat = $ev['category'];
                        $cfg = [
                            'ongoing'  => [
                                'pill'    => 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400',
                                'dot'     => 'bg-brand-500 animate-pulse',
                                'bar'     => 'from-brand-400 to-emerald-500',
                                'icon_bg' => 'bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400',
                                'label'   => 'Ongoing'
                            ],
                            'upcoming' => [
                                'pill'    => 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
                                'dot'     => 'bg-amber-400',
                                'bar'     => 'from-amber-400 to-orange-400',
                                'icon_bg' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400',
                                'label'   => 'Upcoming'
                            ],
                            'ended'    => [
                                'pill'    => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400',
                                'dot'     => 'bg-gray-400',
                                'bar'     => 'from-gray-400 to-gray-500',
                                'icon_bg' => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400',
                                'label'   => 'Ended'
                            ],
                        ][$cat] ?? [];
                    ?>
                        <div class="card-hover anim-up d-<?= min($i, 5) ?>
                        bg-white dark:bg-gray-800
                        rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden
                        event-card group"
                            data-title="<?= strtolower(htmlspecialchars($ev['title'])) ?>"
                            data-status="<?= $cat ?>"
                            style="animation-delay:<?= $i * 70 ?>ms">

                            <div class="h-1.5 bg-gradient-to-r <?= $cfg['bar'] ?>"></div>

                            <div class="p-5">
                                <div class="flex items-start justify-between gap-3 mb-4">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-bold text-gray-900 dark:text-white text-base leading-snug line-clamp-2
                                       group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">
                                            <?= htmlspecialchars($ev['title']) ?>
                                        </h3>
                                        <p class="text-xs text-gray-400 mt-1 flex items-center gap-1.5">
                                            <i class="fas fa-location-dot text-red-400 text-[10px]"></i>
                                            <?= htmlspecialchars($ev['venue_name'] ?? 'TBD') ?>
                                        </p>
                                    </div>
                                    <span class="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full flex-shrink-0 <?= $cfg['pill'] ?>">
                                        <span class="w-1.5 h-1.5 rounded-full <?= $cfg['dot'] ?>"></span>
                                        <?= $cfg['label'] ?>
                                    </span>
                                </div>

                                <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-700/50
                                rounded-xl p-3 mb-4 border border-gray-100 dark:border-gray-600/50">
                                    <span class="icon-wrap w-9 h-9 rounded-lg <?= $cfg['icon_bg'] ?> flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-calendar-day text-sm"></i>
                                    </span>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-900 dark:text-white">
                                            <?= date('F j, Y', strtotime($ev['start_datetime'])) ?>
                                        </p>
                                        <p class="text-[11px] text-gray-400">
                                            <?= date('g:i A', strtotime($ev['start_datetime'])) ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                                        <i class="fas fa-check-circle text-brand-500 text-[11px]"></i> Scanned today
                                    </span>
                                    <span class="font-bold text-gray-900 dark:text-white text-lg"><?= $ev['scanned'] ?></span>
                                </div>
                                <div class="h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden mb-5">
                                    <div class="h-full rounded-full bg-gradient-to-r <?= $cfg['bar'] ?> transition-all duration-700"
                                        style="width:<?= min(100, $ev['scanned'] * 5) ?>%"></div>
                                </div>

                                <!-- ACTION BUTTONS -->
                                <?php if ($cat === 'ongoing'): ?>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button onclick="openScanner(<?= htmlspecialchars(json_encode($ev['title'])) ?>, <?= $ev['event_id'] ?>)"
                                            class="py-2.5 text-xs font-bold rounded-xl
                                       bg-gradient-to-r from-brand-500 to-emerald-500
                                       hover:from-brand-600 hover:to-emerald-600
                                       text-white shadow shadow-brand-400/30
                                       transition-all active:scale-95
                                       flex items-center justify-center gap-1.5">
                                            <i class="fas fa-camera"></i> Scan QR
                                        </button>
                                        <button onclick="openManual(<?= htmlspecialchars(json_encode($ev['title'])) ?>, <?= $ev['event_id'] ?>)"
                                            class="py-2.5 text-xs font-semibold rounded-xl
                                       bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200
                                       border border-gray-200 dark:border-gray-600
                                       hover:bg-gray-200 dark:hover:bg-gray-600 transition-all active:scale-95
                                       flex items-center justify-center gap-1.5">
                                            <i class="fas fa-keyboard"></i> Manual
                                        </button>
                                    </div>

                                <?php elseif ($cat === 'upcoming'): ?>
                                    <div class="text-center text-xs text-amber-600 dark:text-amber-400 font-medium
                                bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700
                                rounded-xl py-2.5 px-3">
                                        <i class="fas fa-clock mr-1"></i> Available when the event starts
                                    </div>

                                <?php else: ?>
                                    <div class="text-center text-xs text-gray-400 dark:text-gray-500 font-medium
                                bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600
                                rounded-xl py-2.5 px-3">
                                        <i class="fas fa-archive mr-1"></i> Event has ended
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="noResults" class="hidden anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl
             border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <i class="fas fa-magnifying-glass text-3xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No events match your search or filter.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- SCROLL TO TOP -->
    <button onclick="window.scrollTo({top:0,behavior:'smooth'})"
        class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40
               bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
               text-gray-500 dark:text-gray-400 shadow-lg
               hover:bg-brand-500 hover:text-white hover:border-brand-500
               transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
        <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
    </button>

    <!-- ============================================================
     QR SCANNER MODAL
    ============================================================ -->
    <div id="scannerModal"
        class="fixed inset-0 z-50 hidden items-center justify-center p-4"
        style="background:rgba(2,6,18,.72); backdrop-filter:blur(8px);"
        onclick="if(event.target===this) closeScanner()">

        <div class="modal-pop scanner-modal-inner rounded-2xl w-full max-w-md overflow-hidden">

            <div class="px-5 py-4 flex items-center justify-between"
                style="border-bottom:1px solid rgba(255,255,255,.07);">
                <div class="flex items-center gap-3">
                    <div class="flex gap-1.5">
                        <span class="scanner-header-dot bg-red-500/70"></span>
                        <span class="scanner-header-dot bg-amber-400/70"></span>
                        <span class="scanner-header-dot" style="background:rgba(34,197,94,.7)"></span>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            <i class="fas fa-qrcode text-brand-400 text-xs"></i>
                            <span id="scannerTitle" class="truncate max-w-[220px]">QR Scanner</span>
                        </h3>
                        <p class="text-[11px] mt-0.5" id="scannerSub"
                            style="color:rgba(255,255,255,.4)">Align QR code within the frame</p>
                    </div>
                </div>
                <button onclick="closeScanner()"
                    class="modal-close-btn"
                    style="background:rgba(255,255,255,.07); color:rgba(255,255,255,.45);"
                    onmouseover="this.style.background='rgba(239,68,68,.2)'; this.style.color='#f87171';"
                    onmouseout="this.style.background='rgba(255,255,255,.07)'; this.style.color='rgba(255,255,255,.45)';">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="p-5 space-y-4">
                <div class="scan-pill-track">
                    <button id="qrBtnLogin" onclick="setScanType('login')" class="scan-pill-btn active-login">
                        <i class="fas fa-sign-in-alt text-xs"></i> Log In
                    </button>
                    <button id="qrBtnLogout" onclick="setScanType('logout')" class="scan-pill-btn">
                        <i class="fas fa-sign-out-alt text-xs"></i> Log Out
                    </button>
                </div>
                <input type="hidden" id="qrScanTypeHidden" value="login">

                <div id="liveScannerArea">
                    <div class="scanner-viewport">
                        <div id="interactive-scanner"></div>
                        <div class="reticle-overlay">
                            <div class="reticle-box">
                                <span class="corner tl"></span>
                                <span class="corner tr"></span>
                                <span class="corner bl"></span>
                                <span class="corner br"></span>
                                <div class="laser-line"></div>
                            </div>
                        </div>
                        <div class="scanner-hint-strip">
                            <span class="pulse-dot"></span>
                            Hold QR code steady inside the frame
                        </div>
                    </div>
                </div>

                <div id="photoUploadArea" class="hidden">
                    <div class="photo-zone" onclick="document.getElementById('qrPhotoInput').click()">
                        <input type="file" id="qrPhotoInput" accept="image/*" capture="environment"
                            class="hidden" onchange="handlePhotoScan(this)">

                        <div id="photoUploadContent">
                            <div class="w-14 h-14 rounded-2xl mx-auto mb-3 flex items-center justify-center text-2xl"
                                style="background:rgba(34,197,94,.12); color:#4ade80;">
                                <i class="fas fa-camera"></i>
                            </div>
                            <p class="font-semibold text-white mb-1 text-sm">Tap to take photo</p>
                            <p class="text-xs" style="color:rgba(255,255,255,.35);">Point camera at QR code</p>
                        </div>

                        <div id="photoPreview" class="hidden">
                            <img id="previewImage" class="max-h-44 mx-auto rounded-xl mb-3 ring-2 ring-brand-500/40">
                            <p class="text-xs font-semibold" style="color:#4ade80;">
                                <i class="fas fa-check-circle mr-1"></i> Photo captured – processing…
                            </p>
                        </div>
                    </div>
                    <p class="text-[11px] text-center mt-2" style="color:rgba(255,255,255,.3);">
                        <i class="fas fa-info-circle mr-1"></i> Live camera needs HTTPS · Using photo mode
                    </p>
                </div>

                <div id="scan-feedback" class="scan-feedback-bar sfb-idle">
                    <i class="fas fa-wifi text-xs opacity-50"></i>
                    <span id="scanText">Ready to scan…</span>
                </div>

                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest mb-2"
                        style="color:rgba(255,255,255,.3);">Recent scans</p>
                    <div id="scanLog" class="space-y-1.5 max-h-36 overflow-y-auto">
                        <p class="text-xs text-center py-3" style="color:rgba(255,255,255,.2);">No scans yet this session</p>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- ============================================================
     MANUAL ENTRY MODAL
    ============================================================ -->
    <div id="manualModal"
        class="fixed inset-0 z-50 hidden items-center justify-center p-4"
        style="background:rgba(2,6,18,.6); backdrop-filter:blur(8px);"
        onclick="if(event.target===this) closeManual()">

        <div class="modal-pop manual-modal-inner rounded-2xl w-full max-w-md overflow-hidden">

            <div class="px-6 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-800">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center text-sm"
                        style="background:linear-gradient(135deg,#fbbf24,#f59e0b); color:#fff; box-shadow:0 4px 12px rgba(245,158,11,.3);">
                        <i class="fas fa-keyboard"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-white text-sm flex items-center gap-1.5">
                            <span id="manualTitle" class="truncate max-w-[200px]">Manual Entry</span>
                        </h3>
                        <p class="text-[11px] text-gray-400 mt-0.5">Enter QR value or student number</p>
                    </div>
                </div>
                <button onclick="closeManual()"
                    class="modal-close-btn bg-gray-100 dark:bg-gray-700 text-gray-400
                           hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors">
                    <i class="fas fa-times text-xs"></i>
                </button>
            </div>

            <div class="p-6 space-y-4">

                <div class="manual-pill-track">
                    <button id="mBtnLogin" onclick="setManualType('login')" class="manual-pill-btn active-login">
                        <i class="fas fa-sign-in-alt text-xs"></i> Log In
                    </button>
                    <button id="mBtnLogout" onclick="setManualType('logout')" class="manual-pill-btn">
                        <i class="fas fa-sign-out-alt text-xs"></i> Log Out
                    </button>
                </div>
                <input type="hidden" id="manualTypeHidden" value="login">

                <div class="input-tab-bar">
                    <div class="input-tab active-tab" id="tabQR" onclick="switchInputTab('qr')">
                        <i class="fas fa-qrcode text-[11px]"></i> QR Value
                    </div>
                    <div class="input-tab" id="tabSN" onclick="switchInputTab('sn')">
                        <i class="fas fa-id-card text-[11px]"></i> Student Number
                    </div>
                </div>

                <div id="panelQR">
                    <label class="block text-[11px] font-semibold text-gray-400 dark:text-gray-500 mb-2 uppercase tracking-wider">
                        QR Code Value
                    </label>
                    <div class="relative">
                        <i class="fas fa-qrcode absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 dark:text-gray-600 text-sm pointer-events-none"></i>
                        <input type="text" id="qr_value_input"
                            placeholder="e.g. SEMS-2023-0001"
                            class="manual-input-field pl-11 font-mono uppercase tracking-wider">
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1.5 flex items-center gap-1.5">
                        <i class="fas fa-info-circle text-brand-400"></i>
                        Paste or type the student's QR code value
                    </p>
                </div>

                <div id="panelSN" class="hidden">
                    <label class="block text-[11px] font-semibold text-gray-400 dark:text-gray-500 mb-2 uppercase tracking-wider">
                        Student Number
                    </label>
                    <div class="relative">
                        <i class="fas fa-hashtag absolute left-4 top-1/2 -translate-y-1/2 text-gray-300 dark:text-gray-600 text-sm pointer-events-none"></i>
                        <input type="text" id="sn_value_input"
                            placeholder="e.g. 2023-00001"
                            class="manual-input-field pl-11 sn-mode">
                    </div>
                    <p class="text-[11px] text-amber-500/80 dark:text-amber-400/70 mt-1.5 flex items-center gap-1.5">
                        <i class="fas fa-triangle-exclamation"></i>
                        Fallback when QR scan is unavailable
                    </p>
                </div>

                <!-- ── PROOF PHOTO ZONE ── -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider flex items-center gap-1.5">
                            <i class="fas fa-camera text-purple-400 text-[10px]"></i>
                            Proof Photo
                        </label>
                        <span class="text-[10px] text-gray-400 dark:text-gray-600 flex items-center gap-1">
                            <i class="fas fa-shield-halved text-brand-400 text-[9px]"></i>
                            Anti-cheat record
                        </span>
                    </div>

                    <!-- Hidden file input as fallback -->
                    <input type="file" id="manualProofInput"
                        accept="image/*"
                        class="hidden" onchange="handleManualProof(this)">

                    <!-- FIX: onclick now calls openProofCamera() instead of directly
                         triggering the file input — this opens the live camera on
                         desktop/laptop via getUserMedia, and on mobile uses the
                         rear-facing camera. The file picker is only used as fallback
                         when getUserMedia is unavailable (proofFallbackFilePicker). -->
                    <div id="manualProofZone"
                        class="proof-zone"
                        onclick="openProofCamera()"
                        title="Tap to capture student photo">

                        <div id="manualProofPlaceholder" class="text-center py-3 px-4 select-none">
                            <div class="w-10 h-10 rounded-xl mx-auto mb-2 flex items-center justify-center"
                                style="background:rgba(168,85,247,.12); border:1px solid rgba(168,85,247,.2);">
                                <i class="fas fa-camera text-purple-400 text-sm"></i>
                            </div>
                            <p class="text-[11px] font-semibold text-gray-500 dark:text-gray-400 mb-0.5">
                                Capture student photo
                            </p>
                            <p class="text-[10px] text-gray-400 dark:text-gray-600">
                                Point camera at the student, then tap
                            </p>
                        </div>

                        <div id="manualProofPreview" class="hidden text-center py-2 px-3">
                            <img id="manualProofImg"
                                class="max-h-20 mx-auto rounded-lg ring-2 ring-[rgba(34,197,94,0.4)]"
                                alt="Proof photo">
                            <p class="text-[10px] mt-1.5 font-semibold" style="color:rgba(34,197,94,.8);">
                                <i class="fas fa-check-circle mr-1"></i> Photo captured
                            </p>
                            <button class="proof-retake-btn"
                                onclick="event.stopPropagation(); openProofCamera();"
                                title="Retake photo">
                                <i class="fas fa-redo"></i> Retake
                            </button>
                        </div>
                    </div>

                    <p class="text-[10px] text-gray-400 dark:text-gray-600 mt-1.5 flex items-center gap-1.5">
                        <i class="fas fa-eye-slash text-[9px]"></i>
                        Stored privately · visible only on attendance review
                    </p>
                </div>

                <button id="manualSubmitBtn" onclick="submitManual()"
                    class="manual-submit-btn btn-login">
                    <i class="fas fa-check-circle text-sm"></i>
                    <span id="manualBtnLabel">Record Login</span>
                </button>

                <div id="manual-feedback" class="manual-feedback-bar mfb-idle"
                    style="opacity:0; transition: opacity .25s, background .28s, border-color .28s;">
                    <span id="manualFeedbackText" class="flex items-center gap-2"></span>
                </div>

                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-600 mb-2">Recent entries</p>
                    <div id="manualLog" class="space-y-1.5 max-h-28 overflow-y-auto">
                        <p class="text-xs text-center py-2 text-gray-300 dark:text-gray-600">No entries yet</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
     PROOF CAMERA OVERLAY
     Opens a live camera feed (getUserMedia) so the organizer can
     take a real-time photo on desktop AND mobile.
     Falls back to file picker via proofFallbackFilePicker() if the
     browser denies camera access.
    ============================================================ -->
    <div id="proofCameraOverlay"
         class="fixed inset-0 z-[60] hidden items-center justify-center p-4"
         style="background:rgba(2,6,18,.85); backdrop-filter:blur(10px);">

        <div class="cam-modal rounded-2xl w-full max-w-sm overflow-hidden" style="border:1px solid rgba(255,255,255,.08); box-shadow:0 32px 80px rgba(0,0,0,.7);">

            <!-- Header -->
            <div class="px-5 py-4 flex items-center justify-between" style="border-bottom:1px solid rgba(255,255,255,.07);">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center text-sm flex-shrink-0"
                         style="background:rgba(168,85,247,.18); color:#c084fc; border:1px solid rgba(168,85,247,.25);">
                        <i class="fas fa-camera"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-white">Capture Proof Photo</p>
                        <p id="proofCameraHint" class="text-[11px] mt-0.5" style="color:rgba(255,255,255,.4);">Starting camera…</p>
                    </div>
                </div>
                <!-- Browse fallback -->
                <button onclick="proofFallbackFilePicker()"
                        class="flex items-center gap-1.5 text-[11px] font-semibold px-3 py-1.5 rounded-lg transition-colors"
                        style="background:rgba(255,255,255,.07); color:rgba(255,255,255,.5);"
                        onmouseover="this.style.background='rgba(255,255,255,.13)'; this.style.color='rgba(255,255,255,.85)';"
                        onmouseout="this.style.background='rgba(255,255,255,.07)'; this.style.color='rgba(255,255,255,.5)';">
                    <i class="fas fa-folder-open text-[10px]"></i> Browse
                </button>
            </div>

            <!-- Body -->
            <div class="p-4 space-y-3" style="background:#0f1117;">

                <!-- Live video preview -->
                <div class="relative rounded-xl overflow-hidden bg-black" style="aspect-ratio:4/3;">
                    <video id="proofCameraVideo" autoplay playsinline muted
                           style="width:100%; height:100%; object-fit:cover; display:block;"></video>

                    <!-- Corner reticle -->
                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                        <div class="relative" style="width:140px; height:140px;">
                            <span class="cam-corner cam-corner-tl"></span>
                            <span class="cam-corner cam-corner-tr"></span>
                            <span class="cam-corner cam-corner-bl"></span>
                            <span class="cam-corner cam-corner-br"></span>
                        </div>
                    </div>

                    <!-- Hint strip -->
                    <div class="absolute bottom-0 left-0 right-0 px-3 py-2 text-center text-[11px] font-medium"
                         style="background:linear-gradient(to top,rgba(0,0,0,.7),transparent); color:rgba(255,255,255,.6);">
                        Frame the student's face in the centre
                    </div>
                </div>

                <!-- Error state -->
                <div id="proofCameraError"
                     class="hidden text-xs rounded-lg px-3 py-2.5 flex items-start gap-2"
                     style="background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25); color:#fca5a5;">
                    <i class="fas fa-exclamation-triangle flex-shrink-0 mt-0.5"></i>
                    <span>Camera unavailable or permission denied. Use <strong>Browse</strong> above to upload a photo instead.</span>
                </div>

                <!-- Action buttons -->
                <div class="grid grid-cols-2 gap-2">
                    <button onclick="closeProofCamera()"
                            class="py-2.5 text-xs font-semibold rounded-xl transition-all active:scale-95"
                            style="background:rgba(255,255,255,.06); color:rgba(255,255,255,.55); border:1px solid rgba(255,255,255,.1);"
                            onmouseover="this.style.background='rgba(255,255,255,.12)';"
                            onmouseout="this.style.background='rgba(255,255,255,.06)';">
                        <i class="fas fa-times mr-1.5"></i>Cancel
                    </button>
                    <button id="proofCaptureBtn"
                            onclick="captureProofPhoto()"
                            disabled
                            class="py-2.5 text-xs font-bold rounded-xl text-white transition-all active:scale-95
                                   disabled:opacity-35 disabled:cursor-not-allowed disabled:shadow-none">
                        <i class="fas fa-camera mr-1.5"></i>Capture
                    </button>
                </div>

                <p class="text-center text-[10px]" style="color:rgba(255,255,255,.2);">
                    <i class="fas fa-lock text-[9px] mr-1"></i>
                    Photo is stored privately and never shared publicly
                </p>
            </div>
        </div>
    </div>

    <div id="temp-qr-scanner" style="display:none;"></div>

    <script src="/js/organizer_qrscan.js"></script>
    <script src="/js/fraud_detection.js"></script>
</body>

</html>