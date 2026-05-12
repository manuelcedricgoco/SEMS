<?php
/* =============================================================================
 * organizer_tracking.php
 * Registration Tracker ng Organizer — SEMS (Student Event Management System)
 * Dito makikita ng organizer ang lahat ng nag-register sa kanilang mga events,
 * kasama ang status (confirmed, pending, absent) at option para i-cancel ang reg.
 * ============================================================================= */

// ─── SESSION AT DATABASE ───────────────────────────────────────────────────────
// I-start ang session para makilala kung sino ang naka-login
session_start();

// I-load ang database connection (ibinabalik ng db.php ang $pdo object)
$pdo = require_once '../includes/db.php';


// ─── GUARD: Organizer lang ang allowed dito ────────────────────────────────────
// Kung hindi organizer ang naka-login, i-redirect agad papunta sa auth page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

// I-store ang user ID ng kasalukuyang naka-login na organizer
$uid = (int) $_SESSION['user_id'];


/* =============================================================================
 * ORGANIZER CONTEXT — Shared Org/Club Visibility
 * Kinukuha ang org_id at club_id para ma-include ang events ng buong org/club,
 * hindi lang ang personal na events ng organizer.
 * ============================================================================= */
$myCtx = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = ?");
$myCtx->execute([$uid]);
$myCtxRow = $myCtx->fetch(PDO::FETCH_ASSOC) ?: ['org_id' => null, 'club_id' => null];
$myOrgId  = !empty($myCtxRow['org_id'])  ? (int)$myCtxRow['org_id']  : null;
$myClubId = !empty($myCtxRow['club_id']) ? (int)$myCtxRow['club_id'] : null;


/* =============================================================================
 * HELPER FUNCTION: buildOrgEventWhere()
 * Ginagawa ang dynamic WHERE clause para sa lahat ng events-related queries.
 *
 * @param string $prefix  - Table alias (e.g., 'e' para sa "e.organizer_id")
 *                          Kung walang prefix, gamitin ang ''
 * @param int    $uid     - User ID ng kasalukuyang organizer
 * @param ?int   $orgId   - Org ID kung member ng isang organization
 * @param ?int   $clubId  - Club ID kung member ng isang club
 * @param array  &$params - Reference sa params array; dito idadagdag ang bound values
 * @return string         - Tapos na WHERE clause string
 * ============================================================================= */
function buildOrgEventWhere(string $prefix, int $uid, ?int $orgId, ?int $clubId, array &$params): string
{
    // Kung may prefix (e.g., 'e'), idagdag ang dot; kung wala, blank lang
    $p = $prefix !== '' ? $prefix . '.' : '';

    // Palaging kasama ang sariling organizer_id
    $params[] = $uid;

    if ($orgId || $clubId) {
        $orParts = [];

        // Idagdag ang org filter kung may org_id
        if ($orgId) {
            $orParts[] = "{$p}org_id = ?";
            $params[]  = $orgId;
        }

        // Idagdag ang club filter kung may club_id
        if ($clubId) {
            $orParts[] = "{$p}club_id = ?";
            $params[]  = $clubId;
        }

        // Pagsamahin: sariling events OR events ng org/club
        return "({$p}organizer_id = ? OR " . implode(' OR ', $orParts) . ")";
    }

    // Kung walang org/club, sariling events lang ang makikita
    return "{$p}organizer_id = ?";
}


/* =============================================================================
 * ORGANIZATION / CLUB DETAILS
 * Kinukuha ang logo at pangalan ng org o club para sa sidebar header.
 * ============================================================================= */
$orgName    = 'Organization'; // Default name kung walang nahanap
$orgType    = 'Organization'; // Default type label
$hasOrgLogo = false;          // Flag kung may logo
$orgData    = null;           // Placeholder para sa query result
$orgMime    = 'image/jpeg';   // Default MIME type ng logo

try {
    // ─── Subukan muna sa Organizations table ──────────────────────────────────
    $orgQ = $pdo->prepare("
        SELECT o.org_name, o.logo, 'Organization' as type
        FROM users u
        LEFT JOIN organizations o ON u.org_id = o.org_id
        WHERE u.user_id = ? AND o.org_id IS NOT NULL
    ");
    $orgQ->execute([$uid]);
    $orgData = $orgQ->fetch(PDO::FETCH_ASSOC);

    if ($orgData) {
        // May nahanap na organization — gamitin ang detalye nito
        $orgName    = htmlspecialchars($orgData['org_name']);
        $orgType    = $orgData['type'];
        $hasOrgLogo = !empty($orgData['logo']);
    } else {
        // ─── Wala sa Organizations — subukan sa Clubs table ───────────────────
        $clubQ = $pdo->prepare("
            SELECT c.club_name as org_name, c.logo, 'Club' as type
            FROM users u
            LEFT JOIN clubs c ON u.club_id = c.club_id
            WHERE u.user_id = ? AND c.club_id IS NOT NULL
        ");
        $clubQ->execute([$uid]);
        $orgData = $clubQ->fetch(PDO::FETCH_ASSOC);

        if ($orgData) {
            // May nahanap na club — gamitin ang detalye nito
            $orgName    = htmlspecialchars($orgData['org_name']);
            $orgType    = $orgData['type'];
            $hasOrgLogo = !empty($orgData['logo']);
        }
    }

    // ─── I-detect ang MIME type ng org/club logo para sa base64 display ───────
    if ($hasOrgLogo && !empty($orgData['logo'])) {
        $finfo           = finfo_open(FILEINFO_MIME_TYPE);
        $detectedOrgMime = finfo_buffer($finfo, $orgData['logo']);

        // I-overwrite ang default MIME type kung valid na image ang nadetect
        if ($detectedOrgMime && strpos($detectedOrgMime, 'image/') === 0) {
            $orgMime = $detectedOrgMime;
        }
    }
} catch (Exception $e) {
    // Silent fail — huwag ipakita ang error sa user, mag-default na lang
}


/* =============================================================================
 * PROFILE DATA ng Organizer
 * Kinukuha ang pangalan, profile image, position, at department.
 * Ginagamit ang COALESCE para suportahan ang dalawang table (profiles at organizer).
 * ============================================================================= */
$ps = $pdo->prepare("
    SELECT
        COALESCE(p.first_name,    o.first_name)    AS first_name,
        COALESCE(p.last_name,     o.last_name)     AS last_name,
        COALESCE(p.middle_name,   o.middle_name)   AS middle_name,
        COALESCE(p.profile_image, o.profile_image) AS profile_image,
        o.position,
        d.dept_name
    FROM users u
    LEFT JOIN profiles    p ON u.user_id = p.user_id
    LEFT JOIN organizer   o ON u.user_id = o.user_id
    LEFT JOIN departments d ON u.dept_id  = d.dept_id
    WHERE u.user_id = ?
");
$ps->execute([$uid]);
$profile = $ps->fetch(PDO::FETCH_ASSOC);

// ─── I-format ang mga pangalan ────────────────────────────────────────────────
$firstName  = $profile['first_name'] ?? 'Organizer';
$lastName   = $profile['last_name']  ?? '';

// Middle initial format: "J. " o blank kung walang middle name
$middleName = !empty($profile['middle_name'])
    ? ' ' . strtoupper(substr($profile['middle_name'], 0, 1)) . '. '
    : ' ';

// Buong pangalan na sanitized para sa HTML output
$fullName = htmlspecialchars(($profile['first_name'] ?? '') . $middleName . ($profile['last_name'] ?? ''));

// Initials para sa avatar fallback (e.g., "JD" para kay Juan Dela Cruz)
$initials = strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));

// Kung walang initials (blank name), gamitin ang "OR" bilang default
if (empty(trim($initials))) $initials = 'OR';

// Department name na sanitized
$deptName = htmlspecialchars($profile['dept_name'] ?? 'Department');

// ─── I-detect ang MIME type ng profile image at i-encode sa base64 ────────────
$hasImage         = !empty($profile['profile_image']);
$mime             = 'image/jpeg'; // Default MIME type
$profileImageData = '';           // Base64 string ng profile image

if ($hasImage) {
    try {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $det   = finfo_buffer($finfo, $profile['profile_image']);

        // Kung valid na image, gamitin ang detected MIME type
        if ($det && strpos($det, 'image/') === 0) $mime = $det;

        // I-encode ang binary image data sa base64 para sa HTML src attribute
        $profileImageData = base64_encode($profile['profile_image']);
    } catch (Exception $e) {
        // Kung may error sa pag-process ng image, huwag nang ipakita
        $hasImage = false;
    }
}


/* =============================================================================
 * SIDEBAR BADGE — Bilang ng Events (para sa "My Events" nav item)
 * Ibinibilang ang lahat ng hindi-rejected, hindi-archived na events
 * ng organizer/org/club.
 * ============================================================================= */
$sbParams = [];
$sbWhere  = buildOrgEventWhere('', $uid, $myOrgId, $myClubId, $sbParams);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $sbWhere AND status != 'rejected' AND deleted_at IS NULL");
$stmt->execute($sbParams);
$myEvents = $stmt->fetchColumn();


/* =============================================================================
 * CLEANUP — Pag-alis ng mga Orphaned at Rejected Registrations
 * Tinatanggal ang mga registration na:
 *   1. Walang katumbas na event (orphaned / permanently deleted)
 *   2. Ang katumbas na event ay na-reject na
 *
 * HINDI tinatanggal ang registrations ng mga ARCHIVED (soft-deleted) events —
 * ang deleted_at lang ang nakatakda, pero nandoon pa rin ang event row,
 * kaya hindi sila mahahanap ng orphan check. Kapag na-restore ang event,
 * awtomatikong babalik ang mga registration sa view.
 * ============================================================================= */

// Tanggalin ang mga registration na walang katumbas na event (permanently deleted)
try {
    $pdo->exec("DELETE FROM registrations WHERE NOT EXISTS (
        SELECT 1 FROM events e WHERE e.event_id = registrations.event_id
    )");
} catch (Exception $e) {
    // Silent fail — hindi dapat pumarada ang page dahil dito
}

// Tanggalin ang mga registration na ang event ay na-reject
try {
    $pdo->exec("DELETE r FROM registrations r
                JOIN events e ON r.event_id = e.event_id
                WHERE e.status = 'rejected'");
} catch (Exception $e) {
    // Silent fail
}


/* =============================================================================
 * PAGINATION — Pagkuha ng Kasalukuyang Page at Offset
 * Default ay page 1, 10 records per page.
 * ============================================================================= */
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10; // Bilang ng records na ipapakita per page
$offset  = ($page - 1) * $perPage; // Kalkulasyon ng SQL OFFSET


/* =============================================================================
 * POST ACTION: Cancel Registration
 * Kapag nag-submit ang organizer ng "cancel_reg" form,
 * tatanggalin ang registration sa database at mag-reredirect.
 * May security check: validated na ang reg ay pag-aari ng org/club bago tanggalin.
 * ============================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_reg'])) {
    $regId = (int)$_POST['reg_id'];

    if ($regId > 0) {
        // I-build ang WHERE clause para tiyakin na ang event ay pag-aari ng organizer/org/club
        // (Security measure: hindi makakatanggal ng registration mula sa ibang org)
        $delParams = [$regId];
        $delWhere  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $delParams);

        $del = $pdo->prepare("
            DELETE r FROM registrations r
            JOIN events e ON r.event_id = e.event_id
            WHERE r.reg_id = ? AND $delWhere
        ");
        $del->execute($delParams);
    }

    // I-redirect para maiwasan ang form resubmission (PRG pattern)
    header("Location: organizer_tracking.php");
    exit();
}


/* =============================================================================
 * FETCH REGISTRATIONS — Pangunahing Query
 * Pinagsama ang dalawang uri ng registration gamit ang UNION ALL:
 *
 * 1. MANUAL REGISTRATIONS — mga student na personal na nag-register
 * 2. AUTO REGISTRATIONS — mga student na automatically enrolled dahil
 *    ang kanilang department ay kasama sa restricted event
 *
 * ARCHIVED EVENTS (deleted_at IS NOT NULL) ay hindi isasama sa results —
 * ang mga registration nila ay nakatago lang, hindi tinanggal. Kapag
 * na-restore ang event (deleted_at = NULL), babalik sila sa view.
 *
 * PERMANENTLY DELETED EVENTS ay hawak ng orphan-cleanup sa itaas.
 *
 * Status ng bawat registration ay kino-compute batay sa:
 *   - 'confirmed' kung may attendance record
 *   - 'absent'    kung tapos na ang event pero walang attendance
 *   - 'pending'   kung hindi pa nagaganap ang event
 * ============================================================================= */

// Separate params para sa bawat sub-query ng UNION ALL
$rq1Params = [];
$rq1Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $rq1Params);
$rq2Params = [];
$rq2Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $rq2Params);

$regQ = $pdo->prepare("
    SELECT * FROM (

        /* ── 1. Manual Registrations: Mga nagparehistro ng sarili ── */
        SELECT
            r.reg_id,
            e.title             AS event_title,
            e.start_datetime,
            e.end_datetime,
            CONCAT(p.first_name, ' ', p.last_name) AS student_name,
            u.email,
            p.student_number,
            p.profile_image     AS student_photo,
            qr.qr_value,
            r.registered_at,
            CASE
                WHEN a.attendance_id IS NOT NULL                           THEN 'confirmed'
                WHEN e.end_datetime IS NOT NULL AND e.end_datetime < NOW() THEN 'absent'
                ELSE 'pending'
            END                 AS status,
            'manual'            AS reg_source
        FROM registrations r
        JOIN events e              ON r.event_id  = e.event_id
        JOIN users  u              ON r.user_id   = u.user_id AND u.role = 'student'
        LEFT JOIN profiles p       ON u.user_id   = p.user_id
        LEFT JOIN student_qr_codes qr ON qr.user_id = u.user_id
        LEFT JOIN attendance a     ON a.event_id  = r.event_id AND a.user_id = r.user_id
        WHERE $rq1Where
          AND e.status     = 'approved'
          AND e.deleted_at IS NULL

        UNION ALL

        /* ── 2. Auto Registrations: Enrolled dahil sa department restriction ── */
        SELECT
            NULL                AS reg_id,   -- Walang reg_id dahil hindi manwal
            e.title             AS event_title,
            e.start_datetime,
            e.end_datetime,
            CONCAT(p.first_name, ' ', p.last_name) AS student_name,
            u.email,
            p.student_number,
            p.profile_image     AS student_photo,
            qr.qr_value,
            e.created_at        AS registered_at, -- Gamitin ang event creation date
            CASE
                WHEN a.attendance_id IS NOT NULL                           THEN 'confirmed'
                WHEN e.end_datetime IS NOT NULL AND e.end_datetime < NOW() THEN 'absent'
                ELSE 'pending'
            END                 AS status,
            'auto'              AS reg_source
        FROM events e
        JOIN event_departments ed  ON ed.event_id = e.event_id
        JOIN users u               ON u.dept_id   = ed.dept_id AND u.role = 'student'
        LEFT JOIN profiles p       ON u.user_id   = p.user_id
        LEFT JOIN student_qr_codes qr ON qr.user_id = u.user_id
        LEFT JOIN attendance a     ON a.event_id  = e.event_id AND a.user_id = u.user_id
        -- I-exclude ang mga may manwal na registration (para walang duplicate)
        LEFT JOIN registrations r  ON r.event_id  = e.event_id AND r.user_id = u.user_id
        WHERE $rq2Where
          AND e.status        = 'approved'
          AND e.deleted_at    IS NULL
          AND e.is_restricted = 1
          AND r.reg_id        IS NULL  -- Auto-enrolled lang, hindi pa nag-manual register

    ) AS combined
    ORDER BY registered_at DESC
    LIMIT ? OFFSET ?
");
$regQ->execute(array_merge($rq1Params, $rq2Params, [$perPage, $offset]));
$registrations = $regQ->fetchAll(PDO::FETCH_ASSOC);


/* =============================================================================
 * PRE-PROCESS PROFILE IMAGES — I-convert ang binary data sa base64
 * Ginagawa ito bago ang HTML rendering para mas malinis ang template code.
 * Tinatanggal ang orihinal na binary data pagkatapos para makatipid ng memory.
 * ============================================================================= */
foreach ($registrations as &$reg) {
    if (!empty($reg['student_photo'])) {
        try {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $dm = finfo_buffer($fi, $reg['student_photo']);

            // I-detect ang MIME type; default sa jpeg kung hindi makilala
            $reg['photo_mime'] = ($dm && strpos($dm, 'image/') === 0) ? $dm : 'image/jpeg';
            $reg['photo_b64']  = base64_encode($reg['student_photo']); // Para sa <img src="data:...">
            $reg['has_photo']  = true;
        } catch (Exception $e) {
            $reg['has_photo'] = false;
        }
    } else {
        // Walang profile photo — magpapakita ng initials avatar sa HTML
        $reg['has_photo'] = false;
    }

    // Tanggalin ang binary data para makatipid ng memory (hindi na kailangan)
    unset($reg['student_photo']);
}
unset($reg); // I-unset ang reference variable pagkatapos ng loop


/* =============================================================================
 * TOTAL COUNT — Para sa Pagination at "Showing X of Y records" display
 * Parehong structure ng main query pero COUNT(*) lang para mabilis.
 * Kasama rin ang deleted_at IS NULL filter — archived events ay hindi
 * binibilang para consistent ang pagination sa actual na makikitang rows.
 * ============================================================================= */
$cq1Params = [];
$cq1Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $cq1Params);
$cq2Params = [];
$cq2Where  = buildOrgEventWhere('e', $uid, $myOrgId, $myClubId, $cq2Params);

$countQ = $pdo->prepare("
    SELECT COUNT(*) FROM (
        /* Manual registrations count */
        SELECT 1 FROM registrations r
        JOIN events e ON r.event_id = e.event_id
        JOIN users  u ON r.user_id  = u.user_id AND u.role = 'student'
        WHERE $cq1Where
          AND e.status     = 'approved'
          AND e.deleted_at IS NULL

        UNION ALL

        /* Auto registrations count */
        SELECT 1 FROM events e
        JOIN event_departments ed ON ed.event_id = e.event_id
        JOIN users u              ON u.dept_id   = ed.dept_id AND u.role = 'student'
        LEFT JOIN registrations r ON r.event_id  = e.event_id AND r.user_id = u.user_id
        WHERE $cq2Where
          AND e.status        = 'approved'
          AND e.deleted_at    IS NULL
          AND e.is_restricted = 1
          AND r.reg_id        IS NULL
    ) AS combined
");
$countQ->execute(array_merge($cq1Params, $cq2Params));
$totalRegs  = (int)$countQ->fetchColumn();
$totalPages = (int)ceil($totalRegs / $perPage); // Kalkulasyon ng bilang ng pages


/* =============================================================================
 * STATUS SUMMARY — Bilang ng bawat status para sa stat chips sa header
 * Ginagamit ang array_filter para bilangin ang bawat kategorya
 * ============================================================================= */
$confirmedCount = count(array_filter($registrations, fn($r) => $r['status'] === 'confirmed'));
$pendingCount   = count(array_filter($registrations, fn($r) => $r['status'] === 'pending'));
$absentCount    = count(array_filter($registrations, fn($r) => $r['status'] === 'absent'));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrations – SEMS</title>

    <!-- ─── External Libraries ──────────────────────────────────────────────── -->
    <!-- Tailwind CSS — utility-first styling framework -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome — icon library para sa lahat ng icons sa UI -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Google Fonts: Poppins — pangunahing font ng application -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom CSS ng tracking page (row-hover, avatar-ring, qr-pill, etc.) -->
    <link rel="stylesheet" href="/CSS/organizer_tracking.css">

    <!-- ─── Tailwind Custom Config ───────────────────────────────────────────── -->
    <!-- Dark mode at custom brand colors (green palette) -->
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: {
                        brand: {
                            50:  '#f0fdf4', 100: '#dcfce7', 200: '#bbf7d0',
                            300: '#86efac', 400: '#4ade80', 500: '#22c55e',
                            600: '#16a34a', 700: '#15803d', 800: '#166534', 900: '#14532d'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300">

    <!-- ─── Sidebar Overlay (Mobile) ─────────────────────────────────────────── -->
    <!-- Madilim na background sa likod ng sidebar kapag bukas sa mobile -->
    <div id="sb-overlay" onclick="closeSidebar()"></div>


    <!-- =========================================================================
     SIDEBAR — Main Navigation
     Fixed sa kaliwa, nakasaklaw ng buong taas ng screen.
     Sa mobile: naka-hide by default, lalabas kapag pinindot ang hamburger.
     Sa desktop (lg+): laging visible.
    ========================================================================= -->
    <aside id="sidebar"
        class="fixed top-0 left-0 h-screen w-64 z-50
               bg-white dark:bg-gray-800
               border-r border-gray-200 dark:border-gray-700
               flex flex-col transition-transform duration-300
               -translate-x-full lg:translate-x-0">

        <!-- ─── Sidebar Header: Logo at Pangalan ng Org/Club ─────────────────── -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <?php if ($hasOrgLogo): ?>
                    <!-- Ipakita ang org/club logo bilang base64 encoded image -->
                    <img src="data:<?= $orgMime ?>;base64,<?= base64_encode($orgData['logo']) ?>"
                         class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0"
                         alt="<?= $orgName ?>">
                <?php else: ?>
                    <!-- Fallback building icon kung walang logo -->
                    <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-brand-300/30">
                        <i class="fas fa-building text-white text-lg"></i>
                    </div>
                <?php endif; ?>

                <div class="min-w-0">
                    <!-- Pangalan ng org/club -->
                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words">
                        <?= $orgName ?>
                    </p>
                    <!-- Badge: "Organization" o "Club" -->
                    <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full
                                 bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300">
                        <?= $orgType ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ─── Navigation Links ─────────────────────────────────────────────── -->
        <nav class="flex-1 overflow-y-auto p-3 space-y-1">

            <!-- Section: Overview -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-2 pb-1 font-semibold">
                Overview
            </p>

            <!-- Dashboard link -->
            <a href="/organizer/organizer_panel.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40
                             text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm">
                    <i class="fas fa-gauge-high"></i>
                </span>
                Dashboard
            </a>

            <!-- Section: Events -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">
                Events
            </p>

            <!-- My Events — may badge na bilang ng total events -->
            <a href="/organizer/organizer_event.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40
                             text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm">
                    <i class="fas fa-clipboard-list"></i>
                </span>
                <span class="flex-1">My Events</span>
                <?php if ($myEvents > 0): ?>
                    <!-- Badge na nagpapakita ng bilang ng events -->
                    <span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400
                                 px-2 py-0.5 rounded-full font-semibold">
                        <?= $myEvents ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- QR Scanner — para sa attendance check-in/check-out -->
            <a href="/organizer/organizer_qrscan.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40
                             text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm">
                    <i class="fas fa-qrcode"></i>
                </span>
                QR Scanner
            </a>

            <!-- Section: Tracking -->
            <p class="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 px-3 pt-4 pb-1 font-semibold">
                Tracking
            </p>

            <!-- Registrations — ACTIVE link (kasalukuyang page), may badge na bilang ng total -->
            <a href="/organizer/organizer_tracking.php"
               class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40
                             text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm">
                    <i class="fas fa-users"></i>
                </span>
                <span class="flex-1">Registrations</span>
                <?php if ($totalRegs > 0): ?>
                    <!-- Badge na nagpapakita ng kabuuang bilang ng registrations -->
                    <span class="text-xs bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-400
                                 px-2 py-0.5 rounded-full font-semibold">
                        <?= $totalRegs ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- Attendance — para sa monitoring ng attendance ng bawat event -->
            <a href="/organizer/organizer_attendance.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40
                             text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fas fa-user-check"></i>
                </span>
                Attendance
            </a>

            <!-- Analytics — mga chart at report ng event performance -->
            <a href="/organizer/organizer_analytics.php"
               class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/40
                             text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm">
                    <i class="fas fa-chart-line"></i>
                </span>
                Analytics
            </a>
        </nav>

        <!-- ─── Sidebar Footer: Settings at Logout ───────────────────────────── -->
        <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-1">

            <!-- Settings page link -->
            <a href="/organizer/organizer_settings.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700
                             text-gray-500 flex items-center justify-center text-sm">
                    <i class="fas fa-gear"></i>
                </span>
                Settings
            </a>

            <!-- Logout — end ang session at bumalik sa login page -->
            <a href="../includes/logout.php"
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium
                      text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-red-100 dark:bg-red-900/30
                             text-red-500 flex items-center justify-center text-sm">
                    <i class="fas fa-right-from-bracket"></i>
                </span>
                Logout
            </a>
        </div>
    </aside>


    <!-- =========================================================================
     MAIN WRAPPER
     Lahat ng content ay nandito. May left margin para hindi matakpan ng sidebar
     sa desktop (lg:ml-64).
    ========================================================================= -->
    <div class="lg:ml-64 min-h-screen flex flex-col">

        <!-- =====================================================================
         STICKY HEADER — Top Navigation Bar
         Lagi itong nasa taas kahit mag-scroll pababa. May backdrop blur para
         transparent na mukhang glass effect.
        ===================================================================== -->
        <header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-800/90
                       border-b border-gray-200 dark:border-gray-700 px-4 sm:px-6 py-3"
                style="backdrop-filter: blur(10px);">

            <div class="flex items-center gap-3">

                <!-- ─── Hamburger Button (Mobile Only) ───────────────────────── -->
                <!-- Kapag na-click, magbubukas ng sidebar sa mobile -->
                <button onclick="openSidebar()"
                        class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700
                               text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                    <i class="fas fa-bars"></i>
                </button>

                <!-- ─── Page Title (Desktop Only) ────────────────────────────── -->
                <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">
                    Registrations
                </span>

                <!-- ─── Live Record Count Chip ────────────────────────────────── -->
                <!-- Nagpapakita ng org name at total record count sa header -->
                <span class="hidden md:flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500
                             bg-gray-100 dark:bg-gray-700 px-3 py-1.5 rounded-full border border-gray-200 dark:border-gray-600">
                    <!-- Pulsing green dot = live data indicator -->
                    <span class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                    <?= $orgName ?> &middot; <?= $totalRegs ?> records
                </span>

                <!-- ─── Header Search Bar ─────────────────────────────────────── -->
                <!-- Naka-sync ito sa pageSearch sa filter bar (parehong nagfa-filter) -->
                <div class="flex-1 mx-2 sm:mx-4 max-w-xs sm:max-w-sm">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text"
                               id="headerSearch"
                               oninput="syncSearch(this.value)"
                               placeholder="Search registrations…"
                               class="w-full pl-9 pr-4 py-2 text-sm rounded-lg
                                      bg-gray-100 dark:bg-gray-700
                                      border border-transparent focus:border-brand-400 dark:focus:border-brand-500
                                      text-gray-700 dark:text-gray-200 placeholder-gray-400
                                      outline-none transition-colors">
                    </div>
                </div>

                <!-- ─── Right-side Actions ────────────────────────────────────── -->
                <div class="flex items-center gap-2 ml-auto">

                    <!-- Export CSV Button (Desktop) — I-download ang table bilang CSV file -->
                    <button onclick="exportToCSV()"
                            class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-semibold
                                   bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300
                                   hover:bg-brand-500 hover:text-white transition-all active:scale-95">
                        <i class="fas fa-file-export text-xs"></i>
                        <span class="hidden md:inline">Export CSV</span>
                    </button>

                    <!-- Dark Mode Toggle — i-switch sa light/dark theme -->
                    <button onclick="toggleTheme()"
                            title="Toggle theme"
                            class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400
                                   hover:bg-gray-200 dark:hover:bg-gray-600 transition-all hover:rotate-12">
                        <i id="themeIcon" class="fas fa-moon text-sm"></i>
                    </button>

                    <!-- ─── Profile Display ───────────────────────────────────── -->
                    <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">

                        <!-- Pangalan at position/department (desktop only) -->
                        <div class="hidden sm:block text-right leading-tight">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= $fullName ?></p>
                            <p class="text-xs text-gray-400">
                                <?= htmlspecialchars($profile['position'] ?? $deptName) ?>
                            </p>
                        </div>

                        <!-- Avatar: profile image o initials kung walang imahe -->
                        <div class="w-9 h-9 rounded-full overflow-hidden
                                    bg-gradient-to-br from-brand-400 to-blue-500
                                    flex items-center justify-center text-white text-xs font-bold
                                    ring-2 ring-brand-200 dark:ring-brand-700
                                    hover:scale-105 transition-transform cursor-pointer">
                            <?php if ($hasImage): ?>
                                <!-- $profileImageData ay pre-encoded na base64 string mula sa PHP -->
                                <img src="data:<?= $mime ?>;base64,<?= $profileImageData ?>"
                                     class="w-full h-full object-cover" alt="Profile">
                            <?php else: ?>
                                <!-- Initials fallback (e.g., "JD") -->
                                <?= $initials ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>


        <!-- =====================================================================
         MAIN CONTENT AREA
         Naglalaman ng: heading + stat chips, filter bar, at registration table.
        ===================================================================== -->
        <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-7xl mx-auto w-full">

            <!-- ─── Section Heading + Status Summary Chips ────────────────────── -->
            <div class="anim-up d-0 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Student Registrations</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                        View and manage all student sign-ups for your events.
                    </p>
                </div>

                <!-- Stat Chips: Confirmed, Pending, Absent, Total -->
                <!-- Bawat chip ay nagpapakita ng bilang ng bawat status -->
                <div class="flex gap-3 self-start sm:self-auto">
                    <!-- Confirmed count — berde ang kulay -->
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center min-w-[75px]">
                        <p class="text-2xl font-bold text-brand-500"><?= $confirmedCount ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Confirmed</p>
                    </div>
                    <!-- Pending count — dilaw/amber ang kulay -->
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center min-w-[75px]">
                        <p class="text-2xl font-bold text-amber-500"><?= $pendingCount ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Pending</p>
                    </div>
                    <!-- Absent count — pula ang kulay -->
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center min-w-[75px]">
                        <p class="text-2xl font-bold text-red-500"><?= $absentCount ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Absent</p>
                    </div>
                    <!-- Total count — gray/neutral ang kulay -->
                    <div class="card-hover bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                                rounded-xl px-4 py-3 text-center min-w-[75px]">
                        <p class="text-2xl font-bold text-gray-700 dark:text-white"><?= $totalRegs ?></p>
                        <p class="text-[10px] text-gray-400 font-medium uppercase tracking-wide">Total</p>
                    </div>
                </div>
            </div>


            <!-- ─── Filter Bar — Search at Status Dropdown ────────────────────── -->
            <div class="anim-up d-1 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-4
                        flex flex-col sm:flex-row gap-3">

                <!-- Text search input — nagha-handle ng live filtering ng table rows -->
                <div class="flex-1 relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text"
                           id="pageSearch"
                           oninput="syncSearch(this.value)"
                           placeholder="Search by name, email, QR value or event…"
                           class="w-full pl-9 pr-4 py-2.5 text-sm rounded-xl
                                  bg-gray-50 dark:bg-gray-700
                                  border border-gray-200 dark:border-gray-600
                                  focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none
                                  text-gray-700 dark:text-gray-200 placeholder-gray-400 transition-colors">
                </div>

                <div class="flex gap-2">
                    <!-- Status dropdown filter — nagfi-filter ng rows ayon sa status -->
                    <select id="statusFilter"
                            onchange="filterTable()"
                            class="px-4 py-2.5 text-sm rounded-xl
                                   bg-gray-50 dark:bg-gray-700
                                   border border-gray-200 dark:border-gray-600
                                   focus:border-brand-400 dark:focus:border-brand-500 focus:outline-none
                                   text-gray-700 dark:text-gray-200 transition-colors appearance-none cursor-pointer">
                        <option value="">All Status</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="pending">Pending</option>
                        <option value="absent">Absent</option>
                    </select>

                    <!-- Export CSV Button (Mobile) — visible lang sa maliit na screen -->
                    <button onclick="exportToCSV()"
                            class="sm:hidden px-4 py-2.5 rounded-xl text-sm font-semibold
                                   bg-brand-500 hover:bg-brand-600 text-white transition-colors active:scale-95">
                        <i class="fas fa-file-export"></i>
                    </button>
                </div>
            </div>


            <!-- ─── EMPTY STATE — Walang Registrations pa ─────────────────────── -->
            <?php if (empty($registrations)): ?>
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl
                            border-2 border-dashed border-gray-300 dark:border-gray-600 p-16 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4 relative">
                        <!-- Pulsing glow effect sa likod ng icon -->
                        <span class="absolute inset-0 rounded-full bg-brand-500/10 animate-ping"></span>
                        <i class="fas fa-users text-4xl text-gray-300 dark:text-gray-600 relative z-10"></i>
                    </div>
                    <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2">No registrations yet</h3>
                    <p class="text-sm text-gray-400 mb-6 max-w-sm mx-auto">
                        Students will appear here once they register for your events.
                    </p>
                    <!-- CTA button para pumunta sa events page -->
                    <a href="/organizer/organizer_event.php"
                       class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-500 hover:bg-brand-600
                              text-white text-sm font-semibold rounded-xl transition-colors active:scale-95">
                        <i class="fas fa-arrow-left"></i> View My Events
                    </a>
                </div>

            <?php else: ?>

                <!-- ─── REGISTRATIONS TABLE ───────────────────────────────────── -->
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl
                            border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm" id="regTable">

                            <!-- Table Header -->
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700/50 border-b border-gray-200 dark:border-gray-700
                                           text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <th class="px-5 py-3.5 text-left">Event</th>
                                    <th class="px-5 py-3.5 text-left">Student</th>
                                    <th class="px-5 py-3.5 text-left col-email">Email</th>
                                    <!-- QR Value column — may icon para sa visual distinction -->
                                    <th class="px-5 py-3.5 text-left col-qr">
                                        <span class="flex items-center gap-1.5">
                                            <i class="fas fa-qrcode text-violet-400"></i> QR Value
                                        </span>
                                    </th>
                                    <th class="px-5 py-3.5 text-left">Registered</th>
                                    <th class="px-5 py-3.5 text-left">Status</th>
                                    <th class="px-5 py-3.5 text-left">Action</th>
                                </tr>
                            </thead>

                            <!-- Table Body — I-loop ang bawat registration row -->
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">

                                <?php foreach ($registrations as $i => $reg):
                                    // ─── Status Badge Config ──────────────────────────────────
                                    // Nagde-determine ng pill color at icon batay sa status
                                    $isConfirmed = $reg['status'] === 'confirmed';
                                    $isAbsent    = $reg['status'] === 'absent';

                                    if ($isConfirmed) {
                                        // Berde para sa confirmed attendance
                                        $pill     = 'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border-brand-200 dark:border-brand-800';
                                        $pillIcon = 'fa-check-circle';
                                    } elseif ($isAbsent) {
                                        // Pula para sa absent (tapos na ang event, wala sa attendance)
                                        $pill     = 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border-red-200 dark:border-red-800';
                                        $pillIcon = 'fa-circle-xmark';
                                    } else {
                                        // Amber/dilaw para sa pending (hindi pa nagaganap ang event)
                                        $pill     = 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800';
                                        $pillIcon = 'fa-clock';
                                    }

                                    // ─── Avatar Initials at Gradient Color ───────────────────
                                    // Kinukuha ang unang letra ng pangalan para sa initials avatar
                                    $initials2 = strtoupper(substr($reg['student_name'] ?: 'U', 0, 1));

                                    // Gradient color ng avatar ay base sa ASCII value ng unang letra
                                    // (para lagi't iba-iba ang kulay per student pero consistent)
                                    $gradients = [
                                        'from-violet-400 to-purple-500',
                                        'from-sky-400 to-blue-500',
                                        'from-rose-400 to-pink-500',
                                        'from-amber-400 to-orange-500',
                                        'from-teal-400 to-cyan-500',
                                        'from-brand-400 to-emerald-500',
                                    ];
                                    $grad = $gradients[ord($initials2) % count($gradients)];
                                ?>
                                    <!-- ─── Registration Table Row ─────────────────────────── -->
                                    <tr class="row-hover group"
                                        data-status="<?= $reg['status'] ?>"
                                        style="animation-delay: <?= $i * 40 ?>ms">

                                        <!-- CELL: Event Title + Source Badge -->
                                        <td class="px-5 py-4 max-w-[200px]">
                                            <!-- Pangalan ng event; nag-iiba ng kulay kapag hovered -->
                                            <span class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2
                                                         group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors">
                                                <?= htmlspecialchars($reg['event_title']) ?>
                                            </span>
                                            <?php if ($reg['reg_source'] === 'auto'): ?>
                                                <!-- "Auto-enrolled" label para sa department-restricted events -->
                                                <span class="inline-flex items-center gap-1 mt-1 text-[10px] text-gray-400 dark:text-gray-500">
                                                    <i class="fas fa-robot text-[9px]"></i> Auto-enrolled
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- CELL: Student Avatar + Pangalan + Student Number -->
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">

                                                <!-- Avatar ng student (profile photo o initials) -->
                                                <div class="flex-shrink-0 relative">
                                                    <?php if ($reg['has_photo']): ?>
                                                        <!-- Actual profile photo (base64 pre-processed na sa PHP) -->
                                                        <img src="data:<?= $reg['photo_mime'] ?>;base64,<?= $reg['photo_b64'] ?>"
                                                             class="w-9 h-9 rounded-xl object-cover avatar-ring"
                                                             alt="<?= htmlspecialchars($reg['student_name']) ?>">
                                                    <?php else: ?>
                                                        <!-- Gradient initials avatar kung walang profile photo -->
                                                        <div class="w-9 h-9 rounded-xl bg-gradient-to-br <?= $grad ?>
                                                                    flex items-center justify-center text-xs font-bold text-white avatar-ring">
                                                            <?= $initials2 ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if ($isConfirmed): ?>
                                                        <!-- Green checkmark badge kapag confirmed ang attendance -->
                                                        <span class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 bg-brand-500
                                                                     border-2 border-white dark:border-gray-800 rounded-full
                                                                     flex items-center justify-center">
                                                            <i class="fas fa-check text-white" style="font-size: 6px;"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Pangalan at student number -->
                                                <div class="min-w-0">
                                                    <p class="font-semibold text-gray-900 dark:text-white text-sm truncate">
                                                        <?= htmlspecialchars($reg['student_name']) ?>
                                                    </p>
                                                    <?php if ($reg['student_number']): ?>
                                                        <!-- Student number sa monospace font para malinaw ang pagbabasa -->
                                                        <p class="text-[11px] text-gray-400 mt-0.5 font-mono">
                                                            <?= htmlspecialchars($reg['student_number']) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- CELL: Email Address -->
                                        <td class="px-5 py-4 col-email">
                                            <span class="text-gray-500 dark:text-gray-400 text-xs truncate block max-w-[160px]">
                                                <?= htmlspecialchars($reg['email']) ?>
                                            </span>
                                        </td>

                                        <!-- CELL: QR Value + Copy Button -->
                                        <td class="px-5 py-4 col-qr">
                                            <?php if (!empty($reg['qr_value'])): ?>
                                                <div class="flex items-center gap-1.5">
                                                    <!-- Pill display ng QR value (truncated, full value sa title tooltip) -->
                                                    <span class="qr-pill" title="<?= htmlspecialchars($reg['qr_value']) ?>">
                                                        <?= htmlspecialchars($reg['qr_value']) ?>
                                                    </span>
                                                    <!-- Copy button — kapag pinindot, makokopya sa clipboard -->
                                                    <button onclick="copyQR(this, '<?= htmlspecialchars(addslashes($reg['qr_value'])) ?>')"
                                                            title="Copy QR value"
                                                            class="w-6 h-6 rounded-md flex items-center justify-center flex-shrink-0
                                                                   text-gray-400 hover:text-violet-600 dark:hover:text-violet-400
                                                                   hover:bg-violet-50 dark:hover:bg-violet-900/20
                                                                   transition-all active:scale-90">
                                                        <i class="fas fa-copy text-[10px]"></i>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <!-- Walang QR code ang student (hindi pa na-assign) -->
                                                <span class="text-[11px] text-gray-300 dark:text-gray-600 italic flex items-center gap-1">
                                                    <i class="fas fa-minus text-[9px]"></i> None
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <!-- CELL: Registration Date at Oras -->
                                        <td class="px-5 py-4 whitespace-nowrap">
                                            <!-- Date (e.g., "May 10, 2025") -->
                                            <p class="text-gray-700 dark:text-gray-300 text-xs font-medium">
                                                <?= date('M j, Y', strtotime($reg['registered_at'])) ?>
                                            </p>
                                            <!-- Time (e.g., "9:00 AM") -->
                                            <p class="text-gray-400 text-[11px]">
                                                <?= date('g:i A', strtotime($reg['registered_at'])) ?>
                                            </p>
                                        </td>

                                        <!-- CELL: Status Badge (Confirmed / Pending / Absent) -->
                                        <td class="px-5 py-4">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full
                                                         text-xs font-semibold border capitalize <?= $pill ?>">
                                                <i class="fas <?= $pillIcon ?> text-[10px]"></i>
                                                <?= $reg['status'] ?>
                                            </span>
                                        </td>

                                        <!-- CELL: Action Button (Cancel / Auto label) -->
                                        <td class="px-5 py-4">
                                            <?php if (!empty($reg['reg_id'])): ?>
                                                <!-- Cancel button — available lang para sa manwal na registrations -->
                                                <!-- Ang data-* attributes ay ginagamit ng JS para sa delete confirmation modal -->
                                                <form method="POST"
                                                      class="inline delete-form"
                                                      data-reg-id="<?= $reg['reg_id'] ?>"
                                                      data-student-name="<?= htmlspecialchars($reg['student_name']) ?>"
                                                      data-event-title="<?= htmlspecialchars($reg['event_title']) ?>">
                                                    <input type="hidden" name="reg_id" value="<?= $reg['reg_id'] ?>">
                                                    <!-- Hindi type="submit" — ang JS ang mag-aasikaso ng confirmation dialog -->
                                                    <button type="button"
                                                            class="delete-trigger w-8 h-8 rounded-lg flex items-center justify-center
                                                                   bg-red-50 dark:bg-red-900/20 text-red-500 dark:text-red-400
                                                                   border border-red-200 dark:border-red-800
                                                                   hover:bg-red-500 hover:text-white dark:hover:bg-red-500 dark:hover:text-white
                                                                   transition-all active:scale-95"
                                                            title="Cancel Registration">
                                                        <i class="fas fa-trash-alt text-xs"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <!-- "Auto" label para sa auto-enrolled students (hindi maaaring i-cancel mano-mano) -->
                                                <span class="inline-flex items-center gap-1 text-[11px] text-gray-300 dark:text-gray-600 italic">
                                                    <i class="fas fa-robot text-[10px]"></i> Auto
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                            </tbody>
                        </table>
                    </div>

                    <!-- ─── Table Footer: Record Count at Last Updated ──────────── -->
                    <div class="px-5 py-3 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-200 dark:border-gray-700
                                flex flex-wrap items-center justify-between gap-2 text-xs text-gray-400">
                        <!-- Pinapanahon ng JS kapag nag-filter ang user -->
                        <span id="rowCount">Showing <?= count($registrations) ?> of <?= $totalRegs ?> records</span>
                        <span>Last updated: <?= date('M j, Y g:i A') ?></span>
                    </div>

                    <!-- ─── Pagination Links ────────────────────────────────────── -->
                    <!-- Lumalabas lang kung higit sa isang page ang mga records -->
                    <?php if ($totalPages > 1): ?>
                        <div class="px-5 py-3 bg-gray-50 dark:bg-gray-700/30 border-t border-gray-200 dark:border-gray-700
                                    flex flex-wrap items-center justify-between gap-2">

                            <!-- "Page X of Y" indicator -->
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Page <?= $page ?> of <?= $totalPages ?>
                            </span>

                            <div class="flex items-center gap-1">
                                <!-- Previous page button (naka-hide kung nasa page 1 na) -->
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?= $page - 1 ?>"
                                       class="px-3 py-1.5 text-xs font-medium rounded-lg
                                              bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                                              text-gray-600 dark:text-gray-300
                                              hover:bg-brand-50 dark:hover:bg-brand-900/20 hover:text-brand-600 dark:hover:text-brand-400
                                              transition-colors">
                                        <i class="fas fa-chevron-left mr-1"></i> Prev
                                    </a>
                                <?php endif; ?>

                                <!-- Numbered page links (current page ay may brand background) -->
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i === $page): ?>
                                        <!-- Active page — highlighted ng brand color -->
                                        <span class="px-3 py-1.5 text-xs font-bold rounded-lg bg-brand-500 text-white">
                                            <?= $i ?>
                                        </span>
                                    <?php else: ?>
                                        <!-- Inactive page — clickable link -->
                                        <a href="?page=<?= $i ?>"
                                           class="px-3 py-1.5 text-xs font-medium rounded-lg
                                                  bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                                                  text-gray-600 dark:text-gray-300
                                                  hover:bg-brand-50 dark:hover:bg-brand-900/20 hover:text-brand-600 dark:hover:text-brand-400
                                                  transition-colors">
                                            <?= $i ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <!-- Next page button (naka-hide kung nasa last page na) -->
                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?= $page + 1 ?>"
                                       class="px-3 py-1.5 text-xs font-medium rounded-lg
                                              bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600
                                              text-gray-600 dark:text-gray-300
                                              hover:bg-brand-50 dark:hover:bg-brand-900/20 hover:text-brand-600 dark:hover:text-brand-400
                                              transition-colors">
                                        Next <i class="fas fa-chevron-right ml-1"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div><!-- end table wrapper -->

            <?php endif; ?>
        </main>
    </div><!-- end main wrapper -->


    <!-- ─── Scroll to Top Button ──────────────────────────────────────────────── -->
    <!-- Lagi visible sa lower-right corner ng screen -->
    <button onclick="window.scrollTo({ top: 0, behavior: 'smooth' })"
            class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40
                   bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700
                   text-gray-500 dark:text-gray-400 shadow-lg
                   hover:bg-brand-500 hover:text-white hover:border-brand-500
                   transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
        <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
    </button>


    <!-- =========================================================================
     DELETE CONFIRMATION MODAL
     Nagpapakita kapag pinindot ng organizer ang trash icon sa isang row.
     Hindi direktang nagsu-submit ang form — ini-intercept ng JS,
     pinopopulate ang modal ng student name at event title, tapos confirm na.
    ========================================================================= -->
    <div id="deleteModal"
         class="fixed inset-0 z-50 hidden items-center justify-center p-4 bg-black/60"
         style="backdrop-filter: blur(6px)"
         onclick="if(event.target === this) closeDeleteModal()">

        <div class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-md
                    border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden">

            <!-- ─── Modal Header ─────────────────────────────────────────────── -->
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <!-- Warning icon -->
                <span class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/30 text-red-500
                             flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-triangle-exclamation"></i>
                </span>
                <h3 class="font-bold text-gray-900 dark:text-white">Cancel Registration</h3>
                <!-- X button para isara ang modal nang walang aksyon -->
                <button onclick="closeDeleteModal()"
                        class="ml-auto w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400
                               hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors
                               flex items-center justify-center">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <!-- ─── Modal Body ────────────────────────────────────────────────── -->
            <div class="p-6 space-y-4">
                <!-- Confirmation message — pino-populate ng JS ang #modalStudentName at #modalEventTitle -->
                <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                    Are you sure you want to cancel the registration for
                    <strong class="text-gray-900 dark:text-white" id="modalStudentName"></strong>
                    from <strong class="text-gray-900 dark:text-white" id="modalEventTitle"></strong>?
                </p>

                <!-- Warning notice: hindi na mababalik ang aksyon -->
                <div class="flex items-start gap-2.5 bg-red-50 dark:bg-red-900/20
                            border border-red-200 dark:border-red-800 rounded-xl p-3 text-xs text-red-600 dark:text-red-400">
                    <i class="fas fa-exclamation-circle mt-0.5 flex-shrink-0"></i>
                    This action cannot be undone. The student will be removed from this event.
                </div>

                <!-- ─── Modal Action Buttons ──────────────────────────────────── -->
                <div class="flex gap-3 pt-1">
                    <!-- Cancel button — isasara ang modal nang walang aksyon -->
                    <button onclick="closeDeleteModal()"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold
                                   bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200
                                   hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">
                        No, Keep It
                    </button>
                    <!-- Confirm Delete button — ifo-forward ng JS ang form submission -->
                    <button id="confirmDeleteBtn"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold
                                   bg-red-500 hover:bg-red-600 text-white
                                   shadow shadow-red-400/20 transition-all active:scale-95
                                   flex items-center justify-center gap-2">
                        <i class="fas fa-trash-alt"></i> Yes, Cancel
                    </button>
                </div>
            </div>
        </div>
    </div><!-- end delete modal -->


    <!-- ─── Copy Toast Notification ───────────────────────────────────────────── -->
    <!-- Lalabas sa ibaba ng screen kapag na-copy ang QR value sa clipboard -->
    <div id="copyToast"
         class="fixed bottom-20 left-1/2 -translate-x-1/2 z-50
                px-4 py-2 rounded-xl text-xs font-semibold text-white
                bg-gray-900 dark:bg-gray-700 shadow-xl
                opacity-0 pointer-events-none transition-all duration-300 flex items-center gap-2">
        <i class="fas fa-check-circle text-brand-400"></i>
        <span id="copyToastText">QR value copied!</span>
    </div>


    <!-- =========================================================================
     SCRIPTS
    ========================================================================= -->

    <!-- ─── Data Bridge: PHP → JavaScript ────────────────────────────────────── -->
    <!-- Ipinapasa ang export date sa JS para gamitin sa CSV filename.
         Dapat ito ay nasa BAGO ng organizer_tracking.js -->
    <script>
        const SEMS_TRACKING_DATA = {
            exportDate: "<?= date('Y-m-d') ?>", // Kasalukuyang petsa para sa CSV filename
        };
    </script>

    <!-- ─── Main Tracking Script ──────────────────────────────────────────────── -->
    <!-- Handles: sidebar toggle, dark mode, search/filter, delete modal, CSV export, at copy toast -->
    <script src="/js/organizer_tracking.js"></script>

</body>
</html>