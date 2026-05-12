<?php
/* ============================================================
 | FILE   : organizer_attendance.php
 | PURPOSE: Attendance tracking page para sa mga organizer —
 |          dito nila makikita kung sino ang naka-attend sa
 |          kanilang mga events (login/logout records).
 ============================================================ */

// ── SESSION AT AUTH CHECK ──────────────────────────────────
// I-start ang session, then i-check kung ang naka-login ay
// isang "organizer". Kung hindi, i-redirect sa auth page.
session_start();
$pdo = require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

// Kunin ang user_id ng kasalukuyang naka-login na organizer
$uid = (int) $_SESSION['user_id'];


/* ============================================================
 | ORG / CLUB SHARED VISIBILITY
 | Kung ang dalawang officers ay nasa parehong org o club,
 | makikita nila ang attendance records ng isa't isa —
 | hindi lang yung sarili nilang events.
 ============================================================ */

// Kunin ang org_id at club_id ng current organizer
$myCtx = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = ?");
$myCtx->execute([$uid]);
$myCtxRow = $myCtx->fetch(PDO::FETCH_ASSOC) ?: ['org_id' => null, 'club_id' => null];

// I-cast sa integer — null kung wala
$myOrgId  = !empty($myCtxRow['org_id'])  ? (int)$myCtxRow['org_id']  : null;
$myClubId = !empty($myCtxRow['club_id']) ? (int)$myCtxRow['club_id'] : null;


/* ============================================================
 | HELPER FUNCTION: buildOrgEventWhere()
 | Gumagawa ng dynamic WHERE clause para ma-filter ang events
 | na pag-aari ng current user O ng kahit sinong officer sa
 | parehong org/club. Ginagamit ito sa maraming SQL queries
 | para consistent ang visibility rules.
 |
 | @param string $prefix    — table alias (e.g. 'e' para sa events)
 | @param int    $uid       — user ID ng naka-login
 | @param int|null $orgId   — org ng organizer (maaaring null)
 | @param int|null $clubId  — club ng organizer (maaaring null)
 | @param array  &$params   — reference array na dadagdagan ng bind values
 | @return string           — WHERE fragment na gagamitin sa SQL
 ============================================================ */
function buildOrgEventWhere(string $prefix, int $uid, ?int $orgId, ?int $clubId, array &$params): string
{
    // Kung may prefix (table alias), idagdag ang dot separator
    $p = $prefix !== '' ? $prefix . '.' : '';

    // Palaging isama ang sariling events ng user
    $params[] = $uid;

    if ($orgId || $clubId) {
        $orParts = [];

        // Isama ang lahat ng events na gawa ng kahit sinong
        // officer sa parehong org
        if ($orgId) {
            $orParts[] = "{$p}org_id = ?";
            $params[] = $orgId;
        }

        // Isama rin ang events mula sa parehong club
        if ($clubId) {
            $orParts[] = "{$p}club_id = ?";
            $params[] = $clubId;
        }

        return "({$p}organizer_id = ? OR " . implode(' OR ', $orParts) . ")";
    }

    // Kung walang org/club, i-filter lang sa sariling events
    return "{$p}organizer_id = ?";
}


/* ============================================================
 | ORG / CLUB DETAILS
 | Kinukuha ang pangalan at logo ng organization o club na
 | kinabibilangan ng organizer — para ipakita sa sidebar.
 ============================================================ */

// Default values bago mag-query
$orgName    = 'Organization';
$orgType    = 'Organization';
$hasOrgLogo = false;
$orgData    = null;
$orgMime    = 'image/jpeg'; // default MIME type ng logo

try {
    // Una, subukan na Organization ang affiliation
    $orgQ = $pdo->prepare("
        SELECT o.org_name, o.logo, 'Organization' as type
        FROM users u
        LEFT JOIN organizations o ON u.org_id = o.org_id
        WHERE u.user_id = ? AND o.org_id IS NOT NULL
    ");
    $orgQ->execute([$uid]);
    $orgData = $orgQ->fetch(PDO::FETCH_ASSOC);

    if ($orgData) {
        // Nahanap ang org — i-set ang mga values
        $orgName    = htmlspecialchars($orgData['org_name']);
        $orgType    = $orgData['type'];
        $hasOrgLogo = !empty($orgData['logo']);
    } else {
        // Wala sa org? Subukan ang Club affiliation
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

    // Kung may logo, i-detect ang tamang MIME type ng image blob
    if ($hasOrgLogo && !empty($orgData['logo'])) {
        $finfo           = finfo_open(FILEINFO_MIME_TYPE);
        $detectedOrgMime = finfo_buffer($finfo, $orgData['logo']);

        // Tanggapin lang kung valid na image MIME type
        if ($detectedOrgMime && strpos($detectedOrgMime, 'image/') === 0) {
            $orgMime = $detectedOrgMime;
        }
    }
} catch (Exception $e) {
    // Silent fail — gagamitin ang default values kung merong error
}


/* ============================================================
 | ORGANIZER PROFILE DATA
 | Kinukuha ang personal info ng naka-login na organizer —
 | pangalan, profile picture, position, at department —
 | para ipakita sa header ng page.
 ============================================================ */

$ps = $pdo->prepare("
    SELECT
        COALESCE(p.first_name,   o.first_name)   as first_name,
        COALESCE(p.last_name,    o.last_name)     as last_name,
        COALESCE(p.middle_name,  o.middle_name)   as middle_name,
        COALESCE(p.profile_image,o.profile_image) as profile_image,
        o.position,
        d.dept_name
    FROM users u
    LEFT JOIN profiles     p ON u.user_id = p.user_id
    LEFT JOIN organizer    o ON u.user_id = o.user_id
    LEFT JOIN departments  d ON u.dept_id = d.dept_id
    WHERE u.user_id = ?
");
$ps->execute([$uid]);
$profile = $ps->fetch(PDO::FETCH_ASSOC);

// Gumawa ng neatly formatted na buong pangalan
$firstName  = $profile['first_name'] ?? 'Organizer';
$lastName   = $profile['last_name']  ?? '';
$middleName = !empty($profile['middle_name'])
    ? ' ' . strtoupper(substr($profile['middle_name'], 0, 1)) . '. '
    : ' ';
$fullName   = htmlspecialchars(($profile['first_name'] ?? '') . $middleName . ($profile['last_name'] ?? ''));

// Initials para sa avatar placeholder (e.g. "JD" para sa Juan Dela Cruz)
$initials = strtoupper(substr($profile['first_name'] ?? 'O', 0, 1) . substr($profile['last_name'] ?? '', 0, 1));
if (empty(trim($initials))) $initials = 'OR'; // fallback initials

$deptName         = htmlspecialchars($profile['dept_name'] ?? 'Department');
$hasImage         = !empty($profile['profile_image']);
$mime             = 'image/jpeg';
$profileImageData = '';

// I-encode ang profile image bilang base64 para sa inline display
if ($hasImage) {
    try {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $det   = finfo_buffer($finfo, $profile['profile_image']);

        // I-override ang default MIME kung ma-detect ang tamang type
        if ($det && strpos($det, 'image/') === 0) $mime = $det;

        $profileImageData = base64_encode($profile['profile_image']);
    } catch (Exception $e) {
        // Kung may error sa encoding, gumamit na lang ng initials fallback
        $hasImage = false;
    }
}


/* ============================================================
 | SIDEBAR BADGE COUNTS
 | Kinukuha ang bilang ng events at registrations para sa
 | notification badges sa sidebar navigation links.
 |
 | ARCHIVED EVENTS (deleted_at IS NOT NULL) ay hindi isasama —
 | kapag na-restore ang event, awtomatiko siyang babalik sa
 | count. Permanently deleted events ay wala nang row kaya
 | hindi na sila mabilang.
 ============================================================ */

// Bilang ng events na pag-aari ng org/club ng organizer
// (hindi kasama ang archived at rejected na events)
$sbEvParams = [];
$sbEvWhere  = buildOrgEventWhere('', $uid, $myOrgId, $myClubId, $sbEvParams);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $sbEvWhere AND status != 'rejected' AND deleted_at IS NULL");
$stmt->execute($sbEvParams);
$myEvents = $stmt->fetchColumn();

// Bilang ng registrations sa lahat ng related events
// (hindi kasama ang mga registration ng archived events)
$sbRegParams = [];
$sbRegWhere  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $sbRegParams);

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM registrations r
    JOIN events e ON r.event_id = e.event_id
    WHERE $sbRegWhere
      AND e.deleted_at IS NULL
");
$stmt->execute($sbRegParams);
$registrations = $stmt->fetchColumn();


/* ============================================================
 | CLEANUP: ORPHANED ATTENDANCE RECORDS
 | Tanggalin ang mga attendance entries na walang katumbas na
 | event — nangyayari ito kapag permanently deleted ang isang
 | event (event row ay wala na).
 |
 | HINDI nito tinatanggal ang attendance ng ARCHIVED events —
 | ang deleted_at lang ang nakatakda pero nandoon pa ang row,
 | kaya hindi sila mahahanap ng orphan check. Kapag na-restore
 | ang event, babalik sila sa view ng attendance table.
 ============================================================ */
try {
    $pdo->exec("DELETE FROM attendance WHERE NOT EXISTS (SELECT 1 FROM events e WHERE e.event_id = attendance.event_id)");
} catch (Exception $e) {
    // Silent fail — hindi critical, tuloy lang ang execution
}


/* ============================================================
 | MAIN ATTENDANCE QUERY
 | Kinukuha ang lahat ng attendance records para sa mga events
 | ng org/club ng organizer, kasama ang student info at
 | login/logout timestamps.
 |
 | ARCHIVED EVENTS (deleted_at IS NOT NULL) ay hindi isasama
 | sa results — ang attendance records nila ay nakatago lang,
 | hindi tinanggal. Kapag na-restore ang event (deleted_at
 | = NULL), awtomatiko silang babalik sa view.
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
        a.scan_time      AS attendance_date
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
 | PRE-PROCESS ATTENDANCE RECORDS
 | Para sa bawat record, i-encode ang student profile image
 | bilang base64 at i-detect ang MIME type nito. Ginagawa ito
 | dito sa PHP para hindi na kailangan pang mag-query ulit
 | sa loob ng table loop.
 ============================================================ */
$allAttendanceRecords = [];

foreach ($rawRecords as $rec) {
    $studentImgData = '';
    $studentMime    = 'image/jpeg';
    $hasStudentImg  = false;

    // Subukang i-encode ang student profile image kung mayroon
    if (!empty($rec['student_profile_image'])) {
        try {
            $finfo2 = finfo_open(FILEINFO_MIME_TYPE);
            $det2   = finfo_buffer($finfo2, $rec['student_profile_image']);

            if ($det2 && strpos($det2, 'image/') === 0) $studentMime = $det2;

            $studentImgData = base64_encode($rec['student_profile_image']);
            $hasStudentImg  = true;
        } catch (Exception $e) {
            // Kung may error, gumamit na lang ng initials avatar
        }
    }

    // Idagdag ang processed image data sa record
    $rec['student_img_data'] = $studentImgData;
    $rec['student_mime']     = $studentMime;
    $rec['has_student_img']  = $hasStudentImg;

    // I-unset ang raw blob para makatipid ng memory
    unset($rec['student_profile_image']);

    $allAttendanceRecords[] = $rec;
}


/* ============================================================
 | ATTENDANCE SUMMARY STATS
 | Ino-compute ang bilang ng "present" at "absent" records
 | para sa stat cards sa tuktok ng page.
 |
 | PRESENT = may login_time AT may logout_time
 | ABSENT  = wala sa isa o sa dalawa
 ============================================================ */
$presentCount = 0;
$absentCount  = 0;

foreach ($allAttendanceRecords as $rec) {
    ($rec['login_time'] && $rec['logout_time']) ? $presentCount++ : $absentCount++;
}

$totalAtt   = $presentCount + $absentCount;

// Percentage para sa progress bar display ng bawat stat card
$presentPct = $totalAtt > 0 ? round($presentCount / $totalAtt * 100) : 0;
$absentPct  = $totalAtt > 0 ? round($absentCount  / $totalAtt * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance – SEMS</title>

    <!-- ── EXTERNAL STYLES AT SCRIPTS ────────────────────────
         Tailwind CSS para sa utility-first styling,
         Font Awesome para sa icons, at Poppins font.
    ──────────────────────────────────────────────────────── -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="/CSS/organizer_attendance.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- ── TAILWIND CONFIG ────────────────────────────────────
         Dinagdagan ng brand color palette (green shades) at
         dark mode support para sa consistent theming.
    ──────────────────────────────────────────────────────── -->
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Poppins', 'sans-serif']
                    },
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
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

    <!-- ── MOBILE SIDEBAR OVERLAY ─────────────────────────────
         Semi-transparent na overlay na lalabas sa likod ng
         sidebar kapag bukas ito sa mobile. I-click para isara.
    ──────────────────────────────────────────────────────── -->
    <div id="sb-overlay" onclick="closeSidebar()"></div>


    <!-- ===========================================================
         SIDEBAR NAVIGATION
         Fixed sa kaliwa — naglalaman ng org info, nav links,
         at logout button. Nakatago sa mobile (toggle via JS).
    =========================================================== -->
    <aside id="sidebar"
        class="fixed top-0 left-0 h-screen w-64 z-50
               bg-white dark:bg-gray-800
               border-r border-gray-200 dark:border-gray-700
               flex flex-col transition-transform duration-300
               -translate-x-full lg:translate-x-0">

        <!-- ── ORG / CLUB HEADER ────────────────────────────────
             Ipinapakita ang logo at pangalan ng org/club ng
             organizer sa tuktok ng sidebar.
        ──────────────────────────────────────────────────────── -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">

                <?php if ($hasOrgLogo): ?>
                    <!-- May logo — i-render bilang inline base64 image -->
                    <img src="data:<?= $orgMime ?>;base64,<?= base64_encode($orgData['logo']) ?>"
                        class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                        alt="<?= $orgName ?>">
                <?php else: ?>
                    <!-- Walang logo — gumamit ng building icon placeholder -->
                    <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/30">
                        <i class="fas fa-building text-white text-lg"></i>
                    </div>
                <?php endif; ?>

                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words">
                        <?= $orgName ?>
                    </p>
                    <!-- Badge na nagpapakita kung "Organization" o "Club" -->
                    <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full
                                 bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                        <?= $orgType ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── NAVIGATION LINKS ─────────────────────────────────
             Grouped nav items: Overview, Events, Tracking.
             Ang "Attendance" link ay may "active" state.
        ──────────────────────────────────────────────────────── -->
        <nav class="flex-1 overflow-y-auto p-3 space-y-1">

            <!-- Section: Overview -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">Overview</p>

            <a href="/organizer/organizer_panel.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                    <i class="fas fa-gauge-high"></i>
                </span>
                Dashboard
            </a>

            <!-- Section: Events -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Events</p>

            <!-- My Events — may badge count kung may events -->
            <a href="/organizer/organizer_event.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                    <i class="fas fa-clipboard-list"></i>
                </span>
                <span class="flex-1">My Events</span>
                <?php if ($myEvents > 0): ?>
                    <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold">
                        <?= $myEvents ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- QR Scanner — para sa pag-scan ng student QR codes -->
            <a href="/organizer/organizer_qrscan.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm">
                    <i class="fas fa-qrcode"></i>
                </span>
                QR Scanner
            </a>

            <!-- Section: Tracking -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">Tracking</p>

            <!-- Registrations — may badge count -->
            <a href="/organizer/organizer_tracking.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm">
                    <i class="fas fa-users"></i>
                </span>
                <span class="flex-1">Registrations</span>
                <?php if ($registrations > 0): ?>
                    <span class="text-xs bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400 px-2 py-0.5 rounded-full font-semibold">
                        <?= $registrations ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Attendance — ACTIVE na page ngayon, may badge count -->
            <a href="/organizer/organizer_attendance.php"
                class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fas fa-user-check"></i>
                </span>
                <span class="flex-1">Attendance</span>
                <?php if ($totalAtt > 0): ?>
                    <span class="text-xs bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 px-2 py-0.5 rounded-full font-semibold">
                        <?= $totalAtt ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Analytics — charts at summaries ng event data -->
            <a href="/organizer/organizer_analytics.php"
                class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                    <i class="fas fa-chart-line"></i>
                </span>
                Analytics
            </a>
        </nav>

        <!-- ── SIDEBAR FOOTER: SETTINGS AT LOGOUT ───────────────
             Nasa ibaba ng sidebar — Settings link at Logout button.
        ──────────────────────────────────────────────────────── -->
        <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">
            <a href="/organizer/organizer_settings.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 flex items-center justify-center text-sm">
                    <i class="fas fa-gear"></i>
                </span>
                Settings
            </a>
            <a href="../includes/logout.php"
                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                       text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-sm">
                    <i class="fas fa-right-from-bracket"></i>
                </span>
                Logout
            </a>
        </div>
    </aside>


    <!-- ===========================================================
         MAIN CONTENT WRAPPER
         Lahat ng pangunahing content ay narito — header at main.
         Naka-offset sa kanan ng sidebar sa large screens.
    =========================================================== -->
    <div class="lg:ml-64 min-h-screen flex flex-col">

        <!-- ── STICKY TOP HEADER ────────────────────────────────
             Nananatili sa tuktok kahit mag-scroll — naglalaman
             ng hamburger menu (mobile), search bar, export
             button, dark mode toggle, at organizer profile.
        ──────────────────────────────────────────────────────── -->
        <header class="sticky top-0 z-30
                       bg-white/90 dark:bg-gray-800/90
                       border-b border-gray-200 dark:border-gray-700
                       px-4 sm:px-6 py-3"
            style="backdrop-filter:blur(10px);">
            <div class="flex items-center gap-3">

                <!-- Hamburger button — visible lang sa mobile -->
                <button onclick="openSidebar()"
                    class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                           hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-bars"></i>
                </button>

                <!-- Page title — nakatago sa pinakamaliit na screen -->
                <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">Attendance</span>

                <!-- Org badge — nagpapakita ng org name na may animated pulse dot -->
                <span class="hidden md:flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500
                             bg-gray-100 dark:bg-gray-700 px-3 py-1.5 rounded-full border border-gray-200 dark:border-gray-600">
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                    <?= $orgName ?>
                </span>

                <!-- Search bar ng header — naka-sync sa filter table sa baba -->
                <div class="flex-1 mx-2 sm:mx-4 max-w-xs sm:max-w-sm">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" id="headerSearch" oninput="syncSearch(this.value)"
                            placeholder="Search attendance…"
                            class="w-full pl-9 pr-4 py-2 text-sm rounded-lg
                                   bg-gray-100 dark:bg-gray-700
                                   border border-transparent focus:border-brand-400 dark:focus:border-brand-500
                                   text-gray-700 dark:text-gray-200 placeholder-gray-400
                                   outline-none transition-colors">
                    </div>
                </div>

                <!-- Right side actions: Export, Dark Mode Toggle, Profile -->
                <div class="flex items-center gap-2 ml-auto">

                    <!-- Export CSV button — nakatago sa mobile (available sa header lang sa sm+) -->
                    <button onclick="exportAttendanceCSV()"
                        class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold
                               bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                               hover:bg-brand-500 hover:text-white transition-all active:scale-95">
                        <i class="fas fa-file-csv text-xs"></i>
                        <span class="hidden md:inline">Export CSV</span>
                    </button>

                    <!-- Dark mode toggle — nagpapalit ng 'dark' class sa HTML element -->
                    <button onclick="toggleTheme()" title="Toggle theme"
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400
                               hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                        <i id="themeIcon" class="fas fa-moon text-sm"></i>
                    </button>

                    <!-- Organizer profile section — pangalan at avatar -->
                    <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">

                        <!-- Pangalan at position — nakatago sa mobile -->
                        <div class="hidden sm:block text-right leading-tight">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= $fullName ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($profile['position'] ?? $deptName) ?></p>
                        </div>

                        <!-- Avatar: real photo kung mayroon, initials kung wala -->
                        <div class="w-9 h-9 rounded-full overflow-hidden
                                    bg-gradient-to-br from-brand-400 to-blue-500
                                    flex items-center justify-center text-white text-xs font-bold
                                    ring-2 ring-brand-200 dark:ring-brand-700
                                    hover:scale-105 transition-transform cursor-pointer">
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


        <!-- ===========================================================
             MAIN PAGE CONTENT
             Naglalaman ng: page heading, stat cards, filter bar,
             attendance table, at empty state (kung walang records).
        =========================================================== -->
        <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-7xl mx-auto w-full">

            <!-- ── PAGE HEADING ────────────────────────────────────
                 Title at description ng page + mobile export button
            ──────────────────────────────────────────────────────── -->
            <div class="anim-up d-0 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Track Attendance</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        Monitor check-in and check-out status for your events.
                    </p>
                </div>

                <!-- Mobile export button — visible lang sa maliit na screen -->
                <button onclick="exportAttendanceCSV()"
                    class="sm:hidden self-start inline-flex items-center gap-2 px-4 py-2.5 rounded-xl
                           bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold transition-colors active:scale-95">
                    <i class="fas fa-file-export"></i> Export
                </button>
            </div>

            <!-- ── STAT CARDS: PRESENT AT ABSENT ──────────────────
                 Dalawang cards na nagpapakita ng bilang at
                 percentage ng present vs absent na attendees.
            ──────────────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 anim-up d-1">

                <!-- Present Card -->
                <div class="card-hover bg-white dark:bg-gray-800
                            rounded-2xl border border-gray-200 dark:border-gray-700 p-5 overflow-hidden relative">
                    <!-- Decorative background circle -->
                    <div class="absolute top-0 right-0 w-28 h-28 bg-brand-500/5 rounded-bl-full -mr-6 -mt-6"></div>

                    <div class="flex items-center gap-4">
                        <span class="icon-wrap w-14 h-14 rounded-2xl bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400
                                     flex items-center justify-center text-2xl border border-brand-200 dark:border-brand-800 flex-shrink-0">
                            <i class="fas fa-user-check"></i>
                        </span>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-0.5">Total Present</p>
                            <!-- Dynamic count mula sa PHP -->
                            <p id="presentCount" class="text-4xl font-extrabold text-gray-900 dark:text-white"><?= $presentCount ?></p>
                        </div>
                    </div>

                    <!-- Progress bar — lapad ay katumbas ng presentPct percentage -->
                    <div class="mt-4 h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-brand-400 to-emerald-500 transition-all duration-700"
                            style="width:<?= $presentPct ?>%"></div>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1.5"><?= $presentPct ?>% of total attendance</p>
                </div>

                <!-- Absent / Incomplete Card -->
                <div class="card-hover bg-white dark:bg-gray-800
                            rounded-2xl border border-gray-200 dark:border-gray-700 p-5 overflow-hidden relative">
                    <!-- Decorative background circle -->
                    <div class="absolute top-0 right-0 w-28 h-28 bg-red-500/5 rounded-bl-full -mr-6 -mt-6"></div>

                    <div class="flex items-center gap-4">
                        <span class="icon-wrap w-14 h-14 rounded-2xl bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400
                                     flex items-center justify-center text-2xl border border-red-200 dark:border-red-800 flex-shrink-0">
                            <i class="fas fa-user-times"></i>
                        </span>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium mb-0.5">Incomplete / Absent</p>
                            <!-- Dynamic count mula sa PHP -->
                            <p id="absentCount" class="text-4xl font-extrabold text-gray-900 dark:text-white"><?= $absentCount ?></p>
                        </div>
                    </div>

                    <!-- Progress bar para sa absent percentage -->
                    <div class="mt-4 h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="h-full rounded-full bg-gradient-to-r from-red-400 to-rose-500 transition-all duration-700"
                            style="width:<?= $absentPct ?>%"></div>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1.5"><?= $absentPct ?>% of total attendance</p>
                </div>
            </div>


            <!-- ── CONDITIONAL CONTENT: EMPTY STATE O TABLE ───────
                 Kung walang records, ipakita ang empty state prompt.
                 Kung may records, ipakita ang filter bar at table.
            ──────────────────────────────────────────────────────── -->
            <?php if (empty($allAttendanceRecords)): ?>

                <!-- EMPTY STATE — Walang attendance records pa -->
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-16 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4 relative">
                        <!-- Animated ping ring para may buhay ang empty state -->
                        <span class="absolute inset-0 rounded-full bg-brand-500/10 animate-ping"></span>
                        <i class="fas fa-clipboard-check text-4xl text-gray-300 dark:text-gray-600 relative z-10"></i>
                    </div>
                    <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2">No attendance records yet</h3>
                    <p class="text-sm text-gray-400 mb-6 max-w-sm mx-auto">
                        Start scanning QR codes or adding manual entries to see attendance data here.
                    </p>
                    <!-- CTA para mag-redirect sa QR scanner -->
                    <a href="/organizer/organizer_qrscan.php"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-500 hover:bg-brand-600
                               text-white text-sm font-semibold rounded-xl transition-colors active:scale-95">
                        <i class="fas fa-qrcode"></i> Go to QR Scanner
                    </a>
                </div>

            <?php else: ?>

                <!-- ── FILTER BAR ──────────────────────────────────
                     Search input at status dropdown para i-filter
                     ang rows ng attendance table sa real-time.
                ──────────────────────────────────────────────────── -->
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4
                            flex flex-col sm:flex-row gap-3">

                    <!-- Search by student name o event title -->
                    <div class="flex-1 relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" id="pageSearch" oninput="syncSearch(this.value)"
                            placeholder="Search by student name or event…"
                            class="w-full pl-9 pr-4 py-2.5 text-sm rounded-xl
                                   bg-gray-50 dark:bg-gray-700
                                   border border-gray-200 dark:border-gray-600
                                   focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none
                                   text-gray-700 dark:text-gray-200 placeholder-gray-400 transition-colors">
                    </div>

                    <!-- Dropdown filter: All / Present / Absent -->
                    <select id="statusFilter" onchange="filterAttendance()"
                        class="px-4 py-2.5 text-sm rounded-xl
                               bg-gray-50 dark:bg-gray-700
                               border border-gray-200 dark:border-gray-600
                               focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none
                               text-gray-700 dark:text-gray-200 transition-colors appearance-none cursor-pointer">
                        <option value="">All Status</option>
                        <option value="PRESENT">Present</option>
                        <option value="ABSENT">Absent</option>
                    </select>
                </div>

                <!-- ── ATTENDANCE TABLE ─────────────────────────────
                     Pangunahing talahanayan ng lahat ng attendance
                     records — event, student, login/logout, status.
                ──────────────────────────────────────────────────── -->
                <div class="anim-up d-3 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="attendanceTable">

                            <!-- Table header row -->
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700
                                           text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <th class="px-5 py-3.5 text-left">Event</th>
                                    <th class="px-5 py-3.5 text-left">Student</th>
                                    <th class="px-5 py-3.5 text-left col-stunum">Student No.</th>
                                    <th class="px-5 py-3.5 text-left">Log In</th>
                                    <th class="px-5 py-3.5 text-left">Log Out</th>
                                    <th class="px-5 py-3.5 text-left">Status</th>
                                    <th class="px-5 py-3.5 text-left">Details</th>
                                </tr>
                            </thead>

                            <!-- Table body — loop sa bawat attendance record -->
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">

                                <?php foreach ($allAttendanceRecords as $i => $rec):

                                    // ── DETERMINE STATUS ────────────────────────────
                                    // Present = may login AT logout; Absent = kulang ang isa
                                    $hasLogin   = !empty($rec['login_time']);
                                    $hasLogout  = !empty($rec['logout_time']);
                                    $isPresent  = $hasLogin && $hasLogout;
                                    $statusText = $isPresent ? 'PRESENT' : 'ABSENT';

                                    // Pill style depende sa status
                                    $pill     = $isPresent
                                        ? 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border-brand-200 dark:border-brand-800'
                                        : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800';
                                    $pillIcon = $isPresent ? 'fa-check-circle' : 'fa-times-circle';

                                    // Avatar initial — unang letra ng pangalan ng student
                                    $avatar = strtoupper(substr($rec['student_name'], 0, 1));

                                    // ── COMPUTE DURATION ────────────────────────────
                                    // Kalkulahin ang oras na nandun ang student
                                    $duration = 'N/A';
                                    if ($hasLogin && $hasLogout) {
                                        $diff = strtotime($rec['logout_time']) - strtotime($rec['login_time']);
                                        if ($diff > 0) {
                                            $hours    = floor($diff / 3600);
                                            $mins     = floor(($diff % 3600) / 60);
                                            $duration = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins} mins";
                                        }
                                    }

                                    // ── FORMAT TIMESTAMPS ───────────────────────────
                                    // I-format ang login/logout para sa display
                                    $loginDisplay  = $hasLogin  ? date('M j, Y g:i A', strtotime($rec['login_time']))  : '—';
                                    $logoutDisplay = $hasLogout ? date('M j, Y g:i A', strtotime($rec['logout_time'])) : 'Not logged out';

                                    // ── STUDENT PROFILE IMAGE ───────────────────────
                                    // Kunin ang pre-processed image data mula sa PHP loop
                                    $hasStudentImg  = $rec['has_student_img'];
                                    $studentImgData = $rec['student_img_data'];
                                    $studentMime    = $rec['student_mime'];

                                    // I-prepare ang image src para sa data attribute ng row
                                    // (blank string = ipakita ang initials avatar sa modal)
                                    $imgSrc = $hasStudentImg
                                        ? 'data:' . $studentMime . ';base64,' . $studentImgData
                                        : '';
                                ?>

                                    <!-- ── TABLE ROW ───────────────────────────────────
                                         Bawat row ay naglalaman ng data attributes para
                                         magamit ng JS modal (openDetailsModal).
                                         Animation delay para sa staggered entrance effect.
                                    ──────────────────────────────────────────────────── -->
                                    <tr class="row-hover group"
                                        data-student-name="<?= htmlspecialchars($rec['student_name']) ?>"
                                        data-event-title="<?= htmlspecialchars($rec['event_title']) ?>"
                                        data-login-time="<?= $loginDisplay ?>"
                                        data-logout-time="<?= $logoutDisplay ?>"
                                        data-status="<?= $statusText ?>"
                                        data-duration="<?= $duration ?>"
                                        data-student-img="<?= htmlspecialchars($imgSrc) ?>"
                                        data-student-initial="<?= $avatar ?>"
                                        style="animation-delay:<?= $i * 40 ?>ms">

                                        <!-- Event title — may hover color change -->
                                        <td class="px-5 py-4">
                                            <span class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-1
                                                         group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">
                                                <?= htmlspecialchars($rec['event_title']) ?>
                                            </span>
                                        </td>

                                        <!-- Student: avatar (photo o initials) + full name -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">
                                                <?php if ($hasStudentImg): ?>
                                                    <!-- Real profile photo ng student -->
                                                    <img src="data:<?= $studentMime ?>;base64,<?= $studentImgData ?>"
                                                        alt="<?= htmlspecialchars($rec['student_name']) ?>"
                                                        class="student-avatar student-avatar-img">
                                                <?php else: ?>
                                                    <!-- Initials avatar kapag walang profile photo -->
                                                    <span class="student-avatar student-avatar-init"><?= $avatar ?></span>
                                                <?php endif; ?>
                                                <span class="font-medium text-gray-900 dark:text-white text-sm">
                                                    <?= htmlspecialchars($rec['student_name']) ?>
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Student number — monospace font para sa readability -->
                                        <td class="px-5 py-4 col-stunum">
                                            <span class="text-gray-400 font-mono text-xs">
                                                <?= htmlspecialchars($rec['student_number'] ?? '—') ?>
                                            </span>
                                        </td>

                                        <!-- Login time — date at oras na hiwalay na lines -->
                                        <td class="px-5 py-4">
                                            <?php if ($hasLogin): ?>
                                                <p class="text-gray-700 dark:text-gray-300 text-xs"><?= date('M j, Y', strtotime($rec['login_time'])) ?></p>
                                                <p class="text-brand-500 dark:text-brand-400 text-[11px] font-semibold"><?= date('g:i A', strtotime($rec['login_time'])) ?></p>
                                            <?php else: ?>
                                                <span class="text-gray-300 dark:text-gray-600 text-xs">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Logout time — may "Not logged out" state -->
                                        <td class="px-5 py-4">
                                            <?php if ($hasLogout): ?>
                                                <p class="text-gray-700 dark:text-gray-300 text-xs"><?= date('M j, Y', strtotime($rec['logout_time'])) ?></p>
                                                <p class="text-amber-500 dark:text-amber-400 text-[11px] font-semibold"><?= date('g:i A', strtotime($rec['logout_time'])) ?></p>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs italic">Not logged out</span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- Status badge pill — green=Present, red=Absent -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border uppercase <?= $pill ?>">
                                                <i class="fas <?= $pillIcon ?> text-[10px]"></i>
                                                <?= $statusText ?>
                                            </span>
                                        </td>

                                        <!-- Details button — nagbubukas ng modal kapag naka-click -->
                                        <td class="px-5 py-4">
                                            <button onclick="openDetailsModal(this)"
                                                class="w-8 h-8 rounded-lg flex items-center justify-center
                                                       bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400
                                                       border border-gray-200 dark:border-gray-600
                                                       hover:bg-brand-500 hover:text-white hover:border-brand-500
                                                       transition-all active:scale-95"
                                                title="View Details">
                                                <i class="fas fa-clock-rotate-left text-xs"></i>
                                            </button>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                    <!-- ── TABLE FOOTER ────────────────────────────
                         Nagpapakita ng record count at last update.
                         Ang rowCount ay dina-dynamic na i-update ng
                         JS kapag nagfi-filter ang user.
                    ──────────────────────────────────────────────── -->
                    <div class="px-5 py-3 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-200 dark:border-gray-700
                                flex flex-wrap items-center justify-between gap-2 text-xs text-gray-400">
                        <span id="rowCount">Showing <?= count($allAttendanceRecords) ?> records</span>
                        <span>Last updated: <?= date('M j, Y g:i A') ?></span>
                    </div>
                </div>

            <?php endif; ?>

        </main>
    </div>


    <!-- ── SCROLL TO TOP BUTTON ───────────────────────────────
         Fixed sa lower-right — lilitaw kapag nag-scroll pababa.
         Nagbabalik sa tuktok ng page kapag na-click.
    ──────────────────────────────────────────────────────── -->
    <button onclick="window.scrollTo({top:0,behavior:'smooth'})"
        class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40
               bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
               text-gray-500 dark:text-gray-400 shadow-lg
               hover:bg-brand-500 hover:text-white hover:border-brand-500
               transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
        <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
    </button>


    <!-- ===========================================================
         DETAILS MODAL
         Pop-up dialog na nagpapakita ng detalye ng isang
         attendance record — student info, login/logout times,
         at computed duration. Nagbubukas via openDetailsModal(btn).
    =========================================================== -->
    <div id="detailsModal"
        class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/60"
        style="backdrop-filter:blur(6px)"
        onclick="if(event.target===this) closeDetailsModal()">

        <!-- Modal card -->
        <div class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-md
                    border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden">

            <!-- Colorful accent bar sa tuktok ng modal -->
            <div class="h-1.5 bg-gradient-to-r from-brand-400 to-blue-500"></div>

            <!-- Modal header: icon, event title, close button -->
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <span class="w-10 h-10 rounded-xl bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 flex items-center justify-center">
                    <i class="fas fa-user-clock"></i>
                </span>
                <div class="flex-1 min-w-0">
                    <h3 class="font-bold text-gray-900 dark:text-white">Attendance Details</h3>
                    <!-- Event title — pinupuno ng JS bago buksan ang modal -->
                    <p class="text-xs text-gray-400 mt-0.5 truncate" id="modalEventTitle">—</p>
                </div>
                <!-- Close button — red on hover para malinaw na "isara" ito -->
                <button onclick="closeDetailsModal()"
                    class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400
                           hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors flex items-center justify-center">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <!-- Modal body -->
            <div class="p-6 space-y-4">

                <!-- Student info row: photo (o initials) + pangalan + status badge -->
                <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-700/50
                            border border-gray-200 dark:border-gray-600 rounded-xl p-4">

                    <!-- Real photo — ipinipinta ng JS kung may image ang student -->
                    <img id="modalStudentPhoto"
                        src=""
                        alt="Student"
                        class="hidden rounded-xl object-cover border-2 border-brand-200 dark:border-brand-800 flex-shrink-0"
                        style="width:3rem;height:3rem;">

                    <!-- Initials fallback — lilitaw kapag walang photo -->
                    <span id="modalStudentInitial"
                        class="w-12 h-12 rounded-xl bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400
                               border border-brand-200 dark:border-brand-800
                               flex items-center justify-center font-bold flex-shrink-0 text-base">
                        J
                    </span>

                    <div>
                        <!-- Pangalan ng student — pinupuno ng JS -->
                        <p class="font-semibold text-gray-900 dark:text-white text-sm" id="modalStudentName">—</p>
                        <!-- Present/Absent badge — ginawa ng JS dynamically -->
                        <div id="modalStatusBadge" class="mt-1"></div>
                    </div>
                </div>

                <!-- Login at logout times — 2-column grid -->
                <div class="grid grid-cols-2 gap-3">

                    <!-- Login time card -->
                    <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl p-4">
                        <p class="text-[10px] uppercase font-semibold text-gray-400 mb-1 flex items-center gap-1">
                            <i class="fas fa-sign-in-alt text-brand-400"></i> Log In
                        </p>
                        <p class="font-semibold text-gray-900 dark:text-white text-sm" id="modalLoginTime">—</p>
                    </div>

                    <!-- Logout time card -->
                    <div class="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl p-4">
                        <p class="text-[10px] uppercase font-semibold text-gray-400 mb-1 flex items-center gap-1">
                            <i class="fas fa-sign-out-alt text-amber-400"></i> Log Out
                        </p>
                        <p class="font-semibold text-gray-900 dark:text-white text-sm" id="modalLogoutTime">—</p>
                    </div>
                </div>

                <!-- Duration display — oras na nandun ang student sa event -->
                <div class="flex items-center justify-between
                            bg-gradient-to-r from-brand-50 to-blue-50 dark:from-brand-900/20 dark:to-blue-900/20
                            border border-brand-200 dark:border-brand-800 rounded-xl px-5 py-4">
                    <span class="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-2">
                        <i class="fas fa-clock text-brand-500"></i> Duration
                    </span>
                    <!-- Duration value — pinupuno ng JS mula sa data attribute ng row -->
                    <span class="text-xl font-bold text-gray-900 dark:text-white" id="modalDuration">N/A</span>
                </div>

                <!-- Close button sa ibaba ng modal body -->
                <button onclick="closeDetailsModal()"
                    class="w-full py-2.5 rounded-xl text-sm font-semibold
                           bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200
                           hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-2">
                    <i class="fas fa-times text-red-400"></i> Close
                </button>
            </div>
        </div>
    </div>


    <!-- ===========================================================
         JAVASCRIPT SECTION
    =========================================================== -->

    <!-- ── PHP → JS DATA BRIDGE ───────────────────────────────
         Ipinapasa ang mga PHP values sa JS namespace (SEMS_ATTENDANCE_DATA)
         para magamit ng organizer_attendance.js (e.g., sa CSV export filename).
         Dapat itong nasa itaas ng organizer_attendance.js script tag.
    ──────────────────────────────────────────────────────── -->
    <script>
        const SEMS_ATTENDANCE_DATA = {
            exportDate: "<?= date('Y-m-d') ?>",
        };
    </script>

    <!-- ── MAIN ATTENDANCE JAVASCRIPT ────────────────────────
         Naglalaman ng: filterAttendance(), syncSearch(),
         openDetailsModal(), closeDetailsModal(), exportAttendanceCSV(),
         at toggleTheme() functions.
    ──────────────────────────────────────────────────────── -->
    <script src="/js/organizer_attendance.js"></script>

</body>
</html>