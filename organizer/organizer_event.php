<?php
/* ============================================================
 * organizer_event.php
 * ============================================================ */

// ── SECTION 1 — SESSION AT DATABASE ──────────────────────────
session_start();
$pdo = require_once '../includes/db.php';

// ── SECTION 2 — AUTHENTICATION GUARD ─────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'organizer') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}
$uid = (int) $_SESSION['user_id'];

// ── SECTION 3 — ORG/CLUB CONTEXT ─────────────────────────────
$uCtx = $pdo->prepare("SELECT org_id, club_id FROM users WHERE user_id = ?");
$uCtx->execute([$uid]);
$myCtx    = $uCtx->fetch(PDO::FETCH_ASSOC) ?: ['org_id' => null, 'club_id' => null];
$myOrgId  = !empty($myCtx['org_id'])  ? (int)$myCtx['org_id']  : null;
$myClubId = !empty($myCtx['club_id']) ? (int)$myCtx['club_id'] : null;

// ── SECTION 4 — DYNAMIC WHERE CLAUSE ─────────────────────────
$eventWhere = "e.organizer_id = ?";
$params     = [$uid];

if ($myOrgId || $myClubId) {
    $eventWhere = "(e.organizer_id = ? OR ";
    $orParts    = [];
    if ($myOrgId)  { $orParts[] = "e.org_id = ?";  $params[] = $myOrgId; }
    if ($myClubId) { $orParts[] = "e.club_id = ?"; $params[] = $myClubId; }
    $eventWhere .= implode(' OR ', $orParts) . ")";
}
$statWhere  = str_replace('e.', '', $eventWhere);
$statParams = $params;

// ── SECTION 5 — AUTO-REJECT EXPIRED PENDING EVENTS ───────────
try {
    $expiredStmt = $pdo->prepare("
        SELECT event_id FROM events
        WHERE organizer_id = ? AND status = 'pending'
          AND end_datetime < NOW() AND deleted_at IS NULL
    ");
    $expiredStmt->execute([$uid]);
    $expiredIds = $expiredStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($expiredIds)) {
        $ph = implode(',', array_fill(0, count($expiredIds), '?'));
        $pdo->prepare("UPDATE events SET status = 'rejected' WHERE event_id IN ($ph)")->execute($expiredIds);
        $autoRejectStmt = $pdo->prepare("
            INSERT INTO event_approvals (event_id, admin_id, approval_status, remarks)
            VALUES (?, ?, 'rejected', 'Auto-rejected: Event ended without approval.')
            ON DUPLICATE KEY UPDATE
                approval_status = 'rejected',
                remarks         = 'Auto-rejected: Event ended without approval.',
                approved_at     = NOW()
        ");
        foreach ($expiredIds as $expId) { $autoRejectStmt->execute([$expId, $uid]); }
    }
} catch (Exception $e) { /* non-critical */ }

// ── SECTION 6 — ORG/CLUB LOGO & NAME ─────────────────────────
$orgName = 'Organization'; $orgType = 'Organization';
$hasOrgLogo = false; $orgData = null; $orgMime = 'image/jpeg';
try {
    $orgQ = $pdo->prepare("
        SELECT o.org_name, o.logo, 'Organization' as type
        FROM users u LEFT JOIN organizations o ON u.org_id = o.org_id
        WHERE u.user_id = ? AND o.org_id IS NOT NULL
    ");
    $orgQ->execute([$uid]);
    $orgData = $orgQ->fetch(PDO::FETCH_ASSOC);
    if ($orgData) {
        $orgName = htmlspecialchars($orgData['org_name']);
        $orgType = $orgData['type'];
        $hasOrgLogo = !empty($orgData['logo']);
    } else {
        $clubQ = $pdo->prepare("
            SELECT c.club_name as org_name, c.logo, 'Club' as type
            FROM users u LEFT JOIN clubs c ON u.club_id = c.club_id
            WHERE u.user_id = ? AND c.club_id IS NOT NULL
        ");
        $clubQ->execute([$uid]);
        $orgData = $clubQ->fetch(PDO::FETCH_ASSOC);
        if ($orgData) {
            $orgName = htmlspecialchars($orgData['org_name']);
            $orgType = $orgData['type'];
            $hasOrgLogo = !empty($orgData['logo']);
        }
    }
    if ($hasOrgLogo && !empty($orgData['logo'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $det   = finfo_buffer($finfo, $orgData['logo']);
        if ($det && strpos($det, 'image/') === 0) $orgMime = $det;
    }
} catch (Exception $e) {}

// ── SECTION 7 — ORGANIZER CONTEXT ────────────────────────────
$organizerRow = ['dept_id' => null, 'org_id' => null, 'club_id' => null];
$uCtx = $pdo->prepare("SELECT dept_id, org_id, club_id FROM users WHERE user_id = ?");
$uCtx->execute([$uid]);
$row = $uCtx->fetch(PDO::FETCH_ASSOC);
if ($row) $organizerRow = $row;
$organizerOrgId  = !empty($organizerRow['org_id'])  ? (int)$organizerRow['org_id']  : null;
$organizerClubId = !empty($organizerRow['club_id']) ? (int)$organizerRow['club_id'] : null;

// ── SECTION 8 — ORGANIZER TYPE & RESTRICTIONS ────────────────
$orgDeptMap = [
    'Programmers Animators Developers Clan' => 1,
    'Junior Operations Executive Society'   => 3,
    'Youth Mentors Organization'            => 4,
    'Junior Financial Managers Society'     => 2,
];
$freeOrgs = [
    'Supreme Student Government', 'Supreme Students Government',
    'Library Student Council', 'Library Council',
];
$organizerType = 'unknown'; $allowedDeptIds = []; $pageError = '';
$rawOrgName = null; $rawClubName = null;

if ($organizerOrgId) {
    $s = $pdo->prepare("SELECT org_name FROM organizations WHERE org_id = ?");
    $s->execute([$organizerOrgId]); $rawOrgName = $s->fetchColumn();
}
if ($organizerClubId) {
    $s = $pdo->prepare("SELECT club_name FROM clubs WHERE club_id = ?");
    $s->execute([$organizerClubId]); $rawClubName = $s->fetchColumn();
}

if ($organizerClubId && !$organizerOrgId) {
    $organizerType = 'club';
} elseif ($organizerOrgId && $rawOrgName) {
    if (in_array($rawOrgName, $freeOrgs, true)) {
        $organizerType = 'free_org'; $allowedDeptIds = null;
    } elseif (isset($orgDeptMap[$rawOrgName])) {
        $organizerType = 'restricted_org'; $allowedDeptIds = [$orgDeptMap[$rawOrgName]];
    } else {
        $organizerType = 'unknown'; $pageError = 'Your organization is not authorized to create events.';
    }
} else {
    $organizerType = 'unknown'; $pageError = 'You must be assigned to an organization or club to create events.';
}

$canPostAnnouncement = ($organizerType !== 'unknown');
$annVisibility = 'all'; $annVisibilityLabel = 'All Students';
$annVisibilityDesc = 'Visible to every student in the system.';
$annDeptId = null; $annClubId = null; $annOrgId = $organizerOrgId;
$annDeptName = 'your department';

if ($organizerType === 'free_org') {
    $annVisibility = 'all'; $annVisibilityLabel = 'All Students';
    $annVisibilityDesc = 'Visible to every student across all departments.';
} elseif ($organizerType === 'restricted_org' && !empty($allowedDeptIds)) {
    $annVisibility = 'dept'; $annDeptId = $allowedDeptIds[0]; $annVisibilityLabel = 'Department Only';
    try {
        $dStmt = $pdo->prepare("SELECT dept_name FROM departments WHERE dept_id = ?");
        $dStmt->execute([$annDeptId]); $annDeptName = $dStmt->fetchColumn() ?: 'your department';
    } catch (Throwable $e) {}
    $annVisibilityDesc = "Visible only to students in <strong>{$annDeptName}</strong>.";
} elseif ($organizerType === 'club') {
    $annVisibility = 'club'; $annClubId = $organizerClubId; $annOrgId = null;
    $annVisibilityLabel = 'Club Members Only';
    $annVisibilityDesc  = 'Visible only to students who have joined your club.';
}

// ── SECTION 9 — CLUBS TARGET ──────────────────────────────────
$clubsHasOrgIdColumn = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM clubs LIKE 'org_id'");
    $clubsHasOrgIdColumn = (bool)$chk->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$eventTargetClubs = [];
if ($organizerOrgId && $clubsHasOrgIdColumn) {
    $tq = $pdo->prepare("SELECT club_id, club_name FROM clubs WHERE org_id = ? ORDER BY club_name");
    $tq->execute([$organizerOrgId]); $eventTargetClubs = $tq->fetchAll(PDO::FETCH_ASSOC);
} elseif (!$organizerOrgId && $organizerClubId) {
    $tq = $pdo->prepare("SELECT club_id, club_name FROM clubs WHERE club_id = ? LIMIT 1");
    $tq->execute([$organizerClubId]); $eventTargetClubs = $tq->fetchAll(PDO::FETCH_ASSOC);
}

// ── SECTION 10 — PROFILE DATA ─────────────────────────────────
$profileStmt = $pdo->prepare("
    SELECT COALESCE(p.first_name,o.first_name) AS first_name,
           COALESCE(p.last_name,o.last_name)   AS last_name,
           COALESCE(p.middle_name,o.middle_name) AS middle_name,
           COALESCE(p.profile_image,o.profile_image) AS profile_image,
           o.position, d.dept_name
    FROM users u
    LEFT JOIN profiles p ON u.user_id=p.user_id
    LEFT JOIN organizer o ON u.user_id=o.user_id
    LEFT JOIN departments d ON u.dept_id=d.dept_id
    WHERE u.user_id=?
");
$profileStmt->execute([$uid]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
$middleName = !empty($profile['middle_name']) ? ' '.strtoupper(substr($profile['middle_name'],0,1)).'. ' : ' ';
$fullName   = htmlspecialchars(($profile['first_name']??'').$middleName.($profile['last_name']??''));
$initials   = strtoupper(substr($profile['first_name']??'O',0,1).substr($profile['last_name']??'',0,1));
$hasImage   = !empty($profile['profile_image']); $mime = 'image/jpeg';
if ($hasImage) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $det = finfo_buffer($finfo,$profile['profile_image']);
    if ($det && strpos($det,'image/')===0) $mime=$det;
}

// ── SECTION 11 — SIDEBAR BADGE COUNTS ────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $statWhere AND status!='rejected' AND deleted_at IS NULL");
$stmt->execute($statParams); $myEvents = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM registrations r JOIN events e ON r.event_id=e.event_id WHERE $eventWhere AND e.deleted_at IS NULL");
$stmt->execute($params); $registrations = $stmt->fetchColumn();

$annBadgeCount = 0;
try {
    if ($organizerType === 'club') {
        $ab = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE club_id=? AND deleted_at IS NULL");
        $ab->execute([$organizerClubId]);
    } elseif ($organizerOrgId) {
        $ab = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE org_id=? AND deleted_at IS NULL");
        $ab->execute([$organizerOrgId]);
    } else {
        $ab = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE organizer_id=? AND deleted_at IS NULL");
        $ab->execute([$uid]);
    }
    $annBadgeCount = (int)$ab->fetchColumn();
} catch (Throwable $e) {}

// ── SECTION 12 — STAT CARD COUNTS ────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $statWhere AND deleted_at IS NULL");
$stmt->execute($statParams); $totalEvents = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $statWhere AND status='approved' AND end_datetime>=NOW() AND deleted_at IS NULL");
$stmt->execute($statParams); $approvedEvents = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE $statWhere AND status='pending' AND deleted_at IS NULL");
$stmt->execute($statParams); $pendingEvents = $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM events e
    LEFT JOIN event_approvals ea ON e.event_id=ea.event_id
    WHERE $statWhere AND e.deleted_at IS NULL
      AND ((e.status='approved' AND e.end_datetime<NOW())
        OR (e.status='rejected' AND e.end_datetime<NOW() AND ea.remarks LIKE '%Auto-rejected%'))
");
$stmt->execute($statParams); $endedEvents = $stmt->fetchColumn();

// ── SECTION 13 — AJAX: CLUB MEMBER PREVIEW ───────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'club_member_preview') {
    header('Content-Type: application/json; charset=utf-8');
    $allowedClubIds = array_map('intval', array_column($eventTargetClubs,'club_id'));
    $clubId  = (int)($_GET['club_id'] ?? 0);
    $deptCsv = trim((string)($_GET['dept_ids'] ?? ''));
    $deptIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $deptCsv)))));
    if (!$clubId || !in_array($clubId,$allowedClubIds,true)) { echo json_encode(['ok'=>false,'error'=>'Invalid club']); exit; }
    if (empty($deptIds)) { echo json_encode(['ok'=>true,'count'=>0,'students'=>[],'message'=>'Select departments to preview.']); exit; }
    $ph = implode(',', array_fill(0,count($deptIds),'?'));
    $p  = array_merge([$clubId], $deptIds);
    try {
        $st = $pdo->prepare("
            SELECT u.user_id,
                   COALESCE(NULLIF(TRIM(CONCAT(p.first_name,' ',p.last_name)),''),u.email) AS display_name,
                   COALESCE(d.dept_name,'') AS dept_name
            FROM users u
            LEFT JOIN profiles p ON p.user_id=u.user_id
            LEFT JOIN departments d ON d.dept_id=u.dept_id
            WHERE u.role='student' AND u.club_id=? AND u.dept_id IN ($ph)
            ORDER BY p.last_name,p.first_name LIMIT 80
        ");
        $st->execute($p); $students = $st->fetchAll(PDO::FETCH_ASSOC);
        $ct = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role='student' AND u.club_id=? AND u.dept_id IN ($ph)");
        $ct->execute($p);
        echo json_encode(['ok'=>true,'count'=>(int)$ct->fetchColumn(),'students'=>$students]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>'Query failed']); }
    exit;
}

// ── SECTION 14 — POST: CREATE EVENT ──────────────────────────
// Auto-registration fires only when admin APPROVES — NOT here.
$formError = ''; $formSuccess = '';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_event'])) {
    if ($organizerType==='unknown') {
        $formError = $pageError ?: 'You are not authorized to create events.';
    } else {
        $title         = trim($_POST['title'] ?? '');
        $desc          = trim($_POST['description'] ?? '');
        $start         = $_POST['start_datetime'] ?? '';
        $end           = $_POST['end_datetime'] ?? '';
        $venueId       = (int)($_POST['venue_id'] ?? 0);
        $typeId        = (int)($_POST['event_type_id'] ?? 0);
        $requiredDepts = $_POST['required_departments'] ?? [];
        $eventClubId   = (int)($_POST['event_club_id'] ?? 0);
        $isRestricted  = 0;

        if ($organizerType==='restricted_org') {
            $requiredDepts = $allowedDeptIds; $isRestricted = 1;
        } elseif ($organizerType==='club') {
            $requiredDepts = []; $eventClubId = $organizerClubId; $isRestricted = 1;
        } elseif ($organizerType==='free_org') {
            $accessType = $_POST['event_access_type'] ?? 'general';
            if ($accessType==='general') { $requiredDepts=[]; $isRestricted=0; }
            else { $isRestricted=1; }
        }

        $allowedClubIds = array_map('intval', array_column($eventTargetClubs,'club_id'));
        if ($eventClubId>0 && !in_array($eventClubId,$allowedClubIds,true)) $eventClubId=0;

        $deptId = isset($organizerRow['dept_id']) && $organizerRow['dept_id']!==null ? (int)$organizerRow['dept_id'] : null;
        $orgId  = $organizerOrgId;

        if ($title && $start && $end && $venueId && $typeId) {
            if ($eventClubId>0 && empty($requiredDepts) && $organizerType!=='club') {
                $formError = 'When a club is selected, choose at least one required department.';
            }
            if ($formError==='') {
                try {
                    $pdo->beginTransaction();
                    $clubIdForInsert = $eventClubId>0 ? $eventClubId : null;
                    $ins = $pdo->prepare("
                        INSERT INTO events
                            (title,description,event_type_id,venue_id,organizer_id,
                             dept_id,org_id,club_id,start_datetime,end_datetime,is_restricted,status)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')
                    ");
                    $ins->execute([$title,$desc,$typeId,$venueId,$uid,$deptId,$orgId,$clubIdForInsert,$start,$end,$isRestricted]);
                    $eventId = $pdo->lastInsertId();

                    // Save dept links only — NO auto-registration here
                    if (!empty($requiredDepts)) {
                        $deptIns = $pdo->prepare("INSERT INTO event_departments (event_id,dept_id) VALUES (?,?)");
                        foreach ($requiredDepts as $reqDeptId) { $deptIns->execute([$eventId,(int)$reqDeptId]); }
                    }
                    $pdo->commit();
                    $successMsg = urlencode('Event submitted for approval. Students will be auto-registered once an admin approves the event.');
                    header("Location: ".$_SERVER['PHP_SELF']."?success=".$successMsg); exit();
                } catch (Exception $e) { $pdo->rollBack(); $formError='Failed to submit event. '.$e->getMessage(); }
            }
        } else { $formError='Please fill in all required fields.'; }
    }
}
if (!empty($_GET['success'])) $formSuccess = htmlspecialchars(urldecode($_GET['success']));

// ── SECTION 15 — POST: SOFT-DELETE EVENT ─────────────────────
// Also wipes registrations so re-archive doesn't leave stale records.
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_event'])) {
    $deleteId = (int)($_POST['delete_event_id'] ?? 0);
    if ($deleteId>0) {
        try {
            $ownerCheck = $pdo->prepare("
                SELECT e.organizer_id, u.org_id, u.club_id
                FROM events e JOIN users u ON e.organizer_id=u.user_id
                WHERE e.event_id=? AND e.deleted_at IS NULL
            ");
            $ownerCheck->execute([$deleteId]);
            $eventOwner = $ownerCheck->fetch(PDO::FETCH_ASSOC);
            $canDelete = false;
            if ($eventOwner) {
                if ((int)$eventOwner['organizer_id']===$uid) $canDelete=true;
                elseif ($myOrgId  && !empty($eventOwner['org_id'])  && (int)$eventOwner['org_id']===$myOrgId)  $canDelete=true;
                elseif ($myClubId && !empty($eventOwner['club_id']) && (int)$eventOwner['club_id']===$myClubId) $canDelete=true;
            }
            if ($canDelete) {
                $pdo->beginTransaction();
                // Wipe registrations so they can be re-created cleanly on restore
                $pdo->prepare("DELETE FROM registrations WHERE event_id=?")->execute([$deleteId]);
                $pdo->prepare("UPDATE events SET deleted_at=NOW(), deleted_by=? WHERE event_id=?")->execute([$uid,$deleteId]);
                $pdo->commit();
                header("Location: ".$_SERVER['PHP_SELF']."?deleted=1"); exit();
            } else { $formError='Not authorised to delete this event.'; }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $formError='Failed to archive event. '.$e->getMessage();
        }
    }
}

// ── SECTION 15b — POST: RESTORE EVENT ────────────────────────
// Blocks restore if archived by admin.
// On successful restore, re-runs auto-registration.
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['restore_event'])) {
    $restoreId = (int)($_POST['restore_event_id'] ?? 0);
    if ($restoreId>0) {
        // Check who archived it
        $roleChk = $pdo->prepare("
            SELECT u.role FROM users u
            JOIN events e ON e.deleted_by=u.user_id
            WHERE e.event_id=? AND e.deleted_at IS NOT NULL
        ");
        $roleChk->execute([$restoreId]);
        $deleterRole = $roleChk->fetchColumn();

        if ($deleterRole==='admin') {
            header("Location: ".$_SERVER['PHP_SELF']."?restore_blocked=1"); exit();
        }

        $chk = $pdo->prepare("SELECT organizer_id, org_id, club_id, status, is_restricted FROM events WHERE event_id=? AND deleted_at IS NOT NULL");
        $chk->execute([$restoreId]);
        $evRow = $chk->fetch(PDO::FETCH_ASSOC);
        $ok = $evRow && (
            (int)$evRow['organizer_id']===$uid ||
            ($myOrgId  && (int)$evRow['org_id']===$myOrgId) ||
            ($myClubId && (int)$evRow['club_id']===$myClubId)
        );

        if ($ok) {
            try {
                $pdo->beginTransaction();

                // Restore the event
                $pdo->prepare("UPDATE events SET deleted_at=NULL, deleted_by=NULL WHERE event_id=?")->execute([$restoreId]);

                // Re-run auto-registration only if event is approved
                if ($evRow['status']==='approved') {
                    // Re-fetch club_id from events row
                    $evDetail = $pdo->prepare("SELECT club_id, is_restricted FROM events WHERE event_id=?");
                    $evDetail->execute([$restoreId]);
                    $ev = $evDetail->fetch(PDO::FETCH_ASSOC);

                    if (!empty($ev['club_id'])) {
                        // Club-only event
                        $pdo->prepare("
                            INSERT IGNORE INTO registrations (event_id, user_id, registered_at)
                            SELECT ?, u.user_id, NOW()
                            FROM users u
                            WHERE u.club_id=? AND u.role='student'
                        ")->execute([$restoreId, (int)$ev['club_id']]);

                    } elseif (!empty($ev['is_restricted'])) {
                        // Dept-restricted event
                        $deptStmt = $pdo->prepare("SELECT dept_id FROM event_departments WHERE event_id=?");
                        $deptStmt->execute([$restoreId]);
                        $deptIds = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($deptIds as $dId) {
                            $pdo->prepare("
                                INSERT IGNORE INTO registrations (event_id, user_id, registered_at)
                                SELECT ?, u.user_id, NOW()
                                FROM users u
                                WHERE u.dept_id=? AND u.role='student'
                            ")->execute([$restoreId, (int)$dId]);
                        }
                    }
                    // General events: voluntary — no auto-reg
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        }
        header("Location: ".$_SERVER['PHP_SELF']."?restored_event=1"); exit();
    }
}

// ── SECTION 15c — POST: PERMANENTLY DELETE EVENT ─────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['perm_delete_event'])) {
    $permId = (int)($_POST['perm_delete_event_id'] ?? 0);
    if ($permId>0) {
        try {
            $pdo->beginTransaction();
            $chk = $pdo->prepare("SELECT organizer_id, org_id, club_id FROM events WHERE event_id=? AND deleted_at IS NOT NULL");
            $chk->execute([$permId]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            $ok  = $row && (
                (int)$row['organizer_id']===$uid ||
                ($myOrgId  && (int)$row['org_id']===$myOrgId) ||
                ($myClubId && (int)$row['club_id']===$myClubId)
            );
            if ($ok) {
                foreach (['event_departments','registrations','attendance','event_approvals'] as $t) {
                    $pdo->prepare("DELETE FROM $t WHERE event_id=?")->execute([$permId]);
                }
                $pdo->prepare("DELETE FROM events WHERE event_id=?")->execute([$permId]);
                $pdo->commit();
            } else { $pdo->rollBack(); }
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); }
        header("Location: ".$_SERVER['PHP_SELF']."?perm_deleted_event=1"); exit();
    }
}

// ── SECTION 16 — FETCH: LIVE EVENTS ──────────────────────────
$evQ = $pdo->prepare("
    SELECT e.event_id, e.title, e.description, e.status,
           e.start_datetime, e.end_datetime, e.organizer_id,
           COALESCE(creator_o.first_name,creator_p.first_name) AS creator_first_name,
           COALESCE(creator_o.last_name, creator_p.last_name)  AS creator_last_name,
           v.venue_name, v.capacity AS max_capacity, et.type_name,
           COUNT(DISTINCT r.reg_id)       AS reg_count,
           COUNT(DISTINCT a.attendance_id) AS attend_count,
           GROUP_CONCAT(DISTINCT d.dept_name SEPARATOR ', ') AS required_departments,
           COALESCE(ea.remarks,'')          AS admin_remarks,
           COALESCE(ea.approved_by_name,'') AS approved_by_name,
           COALESCE(ea.approved_at,'')      AS approved_at
    FROM events e
    LEFT JOIN venues       v   ON e.venue_id      = v.venue_id
    LEFT JOIN event_types  et  ON e.event_type_id = et.type_id
    LEFT JOIN registrations r  ON e.event_id      = r.event_id
    LEFT JOIN attendance    a  ON e.event_id      = a.event_id
    LEFT JOIN event_departments ed ON e.event_id  = ed.event_id
    LEFT JOIN departments       d  ON ed.dept_id  = d.dept_id
    LEFT JOIN organizer  creator_o ON e.organizer_id = creator_o.user_id
    LEFT JOIN profiles   creator_p ON e.organizer_id = creator_p.user_id
    LEFT JOIN (
        SELECT ea.event_id, ea.remarks,
               CONCAT(p.first_name,' ',p.last_name) AS approved_by_name, ea.approved_at
        FROM event_approvals ea LEFT JOIN profiles p ON ea.admin_id=p.user_id
        WHERE ea.approval_id IN (SELECT MAX(approval_id) FROM event_approvals GROUP BY event_id)
    ) ea ON e.event_id = ea.event_id
    WHERE $eventWhere AND e.deleted_at IS NULL
      AND NOT (e.status='approved' AND e.end_datetime < DATE_SUB(NOW(), INTERVAL 30 DAY))
    GROUP BY e.event_id, e.organizer_id,
             creator_o.first_name, creator_o.last_name,
             creator_p.first_name, creator_p.last_name,
             v.capacity, ea.remarks, ea.approved_by_name, ea.approved_at
    ORDER BY e.created_at DESC
");
$evQ->execute($params);
$events = $evQ->fetchAll(PDO::FETCH_ASSOC);

// ── SECTION 17 — DROPDOWN DATA ───────────────────────────────
$venues     = $pdo->query("SELECT venue_id,venue_name,capacity FROM venues ORDER BY venue_name")->fetchAll(PDO::FETCH_ASSOC);
$eventTypes = $pdo->query("SELECT type_id,type_name FROM event_types ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);
$departments= $pdo->query("SELECT dept_id,dept_name FROM departments ORDER BY dept_name")->fetchAll(PDO::FETCH_ASSOC);
if ($organizerType==='restricted_org' && is_array($allowedDeptIds)) {
    $departments = array_values(array_filter($departments, fn($d)=>in_array((int)$d['dept_id'],$allowedDeptIds,true)));
}

// ── SECTION 18 — POST: CREATE ANNOUNCEMENT ───────────────────
$annFormError = ''; $annFormSuccess = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_announcement'])) {
    if (!$canPostAnnouncement) {
        $annFormError = 'You are not authorized to post announcements.';
    } else {
        $annTitle = trim($_POST['ann_title'] ?? '');
        $annBody  = trim($_POST['ann_body']  ?? '');
        if ($annTitle==='' || $annBody==='') {
            $annFormError = 'Title and body are required.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO announcements (title,body,organizer_id,org_id,club_id,dept_id,visibility,is_pinned)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([$annTitle,$annBody,$uid,$annOrgId,$annClubId,$annDeptId,$annVisibility,isset($_POST['ann_pinned'])?1:0]);
                header("Location: ".$_SERVER['PHP_SELF']."?ann_success=1#announcements"); exit();
            } catch (Exception $e) { $annFormError='Failed to post announcement. '.$e->getMessage(); }
        }
    }
}
if (!empty($_GET['ann_success'])) $annFormSuccess = 'Announcement posted successfully!';

// ── SECTION 19 — POST: SOFT-DELETE ANNOUNCEMENT ──────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_announcement'])) {
    $delAnnId = (int)($_POST['delete_ann_id'] ?? 0);
    if ($delAnnId>0) {
        try {
            $s = $pdo->prepare("SELECT organizer_id,org_id,club_id FROM announcements WHERE announcement_id=? AND deleted_at IS NULL");
            $s->execute([$delAnnId]); $annOwner = $s->fetch(PDO::FETCH_ASSOC);
            $canDelAnn = false;
            if ($annOwner) {
                if ((int)$annOwner['organizer_id']===$uid) $canDelAnn=true;
                elseif ($myOrgId  && !empty($annOwner['org_id'])  && (int)$annOwner['org_id']===$myOrgId)   $canDelAnn=true;
                elseif ($myClubId && !empty($annOwner['club_id']) && (int)$annOwner['club_id']===$myClubId)  $canDelAnn=true;
            }
            if ($canDelAnn) {
                $pdo->prepare("UPDATE announcements SET deleted_at=NOW(), deleted_by=? WHERE announcement_id=?")->execute([$uid,$delAnnId]);
                header("Location: ".$_SERVER['PHP_SELF']."?ann_deleted=1#announcements"); exit();
            } else { $annFormError='Not authorised to delete this announcement.'; }
        } catch (Exception $e) { $annFormError='Failed to archive announcement. '.$e->getMessage(); }
    }
}
$annDeleted = !empty($_GET['ann_deleted']);

// ── SECTION 19b — POST: RESTORE ANNOUNCEMENT ─────────────────
// Blocks restore if archived by admin.
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['restore_announcement'])) {
    $restoreAnnId = (int)($_POST['restore_ann_id'] ?? 0);
    if ($restoreAnnId>0) {
        $roleChk = $pdo->prepare("
            SELECT u.role FROM users u
            JOIN announcements a ON a.deleted_by=u.user_id
            WHERE a.announcement_id=? AND a.deleted_at IS NOT NULL
        ");
        $roleChk->execute([$restoreAnnId]);
        $deleterRole = $roleChk->fetchColumn();
        if ($deleterRole==='admin') {
            header("Location: ".$_SERVER['PHP_SELF']."?restore_blocked=1#archive"); exit();
        }
        $chk = $pdo->prepare("SELECT organizer_id,org_id,club_id FROM announcements WHERE announcement_id=? AND deleted_at IS NOT NULL");
        $chk->execute([$restoreAnnId]);
        $r = $chk->fetch(PDO::FETCH_ASSOC);
        $ok = $r && (
            (int)$r['organizer_id']===$uid ||
            ($myOrgId  && (int)$r['org_id']===$myOrgId) ||
            ($myClubId && (int)$r['club_id']===$myClubId)
        );
        if ($ok) {
            $pdo->prepare("UPDATE announcements SET deleted_at=NULL, deleted_by=NULL WHERE announcement_id=?")->execute([$restoreAnnId]);
        }
        header("Location: ".$_SERVER['PHP_SELF']."?restored_ann=1#archive"); exit();
    }
}

// ── SECTION 19c — POST: PERMANENTLY DELETE ANNOUNCEMENT ──────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['perm_delete_announcement'])) {
    $permAnnId = (int)($_POST['perm_ann_id'] ?? 0);
    if ($permAnnId>0) {
        $chk = $pdo->prepare("SELECT organizer_id,org_id,club_id FROM announcements WHERE announcement_id=? AND deleted_at IS NOT NULL");
        $chk->execute([$permAnnId]);
        $r = $chk->fetch(PDO::FETCH_ASSOC);
        $ok = $r && (
            (int)$r['organizer_id']===$uid ||
            ($myOrgId  && (int)$r['org_id']===$myOrgId) ||
            ($myClubId && (int)$r['club_id']===$myClubId)
        );
        if ($ok) {
            $pdo->prepare("DELETE FROM announcements WHERE announcement_id=? AND deleted_at IS NOT NULL")->execute([$permAnnId]);
        }
        header("Location: ".$_SERVER['PHP_SELF']."?perm_deleted_ann=1#archive"); exit();
    }
}

// ── SECTION 20 — FETCH: LIVE ANNOUNCEMENTS ───────────────────
$announcements = [];
$annFetchWhere = ''; $annFetchParams = [];
try {
    if ($organizerType==='club' && $organizerClubId) {
        $annFetchWhere='a.club_id=?'; $annFetchParams=[$organizerClubId];
    } elseif ($organizerOrgId) {
        $annFetchWhere='a.org_id=?'; $annFetchParams=[$organizerOrgId];
    } else {
        $annFetchWhere='a.organizer_id=?'; $annFetchParams=[$uid];
    }
    $annQ = $pdo->prepare("
        SELECT a.announcement_id,a.title,a.body,a.visibility,a.is_pinned,
               a.created_at,a.updated_at,a.organizer_id,a.dept_id,a.club_id,a.org_id,
               COALESCE(o_tbl.first_name,p_tbl.first_name) AS poster_first,
               COALESCE(o_tbl.last_name, p_tbl.last_name)  AS poster_last,
               d.dept_name, c.club_name, org.org_name
        FROM announcements a
        LEFT JOIN organizer    o_tbl ON a.organizer_id=o_tbl.user_id
        LEFT JOIN profiles     p_tbl ON a.organizer_id=p_tbl.user_id
        LEFT JOIN departments  d     ON a.dept_id=d.dept_id
        LEFT JOIN clubs        c     ON a.club_id=c.club_id
        LEFT JOIN organizations org  ON a.org_id=org.org_id
        WHERE $annFetchWhere AND a.deleted_at IS NULL
        ORDER BY a.is_pinned DESC, a.created_at DESC
    ");
    $annQ->execute($annFetchParams);
    $announcements = $annQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $announcements=[]; }

// ── SECTION 21 — FETCH: ARCHIVED EVENTS & ANNOUNCEMENTS ──────
$archivedEvents = [];
try {
    $archEvQ = $pdo->prepare("
        SELECT e.event_id, e.title, e.status, e.start_datetime, e.end_datetime,
               e.deleted_at, e.organizer_id, e.club_id,
               COALESCE(del_o.first_name, del_p.first_name,'') AS deleter_first,
               COALESCE(del_o.last_name,  del_p.last_name, '') AS deleter_last,
               del_u.role                                       AS deleter_role,
               v.venue_name, et.type_name,
               COUNT(DISTINCT r.reg_id) AS reg_count
        FROM events e
        LEFT JOIN venues       v   ON e.venue_id      = v.venue_id
        LEFT JOIN event_types  et  ON e.event_type_id = et.type_id
        LEFT JOIN registrations r  ON e.event_id      = r.event_id
        LEFT JOIN users    del_u   ON e.deleted_by    = del_u.user_id
        LEFT JOIN organizer del_o  ON e.deleted_by    = del_o.user_id
        LEFT JOIN profiles  del_p  ON e.deleted_by    = del_p.user_id
        WHERE $eventWhere AND e.deleted_at IS NOT NULL
        GROUP BY e.event_id, v.venue_name, et.type_name, del_u.role,
                 del_o.first_name, del_o.last_name, del_p.first_name, del_p.last_name
        ORDER BY e.deleted_at DESC
    ");
    $archEvQ->execute($params);
    $archivedEvents = $archEvQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $archivedEvents=[]; }

$archivedAnnouncements = [];
try {
    if ($canPostAnnouncement && $annFetchWhere) {
        $archAnnQ = $pdo->prepare("
            SELECT a.announcement_id, a.title, a.body, a.visibility,
                   a.deleted_at, a.organizer_id,
                   COALESCE(del_o.first_name, del_p.first_name,'') AS deleter_first,
                   COALESCE(del_o.last_name,  del_p.last_name, '') AS deleter_last,
                   del_u.role                                       AS deleter_role,
                   d.dept_name, c.club_name
            FROM announcements a
            LEFT JOIN departments  d     ON a.dept_id    = d.dept_id
            LEFT JOIN clubs        c     ON a.club_id    = c.club_id
            LEFT JOIN users    del_u     ON a.deleted_by = del_u.user_id
            LEFT JOIN organizer del_o    ON a.deleted_by = del_o.user_id
            LEFT JOIN profiles  del_p    ON a.deleted_by = del_p.user_id
            WHERE $annFetchWhere AND a.deleted_at IS NOT NULL
            ORDER BY a.deleted_at DESC
        ");
        $archAnnQ->execute($annFetchParams);
        $archivedAnnouncements = $archAnnQ->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { $archivedAnnouncements=[]; }

$restoredEvent  = !empty($_GET['restored_event']);
$restoredAnn    = !empty($_GET['restored_ann']);
$permDelEvent   = !empty($_GET['perm_deleted_event']);
$permDelAnn     = !empty($_GET['perm_deleted_ann']);
$restoreBlocked = !empty($_GET['restore_blocked']);
$totalArchived  = count($archivedEvents) + count($archivedAnnouncements);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Events – SEMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="/CSS/organizer_event.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {
                fontFamily: { sans: ['Poppins','sans-serif'] },
                colors: { brand: { 50:'#f0fdf4',100:'#dcfce7',200:'#bbf7d0',300:'#86efac',400:'#4ade80',500:'#22c55e',600:'#16a34a',700:'#15803d',800:'#166534',900:'#14532d' } }
            }}
        }
    </script>
</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 font-sans transition-colors duration-300"
    x-data="{
        sidebarOpen: false,
        showCreate:  <?= $formError ? 'true' : 'false' ?>,
        showDetails: false, showDelete: false,
        selectedEvt: null, deleteId: null, deleteTitle: '',
        activeTab: 'all',
        showAnnCreate:  <?= $annFormError ? 'true' : 'false' ?>,
        showAnnDelete:  false, deleteAnnId: null, deleteAnnTitle: '',
        showAnnView: false, selectedAnn: null,
        showArchive:     false,
        archiveTab:      'events',
        showPermDelEvt:  false, permDelEvtId: null, permDelEvtTitle: '',
        showPermDelAnn:  false, permDelAnnId: null, permDelAnnTitle: ''
    }">

    <div id="sb-overlay" onclick="closeSidebar()"></div>

    <!-- ═══════ SIDEBAR ═══════════════════════════════════════ -->
    <aside id="sidebar" class="fixed top-0 left-0 h-screen w-64 z-50 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col transition-transform duration-300 -translate-x-full lg:translate-x-0">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <?php if ($hasOrgLogo): ?>
                    <img src="data:<?= $orgMime ?>;base64,<?= base64_encode($orgData['logo']) ?>" class="w-12 h-12 rounded-xl object-cover ring-2 ring-brand-200 dark:ring-brand-800 flex-shrink-0" alt="<?= $orgName ?>">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-xl bg-brand-500 flex items-center justify-center flex-shrink-0"><i class="fas fa-building text-white text-lg"></i></div>
                <?php endif; ?>
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 dark:text-white text-sm leading-tight break-words"><?= $orgName ?></p>
                    <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300"><?= $orgType ?></span>
                </div>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto p-3 space-y-1">
            <p class="text-[10px] uppercase tracking-widest text-gray-400 px-3 pt-2 pb-1 font-semibold">Overview</p>
            <a href="/organizer/organizer_panel.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm"><i class="fas fa-gauge-high"></i></span>Dashboard
            </a>
            <p class="text-[10px] uppercase tracking-widest text-gray-400 px-3 pt-4 pb-1 font-semibold">Events</p>
            <a href="/organizer/organizer_event.php" class="nav-link active flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-brand-100 dark:bg-brand-900/40 text-brand-600 dark:text-brand-400 flex items-center justify-center text-sm"><i class="fas fa-clipboard-list"></i></span>
                <span class="flex-1">Events  & Announcements</span>
                <?php if ($myEvents>0): ?><span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?= $myEvents ?></span><?php endif; ?>
            </a>
            <a href="/organizer/organizer_qrscan.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm"><i class="fas fa-qrcode"></i></span>QR Scanner
            </a>
            
            <p class="text-[10px] uppercase tracking-widest text-gray-400 px-3 pt-4 pb-1 font-semibold">Tracking</p>
            <a href="/organizer/organizer_tracking.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-sky-100 dark:bg-sky-900/40 text-sky-600 dark:text-sky-400 flex items-center justify-center text-sm"><i class="fas fa-users"></i></span>
                <span class="flex-1">Registrations</span>
                <?php if ($registrations>0): ?><span class="text-xs bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-400 px-2 py-0.5 rounded-full font-semibold"><?= $registrations ?></span><?php endif; ?>
            </a>
            <a href="/organizer/organizer_attendance.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                <span class="icon-wrap w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm"><i class="fas fa-user-check"></i></span>Attendance
            </a>
            <a href="/organizer/organizer_analytics.php" class="nav-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
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

    <!-- ═══════ MAIN WRAPPER ═══════════════════════════════════ -->
    <div class="lg:ml-64 min-h-screen flex flex-col">

        <!-- TOP HEADER -->
        <header class="sticky top-0 z-30 bg-white/90 dark:bg-gray-800/90 border-b border-gray-200 dark:border-gray-700 px-4 sm:px-6 py-3" style="backdrop-filter:blur(10px);">
            <div class="flex items-center gap-3">
                <button onclick="openSidebar()" class="lg:hidden p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"><i class="fas fa-bars"></i></button>
                <span class="hidden sm:block text-lg font-semibold text-gray-900 dark:text-white">My Events</span>
                <div class="flex-1 mx-2 sm:mx-4 max-w-xs sm:max-w-sm">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        <input type="text" id="searchInput" onkeyup="filterEvents(this.value)" placeholder="Search events…"
                            class="w-full pl-9 pr-4 py-2 text-sm rounded-lg bg-gray-100 dark:bg-gray-700 border border-transparent focus:border-brand-400 text-gray-700 dark:text-gray-200 placeholder-gray-400 outline-none transition-colors">
                    </div>
                </div>
                <div class="flex items-center gap-2 ml-auto">
                    <button onclick="toggleTheme()" class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-amber-400 hover:rotate-12 transition-all"><i id="themeIcon" class="fas fa-moon text-sm"></i></button>
                    <?php if ($canPostAnnouncement): ?>
                        <button @click="showAnnCreate=true" class="hidden sm:flex items-center gap-2 px-4 py-2 rounded-lg bg-orange-500 hover:bg-orange-600 text-white text-sm font-semibold shadow shadow-orange-400/30 transition-all active:scale-95"><i class="fas fa-bullhorn"></i> Announce</button>
                    <?php endif; ?>
                    <?php if ($organizerType!=='unknown'): ?>
                        <button @click="showCreate=true" class="hidden sm:flex items-center gap-2 px-4 py-2 rounded-lg bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold shadow shadow-brand-400/30 transition-all active:scale-95"><i class="fas fa-plus"></i> New Event</button>
                    <?php endif; ?>
                    <div class="flex items-center gap-2.5 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700">
                        <div class="hidden sm:block text-right leading-tight">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= $fullName ?></p>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($profile['position'] ?? ($profile['dept_name'] ?? 'Organizer')) ?></p>
                        </div>
                        <div class="w-9 h-9 rounded-full overflow-hidden bg-gradient-to-br from-brand-400 to-blue-500 flex items-center justify-center text-white text-xs font-bold ring-2 ring-brand-200 dark:ring-brand-700 cursor-pointer">
                            <?php if ($hasImage): ?><img src="data:<?= $mime ?>;base64,<?= base64_encode($profile['profile_image']) ?>" class="w-full h-full object-cover" alt="Profile"><?php else: ?><?= $initials ?><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- MAIN CONTENT -->
        <main class="flex-1 p-4 sm:p-6 space-y-6 max-w-7xl mx-auto w-full">

            <div class="anim-up d-0 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">My Events</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Manage events for <span class="text-brand-600 dark:text-brand-400 font-medium"><?= $orgName ?></span></p>
                </div>
                <div class="flex gap-2 flex-wrap self-start">
                    <?php if ($canPostAnnouncement): ?>
                        <button @click="showAnnCreate=true" class="sm:hidden inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white text-sm font-semibold shadow shadow-orange-400/30 transition-all active:scale-95"><i class="fas fa-bullhorn"></i> Announce</button>
                    <?php endif; ?>
                    <?php if ($organizerType!=='unknown'): ?>
                        <button @click="showCreate=true" class="sm:hidden inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold shadow shadow-brand-400/30 transition-all active:scale-95"><i class="fas fa-plus"></i> New Event</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ALERTS -->
            <?php if ($formSuccess): ?>
                <div id="success-alert" class="anim-up d-0 bg-brand-50 dark:bg-brand-900/20 border border-brand-300 dark:border-brand-700 rounded-xl overflow-hidden">
                    <div class="flex items-center gap-3 px-4 py-3 text-brand-700 dark:text-brand-300 text-sm">
                        <i class="fas fa-circle-check text-brand-500 flex-shrink-0"></i>
                        <span class="flex-1"><?= $formSuccess ?></span>
                        <span class="text-xs text-brand-400 whitespace-nowrap"><i class="fas fa-times-circle mr-1"></i>Closing in <span id="dismiss-countdown">3</span>s…</span>
                        <button onclick="cancelAutoDismiss()" class="text-brand-400 hover:text-brand-600 ml-1"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="h-1 bg-brand-200 dark:bg-brand-800"><div id="dismiss-bar" class="h-full bg-brand-500 reload-progress"></div></div>
                </div>
            <?php elseif ($formError): ?>
                <div class="anim-up d-0 flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded-xl">
                    <i class="fas fa-circle-exclamation text-red-500 flex-shrink-0"></i><span class="flex-1"><?= $formError ?></span>
                    <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="anim-up d-0 flex items-center gap-3 bg-slate-50 dark:bg-slate-900/20 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-sm px-4 py-3 rounded-xl">
                    <i class="fas fa-box-archive text-slate-500 flex-shrink-0"></i>
                    <span class="flex-1">Event moved to archive. Registrations cleared — they will be re-created if restored.</span>
                    <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                </div>
            <?php endif; ?>

            <?php if ($restoreBlocked): ?>
                <div class="anim-up d-0 flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded-xl">
                    <i class="fas fa-shield-halved text-red-500 flex-shrink-0"></i>
                    <span class="flex-1">This item was archived by an admin and cannot be restored by organizers.</span>
                    <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                </div>
            <?php endif; ?>

            <?php if ($pageError): ?>
                <div class="anim-up d-0 flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded-xl">
                    <i class="fas fa-circle-exclamation text-red-500 flex-shrink-0"></i><span class="flex-1"><?= htmlspecialchars($pageError) ?></span>
                </div>
            <?php endif; ?>

            <!-- STAT CARDS -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 anim-up d-1">
                <?php
                $stats = [
                    ['label'=>'Approved','value'=>$approvedEvents,'icon'=>'fa-calendar-check','bg'=>'bg-brand-100 dark:bg-brand-900/30','ic'=>'text-brand-600 dark:text-brand-400','bar'=>'bg-brand-400','tab'=>'approved'],
                    ['label'=>'Pending', 'value'=>$pendingEvents, 'icon'=>'fa-hourglass-half','bg'=>'bg-amber-100 dark:bg-amber-900/30','ic'=>'text-amber-600 dark:text-amber-400','bar'=>'bg-amber-400','tab'=>'pending'],
                    ['label'=>'Ended',   'value'=>$endedEvents,   'icon'=>'fa-flag-checkered','bg'=>'bg-rose-100 dark:bg-rose-900/30', 'ic'=>'text-rose-600 dark:text-rose-400', 'bar'=>'bg-rose-400', 'tab'=>'ended'],
                    ['label'=>'Total',   'value'=>$totalEvents,   'icon'=>'fa-layer-group',   'bg'=>'bg-blue-100 dark:bg-blue-900/30', 'ic'=>'text-blue-600 dark:text-blue-400', 'bar'=>'bg-blue-400', 'tab'=>'all'],
                ];
                foreach ($stats as $s): ?>
                    <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-200 dark:border-gray-700 cursor-pointer" onclick="setTab('<?= $s['tab'] ?>')">
                        <div class="flex items-start justify-between mb-4">
                            <span class="icon-wrap w-11 h-11 rounded-xl <?= $s['bg'] ?> <?= $s['ic'] ?> flex items-center justify-center text-lg"><i class="fas <?= $s['icon'] ?>"></i></span>
                        </div>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $s['value'] ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 font-medium"><?= $s['label'] ?> Events</p>
                        <div class="mt-3 h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                            <div class="h-full rounded-full <?= $s['bar'] ?> transition-all duration-700" style="width:<?= $totalEvents>0 ? round(($s['value']/$totalEvents)*100) : 0 ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- TAB BAR -->
            <?php if (!empty($events)): ?>
                <div class="anim-up d-2 flex flex-wrap gap-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl p-1.5 w-fit shadow-sm">
                    <?php foreach ([['id'=>'all','label'=>'All','icon'=>'fa-layer-group'],['id'=>'pending','label'=>'Pending','icon'=>'fa-hourglass-half'],['id'=>'approved','label'=>'Approved','icon'=>'fa-calendar-check'],['id'=>'ended','label'=>'Ended','icon'=>'fa-flag-checkered']] as $tab): ?>
                        <button id="tab-<?= $tab['id'] ?>" onclick="setTab('<?= $tab['id'] ?>')"
                            class="tab-btn flex items-center gap-2 px-3 py-2 rounded-xl text-sm font-semibold text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition-all">
                            <i class="fas <?= $tab['icon'] ?> text-xs"></i><?= $tab['label'] ?>
                            <span id="count-<?= $tab['id'] ?>" class="text-xs px-1.5 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-500 font-bold min-w-[1.25rem] text-center">0</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- EVENT GRID -->
            <?php if (empty($events)): ?>
                <div class="anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-16 text-center">
                    <i class="fas fa-calendar-plus text-5xl text-gray-300 dark:text-gray-600 mb-4 block"></i>
                    <h3 class="font-semibold text-gray-700 dark:text-gray-300 mb-2">No events yet</h3>
                    <p class="text-sm text-gray-400 mb-6">Create your first event to see it here.</p>
                    <?php if ($organizerType!=='unknown'): ?>
                        <button @click="showCreate=true" class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-500 hover:bg-brand-600 text-white text-sm font-semibold rounded-xl transition-colors active:scale-95"><i class="fas fa-plus"></i> Create Event</button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach (['pending','approved','ended'] as $tId): ?>
                    <div id="empty-<?= $tId ?>" class="hidden anim-up d-2 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-12 text-center">
                        <i class="fas fa-inbox text-3xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400">No <?= $tId ?> events found.</p>
                    </div>
                <?php endforeach; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5" id="eventGrid">
                    <?php foreach ($events as $i => $ev):
                        $isEnded       = $ev['status']==='approved' && strtotime($ev['end_datetime'])<time();
                        $isAutoRej     = $ev['status']==='rejected' && strtotime($ev['end_datetime'])<time() && strpos($ev['admin_remarks'],'Auto-rejected')!==false;
                        $displayStatus = $isEnded||$isAutoRej ? 'ended' : $ev['status'];
                        $fillPct       = isset($ev['max_capacity'])&&$ev['max_capacity']>0 ? min(100,round(($ev['reg_count']/$ev['max_capacity'])*100)) : 0;
                        $reqDepts      = !empty($ev['required_departments']) ? array_map('trim',explode(', ',$ev['required_departments'])) : [];
                        $isRestricted  = !empty($reqDepts)||!empty($ev['club_id']);
                        $isCreator     = (int)$ev['organizer_id']===$uid;
                        $cfg = ['approved'=>['pill'=>'bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400','dot'=>'bg-brand-500','bar'=>'from-brand-400 to-emerald-500'],
                                'pending' =>['pill'=>'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400','dot'=>'bg-amber-400','bar'=>'from-amber-400 to-orange-400'],
                                'rejected'=>['pill'=>'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400','dot'=>'bg-red-500','bar'=>'from-red-400 to-red-500'],
                                'ended'   =>['pill'=>'bg-rose-100 dark:bg-rose-900/30 text-rose-700 dark:text-rose-400','dot'=>'bg-rose-500','bar'=>'from-rose-400 to-pink-500'],
                               ][$displayStatus] ?? ['pill'=>'bg-gray-100 text-gray-600','dot'=>'bg-gray-400','bar'=>'from-gray-400 to-gray-500'];
                    ?>
                        <div class="card-hover anim-up d-<?= min($i,5) ?> bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden filterable-event group"
                            data-title="<?= strtolower(htmlspecialchars($ev['title'])) ?>" data-status="<?= $displayStatus ?>"
                            data-departments="<?= strtolower(htmlspecialchars($ev['required_departments']??'')) ?>"
                            style="animation-delay:<?= $i*70 ?>ms">
                            <div class="h-1.5 bg-gradient-to-r <?= $cfg['bar'] ?>"></div>
                            <div class="p-5 flex flex-col h-full">
                                <div class="flex items-start justify-between gap-2 mb-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap mb-0.5">
                                            <h4 class="font-bold text-gray-900 dark:text-white text-base leading-snug line-clamp-1 group-hover:text-brand-600 dark:group-hover:text-brand-400 transition-colors"><?= htmlspecialchars($ev['title']) ?></h4>
                                            <?php if (!$isCreator): ?><span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 border border-blue-200 dark:border-blue-700 flex-shrink-0"><i class="fas fa-users" style="font-size:.6rem"></i> Team</span><?php endif; ?>
                                            <?php if ($isRestricted): ?><span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 border border-purple-200 dark:border-purple-700 flex-shrink-0"><i class="fas fa-lock" style="font-size:.6rem"></i> Restricted</span>
                                            <?php else: ?><span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-700 flex-shrink-0"><i class="fas fa-globe" style="font-size:.6rem"></i> General</span><?php endif; ?>
                                        </div>
                                        <p class="text-xs text-gray-400 flex items-center gap-1.5"><i class="fas fa-tag text-blue-400 text-[10px]"></i><?= htmlspecialchars($ev['type_name']??'General') ?></p>
                                    </div>
                                    <span class="flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full capitalize flex-shrink-0 <?= $cfg['pill'] ?>"><span class="w-1.5 h-1.5 rounded-full <?= $cfg['dot'] ?>"></span><?= $displayStatus ?></span>
                                </div>
                                <?php if (!empty($reqDepts)): ?>
                                    <div class="flex flex-wrap gap-1.5 mb-3">
                                        <span class="text-[10px] text-purple-500 dark:text-purple-400 font-semibold flex items-center gap-0.5"><i class="fas fa-building text-[9px]"></i> Dept:</span>
                                        <?php foreach (array_slice($reqDepts,0,2) as $dept): ?><span class="text-[10px] font-medium px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 border border-purple-200 dark:border-purple-700"><?= htmlspecialchars($dept) ?></span><?php endforeach; ?>
                                        <?php if (count($reqDepts)>2): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-500">+<?= count($reqDepts)-2 ?> more</span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-700/50 rounded-xl p-3 mb-3 border border-gray-100 dark:border-gray-600/50">
                                    <span class="w-9 h-9 rounded-lg bg-white dark:bg-gray-600 shadow-sm flex items-center justify-center text-brand-500 flex-shrink-0"><i class="fas fa-calendar-day text-sm"></i></span>
                                    <div>
                                        <p class="text-xs font-semibold text-gray-900 dark:text-white"><?= date('F j, Y',strtotime($ev['start_datetime'])) ?></p>
                                        <p class="text-[11px] text-gray-400"><?= date('g:i A',strtotime($ev['start_datetime'])) ?> &ndash; <?= date('g:i A',strtotime($ev['end_datetime'])) ?></p>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-400 flex items-center gap-1.5 mb-4"><i class="fas fa-location-dot text-red-400 text-[10px]"></i><?= htmlspecialchars($ev['venue_name']??'No venue') ?></p>
                                <div class="grid grid-cols-3 gap-2 py-3 mb-4 border-t border-b border-gray-100 dark:border-gray-700">
                                    <div class="text-center"><p class="text-base font-bold text-gray-900 dark:text-white"><?= $ev['reg_count'] ?></p><p class="text-[10px] text-gray-400">Registered</p></div>
                                    <div class="text-center border-x border-gray-100 dark:border-gray-700"><p class="text-base font-bold text-brand-500"><?= $ev['attend_count'] ?></p><p class="text-[10px] text-gray-400">Attended</p></div>
                                    <div class="text-center"><p class="text-base font-bold text-blue-500"><?= $ev['max_capacity']??'N/A' ?></p><p class="text-[10px] text-gray-400">Capacity</p></div>
                                </div>
                                <div class="mb-4">
                                    <div class="flex justify-between text-[11px] mb-1.5 text-gray-500"><span>Filled</span><span class="font-semibold text-gray-700 dark:text-gray-200"><?= $fillPct ?>%</span></div>
                                    <div class="h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden"><div class="h-full rounded-full bg-gradient-to-r <?= $cfg['bar'] ?> transition-all duration-700" style="width:<?= $fillPct ?>%"></div></div>
                                </div>
                                <div class="flex gap-2 mt-auto">
                                    <button @click="selectedEvt = <?= htmlspecialchars(json_encode(array_merge($ev,['display_status'=>$displayStatus,'is_creator'=>$isCreator]))) ?>; showDetails=true"
                                        class="flex-1 py-2 text-xs font-semibold rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-1.5 active:scale-95">
                                        <i class="far fa-eye text-blue-400"></i> Details
                                    </button>
                                    <?php if ($ev['status']==='approved' && !$isEnded): ?>
                                        <a href="/organizer/organizer_qrscan.php?event=<?= $ev['event_id'] ?>"
                                            class="px-3 py-2 text-xs font-semibold rounded-xl bg-brand-50 dark:bg-brand-900/20 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-800 hover:bg-brand-500 hover:text-white transition-all active:scale-95 flex items-center gap-1">
                                            <i class="fas fa-qrcode"></i><span class="hidden sm:inline">QR</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($isCreator): ?>
                                        <button @click="deleteId=<?= $ev['event_id'] ?>; deleteTitle=<?= htmlspecialchars(json_encode($ev['title'])) ?>; showDelete=true"
                                            class="px-3 py-2 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-900/20 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700 hover:bg-slate-500 hover:text-white transition-all active:scale-95 flex items-center gap-1" title="Archive">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>


            <!-- ═══════ ANNOUNCEMENTS SECTION ══════════════════ -->
            <?php if ($canPostAnnouncement): ?>
            <section id="announcements" class="scroll-mt-20 space-y-4 anim-up d-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <span class="w-9 h-9 rounded-xl bg-orange-100 dark:bg-orange-900/30 text-orange-500 flex items-center justify-center"><i class="fas fa-bullhorn text-sm"></i></span>Announcements
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                            <?= match($annVisibility){
                                'all' =>'Visible to <strong class="text-orange-600 dark:text-orange-400">all students</strong>',
                                'dept'=>'Visible to <strong class="text-purple-600 dark:text-purple-400">'.htmlspecialchars($annDeptName).'</strong> only',
                                'club'=>'Visible to <strong class="text-purple-600 dark:text-purple-400">club members only</strong>',
                                default=>''
                            } ?>
                        </p>
                    </div>
                    <button @click="showAnnCreate=true" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-orange-500 hover:bg-orange-600 text-white text-sm font-semibold shadow shadow-orange-400/30 transition-all active:scale-95 self-start sm:self-auto flex-shrink-0"><i class="fas fa-plus"></i> New Announcement</button>
                </div>

                <?php if ($annFormSuccess): ?>
                    <div class="flex items-center gap-3 bg-orange-50 dark:bg-orange-900/20 border border-orange-300 dark:border-orange-700 text-orange-700 dark:text-orange-300 text-sm px-4 py-3 rounded-xl">
                        <i class="fas fa-circle-check text-orange-500 flex-shrink-0"></i><span class="flex-1"><?= htmlspecialchars($annFormSuccess) ?></span>
                        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                <?php if ($annDeleted): ?>
                    <div class="flex items-center gap-3 bg-slate-50 dark:bg-slate-900/20 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 text-sm px-4 py-3 rounded-xl">
                        <i class="fas fa-box-archive text-slate-500 flex-shrink-0"></i>
                        <span class="flex-1">Announcement moved to archive. You can restore it from the Archive section below.</span>
                        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                <?php if ($annFormError): ?>
                    <div class="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded-xl">
                        <i class="fas fa-circle-exclamation text-red-500 flex-shrink-0"></i><span class="flex-1"><?= htmlspecialchars($annFormError) ?></span>
                        <button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if (empty($announcements)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-12 text-center">
                        <i class="fas fa-bullhorn text-4xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                        <h4 class="font-semibold text-gray-700 dark:text-gray-300 mb-1">No announcements yet</h4>
                        <p class="text-sm text-gray-400 mb-5">Keep your students informed by posting your first announcement.</p>
                        <button @click="showAnnCreate=true" class="inline-flex items-center gap-2 px-5 py-2.5 bg-orange-500 hover:bg-orange-600 text-white text-sm font-semibold rounded-xl transition-colors active:scale-95"><i class="fas fa-plus"></i> Post Announcement</button>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                        <?php foreach ($announcements as $j => $ann):
                            $isPinned   = (bool)$ann['is_pinned'];
                            $isAnnOwner = (int)$ann['organizer_id']===$uid;
                            $posterName = trim(($ann['poster_first']??'').' '.($ann['poster_last']??'')) ?: 'Unknown';
                            $annDate    = date('M j, Y · g:i A',strtotime($ann['created_at']));
                            $bodyPrev   = mb_strimwidth(strip_tags($ann['body']),0,120,'…');
                            $visBadge   = match($ann['visibility']){
                                'all' =>['text'=>'All Students','cls'=>'bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border-brand-200 dark:border-brand-700','icon'=>'fa-globe'],
                                'dept'=>['text'=>htmlspecialchars($ann['dept_name']??'Dept'),'cls'=>'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 border-purple-200 dark:border-purple-700','icon'=>'fa-building'],
                                'club'=>['text'=>htmlspecialchars($ann['club_name']??'Club'),'cls'=>'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 border-indigo-200 dark:border-indigo-700','icon'=>'fa-users'],
                                default=>['text'=>'Unknown','cls'=>'bg-gray-100 text-gray-500','icon'=>'fa-question'],
                            };
                        ?>
                            <div class="card-hover bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden group <?= $isPinned?'ring-2 ring-orange-300 dark:ring-orange-700':'' ?>" style="animation-delay:<?= $j*60 ?>ms">
                                <div class="h-1 bg-gradient-to-r from-orange-400 to-amber-400"></div>
                                <div class="p-4 flex flex-col h-full">
                                    <div class="flex items-start justify-between gap-2 mb-2">
                                        <div class="flex-1 min-w-0">
                                            <?php if ($isPinned): ?><span class="inline-flex items-center gap-1 text-[10px] font-bold text-orange-500 mb-1"><i class="fas fa-thumbtack text-[9px]"></i> Pinned</span><?php endif; ?>
                                            <h5 class="font-bold text-gray-900 dark:text-white text-sm leading-snug line-clamp-2 group-hover:text-orange-600 dark:group-hover:text-orange-400 transition-colors"><?= htmlspecialchars($ann['title']) ?></h5>
                                        </div>
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full border flex-shrink-0 <?= $visBadge['cls'] ?>"><i class="fas <?= $visBadge['icon'] ?>" style="font-size:.6rem"></i><?= $visBadge['text'] ?></span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-3 mb-3 flex-1 leading-relaxed"><?= htmlspecialchars($bodyPrev) ?></p>
                                    <div class="flex items-center gap-2 text-[10px] text-gray-400 mb-3">
                                        <i class="fas fa-user text-[9px]"></i><span><?= htmlspecialchars($posterName) ?></span><span class="mx-1">·</span><i class="fas fa-clock text-[9px]"></i><span><?= $annDate ?></span>
                                    </div>
                                    <div class="flex gap-2 mt-auto">
                                        <button @click="selectedAnn=<?= htmlspecialchars(json_encode($ann)) ?>; showAnnView=true"
                                            class="flex-1 py-1.5 text-xs font-semibold rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-1.5 active:scale-95">
                                            <i class="far fa-eye text-blue-400"></i> Read
                                        </button>
                                        <?php if ($isAnnOwner||($myOrgId&&$ann['org_id']==$myOrgId)||($myClubId&&$ann['club_id']==$myClubId)): ?>
                                            <button @click="deleteAnnId=<?= $ann['announcement_id'] ?>; deleteAnnTitle=<?= htmlspecialchars(json_encode($ann['title'])) ?>; showAnnDelete=true"
                                                class="px-3 py-1.5 text-xs font-semibold rounded-xl bg-slate-50 dark:bg-slate-900/20 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700 hover:bg-slate-500 hover:text-white transition-all active:scale-95 flex items-center gap-1" title="Archive">
                                                <i class="fas fa-box-archive"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>


            <!-- ═══════ ARCHIVE SECTION ════════════════════════ -->
            <?php if ($totalArchived>0||$restoredEvent||$restoredAnn||$permDelEvent||$permDelAnn||$restoreBlocked): ?>
            <section id="archive" class="scroll-mt-20 space-y-4 anim-up d-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <span class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 flex items-center justify-center"><i class="fas fa-box-archive text-sm"></i></span>
                            Archive
                            <?php if ($totalArchived>0): ?><span class="text-xs font-bold px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500"><?= $totalArchived ?></span><?php endif; ?>
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Soft-deleted items — restore or permanently remove them.</p>
                    </div>
                    <button @click="showArchive=!showArchive"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 transition-all self-start sm:self-auto flex-shrink-0">
                        <i class="fas fa-chevron-down transition-transform duration-200" :class="showArchive?'rotate-180':''"></i>
                        <span x-text="showArchive?'Collapse':'View Archive'"></span>
                    </button>
                </div>

                <?php if ($restoredEvent): ?>
                    <div class="flex items-center gap-3 bg-brand-50 dark:bg-brand-900/20 border border-brand-300 dark:border-brand-700 text-brand-700 dark:text-brand-300 text-sm px-4 py-3 rounded-xl">
                        <i class="fas fa-rotate-left text-brand-500 flex-shrink-0"></i><span class="flex-1">Event restored and students re-registered successfully.</span><button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                <?php if ($restoredAnn): ?>
                    <div class="flex items-center gap-3 bg-orange-50 dark:bg-orange-900/20 border border-orange-300 dark:border-orange-700 text-orange-700 dark:text-orange-300 text-sm px-4 py-3 rounded-xl">
                        <i class="fas fa-rotate-left text-orange-500 flex-shrink-0"></i><span class="flex-1">Announcement restored successfully and is now visible.</span><button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>
                <?php if ($permDelEvent||$permDelAnn): ?>
                    <div class="flex items-center gap-3 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 text-sm px-4 py-3 rounded-xl">
                        <i class="fas fa-trash text-red-500 flex-shrink-0"></i><span class="flex-1">Item permanently deleted and cannot be recovered.</span><button onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <div x-show="showArchive" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-cloak>

                    <!-- Archive tab bar -->
                    <div class="flex gap-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl p-1.5 w-fit shadow-sm mb-4">
                        <button @click="archiveTab='events'"
                            :class="archiveTab==='events'?'bg-slate-700 dark:bg-slate-600 text-white shadow':'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700'"
                            class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition-all">
                            <i class="fas fa-calendar-xmark text-xs"></i> Events
                            <span class="text-xs px-1.5 py-0.5 rounded-full bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300 font-bold"><?= count($archivedEvents) ?></span>
                        </button>
                        <?php if ($canPostAnnouncement): ?>
                        <button @click="archiveTab='announcements'"
                            :class="archiveTab==='announcements'?'bg-slate-700 dark:bg-slate-600 text-white shadow':'text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700'"
                            class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold transition-all">
                            <i class="fas fa-bullhorn text-xs"></i> Announcements
                            <span class="text-xs px-1.5 py-0.5 rounded-full bg-slate-200 dark:bg-slate-600 text-slate-600 dark:text-slate-300 font-bold"><?= count($archivedAnnouncements) ?></span>
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Archived Events -->
                    <div x-show="archiveTab==='events'">
                        <?php if (empty($archivedEvents)): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-12 text-center">
                                <i class="fas fa-box-open text-4xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                                <p class="text-sm text-gray-500 dark:text-gray-400">No archived events.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                                <?php foreach ($archivedEvents as $archEv):
                                    $archDelName    = trim($archEv['deleter_first'].' '.$archEv['deleter_last']);
                                    $archivedByAdmin = ($archEv['deleter_role']==='admin');
                                ?>
                                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 overflow-hidden opacity-75 hover:opacity-100 transition-opacity">
                                        <div class="h-1 bg-gradient-to-r from-slate-400 to-slate-500"></div>
                                        <div class="p-5">
                                            <div class="flex items-start justify-between gap-2 mb-3">
                                                <h5 class="font-bold text-gray-600 dark:text-gray-400 text-sm line-clamp-2 flex-1"><?= htmlspecialchars($archEv['title']) ?></h5>
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full flex-shrink-0 bg-slate-100 dark:bg-slate-700 text-slate-500 capitalize"><?= htmlspecialchars($archEv['status']) ?></span>
                                            </div>
                                            <p class="text-xs text-gray-400 flex items-center gap-1.5 mb-1"><i class="fas fa-tag text-blue-300 text-[10px]"></i><?= htmlspecialchars($archEv['type_name']??'—') ?></p>
                                            <p class="text-xs text-gray-400 flex items-center gap-1.5 mb-1"><i class="fas fa-location-dot text-red-300 text-[10px]"></i><?= htmlspecialchars($archEv['venue_name']??'—') ?></p>
                                            <p class="text-xs text-gray-400 flex items-center gap-1.5 mb-3"><i class="fas fa-calendar-day text-slate-400 text-[10px]"></i><?= date('M j, Y',strtotime($archEv['start_datetime'])) ?> &mdash; <?= date('M j, Y',strtotime($archEv['end_datetime'])) ?></p>

                                            <!-- Archived by badge — red if admin, slate if organizer -->
                                            <?php if ($archivedByAdmin): ?>
                                                <div class="flex items-center gap-1.5 text-[10px] text-red-500 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2 mb-4 border border-red-200 dark:border-red-800">
                                                    <i class="fas fa-shield-halved text-[9px]"></i>
                                                    Archived by Admin <?= date('M j, Y',strtotime($archEv['deleted_at'])) ?>
                                                    <?php if($archDelName): ?> — <strong><?= htmlspecialchars($archDelName) ?></strong><?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex items-center gap-1.5 text-[10px] text-slate-500 bg-slate-50 dark:bg-slate-700/50 rounded-lg px-3 py-2 mb-4 border border-slate-200 dark:border-slate-600">
                                                    <i class="fas fa-box-archive text-[9px]"></i>
                                                    Archived <?= date('M j, Y · g:i A',strtotime($archEv['deleted_at'])) ?>
                                                    <?php if($archDelName): ?> by <strong class="ml-1"><?= htmlspecialchars($archDelName) ?></strong><?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="flex items-center gap-3 text-xs text-gray-400 mb-4">
                                                <span><i class="fas fa-users text-[10px] mr-1"></i><?= $archEv['reg_count'] ?> registered</span>
                                            </div>

                                            <!-- Actions: no Restore if archived by admin -->
                                            <div class="flex gap-2">
                                                <?php if ($archivedByAdmin): ?>
                                                    <div class="flex-1 py-2 text-xs font-semibold rounded-xl bg-red-50 dark:bg-red-900/20 text-red-500 dark:text-red-400 border border-red-200 dark:border-red-700 flex items-center justify-center gap-1.5 cursor-not-allowed">
                                                        <i class="fas fa-shield-halved"></i> Archived by Admin
                                                    </div>
                                                <?php else: ?>
                                                    <form method="POST" class="flex-1">
                                                        <input type="hidden" name="restore_event_id" value="<?= $archEv['event_id'] ?>">
                                                        <button type="submit" name="restore_event"
                                                            class="w-full py-2 text-xs font-semibold rounded-xl bg-brand-50 dark:bg-brand-900/20 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-700 hover:bg-brand-500 hover:text-white transition-all active:scale-95 flex items-center justify-center gap-1.5">
                                                            <i class="fas fa-rotate-left"></i> Restore
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <button
                                                    @click="permDelEvtId=<?= $archEv['event_id'] ?>; permDelEvtTitle=<?= htmlspecialchars(json_encode($archEv['title'])) ?>; showPermDelEvt=true"
                                                    <?= $archivedByAdmin ? 'disabled title="Cannot permanently delete admin-archived events"' : 'title="Permanently Delete"' ?>
                                                    class="px-3 py-2 text-xs font-semibold rounded-xl bg-red-50 dark:bg-red-900/20 text-red-500 dark:text-red-400 border border-red-200 dark:border-red-800 hover:bg-red-500 hover:text-white transition-all active:scale-95 flex items-center gap-1 <?= $archivedByAdmin?'opacity-40 cursor-not-allowed':'' ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Archived Announcements -->
                    <?php if ($canPostAnnouncement): ?>
                    <div x-show="archiveTab==='announcements'">
                        <?php if (empty($archivedAnnouncements)): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-300 dark:border-gray-600 p-12 text-center">
                                <i class="fas fa-box-open text-4xl text-gray-300 dark:text-gray-600 mb-3 block"></i>
                                <p class="text-sm text-gray-500 dark:text-gray-400">No archived announcements.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                                <?php foreach ($archivedAnnouncements as $archAnn):
                                    $annDelName2  = trim($archAnn['deleter_first'].' '.$archAnn['deleter_last']);
                                    $annByAdmin   = ($archAnn['deleter_role']==='admin');
                                    $archVisBadge = match($archAnn['visibility']){
                                        'all' =>['text'=>'All Students','cls'=>'bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400'],
                                        'dept'=>['text'=>htmlspecialchars($archAnn['dept_name']??'Dept'),'cls'=>'bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400'],
                                        'club'=>['text'=>htmlspecialchars($archAnn['club_name']??'Club'),'cls'=>'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400'],
                                        default=>['text'=>'Unknown','cls'=>'bg-gray-100 text-gray-500'],
                                    };
                                ?>
                                    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 overflow-hidden opacity-75 hover:opacity-100 transition-opacity">
                                        <div class="h-1 bg-gradient-to-r from-orange-300 to-amber-300 opacity-50"></div>
                                        <div class="p-4">
                                            <div class="flex items-start justify-between gap-2 mb-2">
                                                <h5 class="font-bold text-gray-600 dark:text-gray-400 text-sm line-clamp-2 flex-1"><?= htmlspecialchars($archAnn['title']) ?></h5>
                                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full flex-shrink-0 <?= $archVisBadge['cls'] ?>"><?= $archVisBadge['text'] ?></span>
                                            </div>
                                            <p class="text-xs text-gray-400 line-clamp-2 mb-3 leading-relaxed"><?= htmlspecialchars(mb_strimwidth(strip_tags($archAnn['body']),0,100,'…')) ?></p>

                                            <?php if ($annByAdmin): ?>
                                                <div class="flex items-center gap-1.5 text-[10px] text-red-500 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2 mb-4 border border-red-200 dark:border-red-800">
                                                    <i class="fas fa-shield-halved text-[9px]"></i>
                                                    Archived by Admin <?= date('M j, Y',strtotime($archAnn['deleted_at'])) ?>
                                                    <?php if($annDelName2): ?> — <strong><?= htmlspecialchars($annDelName2) ?></strong><?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex items-center gap-1.5 text-[10px] text-slate-500 bg-slate-50 dark:bg-slate-700/50 rounded-lg px-3 py-2 mb-4 border border-slate-200 dark:border-slate-600">
                                                    <i class="fas fa-box-archive text-[9px]"></i>
                                                    Archived <?= date('M j, Y · g:i A',strtotime($archAnn['deleted_at'])) ?>
                                                    <?php if($annDelName2): ?> by <strong class="ml-1"><?= htmlspecialchars($annDelName2) ?></strong><?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="flex gap-2">
                                                <?php if ($annByAdmin): ?>
                                                    <div class="flex-1 py-2 text-xs font-semibold rounded-xl bg-red-50 dark:bg-red-900/20 text-red-500 dark:text-red-400 border border-red-200 dark:border-red-700 flex items-center justify-center gap-1.5 cursor-not-allowed">
                                                        <i class="fas fa-shield-halved"></i> Archived by Admin
                                                    </div>
                                                <?php else: ?>
                                                    <form method="POST" class="flex-1">
                                                        <input type="hidden" name="restore_ann_id" value="<?= $archAnn['announcement_id'] ?>">
                                                        <button type="submit" name="restore_announcement"
                                                            class="w-full py-2 text-xs font-semibold rounded-xl bg-orange-50 dark:bg-orange-900/20 text-orange-600 dark:text-orange-400 border border-orange-200 dark:border-orange-700 hover:bg-orange-500 hover:text-white transition-all active:scale-95 flex items-center justify-center gap-1.5">
                                                            <i class="fas fa-rotate-left"></i> Restore
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <button
                                                    @click="permDelAnnId=<?= $archAnn['announcement_id'] ?>; permDelAnnTitle=<?= htmlspecialchars(json_encode($archAnn['title'])) ?>; showPermDelAnn=true"
                                                    <?= $annByAdmin ? 'disabled title="Cannot permanently delete admin-archived announcements"' : 'title="Permanently Delete"' ?>
                                                    class="px-3 py-2 text-xs font-semibold rounded-xl bg-red-50 dark:bg-red-900/20 text-red-500 dark:text-red-400 border border-red-200 dark:border-red-800 hover:bg-red-500 hover:text-white transition-all active:scale-95 flex items-center gap-1 <?= $annByAdmin?'opacity-40 cursor-not-allowed':'' ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div><!-- /showArchive -->
            </section>
            <?php endif; ?>

        </main>
    </div>


    <!-- ═══════ CREATE EVENT MODAL ═════════════════════════════ -->
    <div x-show="showCreate" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" style="backdrop-filter:blur(6px)">
        <div @click.away="showCreate=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-2xl border border-gray-200 dark:border-gray-700 shadow-2xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <div>
                    <h3 class="font-bold text-gray-900 dark:text-white flex items-center gap-2"><i class="fas fa-calendar-plus text-brand-500"></i> Create New Event</h3>
                    <p class="text-xs text-gray-400 mt-0.5">Submit for admin approval · Students auto-registered on approval</p>
                </div>
                <button @click="showCreate=false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors flex items-center justify-center"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="overflow-y-auto flex-1 p-6">
                <form method="POST" class="space-y-5">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-heading text-blue-400 mr-1"></i> Event Title <span class="text-red-400">*</span></label>
                        <input type="text" name="title" required placeholder="Enter a descriptive title" class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none text-gray-800 dark:text-white placeholder-gray-400 transition-colors">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-play text-brand-500 mr-1"></i> Start <span class="text-red-400">*</span></label>
                            <input type="datetime-local" name="start_datetime" required class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none text-gray-800 dark:text-white transition-colors">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-stop text-red-400 mr-1"></i> End <span class="text-red-400">*</span></label>
                            <input type="datetime-local" name="end_datetime" required class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none text-gray-800 dark:text-white transition-colors">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-location-dot text-red-400 mr-1"></i> Venue <span class="text-red-400">*</span></label>
                            <select name="venue_id" required class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none text-gray-800 dark:text-white transition-colors appearance-none cursor-pointer">
                                <option value="">Select venue…</option>
                                <?php foreach ($venues as $v): ?><option value="<?= $v['venue_id'] ?>"><?= htmlspecialchars($v['venue_name']) ?> (Cap: <?= $v['capacity']??'N/A' ?>)</option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-tag text-blue-400 mr-1"></i> Event Type <span class="text-red-400">*</span></label>
                            <select name="event_type_id" required class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none text-gray-800 dark:text-white transition-colors appearance-none cursor-pointer">
                                <option value="">Select type…</option>
                                <?php foreach ($eventTypes as $t): ?><option value="<?= $t['type_id'] ?>"><?= htmlspecialchars($t['type_name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ($organizerType==='club'): ?>
                        <div class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-xl p-4">
                            <p class="text-sm font-semibold text-purple-700 dark:text-purple-300 flex items-center gap-2"><i class="fas fa-lock"></i> Club-Only Event</p>
                            <p class="text-xs text-purple-600 dark:text-purple-400 mt-1">All club members will be auto-registered <strong>once an admin approves the event</strong>.</p>
                        </div>
                    <?php elseif ($organizerType==='restricted_org' && count($departments)===1): ?>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-building text-purple-400 mr-1"></i> Required Department</label>
                            <div class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-100 dark:bg-gray-600 border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 flex items-center gap-2">
                                <i class="fas fa-check-circle text-brand-500"></i><strong><?= htmlspecialchars($departments[0]['dept_name']) ?></strong><span class="text-xs text-gray-400 ml-auto">(auto-required)</span>
                            </div>
                            <input type="hidden" name="required_departments[]" value="<?= $departments[0]['dept_id'] ?>">
                            <p class="text-[11px] text-purple-500 dark:text-purple-400 mt-1.5 flex items-start gap-1"><i class="fas fa-bolt mt-0.5 flex-shrink-0"></i>Students will be <strong>auto-registered once approved</strong>.</p>
                        </div>
                    <?php else: ?>
                        <div x-data="{ accessType: 'general' }">
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2 uppercase tracking-wide"><i class="fas fa-users-gear text-blue-400 mr-1"></i> Event Access <span class="text-red-400">*</span></label>
                            <div class="flex gap-2 mb-3">
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="event_access_type" value="general" x-model="accessType" class="sr-only" checked>
                                    <span :class="accessType==='general'?'bg-brand-500 text-white border-brand-500 shadow shadow-brand-300/30':'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-600 hover:border-brand-300'"
                                          class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl text-sm font-semibold border transition-all select-none"><i class="fas fa-globe text-sm"></i> General</span>
                                </label>
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" name="event_access_type" value="required" x-model="accessType" class="sr-only">
                                    <span :class="accessType==='required'?'bg-purple-500 text-white border-purple-500 shadow shadow-purple-300/30':'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 border-gray-200 dark:border-gray-600 hover:border-purple-300'"
                                          class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl text-sm font-semibold border transition-all select-none"><i class="fas fa-lock text-sm"></i> Required</span>
                                </label>
                            </div>
                            <div x-show="accessType==='general'" x-transition class="bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-700 rounded-xl p-3">
                                <p class="text-xs text-brand-700 dark:text-brand-300 flex items-start gap-2"><i class="fas fa-globe mt-0.5 flex-shrink-0 text-brand-500"></i><span><strong>Open to all students.</strong> Students register voluntarily. No auto-registration.</span></p>
                            </div>
                            <div x-show="accessType==='required'" x-cloak x-transition>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 mt-1 uppercase tracking-wide"><i class="fas fa-building text-purple-400 mr-1"></i> Required Departments <span class="text-gray-400 font-normal normal-case text-[10px] ml-1">(Ctrl/Cmd for multiple)</span></label>
                                <select name="required_departments[]" multiple class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-purple-400 focus:outline-none text-gray-800 dark:text-white transition-colors cursor-pointer">
                                    <?php foreach ($departments as $dept): ?><option value="<?= $dept['dept_id'] ?>"><?= htmlspecialchars($dept['dept_name']) ?></option><?php endforeach; ?>
                                </select>
                                <p class="text-[11px] text-purple-500 dark:text-purple-400 mt-1.5 flex items-start gap-1"><i class="fas fa-bolt mt-0.5 flex-shrink-0"></i>Students will be <strong>auto-registered once the event is approved</strong>.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-align-left text-gray-400 mr-1"></i> Description</label>
                        <textarea name="description" rows="3" placeholder="Event details for attendees…" class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-brand-400 focus:outline-none text-gray-800 dark:text-white placeholder-gray-400 resize-none transition-colors"></textarea>
                    </div>
                    <div class="flex items-start gap-2.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-3 text-xs text-blue-600 dark:text-blue-400">
                        <i class="fas fa-circle-info mt-0.5 flex-shrink-0"></i>
                        <span>Event will be <strong>Pending</strong> until admin review. Auto-registration only happens <strong>after approval</strong>.</span>
                    </div>
                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="showCreate=false" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-2"><i class="fas fa-times text-red-400"></i> Cancel</button>
                        <button type="submit" name="submit_event" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-brand-500 hover:bg-brand-600 text-white shadow shadow-brand-400/30 transition-all active:scale-95 flex items-center justify-center gap-2"><i class="fas fa-paper-plane"></i> Submit for Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- ═══════ CREATE ANNOUNCEMENT MODAL ══════════════════════ -->
    <div x-show="showAnnCreate" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" style="backdrop-filter:blur(6px)">
        <div @click.away="showAnnCreate=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-xl border border-gray-200 dark:border-gray-700 shadow-2xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <div><h3 class="font-bold text-gray-900 dark:text-white flex items-center gap-2"><i class="fas fa-bullhorn text-orange-500"></i> New Announcement</h3><p class="text-xs text-gray-400 mt-0.5">Posted immediately — no approval needed</p></div>
                <button @click="showAnnCreate=false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors flex items-center justify-center"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="overflow-y-auto flex-1 p-6">
                <form method="POST" class="space-y-5">
                    <?php
                    $abc = match($annVisibility){'all'=>'bg-brand-50 dark:bg-brand-900/20 border-brand-200 dark:border-brand-700 text-brand-700 dark:text-brand-300','dept'=>'bg-purple-50 dark:bg-purple-900/20 border-purple-200 dark:border-purple-700 text-purple-700 dark:text-purple-300','club'=>'bg-indigo-50 dark:bg-indigo-900/20 border-indigo-200 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300',default=>'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 text-gray-600'};
                    $abi = match($annVisibility){'all'=>'fa-globe','dept'=>'fa-building','club'=>'fa-users',default=>'fa-info-circle'};
                    ?>
                    <div class="flex items-start gap-3 border rounded-xl p-3.5 text-sm <?= $abc ?>">
                        <i class="fas <?= $abi ?> mt-0.5 flex-shrink-0"></i>
                        <div><p class="font-semibold">Audience: <?= $annVisibilityLabel ?></p><p class="text-xs mt-0.5 opacity-80"><?= $annVisibilityDesc ?></p></div>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-heading text-orange-400 mr-1"></i> Title <span class="text-red-400">*</span></label>
                        <input type="text" name="ann_title" required placeholder="e.g. Reminder: General Assembly on Friday" value="<?= htmlspecialchars($_POST['ann_title']??'') ?>" class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-orange-400 focus:outline-none text-gray-800 dark:text-white placeholder-gray-400 transition-colors">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5 uppercase tracking-wide"><i class="fas fa-align-left text-gray-400 mr-1"></i> Message <span class="text-red-400">*</span></label>
                        <textarea name="ann_body" rows="5" required placeholder="Write the full announcement message here…" class="w-full px-4 py-2.5 rounded-xl text-sm bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 focus:border-orange-400 focus:outline-none text-gray-800 dark:text-white placeholder-gray-400 resize-none transition-colors"><?= htmlspecialchars($_POST['ann_body']??'') ?></textarea>
                    </div>
                    <label class="flex items-center gap-3 cursor-pointer select-none group">
                        <input type="checkbox" name="ann_pinned" value="1" class="w-4 h-4 rounded text-orange-500 border-gray-300 focus:ring-orange-400 cursor-pointer" <?= isset($_POST['ann_pinned'])?'checked':'' ?>>
                        <span class="text-sm text-gray-700 dark:text-gray-300 group-hover:text-orange-600 transition-colors"><i class="fas fa-thumbtack text-orange-400 mr-1"></i>Pin this announcement (appears first in the list)</span>
                    </label>
                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="showAnnCreate=false" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-2"><i class="fas fa-times text-red-400"></i> Cancel</button>
                        <button type="submit" name="submit_announcement" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-orange-500 hover:bg-orange-600 text-white shadow shadow-orange-400/30 transition-all active:scale-95 flex items-center justify-center gap-2"><i class="fas fa-bullhorn"></i> Post Announcement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- ═══════ VIEW ANNOUNCEMENT MODAL ════════════════════════ -->
    <div x-show="showAnnView" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" style="backdrop-filter:blur(6px)">
        <div @click.away="showAnnView=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg border border-gray-200 dark:border-gray-700 shadow-2xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="h-1.5 bg-gradient-to-r from-orange-400 to-amber-400 flex-shrink-0"></div>
            <div class="flex items-start justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <div class="flex-1 min-w-0 pr-3">
                    <div x-show="selectedAnn?.is_pinned==1" class="flex items-center gap-1 text-[10px] font-bold text-orange-500 mb-1"><i class="fas fa-thumbtack" style="font-size:.6rem"></i> Pinned</div>
                    <h3 class="font-bold text-gray-900 dark:text-white text-lg leading-snug" x-text="selectedAnn?.title"></h3>
                    <p class="text-xs text-gray-400 mt-1" x-text="(selectedAnn?.poster_first||'') + ' ' + (selectedAnn?.poster_last||'') + ' · ' + (selectedAnn?.created_at ? new Date(selectedAnn.created_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '')"></p>
                </div>
                <button @click="showAnnView=false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors flex items-center justify-center flex-shrink-0"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="overflow-y-auto flex-1 p-6 space-y-4">
                <div class="flex items-center gap-2">
                    <template x-if="selectedAnn?.visibility==='all'"><span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-brand-100 dark:bg-brand-900/30 text-brand-700 dark:text-brand-400 border border-brand-200 dark:border-brand-700"><i class="fas fa-globe text-[10px]"></i> Visible to All Students</span></template>
                    <template x-if="selectedAnn?.visibility==='dept'"><span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 border border-purple-200 dark:border-purple-700"><i class="fas fa-building text-[10px]"></i><span x-text="(selectedAnn?.dept_name||'Department')+' Only'"></span></span></template>
                    <template x-if="selectedAnn?.visibility==='club'"><span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-full bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400 border border-indigo-200 dark:border-indigo-700"><i class="fas fa-users text-[10px]"></i><span x-text="(selectedAnn?.club_name||'Club')+' Members Only'"></span></span></template>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-5 border border-gray-200 dark:border-gray-600 text-sm text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap" x-text="selectedAnn?.body"></div>
                <button @click="showAnnView=false" class="w-full py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-2"><i class="fas fa-times text-red-400"></i> Close</button>
            </div>
        </div>
    </div>


    <!-- ═══════ ARCHIVE EVENT CONFIRM MODAL ════════════════════ -->
    <div x-show="showDelete" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" style="backdrop-filter:blur(6px)">
        <div @click.away="showDelete=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-sm border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <span class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-500 flex items-center justify-center"><i class="fas fa-box-archive"></i></span>
                <h3 class="font-bold text-gray-900 dark:text-white">Archive Event</h3>
                <button @click="showDelete=false" class="ml-auto w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-red-500 flex items-center justify-center"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">Archive <strong class="text-gray-900 dark:text-white" x-text="'«'+deleteTitle+'»'"></strong>?</p>
                <div class="flex items-start gap-2.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-3 text-xs text-amber-700 dark:text-amber-400">
                    <i class="fas fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>
                    Existing <strong>registrations will be cleared</strong>. If you restore the event, students will be <strong>auto-registered again</strong> based on the original rules.
                </div>
                <div class="flex gap-3">
                    <button @click="showDelete=false" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="delete_event_id" :value="deleteId">
                        <button type="submit" name="delete_event" class="w-full py-2.5 rounded-xl text-sm font-semibold bg-slate-600 hover:bg-slate-700 text-white shadow shadow-slate-400/20 transition-all active:scale-95 flex items-center justify-center gap-2"><i class="fas fa-box-archive"></i> Move to Archive</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══════ ARCHIVE ANNOUNCEMENT CONFIRM MODAL ═════════════ -->
    <div x-show="showAnnDelete" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" style="backdrop-filter:blur(6px)">
        <div @click.away="showAnnDelete=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-sm border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <span class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-700 text-slate-500 flex items-center justify-center"><i class="fas fa-box-archive"></i></span>
                <h3 class="font-bold text-gray-900 dark:text-white">Archive Announcement</h3>
                <button @click="showAnnDelete=false" class="ml-auto w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-red-500 flex items-center justify-center"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">Archive <strong class="text-gray-900 dark:text-white" x-text="'«'+deleteAnnTitle+'»'"></strong>?</p>
                <div class="flex items-start gap-2.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-3 text-xs text-blue-600 dark:text-blue-400">
                    <i class="fas fa-circle-info mt-0.5 flex-shrink-0"></i>Hidden from students but <strong>not permanently deleted</strong>. Restorable from Archive.
                </div>
                <div class="flex gap-3">
                    <button @click="showAnnDelete=false" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="delete_ann_id" :value="deleteAnnId">
                        <button type="submit" name="delete_announcement" class="w-full py-2.5 rounded-xl text-sm font-semibold bg-slate-600 hover:bg-slate-700 text-white shadow shadow-slate-400/20 transition-all active:scale-95 flex items-center justify-center gap-2"><i class="fas fa-box-archive"></i> Move to Archive</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══════ PERMANENT DELETE EVENT MODAL ═══════════════════ -->
    <div x-show="showPermDelEvt" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" style="backdrop-filter:blur(6px)">
        <div @click.away="showPermDelEvt=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-sm border border-red-300 dark:border-red-700 shadow-2xl overflow-hidden">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-red-50 dark:bg-red-900/20">
                <span class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/40 text-red-500 flex items-center justify-center"><i class="fas fa-trash"></i></span>
                <h3 class="font-bold text-red-700 dark:text-red-400">Permanent Delete</h3>
                <button @click="showPermDelEvt=false" class="ml-auto w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-red-500 flex items-center justify-center"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">Permanently delete <strong class="text-gray-900 dark:text-white" x-text="'«'+permDelEvtTitle+'»'"></strong>?</p>
                <div class="flex items-start gap-2.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-3 text-xs text-red-600 dark:text-red-400">
                    <i class="fas fa-triangle-exclamation mt-0.5 flex-shrink-0"></i>All registrations, attendance records, and department links will be <strong>irrecoverably wiped</strong>. This cannot be undone.
                </div>
                <div class="flex gap-3">
                    <button @click="showPermDelEvt=false" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="perm_delete_event_id" :value="permDelEvtId">
                        <button type="submit" name="perm_delete_event" class="w-full py-2.5 rounded-xl text-sm font-semibold bg-red-600 hover:bg-red-700 text-white shadow shadow-red-500/20 transition-all active:scale-95 flex items-center justify-center gap-2"><i class="fas fa-trash"></i> Delete Forever</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══════ PERMANENT DELETE ANNOUNCEMENT MODAL ════════════ -->
    <div x-show="showPermDelAnn" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" style="backdrop-filter:blur(6px)">
        <div @click.away="showPermDelAnn=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-sm border border-red-300 dark:border-red-700 shadow-2xl overflow-hidden">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-red-50 dark:bg-red-900/20">
                <span class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/40 text-red-500 flex items-center justify-center"><i class="fas fa-trash"></i></span>
                <h3 class="font-bold text-red-700 dark:text-red-400">Permanent Delete</h3>
                <button @click="showPermDelAnn=false" class="ml-auto w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:text-red-500 flex items-center justify-center"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">Permanently delete <strong class="text-gray-900 dark:text-white" x-text="'«'+permDelAnnTitle+'»'"></strong>? This <span class="text-red-500 font-bold">cannot be undone</span>.</p>
                <div class="flex gap-3">
                    <button @click="showPermDelAnn=false" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="perm_ann_id" :value="permDelAnnId">
                        <button type="submit" name="perm_delete_announcement" class="w-full py-2.5 rounded-xl text-sm font-semibold bg-red-600 hover:bg-red-700 text-white shadow shadow-red-500/20 transition-all active:scale-95 flex items-center justify-center gap-2"><i class="fas fa-trash"></i> Delete Forever</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- ═══════ EVENT DETAILS MODAL ════════════════════════════ -->
    <div x-show="showDetails" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" style="backdrop-filter:blur(6px)">
        <div @click.away="showDetails=false" class="modal-pop bg-white dark:bg-gray-800 rounded-2xl w-full max-w-md border border-gray-200 dark:border-gray-700 shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
            <div class="h-1.5 bg-gradient-to-r from-brand-400 to-blue-500 flex-shrink-0"></div>
            <div class="flex items-start justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                <div class="flex-1 min-w-0 pr-3">
                    <h3 class="font-bold text-gray-900 dark:text-white text-lg leading-snug line-clamp-2" x-text="selectedEvt?.title"></h3>
                    <div class="flex items-center gap-2 flex-wrap mt-1">
                        <span class="text-xs text-gray-400" x-text="selectedEvt?.type_name||'General'"></span>
                        <span x-show="selectedEvt?.required_departments" class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 border border-purple-200 dark:border-purple-700"><i class="fas fa-lock" style="font-size:.6rem"></i> Restricted</span>
                        <span x-show="!selectedEvt?.required_departments" class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full bg-brand-100 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400 border border-brand-200 dark:border-brand-700"><i class="fas fa-globe" style="font-size:.6rem"></i> General</span>
                    </div>
                </div>
                <button @click="showDetails=false" class="w-8 h-8 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-400 hover:bg-red-100 dark:hover:bg-red-900/30 hover:text-red-500 transition-colors flex items-center justify-center flex-shrink-0"><i class="fas fa-times text-sm"></i></button>
            </div>
            <div class="overflow-y-auto flex-1 p-6 space-y-4">
                <div x-show="selectedEvt?.status==='pending'" class="flex items-start gap-2.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-3 text-xs text-amber-700 dark:text-amber-400">
                    <i class="fas fa-hourglass-half mt-0.5 flex-shrink-0"></i>
                    <span>Students will be <strong>auto-registered after admin approval</strong>. No registrations while pending.</span>
                </div>
                <div x-show="selectedEvt?.admin_remarks"
                    :class="{'bg-brand-50 dark:bg-brand-900/20 border-brand-200 dark:border-brand-800 text-brand-700 dark:text-brand-400':selectedEvt?.status==='approved','bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400':selectedEvt?.status==='rejected','bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400':selectedEvt?.status==='pending'}"
                    class="rounded-xl p-4 border text-sm">
                    <p class="font-semibold mb-1 flex items-center gap-1.5"><i class="fas fa-comment-dots"></i><span x-text="selectedEvt?.status==='approved'?'Approval Note':(selectedEvt?.status==='rejected'?'Rejection Reason':'Review Note')"></span></p>
                    <p class="italic" x-text="selectedEvt?.admin_remarks"></p>
                    <p class="text-xs text-gray-400 mt-1" x-text="(selectedEvt?.approved_by_name||'Admin')+(selectedEvt?.approved_at?' · '+formatStandardTime(selectedEvt.approved_at):'')"></p>
                </div>
                <div x-show="selectedEvt?.display_status==='ended'" class="bg-slate-100 dark:bg-slate-700/50 border border-slate-300 dark:border-slate-600 rounded-xl p-4 text-sm">
                    <p class="font-semibold mb-1 flex items-center gap-1.5 text-slate-700 dark:text-slate-300"><i class="fas fa-lock text-slate-500"></i> System Note</p>
                    <p class="italic text-slate-600 dark:text-slate-400">This event has been automatically closed as the scheduled time has ended.</p>
                </div>
                <div x-show="selectedEvt?.required_departments" class="flex items-center gap-2 text-xs text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-xl p-3">
                    <i class="fas fa-lock flex-shrink-0"></i><span>Visible <strong>only</strong> to students in required departments.</span>
                </div>
                <div x-show="!selectedEvt?.required_departments && !selectedEvt?.club_id" class="flex items-center gap-2 text-xs text-brand-600 dark:text-brand-400 bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-700 rounded-xl p-3">
                    <i class="fas fa-globe flex-shrink-0"></i><span>This is a <strong>general event</strong> — open to all students.</span>
                </div>
                <div x-show="selectedEvt?.club_id && !selectedEvt?.required_departments" class="flex items-center gap-2 text-xs text-purple-600 dark:text-purple-400 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-xl p-3">
                    <i class="fas fa-lock flex-shrink-0"></i><span>This event is <strong>restricted to club members only</strong>.</span>
                </div>
                <div x-show="selectedEvt?.required_departments" class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-700 rounded-xl p-4">
                    <p class="text-xs font-semibold text-purple-600 dark:text-purple-400 mb-2 flex items-center gap-1"><i class="fas fa-building"></i> Required Departments</p>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="dept in (selectedEvt?.required_departments||'').split(', ')" :key="dept">
                            <span x-show="dept.trim()" class="text-xs px-2.5 py-1 rounded-full bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-700 font-medium" x-text="dept.trim()"></span>
                        </template>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl border border-gray-200 dark:border-gray-600 divide-y divide-gray-200 dark:divide-gray-600 text-sm overflow-hidden">
                    <div class="flex justify-between items-center px-4 py-3"><span class="text-gray-500 dark:text-gray-400 flex items-center gap-1.5 text-xs"><i class="fas fa-circle-info text-blue-400"></i> Status</span><span class="font-semibold capitalize text-gray-900 dark:text-white" x-text="selectedEvt?.display_status||selectedEvt?.status"></span></div>
                    <div class="flex justify-between items-center px-4 py-3"><span class="text-gray-500 dark:text-gray-400 flex items-center gap-1.5 text-xs"><i class="fas fa-user text-indigo-400"></i> Created by</span><span class="font-medium text-gray-900 dark:text-white" x-text="(selectedEvt?.creator_first_name||'')+' '+(selectedEvt?.creator_last_name||'Unknown')"></span></div>
                    <div class="flex justify-between items-center px-4 py-3"><span class="text-gray-500 dark:text-gray-400 flex items-center gap-1.5 text-xs"><i class="fas fa-location-dot text-red-400"></i> Venue</span><span class="font-medium text-gray-900 dark:text-white" x-text="selectedEvt?.venue_name||'TBD'"></span></div>
                    <div class="flex justify-between items-center px-4 py-3"><span class="text-gray-500 dark:text-gray-400 flex items-center gap-1.5 text-xs"><i class="fas fa-play text-brand-400"></i> Starts</span><span class="font-medium text-gray-900 dark:text-white" x-text="selectedEvt?formatStandardTime(selectedEvt.start_datetime):''"></span></div>
                    <div class="flex justify-between items-center px-4 py-3"><span class="text-gray-500 dark:text-gray-400 flex items-center gap-1.5 text-xs"><i class="fas fa-stop text-red-400"></i> Ends</span><span class="font-medium text-gray-900 dark:text-white" x-text="selectedEvt?formatStandardTime(selectedEvt.end_datetime):''"></span></div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-3 text-center border border-gray-200 dark:border-gray-600"><p class="text-lg font-bold text-gray-900 dark:text-white" x-text="selectedEvt?.reg_count"></p><p class="text-[10px] text-gray-400">Registered</p></div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-3 text-center border border-gray-200 dark:border-gray-600"><p class="text-lg font-bold text-brand-500" x-text="selectedEvt?.attend_count"></p><p class="text-[10px] text-gray-400">Attended</p></div>
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-xl p-3 text-center border border-gray-200 dark:border-gray-600"><p class="text-lg font-bold text-blue-500" x-text="selectedEvt?.max_capacity"></p><p class="text-[10px] text-gray-400">Capacity</p></div>
                </div>
                <div x-show="selectedEvt?.description" class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-4 border border-gray-200 dark:border-gray-600 text-sm">
                    <p class="text-xs text-gray-400 mb-1 flex items-center gap-1"><i class="fas fa-align-left"></i> Description</p>
                    <p class="text-gray-700 dark:text-gray-300" x-text="selectedEvt?.description"></p>
                </div>
                <div class="flex gap-3">
                    <button @click="showDetails=false" class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors flex items-center justify-center gap-2"><i class="fas fa-times text-red-400"></i> Close</button>
                    <a x-show="selectedEvt?.status==='approved' && selectedEvt?.display_status!=='ended'" :href="'/organizer/organizer_qrscan.php?event='+selectedEvt?.event_id"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold bg-brand-500 hover:bg-brand-600 text-white shadow shadow-brand-400/20 transition-colors flex items-center justify-center gap-2 text-center">
                        <i class="fas fa-qrcode"></i> Scan QR
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Scroll to top -->
    <button onclick="window.scrollTo({top:0,behavior:'smooth'})"
        class="fixed bottom-5 right-5 w-10 h-10 rounded-full z-40 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 shadow-lg hover:bg-brand-500 hover:text-white hover:border-brand-500 transition-all hover:scale-110 active:scale-95 flex items-center justify-center group">
        <i class="fas fa-chevron-up text-sm group-hover:-translate-y-0.5 transition-transform"></i>
    </button>

    <script src="/js/organizer_event.js"></script>
</body>
</html>