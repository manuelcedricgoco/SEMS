<?php
/* ============================================================
 | FILE   : organizer_analytics.php
 ============================================================ */

session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$organizer_id = (int) $_SESSION['user_id'];

$myCtx = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = ?");
$myCtx->execute([$organizer_id]);
$myCtxRow = $myCtx->fetch(PDO::FETCH_ASSOC) ?: ['org_id' => null, 'club_id' => null];

$myOrgId  = !empty($myCtxRow['org_id'])  ? (int)$myCtxRow['org_id']  : null;
$myClubId = !empty($myCtxRow['club_id']) ? (int)$myCtxRow['club_id'] : null;

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

$eventFilter  = $_GET['event_filter']  ?? 'all';
$dateRange    = $_GET['date_range']    ?? '30days';
$statusFilter = $_GET['status_filter'] ?? 'all';

try {
    $params       = [];
    $orgBaseWhere = buildOrgEventWhere('', $organizer_id, $myOrgId, $myClubId, $params);
    $whereClauses = [$orgBaseWhere];

    if ($eventFilter !== 'all' && is_numeric($eventFilter)) {
        $whereClauses[] = "event_id = ?";
        $params[]       = (int)$eventFilter;
    }
    if ($statusFilter !== 'all') {
        $whereClauses[] = "status = ?";
        $params[]       = $statusFilter;
    }

    $dateCondition = match($dateRange) {
        '7days'  => "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        '30days' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        '90days' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
        'year'   => "AND YEAR(created_at) = YEAR(NOW())",
        default  => "",
    };

    $whereSql = implode(" AND ", $whereClauses);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_events,
               SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_events,
               SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END) as pending_events
        FROM events WHERE $whereSql $dateCondition
    ");
    $stmt->execute($params);
    $events = $stmt->fetch();

    $regParams = [];
    $regWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $regParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $regWhere .= " AND e.event_id = ?"; $regParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all')                             { $regWhere .= " AND e.status = ?";   $regParams[] = $statusFilter; }

    $stmt = $pdo->prepare("SELECT COUNT(*) as total_registrations FROM registrations r JOIN events e ON r.event_id=e.event_id WHERE $regWhere");
    $stmt->execute($regParams);
    $registrations = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total_attendance,
               SUM(CASE WHEN a.login_time IS NOT NULL AND a.logout_time IS NOT NULL THEN 1 ELSE 0 END) as present_count,
               SUM(CASE WHEN a.login_time IS NULL     OR  a.logout_time IS NULL     THEN 1 ELSE 0 END) as absent_count
        FROM registrations r
        JOIN events e ON r.event_id = e.event_id
        LEFT JOIN attendance a ON a.event_id = r.event_id AND a.user_id = r.user_id
        WHERE $regWhere
    ");
    $stmt->execute($regParams);
    $attendance = $stmt->fetch();

    $feedbackParams = [];
    $feedbackWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $feedbackParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $feedbackWhere .= " AND e.event_id = ?"; $feedbackParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all')                             { $feedbackWhere .= " AND e.status = ?";   $feedbackParams[] = $statusFilter; }

    $stmt = $pdo->prepare("
        SELECT AVG(fr.rating) as avg_rating, COUNT(*) as total_reviews
        FROM feedback_ratings fr JOIN feedback f ON fr.feedback_id=f.feedback_id
        JOIN events e ON f.event_id=e.event_id WHERE $feedbackWhere
    ");
    $stmt->execute($feedbackParams);
    $feedback = $stmt->fetch();

    $stmt = $pdo->prepare("
        SELECT u.org_id, u.club_id, o.org_name, c.club_name,
               d.dept_name,
               COALESCE(p.first_name,    o2.first_name)    as first_name,
               COALESCE(p.last_name,     o2.last_name)     as last_name,
               COALESCE(p.middle_name,   o2.middle_name)   as middle_name,
               COALESCE(p.profile_image, o2.profile_image) as profile_image,
               o2.position
        FROM users u
        LEFT JOIN organizations o  ON u.org_id  = o.org_id
        LEFT JOIN clubs         c  ON u.club_id = c.club_id
        LEFT JOIN departments   d  ON u.dept_id = d.dept_id
        LEFT JOIN profiles      p  ON u.user_id = p.user_id
        LEFT JOIN organizer     o2 ON u.user_id = o2.user_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$organizer_id]);
    $userInfo = $stmt->fetch();

    /* ── DEPARTMENT VISIBILITY PERMISSION ── */
    $_orgNameUpper  = strtoupper(trim($userInfo['org_name']  ?? ''));
    $_clubNameUpper = strtoupper(trim($userInfo['club_name'] ?? ''));

    $allowedForAllDepts = [
        'SSG', 'SUPREME STUDENT GOVERNMENT',
        'LSC', 'LIBRARY STUDENT COUNCIL',
        'SPORTS CLUB', 'SCI-MATH CLUB', 'PEER TO PEER FACILITATOR',
        'ENGLISH CLUB', 'SAMFILKO',
        'UNITED MANGYAN STUDENTS ORGANIZATION',
        'CAMPUS YOUTH MINISTRY IN ACTION',
    ];

    $canSeeAllDepts = in_array($_orgNameUpper,  $allowedForAllDepts)
                   || in_array($_clubNameUpper, $allowedForAllDepts);

    $hasImage = !empty($userInfo['profile_image']);
    $mime     = 'image/jpeg';
    $profileImageData = '';

    if ($hasImage) {
        try {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $det = finfo_buffer($finfo, $userInfo['profile_image']);
                if ($det && strpos($det, 'image/') === 0) $mime = $det;
            }
            $profileImageData = base64_encode($userInfo['profile_image']);
        } catch (Exception $e) { $hasImage = false; }
    }

    $scParams = [];
    $scWhere  = buildOrgEventWhere('', $organizer_id, $myOrgId, $myClubId, $scParams);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $scWhere AND status!='rejected'");
    $stmt->execute($scParams);
    $myEvents = $stmt->fetchColumn();

    $srParams = [];
    $srWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $srParams);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations r JOIN events e ON r.event_id=e.event_id WHERE $srWhere");
    $stmt->execute($srParams);
    $sidebarRegistrations = $stmt->fetchColumn();

    $attendance_rate = $registrations['total_registrations'] > 0
        ? round(($attendance['present_count'] / $registrations['total_registrations']) * 100, 1)
        : 0;

    $oeParams = [];
    $oeWhere  = buildOrgEventWhere('', $organizer_id, $myOrgId, $myClubId, $oeParams);
    $stmt = $pdo->prepare("SELECT event_id, title, status FROM events WHERE $oeWhere ORDER BY created_at DESC");
    $stmt->execute($oeParams);
    $organizerEvents = $stmt->fetchAll();

    $fbParams = [];
    $fbWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $fbParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $fbWhere .= " AND e.event_id = ?"; $fbParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all')                             { $fbWhere .= " AND e.status = ?";   $fbParams[] = $statusFilter; }

    $stmt = $pdo->prepare("
        SELECT f.feedback_id, f.event_id, e.title as event_title, f.created_at,
               fr.rating, fr.comment, fc.category_name,
               p.first_name, p.last_name, d.dept_name, p.year_level, p.section,
               (SELECT AVG(rating) FROM feedback_ratings WHERE feedback_id=f.feedback_id) as avg_rating
        FROM feedback f
        JOIN events            e  ON f.event_id      = e.event_id
        JOIN feedback_ratings  fr ON f.feedback_id   = fr.feedback_id
        JOIN feedback_categories fc ON fr.category_id = fc.category_id
        JOIN users             u  ON f.user_id        = u.user_id
        JOIN profiles          p  ON u.user_id        = p.user_id
        JOIN departments       d  ON u.dept_id        = d.dept_id
        WHERE $fbWhere
        ORDER BY f.created_at DESC
        LIMIT 10
    ");
    $stmt->execute($fbParams);
    $feedbackList = $stmt->fetchAll();

    $catParams = [];
    $catWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $catParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $catWhere .= " AND e.event_id = ?"; $catParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all')                             { $catWhere .= " AND e.status = ?";   $catParams[] = $statusFilter; }

    $stmt = $pdo->prepare("
        SELECT fc.category_name, AVG(fr.rating) as avg_rating, COUNT(*) as count
        FROM feedback_ratings fr
        JOIN feedback_categories fc ON fr.category_id  = fc.category_id
        JOIN feedback           f   ON fr.feedback_id  = f.feedback_id
        JOIN events             e   ON f.event_id      = e.event_id
        WHERE $catWhere
        GROUP BY fc.category_id
    ");
    $stmt->execute($catParams);
    $categoryAverages = $stmt->fetchAll();

    $deptParams = [];
    $deptWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $deptParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $deptWhere .= " AND e.event_id = ?"; $deptParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all')                             { $deptWhere .= " AND e.status = ?";   $deptParams[] = $statusFilter; }

    $stmt = $pdo->prepare("
        SELECT d.dept_name, COUNT(DISTINCT a.user_id) as attendee_count
        FROM attendance a
        JOIN events      e ON a.event_id = e.event_id
        JOIN users       u ON a.user_id  = u.user_id
        JOIN departments d ON u.dept_id  = d.dept_id
        WHERE $deptWhere AND a.login_time IS NOT NULL AND a.logout_time IS NOT NULL
        GROUP BY d.dept_id ORDER BY attendee_count DESC
    ");
    $stmt->execute($deptParams);
    $deptDistribution = $stmt->fetchAll();

    $trendParams = [];
    $trendWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $trendParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $trendWhere .= " AND e.event_id = ?"; $trendParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all')                             { $trendWhere .= " AND e.status = ?";   $trendParams[] = $statusFilter; }

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(r.registered_at, '%b') as month, COUNT(*) as count
        FROM registrations r JOIN events e ON r.event_id = e.event_id
        WHERE $trendWhere AND r.registered_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(r.registered_at, '%Y-%m') ORDER BY r.registered_at
    ");
    $stmt->execute($trendParams);
    $registrationTrends = $stmt->fetchAll();

    $perfParams = [];
    $perfWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $perfParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $perfWhere .= " AND e.event_id = ?"; $perfParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all')                             { $perfWhere .= " AND e.status = ?";   $perfParams[] = $statusFilter; }

    $stmt = $pdo->prepare("
        SELECT e.title,
               (SELECT COUNT(*) FROM registrations WHERE event_id=e.event_id) as registrations,
               (SELECT COUNT(*) FROM attendance WHERE event_id=e.event_id AND login_time IS NOT NULL AND logout_time IS NOT NULL) as attendance
        FROM events e WHERE $perfWhere ORDER BY e.created_at DESC LIMIT 5
    ");
    $stmt->execute($perfParams);
    $eventPerformance = $stmt->fetchAll();

    /* ── Overall Attendance (registration-based) ── */
    $attParams = [];
    $attWhere  = buildOrgEventWhere('e', $organizer_id, $myOrgId, $myClubId, $attParams);
    if ($eventFilter !== 'all' && is_numeric($eventFilter)) { $attWhere .= " AND e.event_id = ?"; $attParams[] = (int)$eventFilter; }
    if ($statusFilter !== 'all') { $attWhere .= " AND e.status = ?"; $attParams[] = $statusFilter; }

    if (!$canSeeAllDepts) {
        $attWhere .= " AND UPPER(TRIM(d.dept_name)) != 'BS OPERATIONAL MANAGEMENT'";
    }

    $stmt = $pdo->prepare("
        SELECT e.title as event_title, e.start_datetime,
               d.dept_name, p.year_level, p.section,
               p.first_name, p.last_name, p.middle_name, p.student_number,
               a.login_time, a.logout_time, a.attendance_id,
               CASE
                   WHEN a.login_time IS NOT NULL AND a.logout_time IS NOT NULL THEN 'Present'
                   WHEN a.login_time IS NOT NULL AND a.logout_time IS NULL     THEN 'Partial'
                   ELSE 'Absent'
               END as attendance_status
        FROM registrations r
        JOIN events      e ON r.event_id  = e.event_id
        JOIN users       u ON r.user_id   = u.user_id
        JOIN profiles    p ON u.user_id   = p.user_id
        JOIN departments d ON u.dept_id   = d.dept_id
        LEFT JOIN attendance a ON a.event_id = r.event_id AND a.user_id = r.user_id
        WHERE $attWhere
        ORDER BY d.dept_name, p.year_level, p.section, p.last_name, p.first_name
    ");
    $stmt->execute($attParams);
    $overallAttendance = $stmt->fetchAll();

    $bsomExclusion = $canSeeAllDepts ? '' : "AND UPPER(TRIM(d.dept_name)) != 'BS OPERATIONAL MANAGEMENT'";
    $deptStmt = $pdo->prepare("
        SELECT DISTINCT d.dept_id, d.dept_name FROM departments d
        JOIN users u ON u.dept_id = d.dept_id
        WHERE u.role = 'student' $bsomExclusion ORDER BY d.dept_name
    ");
    $deptStmt->execute();
    $allDepartments = $deptStmt->fetchAll();

    $yearLevels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

    $attendanceGrouped = [];
    $summaryStats = ['total_students'=>0,'present'=>0,'partial'=>0,'absent'=>0,'department_stats'=>[]];

    foreach ($overallAttendance as $record) {
        $dept = $record['dept_name']; $year = $record['year_level']; $section = $record['section'];
        if (!isset($attendanceGrouped[$dept]))                  $attendanceGrouped[$dept] = [];
        if (!isset($attendanceGrouped[$dept][$year]))           $attendanceGrouped[$dept][$year] = [];
        if (!isset($attendanceGrouped[$dept][$year][$section])) $attendanceGrouped[$dept][$year][$section] = [
            'students' => [], 'stats' => ['present'=>0,'partial'=>0,'absent'=>0,'total'=>0]
        ];
        $status = $record['attendance_status'];
        $attendanceGrouped[$dept][$year][$section]['students'][] = $record;
        $attendanceGrouped[$dept][$year][$section]['stats'][strtolower($status)]++;
        $attendanceGrouped[$dept][$year][$section]['stats']['total']++;
        $summaryStats['total_students']++;
        $summaryStats[strtolower($status)]++;
        if (!isset($summaryStats['department_stats'][$dept]))
            $summaryStats['department_stats'][$dept] = ['total'=>0,'present'=>0,'partial'=>0,'absent'=>0];
        $summaryStats['department_stats'][$dept]['total']++;
        $summaryStats['department_stats'][$dept][strtolower($status)]++;
    }

    $t = $summaryStats['total_students'];
    $summaryStats['present_pct'] = $t > 0 ? round(($summaryStats['present']/$t)*100,1) : 0;
    $summaryStats['partial_pct'] = $t > 0 ? round(($summaryStats['partial']/$t)*100,1) : 0;
    $summaryStats['absent_pct']  = $t > 0 ? round(($summaryStats['absent'] /$t)*100,1) : 0;

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$ratingDistribution = [5=>0,4=>0,3=>0,2=>0,1=>0];
$totalReviews = count($feedbackList);
foreach ($feedbackList as $fb) {
    $r = (int) round($fb['avg_rating']);
    if (isset($ratingDistribution[$r])) $ratingDistribution[$r]++;
}
$ratingPercentages = [];
foreach ($ratingDistribution as $star => $count)
    $ratingPercentages[$star] = $totalReviews > 0 ? round(($count/$totalReviews)*100) : 0;

$orgName     = $userInfo['org_name']  ?? null;
$clubName    = $userInfo['club_name'] ?? null;
$displayName = $orgName ?? $clubName ?? 'Independent Organizer';
$displayType = $orgName ? 'Organization' : ($clubName ? 'Club' : 'Independent');
$displayIcon = $orgName ? 'fa-building'  : ($clubName ? 'fa-users' : 'fa-user');

$hasOrgLogo = false; $orgLogoData = null; $orgLogoMime = 'image/jpeg';
try {
    if (!empty($userInfo['org_id'])) {
        $lq = $pdo->prepare("SELECT logo FROM organizations WHERE org_id=?");
        $lq->execute([$userInfo['org_id']]); $lr = $lq->fetch(PDO::FETCH_ASSOC);
        if ($lr && !empty($lr['logo'])) { $orgLogoData = $lr['logo']; $hasOrgLogo = true; }
    }
    if (!$hasOrgLogo && !empty($userInfo['club_id'])) {
        $lq = $pdo->prepare("SELECT logo FROM clubs WHERE club_id=?");
        $lq->execute([$userInfo['club_id']]); $lr = $lq->fetch(PDO::FETCH_ASSOC);
        if ($lr && !empty($lr['logo'])) { $orgLogoData = $lr['logo']; $hasOrgLogo = true; }
    }
    if ($hasOrgLogo && $orgLogoData) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $det   = finfo_buffer($finfo, $orgLogoData);
        if ($det && strpos($det,'image/') === 0) $orgLogoMime = $det;
    }
} catch (Exception $e) {}

$middleName = !empty($userInfo['middle_name']) ? ' '.strtoupper(substr($userInfo['middle_name'],0,1)).'. ' : ' ';
$fullName   = htmlspecialchars(($userInfo['first_name']??'').$middleName.($userInfo['last_name']??''));
$initials   = strtoupper(substr($userInfo['first_name']??'O',0,1).substr($userInfo['last_name']??'',0,1));

$months      = array_column($registrationTrends,'month');
$regCounts   = array_column($registrationTrends,'count');
$eventTitles = array_column($eventPerformance,'title');
$eventRegs   = array_column($eventPerformance,'registrations');
$eventAttend = array_column($eventPerformance,'attendance');
$deptNames   = array_column($deptDistribution,'dept_name');
$deptCounts  = array_column($deptDistribution,'attendee_count');
$deptColors  = ['#22c55e','#3b82f6','#a855f7','#eab308','#ef4444','#ec4899'];

$ssgLscNames   = ['SSG','SUPREME STUDENT GOVERNMENT','SUPREME STUDENTS GOVERNMENT','LSC','LIBRARY STUDENT COUNCIL'];
$showDeptChart = ($myClubId !== null) || in_array(strtoupper(trim($orgName??'')), $ssgLscNames);

/* ── ORG → DEPARTMENT VISIBILITY MAP ── */
$orgDeptMap = [
    'JUNIOR FINANCIAL MANAGERS SOCIETY'     => 'BS Financial Management',
    'YOUTH MENTORS ORGANIZATION'            => 'Bachelor of Elementary Education',
    'JUNIOR OPERATIONS EXECUTIVE SOCIETY'   => 'BS Operational Management',
    'PROGRAMMERS ANIMATORS DEVELOPERS CLAN' => 'BS Information Technology',
];

$normalizedOrg  = strtoupper(trim($orgName  ?? ''));
$normalizedClub = strtoupper(trim($clubName ?? ''));

$isFullAccessOrg  = in_array($normalizedOrg, $ssgLscNames);
$isFullAccessClub = ($myClubId !== null);

if ($isFullAccessOrg || $isFullAccessClub) {
    $allowedDepts = null;
} elseif (isset($orgDeptMap[$normalizedOrg])) {
    $allowedDepts = [$orgDeptMap[$normalizedOrg]];
} else {
    $allowedDepts = null;
}

if ($allowedDepts !== null) {
    $attendanceGrouped = array_filter(
        $attendanceGrouped,
        fn($key) => in_array($key, $allowedDepts),
        ARRAY_FILTER_USE_KEY
    );
    $summaryStats = ['total_students'=>0,'present'=>0,'partial'=>0,'absent'=>0,'department_stats'=>[]];
    foreach ($attendanceGrouped as $deptName => $years) {
        foreach ($years as $yearLevel => $sections) {
            foreach ($sections as $sectionName => $sectionData) {
                foreach ($sectionData['students'] as $record) {
                    $s = strtolower($record['attendance_status']);
                    $summaryStats['total_students']++;
                    $summaryStats[$s]++;
                    if (!isset($summaryStats['department_stats'][$deptName]))
                        $summaryStats['department_stats'][$deptName] = ['total'=>0,'present'=>0,'partial'=>0,'absent'=>0];
                    $summaryStats['department_stats'][$deptName]['total']++;
                    $summaryStats['department_stats'][$deptName][$s]++;
                }
            }
        }
    }
    $t2 = $summaryStats['total_students'];
    $summaryStats['present_pct'] = $t2 > 0 ? round(($summaryStats['present']/$t2)*100,1) : 0;
    $summaryStats['partial_pct'] = $t2 > 0 ? round(($summaryStats['partial']/$t2)*100,1) : 0;
    $summaryStats['absent_pct']  = $t2 > 0 ? round(($summaryStats['absent'] /$t2)*100,1) : 0;
}

if ($allowedDepts !== null) {
    $allDepartments = array_values(array_filter(
        $allDepartments,
        fn($d) => in_array($d['dept_name'], $allowedDepts)
    ));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics – SEMS</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script>
tailwind.config = {
    darkMode: 'class',
    theme: { extend: {
        fontFamily: { sans: ['Poppins','sans-serif'] },
        colors: { brand: {
            50:'#f0fdf4',100:'#dcfce7',200:'#bbf7d0',300:'#86efac',
            400:'#4ade80',500:'#22c55e',600:'#16a34a',700:'#15803d',
            800:'#166534',900:'#14532d'
        }}
    }}
}
</script>
<style>
/* ── Attendance accordion ── */
.attendance-dept-content,.attendance-section-content{transition:all .2s ease}
.attendance-dept-section .fa-chevron-right,
.attendance-section .fa-chevron-right{transition:transform .2s ease}
.attendance-dept-section.open > div:first-child .fa-chevron-right,
.attendance-section.open > div:first-child .fa-chevron-right{transform:rotate(90deg)}

.att-table{table-layout:fixed;width:100%;border-collapse:collapse}
.att-table th,.att-table td{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
.att-col-name  {width:22%}
.att-col-num   {width:14%}
.att-col-status{width:11%}
.att-col-login {width:14%}
.att-col-logout{width:14%}
.att-col-event {width:25%}

::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:99px}
.dark ::-webkit-scrollbar-thumb{background:#374151}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.anim-up{animation:fadeUp .42s ease both}
.d-0{animation-delay:0ms}.d-1{animation-delay:70ms}.d-2{animation-delay:140ms}.d-3{animation-delay:210ms}

.nav-link{position:relative;transition:background .2s,color .2s}
.nav-link::before{content:"";position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:0 4px 4px 0;background:#22c55e;transform:scaleY(0);transition:transform .25s}
.nav-link:hover::before,.nav-link.active::before{transform:scaleY(1)}
.nav-link.active{background:rgba(34,197,94,.12);color:#16a34a}
.dark .nav-link.active{color:#4ade80;background:rgba(34,197,94,.15)}

.card-hover{transition:transform .25s ease,box-shadow .25s ease}
.card-hover:hover{transform:translateY(-3px);box-shadow:0 12px 24px -6px rgba(0,0,0,.1)}
.dark .card-hover:hover{box-shadow:0 12px 24px -6px rgba(0,0,0,.4)}
.icon-wrap{transition:transform .25s ease}
.nav-link:hover .icon-wrap{transform:scale(1.1)}
.chart-wrap{position:relative;height:280px;width:100%}

#sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:40}
#sb-overlay.show{display:block}

.ct-btn{padding:4px 12px;border-radius:8px;font-size:11px;font-weight:600;border:1px solid rgba(0,0,0,.1);color:#64748b;background:#f8fafc;cursor:pointer;transition:all .18s;display:inline-flex;align-items:center;gap:5px}
.dark .ct-btn{border-color:rgba(255,255,255,.1);color:#94a3b8;background:rgba(255,255,255,.05)}
.ct-btn:hover{background:#e2e8f0;color:#1e293b}
.dark .ct-btn:hover{background:rgba(255,255,255,.12);color:#fff}
.ct-btn.active{background:rgba(34,197,94,.15);border-color:#22c55e;color:#16a34a}
.dark .ct-btn.active{background:rgba(34,197,94,.2);border-color:#22c55e;color:#4ade80}

#fbToolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px}
#fbSearch{flex:1;min-width:160px;padding:6px 12px;border-radius:9px;font-size:12px;border:1.5px solid #e2e8f0;background:#f8fafc;color:#1e293b;outline:none}
.dark #fbSearch{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.1);color:#e2e8f0}
#fbSearch:focus{border-color:#22c55e}
#fbRatingFilter,#fbSortSelect{padding:5px 10px;border-radius:9px;font-size:12px;font-weight:500;border:1.5px solid #e2e8f0;background:#f8fafc;color:#1e293b;outline:none;cursor:pointer}
.dark #fbRatingFilter,.dark #fbSortSelect{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.1);color:#e2e8f0}
.dark #fbRatingFilter option,.dark #fbSortSelect option{background:#1f2937;color:#e2e8f0}
.fb-pagination{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px}
.fb-pg-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:10px;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;border:1px solid rgba(34,197,94,.35);color:#16a34a;background:rgba(34,197,94,.08)}
.dark .fb-pg-btn{color:#4ade80}
.fb-pg-btn:hover:not(:disabled){background:rgba(34,197,94,.18);border-color:#22c55e}
.fb-pg-btn:disabled{opacity:.35;cursor:not-allowed}
.fb-pg-info{font-size:12px;color:#64748b;font-weight:500}
#fbNoResults{display:none;text-align:center;padding:32px;color:#64748b;font-size:14px}
.fb-filter-group{display:inline-flex;align-items:center;gap:5px;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:2px 8px 2px 10px}
.dark .fb-filter-group{background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.08)}
.fb-filter-group #fbRatingFilter,.fb-filter-group #fbSortSelect{background:transparent!important;border:none!important;padding:3px 4px!important}
.fb-filter-icon{font-size:12px;color:#94a3b8}
</style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

<div id="sb-overlay" onclick="closeSidebar()"></div>

<!-- ═══ SIDEBAR ═══════════════════════════════════════════ -->
<aside id="sidebar" class="fixed top-0 left-0 h-screen w-64 z-50 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col transition-transform duration-300 -translate-x-full lg:translate-x-0">
    <div class="p-5 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-3">
            <?php if ($hasOrgLogo): ?>
                <img src="data:<?=$orgLogoMime?>;base64,<?=base64_encode($orgLogoData)?>"
                     class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                     alt="<?=htmlspecialchars($displayName)?>">
            <?php else: ?>
                <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/30">
                    <i class="fas fa-building text-white text-lg"></i>
                </div>
            <?php endif; ?>
            <div class="min-w-0">
                <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words"><?=htmlspecialchars($displayName)?></p>
                <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300"><?=$displayType?></span>
            </div>
        </div>
    </div>
    <nav class="flex-1 overflow-y-auto p-3 space-y-1">
        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>
        <a href="/organizer/organizer_panel.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm"><i class="fas fa-gauge-high"></i></span>Dashboard
        </a>
        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>
        <a href="/organizer/organizer_event.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm"><i class="fas fa-clipboard-list"></i></span>
            <span class="flex-1"> Events  & Announcements</span>
            <?php if ($myEvents > 0): ?><span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?=$myEvents?></span><?php endif; ?>
        </a>
        <a href="/organizer/organizer_qrscan.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm"><i class="fas fa-qrcode"></i></span>QR Scanner
        </a>
        <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>
        <a href="/organizer/organizer_tracking.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm"><i class="fas fa-users"></i></span>
            <span class="flex-1">Registrations</span>
            <?php if ($sidebarRegistrations > 0): ?><span class="text-xs bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400 px-2 py-0.5 rounded-full font-semibold"><?=$sidebarRegistrations?></span><?php endif; ?>
        </a>
        <a href="/organizer/organizer_attendance.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm"><i class="fas fa-user-check"></i></span>Attendance
        </a>
        <a href="/organizer/organizer_analytics.php" class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm"><i class="fas fa-chart-line"></i></span>Analytics
        </a>
    </nav>
    <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
        <a href="/organizer/organizer_settings.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 flex items-center justify-center text-sm"><i class="fas fa-gear"></i></span>Settings
        </a>
        <a href="../includes/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
            <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-sm"><i class="fas fa-right-from-bracket"></i></span>Logout
        </a>
    </div>
</aside>

<!-- ═══ MAIN ═══════════════════════════════════════════════ -->
<div class="lg:ml-64 min-h-screen flex flex-col">

<!-- HEADER -->
<header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-800/90 border-b border-gray-200 dark:border-gray-700 px-4 sm:px-6 py-3" style="backdrop-filter:blur(10px)">
    <div class="flex items-center gap-3">
        <button onclick="openSidebar()" class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"><i class="fas fa-bars"></i></button>
        <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">Analytics</span>
        <span class="hidden md:flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 bg-gray-100 dark:bg-gray-700 px-3 py-1.5 rounded-full border border-gray-200 dark:border-gray-600">
            <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
            <?=htmlspecialchars($displayName)?>
        </span>
        <div class="flex-1 mx-2 sm:mx-4 max-w-xs sm:max-w-sm hidden sm:block">
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" placeholder="Search analytics…" class="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-gray-100 dark:bg-gray-700 border border-transparent focus:border-brand-400 dark:focus:border-brand-500 text-gray-700 dark:text-gray-200 placeholder-gray-400 outline-none transition-colors">
            </div>
        </div>
        <div class="flex items-center gap-2 ml-auto">
            <button onclick="toggleTheme()" title="Toggle theme" class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400 hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                <i id="themeIcon" class="fas fa-moon text-sm"></i>
            </button>
            <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">
                <div class="hidden sm:block text-right leading-tight">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white"><?=$fullName?></p>
                    <p class="text-xs text-gray-400"><?=htmlspecialchars($userInfo['dept_name']??'Department')?></p>
                </div>
                <div class="w-9 h-9 rounded-full overflow-hidden bg-gradient-to-br from-brand-400 to-blue-500 flex items-center justify-center text-white text-xs font-bold ring-2 ring-brand-200 dark:ring-brand-700 hover:scale-105 transition-transform cursor-pointer">
                    <?php if ($hasImage && $profileImageData): ?>
                        <img src="data:<?=$mime?>;base64,<?=$profileImageData?>" class="w-full h-full object-cover" alt="Profile">
                    <?php else: ?><?=$initials?><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</header>

<main class="flex-1 p-4 sm:p-6 space-y-6 max-w-7xl mx-auto w-full">

    <!-- ORG INFO CARD -->
    <div class="anim-up d-0 card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <?php if ($hasOrgLogo): ?>
                    <div class="w-14 h-14 rounded-xl overflow-hidden border border-gray-200 dark:border-gray-600 flex-shrink-0">
                        <img src="data:<?=$orgLogoMime?>;base64,<?=base64_encode($orgLogoData)?>" class="w-full h-full object-cover" alt="Logo">
                    </div>
                <?php else: ?>
                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-brand-400 to-blue-500 flex items-center justify-center text-white text-2xl font-bold flex-shrink-0 shadow-lg">
                        <i class="fas <?=$displayIcon?>"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="flex items-center gap-2 flex-wrap mb-0.5">
                        <h3 class="text-base font-bold text-gray-900 dark:text-white"><?=htmlspecialchars($displayName)?></h3>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400"><?=$displayType?></span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?=htmlspecialchars($userInfo['dept_name']??'Department')?> &nbsp;·&nbsp; <?=$events['total_events']?> Events Created</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-center px-4 py-3 bg-brand-50 dark:bg-brand-900/20 rounded-xl border border-brand-200 dark:border-brand-800 min-w-[80px]">
                    <p class="text-2xl font-bold text-brand-600 dark:text-brand-400"><?=$events['total_events']?></p>
                    <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Total Events</p>
                </div>
                <div class="text-center px-4 py-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800 min-w-[80px]">
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?=$registrations['total_registrations']?></p>
                    <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Registrations</p>
                </div>
            </div>
        </div>
    </div>

    <!-- FILTER BAR -->
    <div class="anim-up d-1 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
        <div class="flex flex-col sm:flex-row gap-3 sm:items-center justify-between">
            <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                <i class="fas fa-filter text-brand-500 text-sm"></i>
                <span class="text-sm font-medium">Filter Analytics</span>
            </div>
            <form method="GET" id="analyticsFilterForm" class="flex flex-wrap gap-2">
                <select name="event_filter" onchange="this.form.submit()" class="px-3 py-2 text-sm rounded-xl bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-200 focus:border-brand-400 focus:outline-none cursor-pointer">
                    <option value="all" <?=$eventFilter==='all'?'selected':''?>>All Events</option>
                    <?php foreach ($organizerEvents as $ev): ?>
                        <option value="<?=$ev['event_id']?>" <?=$eventFilter==$ev['event_id']?'selected':''?>><?=htmlspecialchars($ev['title'])?></option>
                    <?php endforeach; ?>
                </select>
                <select name="date_range" id="dateFilter" onchange="this.form.submit()" class="px-3 py-2 text-sm rounded-xl bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-200 focus:border-brand-400 focus:outline-none cursor-pointer">
                    <option value="7days"  <?=$dateRange==='7days' ?'selected':''?>>Last 7 Days</option>
                    <option value="30days" <?=$dateRange==='30days'?'selected':''?>>Last 30 Days</option>
                    <option value="90days" <?=$dateRange==='90days'?'selected':''?>>Last 3 Months</option>
                    <option value="year"   <?=$dateRange==='year'  ?'selected':''?>>This Year</option>
                    <option value="all"    <?=$dateRange==='all'   ?'selected':''?>>All Time</option>
                </select>
                <select name="status_filter" onchange="this.form.submit()" class="px-3 py-2 text-sm rounded-xl bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-200 focus:border-brand-400 focus:outline-none cursor-pointer">
                    <option value="all"      <?=$statusFilter==='all'     ?'selected':''?>>All Status</option>
                    <option value="approved" <?=$statusFilter==='approved'?'selected':''?>>Approved</option>
                    <option value="pending"  <?=$statusFilter==='pending' ?'selected':''?>>Pending</option>
                    <option value="rejected" <?=$statusFilter==='rejected'?'selected':''?>>Rejected</option>
                </select>
            </form>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 anim-up d-2">
        <?php
        $stats = [
            ['label'=>'Total Events',   'value'=>$events['total_events'],              'icon'=>'fa-calendar-check','bg'=>'bg-brand-100 dark:bg-brand-900/30',  'ic'=>'text-brand-600 dark:text-brand-400'],
            ['label'=>'Registrations',  'value'=>$registrations['total_registrations'],'icon'=>'fa-users',          'bg'=>'bg-blue-100 dark:bg-blue-900/30',   'ic'=>'text-blue-600 dark:text-blue-400'],
            ['label'=>'Attendance Rate','value'=>$attendance_rate.'%',                 'icon'=>'fa-chart-pie',      'bg'=>'bg-purple-100 dark:bg-purple-900/30','ic'=>'text-purple-600 dark:text-purple-400'],
            ['label'=>'Avg. Rating',    'value'=>round($feedback['avg_rating']??0,1),  'icon'=>'fa-star',           'bg'=>'bg-amber-100 dark:bg-amber-900/30',  'ic'=>'text-amber-600 dark:text-amber-400'],
        ];
        foreach ($stats as $s): ?>
        <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-start justify-between mb-4">
                <span class="icon-wrap w-11 h-11 rounded-xl <?=$s['bg']?> <?=$s['ic']?> flex items-center justify-center text-lg border border-current/20">
                    <i class="fas <?=$s['icon']?>"></i>
                </span>
            </div>
            <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?=$s['value']?></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 font-medium"><?=$s['label']?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- CHARTS -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 anim-up d-3">
        <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center justify-between mb-4">
                <div><h3 class="font-semibold text-gray-900 dark:text-white">Registration Trends</h3><p class="text-xs text-gray-400 mt-0.5">Participation over time</p></div>
                <button id="dlRegBtn" class="ct-btn" title="Download PNG"><i class="fas fa-download"></i></button>
            </div>
            <div id="regToolbar" class="flex flex-wrap gap-1.5 mb-3"></div>
            <div class="chart-wrap"><canvas id="registrationChart"></canvas></div>
        </div>
        <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center justify-between mb-4">
                <div><h3 class="font-semibold text-gray-900 dark:text-white">Event Performance</h3><p class="text-xs text-gray-400 mt-0.5">Registrations vs Attendance</p></div>
                <button id="dlPerfBtn" class="ct-btn" title="Download PNG"><i class="fas fa-download"></i></button>
            </div>
            <div id="perfToolbar" class="flex flex-wrap gap-1.5 mb-3"></div>
            <div class="chart-wrap"><canvas id="performanceChart"></canvas></div>
        </div>
    </div>

    <!-- FEEDBACK -->
    <div class="anim-up d-3 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">Student Feedback Analysis</h3>
                <p class="text-xs text-gray-400 mt-0.5">Ratings and comments from event participants</p>
            </div>
            <select id="feedbackEventFilter" onchange="loadFeedback()" class="px-3 py-2 text-sm rounded-xl bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-200 focus:border-brand-400 focus:outline-none cursor-pointer w-full sm:w-auto">
                <option value="all">All Events</option>
                <?php foreach ($organizerEvents as $ev): ?>
                    <option value="<?=$ev['event_id']?>"><?=htmlspecialchars($ev['title'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            <div class="md:col-span-2 bg-gray-50 dark:bg-gray-700/50 rounded-xl p-5 border border-gray-200 dark:border-gray-600">
                <div class="flex items-center gap-4 mb-4">
                    <span class="text-4xl font-black text-gray-900 dark:text-white" id="overallRating"><?=round($feedback['avg_rating']??0,1)?></span>
                    <div>
                        <div class="flex gap-0.5 text-amber-400 mb-1" id="overallStars">
                            <?php $avgR=$feedback['avg_rating']??0;
                            for($i=1;$i<=5;$i++){
                                if($i<=floor($avgR))      echo '<i class="fas fa-star text-sm"></i>';
                                elseif($i-0.5<=$avgR)     echo '<i class="fas fa-star-half-alt text-sm"></i>';
                                else                      echo '<i class="far fa-star text-sm text-gray-300 dark:text-gray-600"></i>';
                            }?>
                        </div>
                        <p class="text-xs text-gray-400" id="reviewCount">Based on <?=$feedback['total_reviews']??0?> reviews</p>
                    </div>
                </div>
                <div class="space-y-2" id="ratingBars">
                    <?php for($i=5;$i>=1;$i--): ?>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-gray-400 w-6 text-right"><?=$i?>★</span>
                        <div class="flex-1 h-2 bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-700"
                                 style="width:<?=$ratingPercentages[$i]?>%;background:<?=$i>=4?'#22c55e':($i==3?'#eab308':($i==2?'#f97316':'#ef4444'))?>"></div>
                        </div>
                        <span class="text-gray-400 w-8"><?=$ratingPercentages[$i]?>%</span>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="md:col-span-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <?php
                $catIcons =['Organization'=>'fa-bullhorn','Content'=>'fa-lightbulb','Experience'=>'fa-heart'];
                $catColrs =['Organization'=>['bg'=>'bg-blue-100 dark:bg-blue-900/30','ic'=>'text-blue-600 dark:text-blue-400'],
                            'Content'     =>['bg'=>'bg-purple-100 dark:bg-purple-900/30','ic'=>'text-purple-600 dark:text-purple-400'],
                            'Experience'  =>['bg'=>'bg-pink-100 dark:bg-pink-900/30','ic'=>'text-pink-600 dark:text-pink-400']];
                foreach($categoryAverages as $cat):
                    $icon=$catIcons[$cat['category_name']]??'fa-star';
                    $clr=$catColrs[$cat['category_name']]??['bg'=>'bg-amber-100 dark:bg-amber-900/30','ic'=>'text-amber-600 dark:text-amber-400'];
                    $rt=round($cat['avg_rating'],1);
                ?>
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4 border border-gray-200 dark:border-gray-600 text-center">
                    <span class="w-10 h-10 rounded-full <?=$clr['bg']?> <?=$clr['ic']?> flex items-center justify-center mx-auto mb-2 text-base"><i class="fas <?=$icon?>"></i></span>
                    <h4 class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1"><?=htmlspecialchars($cat['category_name'])?></h4>
                    <p class="text-2xl font-black text-gray-900 dark:text-white mb-1"><?=$rt?></p>
                    <div class="flex justify-center gap-0.5 text-amber-400 text-xs">
                        <?php for($i=1;$i<=5;$i++): ?>
                            <?php if($i<=$rt): ?><i class="fas fa-star"></i>
                            <?php elseif($i-0.5<=$rt): ?><i class="fas fa-star-half-alt"></i>
                            <?php else: ?><i class="far fa-star text-gray-300 dark:text-gray-600"></i><?php endif; ?>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div id="fbNoResults"></div>
        <div class="space-y-3" id="feedbackList">
            <?php if(empty($feedbackList)): ?>
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-12 border border-dashed border-gray-300 dark:border-gray-600 text-center">
                <i class="fas fa-comment-slash text-3xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No feedback submitted yet</p>
                <p class="text-xs text-gray-400 mt-1">Feedback from students will appear here after they review your events.</p>
            </div>
            <?php else: ?>
                <?php foreach($feedbackList as $fb): ?>
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4 border border-gray-200 dark:border-gray-600">
                    <div class="flex items-start justify-between mb-2 gap-3">
                        <div class="flex items-center gap-3">
                            <img src="https://ui-avatars.com/api/?name=<?=urlencode($fb['first_name'].'+'.$fb['last_name'])?>&background=22c55e&color=fff" class="w-9 h-9 rounded-full flex-shrink-0" alt="">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-white"><?=htmlspecialchars($fb['first_name'].' '.$fb['last_name'])?></h4>
                                <p class="text-xs text-gray-400"><?=htmlspecialchars($fb['dept_name'])?> · <?=htmlspecialchars($fb['year_level'])?> Year</p>
                            </div>
                        </div>
                        <div class="flex gap-0.5 text-amber-400 text-xs flex-shrink-0">
                            <?php $r=round($fb['avg_rating']); for($i=1;$i<=5;$i++) echo $i<=$r?'<i class="fas fa-star"></i>':'<i class="far fa-star text-gray-300 dark:text-gray-600"></i>'; ?>
                        </div>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">"<?=htmlspecialchars($fb['comment'])?>"</p>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <i class="fas fa-calendar text-[10px]"></i>
                        <span><?=htmlspecialchars($fb['event_title'])?></span>
                        <span>·</span>
                        <span><?=date('M d, Y',strtotime($fb['created_at']))?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- DEPARTMENT CHART (conditional) -->
    <?php if($showDeptChart): ?>
    <div class="anim-up d-3 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <div class="mb-5">
            <h3 class="font-semibold text-gray-900 dark:text-white">Attendance by Department</h3>
            <p class="text-xs text-gray-400 mt-0.5">Participant distribution across departments</p>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 chart-wrap"><canvas id="departmentChart"></canvas></div>
            <div class="space-y-2.5">
                <?php $totalAtt=array_sum(array_column($deptDistribution,'attendee_count'));
                foreach($deptDistribution as $idx=>$dept):
                    $pct=$totalAtt>0?round(($dept['attendee_count']/$totalAtt)*100):0;
                    $col=$deptColors[$idx%count($deptColors)];
                ?>
                <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/50 rounded-xl px-3 py-2.5 border border-gray-200 dark:border-gray-600">
                    <div class="flex items-center gap-2.5">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background:<?=$col?>"></span>
                        <span class="text-xs text-gray-700 dark:text-gray-300 truncate max-w-[140px]"><?=htmlspecialchars($dept['dept_name'])?></span>
                    </div>
                    <span class="text-xs font-bold text-gray-900 dark:text-white"><?=$pct?>%</span>
                </div>
                <?php endforeach; ?>
                <?php if(empty($deptDistribution)): ?><p class="text-xs text-gray-400 text-center py-4">No attendance data yet</p><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ OVERALL ATTENDANCE REPORT ═══════════════════════ -->
    <div class="anim-up d-3 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white text-lg">
                    <i class="fas fa-chalkboard-user text-brand-500 mr-2"></i>Overall Attendance Report
                </h3>
                <p class="text-xs text-gray-400 mt-1">Complete attendance summary organized by department, year level, and section</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button onclick="exportAttendanceToCSV()" class="px-4 py-2 text-sm rounded-xl bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-800 text-brand-600 dark:text-brand-400 hover:bg-brand-100 dark:hover:bg-brand-900/40 transition-all flex items-center gap-2">
                    <i class="fas fa-file-csv"></i> Export CSV
                </button>
                <button onclick="toggleAttendanceFilters()" class="px-4 py-2 text-sm rounded-xl bg-gray-100 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-all flex items-center gap-2">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-3 text-center border border-gray-200 dark:border-gray-600">
                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?=$summaryStats['total_students']?></p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Total Students</p>
            </div>
            <div class="bg-green-50 dark:bg-green-900/20 rounded-xl p-3 text-center border border-green-200 dark:border-green-800">
                <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?=$summaryStats['present']?></p>
                <p class="text-xs text-green-600 dark:text-green-400">Present (<?=$summaryStats['present_pct']?>%)</p>
            </div>
            <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-xl p-3 text-center border border-yellow-200 dark:border-yellow-800">
                <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"><?=$summaryStats['partial']?></p>
                <p class="text-xs text-yellow-600 dark:text-yellow-400">Partial (<?=$summaryStats['partial_pct']?>%)</p>
            </div>
            <div class="bg-red-50 dark:bg-red-900/20 rounded-xl p-3 text-center border border-red-200 dark:border-red-800">
                <p class="text-2xl font-bold text-red-600 dark:text-red-400"><?=$summaryStats['absent']?></p>
                <p class="text-xs text-red-600 dark:text-red-400">Absent (<?=$summaryStats['absent_pct']?>%)</p>
            </div>
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-3 text-center border border-blue-200 dark:border-blue-800">
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?=count($attendanceGrouped)?></p>
                <p class="text-xs text-blue-600 dark:text-blue-400">Departments</p>
            </div>
        </div>

        <!-- Filter bar -->
        <div id="attendanceFilters" class="hidden mb-6 p-4 bg-gray-50 dark:bg-gray-700/30 rounded-xl border border-gray-200 dark:border-gray-600">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <select id="attDeptFilter" onchange="filterAttendanceTable()"
                        class="px-3 py-2 text-sm rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none <?= $allowedDepts !== null ? 'opacity-60 cursor-not-allowed' : '' ?>"
                        <?= $allowedDepts !== null ? 'disabled title="Your organization can only view its assigned department"' : '' ?>>
                    <option value="all"><?= $allowedDepts !== null ? htmlspecialchars($allowedDepts[0]) : 'All Departments' ?></option>
                    <?php if ($allowedDepts === null): ?>
                        <?php foreach($allDepartments as $dept): ?>
                            <option value="<?=htmlspecialchars($dept['dept_name'])?>"><?=htmlspecialchars($dept['dept_name'])?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <select id="attYearFilter" onchange="filterAttendanceTable()" class="px-3 py-2 text-sm rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none">
                    <option value="all">All Year Levels</option>
                    <?php foreach($yearLevels as $year): ?><option value="<?=$year?>"><?=$year?></option><?php endforeach; ?>
                </select>
                <select id="attStatusFilter" onchange="filterAttendanceTable()" class="px-3 py-2 text-sm rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none">
                    <option value="all">All Status</option>
                    <option value="Present">Present Only</option>
                    <option value="Partial">Partial Only</option>
                    <option value="Absent">Absent Only</option>
                </select>
                <input type="text" id="attSearchFilter" placeholder="Search by name or student number..."
                       onkeyup="filterAttendanceTable()"
                       class="px-3 py-2 text-sm rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none">
            </div>
        </div>

        <!-- Accordion tree -->
        <div id="attendanceTableContainer">
            <?php if(empty($attendanceGrouped)): ?>
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-12 border border-dashed border-gray-300 dark:border-gray-600 text-center">
                <i class="fas fa-clipboard-list text-3xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No attendance records found</p>
                <p class="text-xs text-gray-400 mt-1">Attendance data will appear here once students check in to events.</p>
            </div>
            <?php else: ?>
                <?php foreach($attendanceGrouped as $deptName => $years): ?>
                <div class="attendance-dept-section mb-4" data-dept="<?=htmlspecialchars($deptName)?>">
                    <div class="flex items-center justify-between cursor-pointer select-none
                                bg-gray-50 dark:bg-gray-700/40 rounded-xl px-4 py-3
                                border border-gray-200 dark:border-gray-600
                                hover:bg-gray-100 dark:hover:bg-gray-700/60 transition-colors"
                         onclick="toggleDeptSection(this)">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-chevron-right text-gray-400 text-xs transition-transform"></i>
                            <i class="fas fa-building text-brand-500 text-sm"></i>
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 text-sm"><?=htmlspecialchars($deptName)?></h4>
                            <span class="text-xs text-gray-400">(<?=$summaryStats['department_stats'][$deptName]['total']??0?> students)</span>
                        </div>
                        <div class="flex gap-2 text-xs">
                            <span class="px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-medium">P: <?=$summaryStats['department_stats'][$deptName]['present']??0?></span>
                            <span class="px-2 py-0.5 rounded-full bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400 font-medium">Par: <?=$summaryStats['department_stats'][$deptName]['partial']??0?></span>
                            <span class="px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 font-medium">A: <?=$summaryStats['department_stats'][$deptName]['absent']??0?></span>
                        </div>
                    </div>
                    <div class="attendance-dept-content ml-2 mt-2 hidden">
                        <?php foreach($years as $yearLevel => $sections): ?>
                        <div class="att-year-group mb-3" data-year="<?=htmlspecialchars($yearLevel)?>">
                            <div class="flex items-center gap-2 mb-2 px-2">
                                <i class="fas fa-graduation-cap text-purple-500 text-xs"></i>
                                <h5 class="font-semibold text-gray-700 dark:text-gray-300 text-sm"><?=htmlspecialchars($yearLevel)?></h5>
                            </div>
                            <?php foreach($sections as $sectionName => $sectionData): ?>
                            <div class="attendance-section mb-3 ml-4" data-section="<?=htmlspecialchars($sectionName)?>">
                                <div class="flex items-center gap-2 cursor-pointer select-none
                                            bg-white dark:bg-gray-800 rounded-lg px-3 py-2 mb-1
                                            border border-gray-200 dark:border-gray-700
                                            hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                                     onclick="toggleSection(this)">
                                    <i class="fas fa-chevron-right text-gray-400 text-[10px] transition-transform"></i>
                                    <i class="fas fa-users text-blue-500 text-xs"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Section <?=htmlspecialchars($sectionName)?></span>
                                    <span class="text-xs text-gray-400 ml-1">— <?=$sectionData['stats']['total']?> student<?=$sectionData['stats']['total']!==1?'s':''?></span>
                                    <div class="ml-auto flex gap-1.5 text-xs">
                                        <span class="px-1.5 py-0.5 rounded bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400"><?=$sectionData['stats']['present']?>P</span>
                                        <span class="px-1.5 py-0.5 rounded bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400"><?=$sectionData['stats']['partial']?>Par</span>
                                        <span class="px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400"><?=$sectionData['stats']['absent']?>A</span>
                                    </div>
                                </div>
                                <div class="attendance-section-content ml-2 hidden overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                                    <table class="att-table">
                                        <thead class="bg-gray-100 dark:bg-gray-700">
                                            <tr>
                                                <th class="att-col-name   px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Student Name</th>
                                                <th class="att-col-num    px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Student #</th>
                                                <th class="att-col-status px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Status</th>
                                                <th class="att-col-login  px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Login Time</th>
                                                <th class="att-col-logout px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Logout Time</th>
                                                <th class="att-col-event  px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-400">Event</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($sectionData['students'] as $student):
                                                $status=$student['attendance_status'];
                                                $sc=match($status){
                                                    'Present'=>'text-green-600 bg-green-100 dark:bg-green-900/30 dark:text-green-400',
                                                    'Partial' =>'text-yellow-600 bg-yellow-100 dark:bg-yellow-900/30 dark:text-yellow-400',
                                                    default   =>'text-red-600 bg-red-100 dark:bg-red-900/30 dark:text-red-400'
                                                };
                                                $sn=trim($student['first_name'].' '.(!empty($student['middle_name'])?substr($student['middle_name'],0,1).'. ':'').$student['last_name']);
                                            ?>
                                            <tr class="attendance-row border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/30"
                                                data-name="<?=strtolower(htmlspecialchars($sn))?>"
                                                data-student-number="<?=strtolower(htmlspecialchars($student['student_number']??''))?>"
                                                data-status="<?=$status?>"
                                                data-department="<?=htmlspecialchars($deptName)?>"
                                                data-year="<?=htmlspecialchars($yearLevel)?>"
                                                data-section="<?=htmlspecialchars($sectionName)?>">
                                                <td class="att-col-name   px-3 py-2 text-xs text-gray-800 dark:text-gray-200 font-medium"><?=htmlspecialchars($sn)?></td>
                                                <td class="att-col-num    px-3 py-2 text-xs text-gray-500 dark:text-gray-400"><?=htmlspecialchars($student['student_number']??'N/A')?></td>
                                                <td class="att-col-status px-3 py-2">
                                                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold <?=$sc?>"><?=$status?></span>
                                                </td>
                                                <td class="att-col-login  px-3 py-2 text-xs text-gray-500 dark:text-gray-400"><?=$student['login_time'] ?date('M d, H:i',strtotime($student['login_time'])) :'—'?></td>
                                                <td class="att-col-logout px-3 py-2 text-xs text-gray-500 dark:text-gray-400"><?=$student['logout_time']?date('M d, H:i',strtotime($student['logout_time'])):'—'?></td>
                                                <td class="att-col-event  px-3 py-2 text-xs text-gray-500 dark:text-gray-400" title="<?=htmlspecialchars($student['event_title'])?>"><?=htmlspecialchars($student['event_title'])?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</main>
</div>

<!-- Scroll to top -->
<button onclick="window.scrollTo({top:0,behavior:'smooth'})"
        class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 shadow-lg hover:bg-brand-500 hover:text-white hover:border-brand-500 transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
    <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
</button>

<!-- ═══════════════════════════════════════════════════════
     PHP → JS DATA BRIDGE  (must come before the external script)
     ═══════════════════════════════════════════════════════ -->
<script>
const SEMS_ANALYTICS_DATA = {
    months:        <?=json_encode($months)?>,
    regCounts:     <?=json_encode($regCounts)?>,
    eventTitles:   <?=json_encode($eventTitles)?>,
    eventRegs:     <?=json_encode($eventRegs)?>,
    eventAttend:   <?=json_encode($eventAttend)?>,
    deptNames:     <?=json_encode($deptNames)?>,
    deptCounts:    <?=json_encode($deptCounts)?>,
    deptColors:    <?=json_encode(array_slice($deptColors, 0, count($deptNames)))?>,
    showDeptChart: <?=json_encode($showDeptChart)?>,
    allowedDepts:  <?=json_encode($allowedDepts)?>,   // null = all; string[] = restricted
};
</script>

<!-- External analytics script — all behaviour lives here -->
<script src="/js/organizer_analytics.js"></script>

</body>
</html>