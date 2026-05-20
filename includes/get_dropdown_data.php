<?php
// ┌─────────────────────────────────────────────────────────────────────┐
// │ api/get_dropdown_data.php                                           │
// │ Real-time dropdown data endpoint for SEMS auth page.               │
// │ Returns JSON consumed by auth.js polling every 30 seconds.         │
// └─────────────────────────────────────────────────────────────────────┘

// ── No session needed; this is a public read-only endpoint ───────────
$pdo = require_once '../includes/db.php';

// ── CORS / cache headers ─────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$payload = [
    'departments'      => [],
    'organizations'    => [],
    'clubs'            => [],
    'takenByOrg'       => [],
    'takenByClub'      => [],
    'orgsWithLogo'     => [],
    'clubsWithLogo'    => [],
    'ts'               => time(),          // epoch — client uses this to detect staleness
];

try {

    // ── 1. DEPARTMENTS ───────────────────────────────────────────────
    $dept_stmt = $pdo->query("SELECT dept_id, dept_name FROM departments ORDER BY dept_name ASC");
    while ($row = $dept_stmt->fetch(PDO::FETCH_ASSOC)) {
        $payload['departments'][] = [
            'id'   => (int)$row['dept_id'],
            'name' => $row['dept_name'],
        ];
    }

} catch (Exception $e) {
    // Departments table may not exist in all installations — skip gracefully
    $payload['departments'] = [];
}

try {

    // ── 2. ORGANIZATIONS ─────────────────────────────────────────────
    $org_stmt = $pdo->query("SELECT org_id, org_name FROM organizations ORDER BY org_name ASC");
    while ($row = $org_stmt->fetch(PDO::FETCH_ASSOC)) {
        $payload['organizations'][] = [
            'id'   => (int)$row['org_id'],
            'name' => $row['org_name'],
        ];
    }

} catch (Exception $e) {
    $payload['organizations'] = [];
}

try {

    // ── 3. CLUBS ─────────────────────────────────────────────────────
    $club_stmt = $pdo->query("SELECT club_id, club_name FROM clubs ORDER BY club_name ASC");
    while ($row = $club_stmt->fetch(PDO::FETCH_ASSOC)) {
        $payload['clubs'][] = [
            'id'   => (int)$row['club_id'],
            'name' => $row['club_name'],
        ];
    }

} catch (Exception $e) {
    $payload['clubs'] = [];
}

try {

    // ── 4. TAKEN POSITIONS ────────────────────────────────────────────
    $pos_stmt = $pdo->query("
        SELECT u.org_id, u.club_id, o.position, COUNT(*) AS cnt
        FROM organizer o
        JOIN users u ON o.user_id = u.user_id
        WHERE o.position IS NOT NULL AND o.position != ''
        GROUP BY u.org_id, u.club_id, o.position
    ");

    while ($prow = $pos_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($prow['org_id'])) {
            $oid = (int)$prow['org_id'];
            if (!isset($payload['takenByOrg'][$oid])) {
                $payload['takenByOrg'][$oid] = new stdClass();
            }
            $payload['takenByOrg'][$oid]->{$prow['position']} = (int)$prow['cnt'];
        }
        if (!empty($prow['club_id'])) {
            $cid = (int)$prow['club_id'];
            if (!isset($payload['takenByClub'][$cid])) {
                $payload['takenByClub'][$cid] = new stdClass();
            }
            $payload['takenByClub'][$cid]->{$prow['position']} = (int)$prow['cnt'];
        }
    }

} catch (Exception $e) {
    $payload['takenByOrg']  = new stdClass();
    $payload['takenByClub'] = new stdClass();
}

try {

    // ── 5. ORG LOGO FLAGS ────────────────────────────────────────────
    $org_logo = $pdo->query("SELECT org_id FROM organizations WHERE logo IS NOT NULL AND logo != ''");
    while ($row = $org_logo->fetch(PDO::FETCH_ASSOC)) {
        $payload['orgsWithLogo'][] = (int)$row['org_id'];
    }

} catch (Exception $e) {
    $payload['orgsWithLogo'] = [];
}

try {

    // ── 6. CLUB LOGO FLAGS ───────────────────────────────────────────
    $club_logo = $pdo->query("SELECT club_id FROM clubs WHERE logo IS NOT NULL AND logo != ''");
    while ($row = $club_logo->fetch(PDO::FETCH_ASSOC)) {
        $payload['clubsWithLogo'][] = (int)$row['club_id'];
    }

} catch (Exception $e) {
    $payload['clubsWithLogo'] = [];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);