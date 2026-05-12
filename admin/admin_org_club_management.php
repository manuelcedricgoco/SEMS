<?php

/**
 * SEMS Admin - Organizations & Clubs Management
 * v2 — Archived filter, Archive/Restore/Permanent-Delete, AJAX Auto-Refresh
 */

// ═══════════════════════════════════════════════════════════════════════════════
// SESSION & DATABASE
// ═══════════════════════════════════════════════════════════════════════════════
session_start();
$pdo = require_once '../includes/db.php';

// ═══════════════════════════════════════════════════════════════════════════════
// CSRF TOKEN
// ═══════════════════════════════════════════════════════════════════════════════
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ═══════════════════════════════════════════════════════════════════════════════
// AUTH GUARD
// ═══════════════════════════════════════════════════════════════════════════════
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../includes/auth.php?error=unauthorized");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER
// ═══════════════════════════════════════════════════════════════════════════════
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
) {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request token']);
        exit();
    }

    try {
        switch ($action) {
            case 'create':           handleCreate($pdo);          break;
            case 'update':           handleUpdate($pdo);          break;
            // ── NEW: soft-delete (archive) replaces hard-delete on active cards ──
            case 'archive':          handleArchive($pdo);         break;
            // ── NEW: clear deleted_at to restore ──
            case 'restore':          handleRestore($pdo);         break;
            // ── NEW: hard-delete only allowed on already-archived items ──
            case 'permanent_delete': handlePermanentDelete($pdo); break;
            // ── NEW: AJAX data refresh (called every 30 s by JS) ──
            case 'refresh_data':     handleRefreshData($pdo);     break;
            case 'get_members':      handleGetMembers($pdo);      break;
            case 'get_events':       handleGetEvents($pdo);       break;
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (PDOException $e) {
        error_log('admin_org_club_management.php PDO error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database operation failed']);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMIN PROFILE
// ═══════════════════════════════════════════════════════════════════════════════
$adminStmt = $pdo->prepare("
    SELECT a.first_name, a.middle_name, a.last_name, a.profile_image, a.phone, u.email
    FROM admin a
    JOIN users u ON a.user_id = u.user_id
    WHERE u.user_id = :admin_id
    LIMIT 1
");
$adminStmt->execute([':admin_id' => $admin_id]);
$adminData = $adminStmt->fetch(PDO::FETCH_ASSOC);

$adminFirstName  = $adminData['first_name']  ?? '';
$adminMiddleName = $adminData['middle_name'] ?? '';
$adminLastName   = $adminData['last_name']   ?? '';
$adminFullName   = trim($adminFirstName . ' ' . $adminMiddleName . ' ' . $adminLastName);
$adminFullName   = $adminFullName !== '' ? htmlspecialchars($adminFullName) : 'Administrator';
$adminAvatar     = '';
if (!empty($adminData['profile_image'])) {
    $adminAvatar = "data:image/jpeg;base64," . base64_encode($adminData['profile_image']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// FETCH ALL ORGS & CLUBS (active + archived)
// ═══════════════════════════════════════════════════════════════════════════════
try {
    [$activeOrgs, $archivedOrgs] = fetchAllOrgsClubs($pdo);
} catch (PDOException $e) {
    error_log('admin_org_club_management.php list query failed: ' . $e->getMessage());
    $activeOrgs = $archivedOrgs = [];
}

$activeDataJson   = json_encode($activeOrgs,   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$archivedDataJson = json_encode($archivedOrgs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

$totalCount    = count($activeOrgs);
$orgCount      = count(array_filter($activeOrgs,   fn($o) => $o['type'] === 'organization'));
$clubCount     = count(array_filter($activeOrgs,   fn($o) => $o['type'] === 'club'));
$archivedCount = count($archivedOrgs);

// ═══════════════════════════════════════════════════════════════════════════════
// ── HELPER: fetchAllOrgsClubs ──
// Returns [activeArray, archivedArray] with logos base64-encoded.
// ═══════════════════════════════════════════════════════════════════════════════
function fetchAllOrgsClubs(PDO $pdo): array
{
    $queries = [
        // [table, id_col, name_col, archived?]
        ['organizations', 'org_id',  'org_name',  false],
        ['organizations', 'org_id',  'org_name',  true ],
        ['clubs',         'club_id', 'club_name', false],
        ['clubs',         'club_id', 'club_name', true ],
    ];

    $active   = [];
    $archived = [];

    foreach ([false, true] as $isArchived) {
        $cond = $isArchived ? 'IS NOT NULL' : 'IS NULL';
        $type_org = 'organization';
        $type_club = 'club';

        $orgs = $pdo->query("
            SELECT o.org_id AS id, '{$type_org}' AS type, o.org_name AS name, o.logo, o.deleted_at,
                   COUNT(DISTINCT u.user_id) AS user_count,
                   COUNT(DISTINCT e.event_id) AS event_count
            FROM organizations o
            LEFT JOIN users u ON o.org_id = u.org_id AND u.role IN ('student', 'organizer')
            LEFT JOIN events e ON o.org_id = e.org_id
            WHERE o.deleted_at {$cond}
            GROUP BY o.org_id, o.org_name, o.logo, o.deleted_at
            ORDER BY o.org_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $clubs = $pdo->query("
            SELECT c.club_id AS id, '{$type_club}' AS type, c.club_name AS name, c.logo, c.deleted_at,
                   COUNT(DISTINCT u.user_id) AS user_count,
                   COUNT(DISTINCT e.event_id) AS event_count
            FROM clubs c
            LEFT JOIN users u ON c.club_id = u.club_id AND u.role IN ('student', 'organizer')
            LEFT JOIN events e ON c.club_id = e.club_id
            WHERE c.deleted_at {$cond}
            GROUP BY c.club_id, c.club_name, c.logo, c.deleted_at
            ORDER BY c.club_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $merged = array_merge($orgs, $clubs);

        foreach ($merged as &$item) {
            $item['logo'] = !empty($item['logo']) ? base64_encode($item['logo']) : null;
        }
        unset($item);

        if ($isArchived) {
            $archived = $merged;
        } else {
            $active = $merged;
        }
    }

    return [$active, $archived];
}

// ═══════════════════════════════════════════════════════════════════════════════
// CRUD: CREATE
// ═══════════════════════════════════════════════════════════════════════════════
function handleCreate(PDO $pdo): void
{
    $type = $_POST['type'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if (!in_array($type, ['organization', 'club']))
        throw new Exception('Invalid type');
    if (empty($name) || strlen($name) > 100)
        throw new Exception('Name required (max 100 chars)');

    $check = $pdo->prepare($type === 'organization'
        ? "SELECT COUNT(*) FROM organizations WHERE org_name = ? AND deleted_at IS NULL"
        : "SELECT COUNT(*) FROM clubs WHERE club_name = ? AND deleted_at IS NULL");
    $check->execute([$name]);
    if ($check->fetchColumn() > 0)
        throw new Exception('Name already exists');

    $logoData = '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $logoData = processLogoUpload($_FILES['logo']);
    }

    if ($type === 'organization') {
        $pdo->prepare("INSERT INTO organizations (org_name, logo) VALUES (?, ?)")->execute([$name, $logoData]);
    } else {
        $pdo->prepare("INSERT INTO clubs (club_name, logo) VALUES (?, ?)")->execute([$name, $logoData]);
    }
    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => ucfirst($type) . ' created',
        'data'    => [
            'id'          => (int) $newId,
            'type'        => $type,
            'name'        => $name,
            'logo'        => $logoData ? base64_encode($logoData) : null,
            'deleted_at'  => null,
            'user_count'  => 0,
            'event_count' => 0,
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// CRUD: UPDATE
// ═══════════════════════════════════════════════════════════════════════════════
function handleUpdate(PDO $pdo): void
{
    $id   = intval($_POST['id']   ?? 0);
    $type = $_POST['type'] ?? '';
    $name = trim($_POST['name']   ?? '');

    if ($id <= 0 || !in_array($type, ['organization', 'club']))
        throw new Exception('Invalid parameters');
    if (empty($name) || strlen($name) > 100)
        throw new Exception('Name required (max 100 chars)');

    $existing = $pdo->prepare($type === 'organization'
        ? "SELECT org_name, logo FROM organizations WHERE org_id = ? AND deleted_at IS NULL"
        : "SELECT club_name, logo FROM clubs WHERE club_id = ? AND deleted_at IS NULL");
    $existing->execute([$id]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Not found or item is archived');

    $dup = $pdo->prepare($type === 'organization'
        ? "SELECT COUNT(*) FROM organizations WHERE org_name = ? AND org_id != ? AND deleted_at IS NULL"
        : "SELECT COUNT(*) FROM clubs WHERE club_name = ? AND club_id != ? AND deleted_at IS NULL");
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn() > 0)
        throw new Exception('Name already in use');

    $logoData = $row['logo'];
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $logoData = processLogoUpload($_FILES['logo']);
    }

    if ($type === 'organization') {
        $pdo->prepare("UPDATE organizations SET org_name = ?, logo = ? WHERE org_id = ?")->execute([$name, $logoData, $id]);
        $stats = $pdo->prepare("SELECT COUNT(DISTINCT CASE WHEN u.role IN ('student','organizer') THEN u.user_id END) as uc, COUNT(DISTINCT e.event_id) as ec FROM organizations o LEFT JOIN users u ON o.org_id = u.org_id AND u.role IN ('student','organizer') LEFT JOIN events e ON o.org_id = e.org_id WHERE o.org_id = ?");
    } else {
        $pdo->prepare("UPDATE clubs SET club_name = ?, logo = ? WHERE club_id = ?")->execute([$name, $logoData, $id]);
        $stats = $pdo->prepare("SELECT COUNT(DISTINCT CASE WHEN u.role IN ('student','organizer') THEN u.user_id END) as uc, COUNT(DISTINCT e.event_id) as ec FROM clubs c LEFT JOIN users u ON c.club_id = u.club_id AND u.role IN ('student','organizer') LEFT JOIN events e ON c.club_id = e.club_id WHERE c.club_id = ?");
    }
    $stats->execute([$id]);
    $s = $stats->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Updated',
        'data'    => [
            'id'          => $id,
            'type'        => $type,
            'name'        => $name,
            'logo'        => $logoData ? base64_encode($logoData) : null,
            'deleted_at'  => null,
            'user_count'  => (int) $s['uc'],
            'event_count' => (int) $s['ec'],
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── NEW: ARCHIVE (soft-delete) ──
// Sets deleted_at = NOW(). No dependency checks — reversible action.
// ═══════════════════════════════════════════════════════════════════════════════
function handleArchive(PDO $pdo): void
{
    $id   = intval($_POST['id']   ?? 0);
    $type = $_POST['type'] ?? '';

    if ($id <= 0 || !in_array($type, ['organization', 'club']))
        throw new Exception('Invalid parameters');

    $now = date('Y-m-d H:i:s');

    if ($type === 'organization') {
        $stmt = $pdo->prepare("UPDATE organizations SET deleted_at = ? WHERE org_id = ? AND deleted_at IS NULL");
    } else {
        $stmt = $pdo->prepare("UPDATE clubs SET deleted_at = ? WHERE club_id = ? AND deleted_at IS NULL");
    }
    $stmt->execute([$now, $id]);

    if ($stmt->rowCount() === 0)
        throw new Exception('Item not found or already archived');

    echo json_encode([
        'success'    => true,
        'message'    => ucfirst($type) . ' archived',
        'deleted_at' => $now,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── NEW: RESTORE ──
// Clears deleted_at to make the item active again.
// ═══════════════════════════════════════════════════════════════════════════════
function handleRestore(PDO $pdo): void
{
    $id   = intval($_POST['id']   ?? 0);
    $type = $_POST['type'] ?? '';

    if ($id <= 0 || !in_array($type, ['organization', 'club']))
        throw new Exception('Invalid parameters');

    if ($type === 'organization') {
        $stmt = $pdo->prepare("UPDATE organizations SET deleted_at = NULL WHERE org_id = ? AND deleted_at IS NOT NULL");
    } else {
        $stmt = $pdo->prepare("UPDATE clubs SET deleted_at = NULL WHERE club_id = ? AND deleted_at IS NOT NULL");
    }
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0)
        throw new Exception('Item not found or already active');

    echo json_encode([
        'success' => true,
        'message' => ucfirst($type) . ' restored successfully',
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── NEW: PERMANENT DELETE (hard-delete, only for archived items) ──
// Keeps dependency checks; DB FK constraints enforce them anyway.
// ═══════════════════════════════════════════════════════════════════════════════
function handlePermanentDelete(PDO $pdo): void
{
    $id   = intval($_POST['id']   ?? 0);
    $type = $_POST['type'] ?? '';

    if ($id <= 0 || !in_array($type, ['organization', 'club']))
        throw new Exception('Invalid parameters');

    // Must be archived first
    $check = $pdo->prepare($type === 'organization'
        ? "SELECT deleted_at FROM organizations WHERE org_id = ?"
        : "SELECT deleted_at FROM clubs WHERE club_id = ?");
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    if (!$row)
        throw new Exception('Not found');
    if (empty($row['deleted_at']))
        throw new Exception('Only archived items can be permanently deleted');

    // Active events check
    $events = $pdo->prepare($type === 'organization'
        ? "SELECT COUNT(*) FROM events WHERE org_id = ? AND status != 'rejected'"
        : "SELECT COUNT(*) FROM events WHERE club_id = ? AND status != 'rejected'");
    $events->execute([$id]);
    if ($events->fetchColumn() > 0)
        throw new Exception('Has active events — delete those first');

    // Assigned users check
    $users = $pdo->prepare($type === 'organization'
        ? "SELECT COUNT(*) FROM users WHERE org_id = ?"
        : "SELECT COUNT(*) FROM users WHERE club_id = ?");
    $users->execute([$id]);
    if ($users->fetchColumn() > 0)
        throw new Exception('Has assigned users — reassign them first');

    $pdo->prepare($type === 'organization'
        ? "DELETE FROM organizations WHERE org_id = ?"
        : "DELETE FROM clubs WHERE club_id = ?")->execute([$id]);

    echo json_encode(['success' => true, 'message' => ucfirst($type) . ' permanently deleted']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ── NEW: REFRESH DATA (AJAX polling endpoint) ──
// Returns fresh active + archived data for the JS auto-refresh loop.
// ═══════════════════════════════════════════════════════════════════════════════
function handleRefreshData(PDO $pdo): void
{
    [$active, $archived] = fetchAllOrgsClubs($pdo);
    echo json_encode(['success' => true, 'active' => $active, 'archived' => $archived]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET MEMBERS
// ═══════════════════════════════════════════════════════════════════════════════
function handleGetMembers(PDO $pdo): void
{
    $id         = intval($_POST['id']   ?? 0);
    $type       = $_POST['type'] ?? '';
    $page       = max(1, intval($_POST['page']   ?? 1));
    $search     = trim($_POST['search'] ?? '');
    $roleFilter = $_POST['role_filter'] ?? 'all';
    $perPage    = 12;
    $offset     = ($page - 1) * $perPage;

    if ($id <= 0 || !in_array($type, ['organization', 'club']))
        throw new Exception('Invalid parameters');
    if (!in_array($roleFilter, ['all', 'student', 'officer']))
        $roleFilter = 'all';

    $idColumn = $type === 'organization' ? 'org_id' : 'club_id';
    $roleCond = $roleFilter === 'student'
        ? " AND u.role = 'student'"
        : ($roleFilter === 'officer'
            ? " AND u.role = 'organizer'"
            : " AND u.role IN ('student', 'organizer')");

    $countSql    = "SELECT COUNT(*) FROM users u LEFT JOIN profiles p ON u.user_id = p.user_id LEFT JOIN organizer o2 ON u.user_id = o2.user_id WHERE u.{$idColumn} = ? {$roleCond}";
    $countParams = [$id];
    if ($search) {
        $countSql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR u.email LIKE ? OR o2.first_name LIKE ? OR o2.last_name LIKE ?)";
        $s = "%{$search}%";
        array_push($countParams, $s, $s, $s, $s, $s);
    }
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT u.user_id, u.email, u.role,
                p.first_name AS p_first_name, p.last_name AS p_last_name,
                p.student_number AS p_student_number, p.year_level AS p_year_level, p.profile_image AS p_profile_image,
                o2.first_name AS o_first_name, o2.last_name AS o_last_name,
                o2.position AS o_position, o2.profile_image AS o_profile_image
            FROM users u
            LEFT JOIN profiles p ON u.user_id = p.user_id
            LEFT JOIN organizer o2 ON u.user_id = o2.user_id
            WHERE u.{$idColumn} = ? {$roleCond}";
    $params = [$id];
    if ($search) {
        $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR u.email LIKE ? OR o2.first_name LIKE ? OR o2.last_name LIKE ?)";
        $s = "%{$search}%";
        array_push($params, $s, $s, $s, $s, $s);
    }
    $sql .= " ORDER BY CASE u.role WHEN 'organizer' THEN 0 ELSE 1 END, COALESCE(o2.last_name, p.last_name) ASC LIMIT ? OFFSET ?";
    array_push($params, $perPage, $offset);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($members as &$m) {
        $isOrganizer      = $m['role'] === 'organizer';
        $rawImage         = $isOrganizer ? $m['o_profile_image'] : $m['p_profile_image'];
        $m['first_name']  = $isOrganizer ? ($m['o_first_name'] ?? '') : ($m['p_first_name'] ?? '');
        $m['last_name']   = $isOrganizer ? ($m['o_last_name']  ?? '') : ($m['p_last_name']  ?? '');
        $m['position']    = $isOrganizer ? ($m['o_position']   ?? 'Officer') : null;
        $m['student_number'] = $isOrganizer ? null : ($m['p_student_number'] ?? null);
        $m['profile_image']  = !empty($rawImage) ? base64_encode($rawImage) : null;
        $m['full_name']      = trim($m['first_name'] . ' ' . $m['last_name']) ?: ($m['email'] ?? 'Unknown');
        $m['display_role']   = $isOrganizer ? 'officer' : 'student';
        unset($m['p_first_name'], $m['p_last_name'], $m['p_student_number'], $m['p_year_level'],
              $m['p_profile_image'], $m['o_first_name'], $m['o_last_name'], $m['o_position'], $m['o_profile_image']);
    }
    unset($m);

    echo json_encode([
        'success'     => true,
        'members'     => $members,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int) ceil($total / $perPage),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// GET EVENTS
// ═══════════════════════════════════════════════════════════════════════════════
function handleGetEvents(PDO $pdo): void
{
    $id      = intval($_POST['id']   ?? 0);
    $type    = $_POST['type'] ?? '';
    $page    = max(1, intval($_POST['page']   ?? 1));
    $search  = trim($_POST['search'] ?? '');
    $perPage = 10;
    $offset  = ($page - 1) * $perPage;

    if ($id <= 0 || !in_array($type, ['organization', 'club']))
        throw new Exception('Invalid parameters');
    $idColumn = $type === 'organization' ? 'org_id' : 'club_id';

    $countSql    = "SELECT COUNT(*) FROM events WHERE {$idColumn} = ?";
    $countParams = [$id];
    if ($search) {
        $countSql .= " AND (title LIKE ? OR description LIKE ?)";
        $s = "%{$search}%";
        array_push($countParams, $s, $s);
    }
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $total = (int) $countStmt->fetchColumn();

    $sql    = "SELECT e.event_id, e.title, e.description, e.start_datetime, e.end_datetime, e.status, e.is_restricted,
                v.venue_name, (SELECT COUNT(*) FROM registrations WHERE event_id = e.event_id) as registration_count
            FROM events e LEFT JOIN venues v ON e.venue_id = v.venue_id WHERE e.{$idColumn} = ?";
    $params = [$id];
    if ($search) {
        $sql .= " AND (e.title LIKE ? OR e.description LIKE ?)";
        $s = "%{$search}%";
        array_push($params, $s, $s);
    }
    $sql .= " ORDER BY e.start_datetime DESC LIMIT ? OFFSET ?";
    array_push($params, $perPage, $offset);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'     => true,
        'events'      => $events,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int) ceil($total / $perPage),
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOGO UPLOAD
// ═══════════════════════════════════════════════════════════════════════════════
function processLogoUpload(array $file): string
{
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))
        throw new Exception('Invalid upload');
    if ($file['size'] > 2 * 1024 * 1024)
        throw new Exception('File too large (max 2MB)');

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif']))
        throw new Exception('Invalid image type');

    $img = getimagesize($file['tmp_name']);
    if (!$img) throw new Exception('Invalid image');

    [$w, $h] = $img;
    $maxDim = 500;

    if ($w > $maxDim || $h > $maxDim) {
        $ratio = min($maxDim / $w, $maxDim / $h);
        $newW  = round($w * $ratio);
        $newH  = round($h * $ratio);
        $src   = imagecreatefromstring(file_get_contents($file['tmp_name']));
        $dst   = imagecreatetruecolor($newW, $newH);
        if ($mime === 'image/png' || $mime === 'image/gif') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        ob_start();
        imagejpeg($dst, null, 85);
        return ob_get_clean();
    }

    $src = imagecreatefromstring(file_get_contents($file['tmp_name']));
    ob_start();
    imagejpeg($src, null, 85);
    return ob_get_clean();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEMS Admin — Organizations & Clubs</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        primary: { 50: '#eff6ff', 100: '#dbeafe', 400: '#60a5fa', 500: '#3b82f6', 600: '#2563eb' },
                        purple:  { 50: '#faf5ff', 100: '#f3e8ff', 400: '#c084fc', 500: '#a855f7', 600: '#9333ea' }
                    },
                    animation: {
                        'fade-up':  'fadeUp .5s ease both',
                        'fade-in':  'fadeIn .4s ease both',
                        'slide-in': 'slideIn .3s ease both',
                        'scale-in': 'scaleIn .2s ease both',
                    },
                    keyframes: {
                        fadeUp:   { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        fadeIn:   { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideIn:  { '0%': { opacity: '0', transform: 'translateX(-10px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                        scaleIn:  { '0%': { opacity: '0', transform: 'scale(0.95)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                    }
                }
            }
        }
    </script>

    <script>
        (function () {
            const t = localStorage.getItem('sems-theme') || 'light';
            if (t === 'dark') document.documentElement.classList.add('dark');
        })();
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/CSS/admin_org_club_management.css">
</head>

<body class="bg-gray-50 dark:bg-slate-900 text-slate-800 dark:text-slate-200 font-sans min-h-screen">

    <div id="overlay" onclick="closeSidebar()"
        class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 opacity-0 pointer-events-none lg:hidden"></div>

    <div class="flex min-h-screen">

        <!-- ── SIDEBAR ── -->
        <aside id="sidebar"
            class="-translate-x-full lg:translate-x-0 fixed top-0 left-0 z-50 h-full w-64 flex flex-col bg-white dark:bg-slate-800 border-r border-gray-200 dark:border-slate-700 shadow-xl">

            <div class="px-6 py-6 border-b border-gray-100 dark:border-slate-700">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl logo-gradient flex items-center justify-center shadow-lg shadow-blue-500/30">
                        <i class="fas fa-calendar-check text-white text-sm"></i>
                    </div>
                    <div>
                        <p class="font-bold text-slate-900 dark:text-white text-lg tracking-tight leading-none">SEMS</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Admin Panel</p>
                    </div>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-4 py-6 space-y-1">
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 uppercase tracking-wider">Overview</p>
                <a href="/admin/admin_dashboard.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-th-large w-5 text-center"></i> Dashboard
                </a>
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Management</p>
                <a href="/admin/admin_event_management.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-calendar-alt w-5 text-center"></i> Events
                </a>
                <a href="/admin/admin_aprovals.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-check-circle w-5 text-center"></i> Approvals
                </a>
                <a href="/admin/admin_user_management.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-users w-5 text-center"></i> Users
                </a>
                <a href="/admin/admin_org_club_management.php" class="nav-item active flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200">
                    <i class="fas fa-building w-5 text-center"></i> Organizations & Clubs
                </a>
                <p class="text-xs font-semibold text-slate-400 dark:text-slate-500 px-3 mb-2 mt-6 uppercase tracking-wider">Insights</p>
                <a href="/admin/admin_insight.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-chart-line w-5 text-center"></i> Analytics
                </a>
            </nav>

            <div class="px-4 py-4 border-t border-gray-100 dark:border-slate-700 space-y-1">
                <a href="/admin/admin_settings.php" class="nav-item flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-cog w-5 text-center"></i> Settings
                </a>
                <button onclick="toggleTheme()" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 dark:text-slate-400 hover:bg-gray-50 dark:hover:bg-slate-700/50 font-medium text-sm transition-all duration-200">
                    <i id="theme-icon" class="fas fa-moon w-5 text-center"></i>
                    <span id="theme-label">Dark Mode</span>
                </button>
                <a href="../includes/logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 font-medium text-sm transition-all duration-200">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i> Logout
                </a>
            </div>
        </aside>

        <!-- ── MAIN CONTENT ── -->
        <main class="flex-1 lg:ml-64 min-w-0 flex flex-col">

            <!-- Sticky Header -->
            <header class="sticky top-0 z-30 bg-white/80 dark:bg-slate-800/80 backdrop-blur-xl border-b border-gray-200 dark:border-slate-700 px-4 sm:px-8 py-4 flex items-center gap-4 transition-colors duration-300">
                <button onclick="openSidebar()" class="lg:hidden w-10 h-10 flex items-center justify-center flex-shrink-0 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 hover:text-primary-500 transition-all duration-200">
                    <i class="fas fa-bars text-sm"></i>
                </button>

                <div class="hidden sm:block mr-auto">
                    <div class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400 mb-0.5">
                        <span>Admin</span><i class="fas fa-chevron-right text-xs"></i>
                        <span class="text-slate-900 dark:text-white font-medium">Organizations & Clubs</span>
                    </div>
                    <!-- ── NEW: Auto-refresh timestamp ── -->
                    <p class="text-xs text-slate-400 dark:text-slate-500 flex items-center gap-1.5">
                        <?= date('l, F j, Y') ?>
                        <span class="mx-1 opacity-40">·</span>
                        <i class="fas fa-sync-alt text-[10px] text-emerald-500 animate-spin" style="animation-duration:3s;animation-iteration-count:1" id="refresh-icon"></i>
                        <span id="last-updated" class="text-emerald-600 dark:text-emerald-400">Auto-refresh active</span>
                    </p>
                </div>

                <div class="flex-1 sm:flex-none sm:w-72 relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                    <input id="searchInput" type="text" placeholder="Search orgs or clubs..." onkeyup="handleSearch()"
                        class="w-full pl-10 pr-4 py-2.5 text-sm rounded-xl bg-gray-100 dark:bg-slate-700 border-0 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:bg-white dark:focus:bg-slate-600 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all duration-200" />
                </div>

                <div class="flex items-center gap-3 pl-4 border-l border-gray-200 dark:border-slate-700">
                    <div class="hidden md:block text-right">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white leading-none"><?= $adminFullName ?></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Administrator</p>
                    </div>
                    <div class="relative cursor-pointer">
                        <?php if ($adminAvatar): ?>
                            <img src="<?= $adminAvatar ?>" alt="<?= $adminFullName ?>" class="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-600 shadow-md">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-purple-600 flex items-center justify-center text-white text-sm font-bold shadow-md">
                                <?= strtoupper(substr($adminFirstName, 0, 1) . substr($adminLastName, 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <span class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-slate-800 rounded-full"></span>
                    </div>
                </div>
            </header>

            <!-- Page Body -->
            <div class="flex-1 px-4 sm:px-8 py-8 space-y-8">

                <div class="animate-fade-up">
                    <h1 class="text-2xl sm:text-3xl font-bold text-slate-900 dark:text-white">Organizations & Clubs</h1>
                    <p class="text-slate-500 dark:text-slate-400 mt-2">Manage recognized organizations and student clubs across the campus.</p>
                </div>

                <!-- ── STATISTICS CARDS (4 cards: Total | Organizations | Clubs | Archived) ── -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
                    <!-- Total -->
                    <div class="card-anim animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden cursor-pointer" onclick="setFilter('all')">
                        <div class="absolute top-4 right-4 w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
                            <i class="fas fa-layer-group text-blue-600 dark:text-blue-400 text-lg"></i>
                        </div>
                        <div class="pr-14">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Total Active</p>
                            <p class="text-3xl font-bold text-slate-900 dark:text-white leading-none" id="stat-total"><?= $totalCount ?></p>
                        </div>
                    </div>
                    <!-- Organizations -->
                    <div class="card-anim animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden cursor-pointer" onclick="setFilter('organization')" style="animation-delay:.08s">
                        <div class="absolute top-4 right-4 w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
                            <i class="fas fa-building text-purple-600 dark:text-purple-400 text-lg"></i>
                        </div>
                        <div class="pr-14">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Organizations</p>
                            <p class="text-3xl font-bold text-purple-600 dark:text-purple-400 leading-none" id="stat-orgs"><?= $orgCount ?></p>
                        </div>
                    </div>
                    <!-- Clubs -->
                    <div class="card-anim animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-gray-100 dark:border-slate-700 relative overflow-hidden cursor-pointer" onclick="setFilter('club')" style="animation-delay:.12s">
                        <div class="absolute top-4 right-4 w-12 h-12 rounded-xl bg-sky-100 dark:bg-sky-500/20 flex items-center justify-center">
                            <i class="fas fa-users text-sky-600 dark:text-sky-400 text-lg"></i>
                        </div>
                        <div class="pr-14">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Clubs</p>
                            <p class="text-3xl font-bold text-sky-600 dark:text-sky-400 leading-none" id="stat-clubs"><?= $clubCount ?></p>
                        </div>
                    </div>
                    <!-- ── NEW: Archived card ── -->
                    <div class="card-anim animate-fade-up bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-amber-100 dark:border-amber-900/30 relative overflow-hidden cursor-pointer" onclick="setFilter('archived')" style="animation-delay:.16s">
                        <div class="absolute top-4 right-4 w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
                            <i class="fas fa-archive text-amber-600 dark:text-amber-400 text-lg"></i>
                        </div>
                        <div class="pr-14">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Archived</p>
                            <p class="text-3xl font-bold text-amber-600 dark:text-amber-400 leading-none" id="stat-archived"><?= $archivedCount ?></p>
                        </div>
                    </div>
                </div>

                <!-- ── FILTER TABS ── -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-3 animate-fade-up" style="animation-delay:.1s">
                    <div class="flex gap-2 flex-wrap">
                        <button id="tab-all" onclick="setFilter('all')"
                            class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-primary-500 text-white shadow-sm shadow-primary-500/30 transition-all duration-200 flex items-center gap-2">
                            <i class="fas fa-layer-group"></i> All
                        </button>
                        <button id="tab-organization" onclick="setFilter('organization')"
                            class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-purple-400 hover:text-purple-600 transition-all duration-200 flex items-center gap-2">
                            <i class="fas fa-building text-purple-500"></i> Organizations
                        </button>
                        <button id="tab-club" onclick="setFilter('club')"
                            class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-sky-400 hover:text-sky-600 transition-all duration-200 flex items-center gap-2">
                            <i class="fas fa-users text-sky-500"></i> Clubs
                        </button>
                        <!-- ── NEW: Archived tab ── -->
                        <button id="tab-archived" onclick="setFilter('archived')"
                            class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold bg-white dark:bg-slate-700 text-slate-600 dark:text-slate-300 border border-gray-200 dark:border-slate-600 hover:border-amber-400 hover:text-amber-600 transition-all duration-200 flex items-center gap-2">
                            <i class="fas fa-archive text-amber-500"></i> Archived
                            <?php if ($archivedCount > 0): ?>
                            <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-amber-500 text-white text-[10px] font-bold"><?= $archivedCount ?></span>
                            <?php endif; ?>
                        </button>
                    </div>
                    <div class="sm:ml-auto flex items-center gap-3">
                        <!-- ── id added so JS can hide it in Archived view ── -->
                        <button id="add-new-btn" onclick="openAddModal()"
                            class="px-4 py-2 rounded-xl text-xs font-semibold bg-emerald-500 hover:bg-emerald-600 text-white shadow-sm shadow-emerald-500/30 transition-all duration-200 flex items-center gap-2">
                            <i class="fas fa-plus"></i> Add New
                        </button>
                        <span class="inline-flex items-center px-3 py-1.5 rounded-xl text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400">
                            <i class="fas fa-table mr-1.5 text-primary-500"></i>
                            <span id="result-num">0</span>&nbsp;<span id="result-label">results</span>
                        </span>
                    </div>
                </div>

                <!-- GRID -->
                <div id="orgs-grid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 animate-fade-up" style="animation-delay:.15s"></div>

                <!-- Empty State -->
                <div id="empty-state" class="hidden px-4 py-12 text-center animate-fade-in">
                    <div class="w-16 h-16 bg-gray-100 dark:bg-slate-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-search text-gray-400 text-2xl"></i>
                    </div>
                    <p class="text-slate-900 dark:text-white font-medium">Nothing found</p>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Try a different filter or search term</p>
                </div>

                <div class="h-4"></div>
            </div>
        </main>
    </div>

    <!-- ── ADD MODAL ── -->
    <div id="addModal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeAddModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg border border-gray-200 dark:border-slate-700 my-8 max-h-[88vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-500/10 flex items-center justify-center">
                            <i class="fas fa-plus text-emerald-500 text-sm"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-sm">Add New</p>
                            <p class="text-xs text-slate-400">Create organization or club</p>
                        </div>
                    </div>
                    <button onclick="closeAddModal()" class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 flex items-center justify-center text-slate-500">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4">
                    <form id="addForm" onsubmit="handleAdd(event)">
                        <div class="space-y-2">
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Type</label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="cursor-pointer">
                                    <input type="radio" name="type" value="organization" class="peer sr-only" checked>
                                    <div class="p-3 rounded-xl border-2 border-gray-200 dark:border-slate-600 peer-checked:border-purple-500 peer-checked:bg-purple-50 dark:peer-checked:bg-purple-500/10 transition-all text-center">
                                        <i class="fas fa-building text-purple-500 mb-1"></i>
                                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Organization</p>
                                    </div>
                                </label>
                                <label class="cursor-pointer">
                                    <input type="radio" name="type" value="club" class="peer sr-only">
                                    <div class="p-3 rounded-xl border-2 border-gray-200 dark:border-slate-600 peer-checked:border-sky-500 peer-checked:bg-sky-50 dark:peer-checked:bg-sky-500/10 transition-all text-center">
                                        <i class="fas fa-users text-sky-500 mb-1"></i>
                                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Club</p>
                                    </div>
                                </label>
                            </div>
                        </div>
                        <div class="space-y-2 mt-4">
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Name</label>
                            <input type="text" name="name" required placeholder="Enter name..." maxlength="100"
                                class="w-full px-4 py-2.5 rounded-xl bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 text-slate-700 dark:text-slate-200 text-sm transition-all">
                        </div>
                        <div class="space-y-2 mt-4">
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Logo <span class="text-slate-400 font-normal">(Optional, max 2MB)</span></label>
                            <input type="file" name="logo" id="logoInput" accept="image/jpeg,image/png,image/gif" class="hidden" onchange="previewLogo(this)">
                            <label for="logoInput" class="flex items-center justify-center w-full h-32 rounded-xl border-2 border-dashed border-gray-300 dark:border-slate-600 hover:border-primary-400 cursor-pointer bg-gray-50 dark:bg-slate-700/50 transition-all overflow-hidden" id="logoPreviewContainer">
                                <div id="logoPlaceholder" class="text-center">
                                    <i class="fas fa-cloud-upload-alt text-2xl text-slate-400 mb-2"></i>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">Click to upload logo</p>
                                    <p class="text-xs text-slate-400 mt-1">JPG, PNG, GIF up to 2MB</p>
                                </div>
                                <img id="logoPreview" class="hidden w-full h-full object-cover">
                            </label>
                        </div>
                    </form>
                </div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-2 flex-shrink-0">
                    <button onclick="closeAddModal()" class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600">Cancel</button>
                    <button id="addSubmitBtn" onclick="document.getElementById('addForm').dispatchEvent(new Event('submit'))"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-emerald-500 hover:bg-emerald-600 text-white shadow-sm shadow-emerald-500/30 flex items-center gap-2">
                        <span>Create</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── EDIT MODAL ── -->
    <div id="editModal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-lg border border-gray-200 dark:border-slate-700 my-8 max-h-[88vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center">
                            <i class="fas fa-edit text-blue-500 text-sm"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-sm">Edit</p>
                            <p class="text-xs text-slate-400">Update organization or club</p>
                        </div>
                    </div>
                    <button onclick="closeEditModal()" class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 flex items-center justify-center text-slate-500">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4">
                    <form id="editForm" onsubmit="handleEdit(event)">
                        <input type="hidden" name="id" id="editId">
                        <input type="hidden" name="type" id="editType">
                        <div class="space-y-2">
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Type</label>
                            <div id="editTypeDisplay" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium capitalize flex items-center gap-2 border border-purple-200 dark:border-purple-800">
                                <i class="fas fa-building text-purple-500"></i><span>Organization</span>
                            </div>
                        </div>
                        <div class="space-y-2 mt-4">
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Name</label>
                            <input type="text" name="name" id="editName" required placeholder="Enter name..." maxlength="100"
                                class="w-full px-4 py-2.5 rounded-xl bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary-500/30 focus:border-primary-500 text-slate-700 dark:text-slate-200 text-sm transition-all">
                        </div>
                        <div class="space-y-2 mt-4">
                            <label class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Logo <span class="text-slate-400 font-normal">(Optional, max 2MB)</span></label>
                            <input type="file" name="logo" id="editLogoInput" accept="image/jpeg,image/png,image/gif" class="hidden" onchange="previewEditLogo(this)">
                            <label for="editLogoInput" class="flex items-center justify-center w-full h-32 rounded-xl border-2 border-dashed border-gray-300 dark:border-slate-600 hover:border-primary-400 cursor-pointer bg-gray-50 dark:bg-slate-700/50 transition-all overflow-hidden">
                                <div id="editLogoPlaceholder" class="text-center">
                                    <i class="fas fa-cloud-upload-alt text-2xl text-slate-400 mb-2"></i>
                                    <p class="text-sm text-slate-500 dark:text-slate-400">Click to change logo</p>
                                </div>
                                <img id="editLogoPreview" class="hidden w-full h-full object-cover">
                            </label>
                        </div>
                    </form>
                </div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-slate-700 flex justify-end gap-2 flex-shrink-0">
                    <button onclick="closeEditModal()" class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600">Cancel</button>
                    <button id="editSubmitBtn" onclick="document.getElementById('editForm').dispatchEvent(new Event('submit'))"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-blue-500 hover:bg-blue-600 text-white shadow-sm shadow-blue-500/30 flex items-center gap-2">
                        <span>Save Changes</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── DELETE/ARCHIVE MODAL (unified — JS controls the wording) ── -->
    <div id="deleteModal" class="fixed inset-0 z-[200] hidden">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-sm border border-gray-200 dark:border-slate-700 text-center p-6">
                <!-- ── Icon and background are dynamically set by JS ── -->
                <div id="deleteIconBg" class="w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-500/10 flex items-center justify-center mx-auto mb-4">
                    <i id="deleteIcon" class="fas fa-archive text-amber-500 text-xl"></i>
                </div>
                <p class="font-bold text-slate-900 dark:text-white mb-2 text-lg" id="deleteTitle">Archive?</p>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed" id="deleteDesc"></p>
                <div class="flex gap-3 justify-center">
                    <button onclick="closeDeleteModal()" class="px-4 py-2 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600">Cancel</button>
                    <button id="confirmDeleteBtn" onclick="confirmDeleteAction()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold bg-amber-500 hover:bg-amber-600 text-white shadow-sm shadow-amber-500/30 flex items-center gap-2">
                        <i class="fas fa-archive"></i><span>Archive</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── MEMBERS MODAL ── -->
    <div id="membersModal" class="fixed inset-0 z-[200] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeMembersModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-2xl border border-gray-200 dark:border-slate-700 my-8 max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-500/10 flex items-center justify-center">
                            <i class="fas fa-users text-purple-500"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-sm" id="membersModalTitle">Members</p>
                            <p class="text-xs text-slate-400"><span id="membersModalCount">0</span> total members</p>
                        </div>
                    </div>
                    <button onclick="closeMembersModal()" class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 flex items-center justify-center text-slate-500">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="px-6 pt-3 pb-0 flex-shrink-0">
                    <div class="flex gap-2 p-1 bg-gray-100 dark:bg-slate-700/50 rounded-xl w-fit">
                        <button id="membersFilter-all"     onclick="setMembersRoleFilter('all')"     class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-white dark:bg-slate-600 text-slate-700 dark:text-slate-200 shadow-sm transition-all">All</button>
                        <button id="membersFilter-student" onclick="setMembersRoleFilter('student')" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-all">Students</button>
                        <button id="membersFilter-officer" onclick="setMembersRoleFilter('officer')" class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-all">Officers</button>
                    </div>
                </div>
                <div class="px-6 py-3 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="membersSearch" placeholder="Search members by name or email..." oninput="debounceMembersSearch()"
                            class="w-full pl-10 pr-4 py-2 text-sm rounded-xl bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-purple-500/30 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all" />
                    </div>
                </div>
                <div id="membersList" class="flex-1 overflow-y-auto px-2 py-2 min-h-[300px] max-h-[50vh]"></div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-slate-700 flex items-center justify-between flex-shrink-0">
                    <span class="text-xs text-slate-500 dark:text-slate-400" id="membersPageInfo">Page 1 of 1</span>
                    <div class="flex gap-2">
                        <button id="membersPrevBtn" onclick="changeMembersPage(-1)" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                            <i class="fas fa-chevron-left mr-1"></i> Prev
                        </button>
                        <button id="membersNextBtn" onclick="changeMembersPage(1)"  class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                            Next <i class="fas fa-chevron-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── EVENTS MODAL ── -->
    <div id="eventsModal" class="fixed inset-0 z-[200] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEventsModal()"></div>
        <div class="absolute inset-0 flex items-center justify-center p-4 overflow-y-auto">
            <div class="modal-enter relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-2xl border border-gray-200 dark:border-slate-700 my-8 max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-sky-50 dark:bg-sky-500/10 flex items-center justify-center">
                            <i class="fas fa-calendar-alt text-sky-500"></i>
                        </div>
                        <div>
                            <p class="font-bold text-slate-900 dark:text-white text-sm" id="eventsModalTitle">Events</p>
                            <p class="text-xs text-slate-400"><span id="eventsModalCount">0</span> total events</p>
                        </div>
                    </div>
                    <button onclick="closeEventsModal()" class="w-8 h-8 rounded-xl bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 flex items-center justify-center text-slate-500">
                        <i class="fas fa-times text-sm"></i>
                    </button>
                </div>
                <div class="px-6 py-3 border-b border-gray-100 dark:border-slate-700 flex-shrink-0">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input type="text" id="eventsSearch" placeholder="Search events by title..." oninput="debounceEventsSearch()"
                            class="w-full pl-10 pr-4 py-2 text-sm rounded-xl bg-gray-50 dark:bg-slate-700 border border-gray-200 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-sky-500/30 text-slate-700 dark:text-slate-200 placeholder-slate-400 transition-all" />
                    </div>
                </div>
                <div id="eventsList" class="flex-1 overflow-y-auto px-2 py-2 min-h-[300px] max-h-[50vh]"></div>
                <div class="px-6 py-3 border-t border-gray-100 dark:border-slate-700 flex items-center justify-between flex-shrink-0">
                    <span class="text-xs text-slate-500 dark:text-slate-400" id="eventsPageInfo">Page 1 of 1</span>
                    <div class="flex gap-2">
                        <button id="eventsPrevBtn" onclick="changeEventsPage(-1)" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                            <i class="fas fa-chevron-left mr-1"></i> Prev
                        </button>
                        <button id="eventsNextBtn" onclick="changeEventsPage(1)"  class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300 hover:bg-gray-200 dark:hover:bg-slate-600 disabled:opacity-40 disabled:cursor-not-allowed transition-all">
                            Next <i class="fas fa-chevron-right ml-1"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── TOAST ── -->
    <div id="toast" class="fixed top-4 right-4 z-[300] translate-x-full transition-transform duration-300 hidden">
        <div class="flex items-center gap-2.5 px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium" id="toastContent">
            <i class="fas fa-check-circle"></i><span>Operation successful</span>
        </div>
    </div>

    <!-- ── DATA BRIDGE: PHP → JS ── -->
    <script>
        const SEMS_ORG_DATA = {
            active:    <?= $activeDataJson ?>,
            archived:  <?= $archivedDataJson ?>,
            csrfToken: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        };
    </script>

    <script src="/js/admin_org_club_manage.js"></script>

</body>
</html>