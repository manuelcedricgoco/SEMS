<?php
include("includes/db.php");   // ← only this line changes

header('Content-Type: application/json');
header('Cache-Control: no-store');

$snum = trim($_GET['snum'] ?? '');

if (!preg_match('/^\d{2}-\d{1}-\d{5}$/', $snum)) {
    echo json_encode(['available' => false, 'message' => 'Invalid format.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 'student'   AS src FROM profiles  WHERE student_number = ?
        UNION ALL
        SELECT 'organizer' AS src FROM organizer WHERE student_number = ?
        LIMIT 1
    ");
    $stmt->execute([$snum, $snum]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $message = $row['src'] === 'student'
            ? 'Already registered to a <strong>student</strong> account.'
            : 'Already registered to an <strong>organizer</strong> account.';
        echo json_encode(['available' => false, 'message' => $message]);
    } else {
        echo json_encode(['available' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['available' => true]);
}