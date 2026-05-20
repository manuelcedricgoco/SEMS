<?php
/* ============================================================
 *  includes/admin_chat_api.php — SEMS Admin ↔ Organizer Chat
 * ============================================================ */

ini_set('display_errors', 0);
error_reporting(E_ALL);

ob_start();

session_start();

// ── DB connection ──────────────────────────────────────────────
// FIX: use __DIR__ so the path is always relative to THIS file,
//      not to the server's working directory.
try {
    $pdo = require_once __DIR__ . '/db.php';
    if (!($pdo instanceof PDO)) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection unavailable');
    }
    // FIX: ensure PDO throws exceptions so errors propagate cleanly
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit();
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ─────────────────────────────────────────────────
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$uid  = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

if (!in_array($role, ['admin', 'organizer'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit();
}

set_exception_handler(function (Throwable $e) {
    ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit();
});

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ── Helpers ────────────────────────────────────────────────────
function api_ok(array $data = []): void {
    $payload = array_merge(['ok' => true], $data);
    $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($json === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'JSON encoding failed']);
        exit();
    }
    echo $json;
    exit();
}

function api_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit();
}

function verify_conv_access(PDO $pdo, int $convId, int $uid, string $role): array {
    $st = $pdo->prepare(
        "SELECT admin_id, organizer_id FROM admin_chat_conversations WHERE conv_id = ?"
    );
    $st->execute([$convId]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) api_err('Conversation not found', 404);
    if ($role === 'admin'     && (int)$conv['admin_id']     !== $uid) api_err('Forbidden', 403);
    if ($role === 'organizer' && (int)$conv['organizer_id'] !== $uid) api_err('Forbidden', 403);
    return $conv;
}


/* ============================================================
 *  ACTION: get_contacts
 * ============================================================ */
if ($action === 'get_contacts') {
    if ($role === 'admin') {
        $st = $pdo->prepare("
            SELECT
                u.user_id,
                CONCAT(COALESCE(og.first_name,''), ' ', COALESCE(og.last_name,'')) AS full_name,
                og.position,
                COALESCE(o.org_name, c.club_name, 'Staff') AS group_name
            FROM users u
            LEFT JOIN organizer     og ON u.user_id = og.user_id
            LEFT JOIN organizations o  ON u.org_id  = o.org_id
            LEFT JOIN clubs         c  ON u.club_id = c.club_id
            WHERE u.role = 'organizer' AND u.deleted_at IS NULL
            ORDER BY group_name, og.last_name, og.first_name
        ");
        $st->execute();
    } else {
        // Organizer: list all admins
        $st = $pdo->prepare("
            SELECT
                u.user_id,
                CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,'')) AS full_name,
                'Administrator' AS position,
                'Administration'   AS group_name
            FROM users u
            LEFT JOIN admin a ON u.user_id = a.user_id
            WHERE u.role = 'admin' AND u.deleted_at IS NULL
            ORDER BY a.last_name, a.first_name
        ");
        $st->execute();
    }

    api_ok(['contacts' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ============================================================
 *  ACTION: get_conversations
 * ============================================================ */
if ($action === 'get_conversations') {
    if ($role === 'admin') {
        $st = $pdo->prepare("
            SELECT
                cc.conv_id,
                cc.organizer_id AS other_id,
                CONCAT(COALESCE(og.first_name,''), ' ', COALESCE(og.last_name,'')) AS other_name,
                COALESCE(o.org_name, c.club_name, og.position, 'Organizer') AS other_sub,
                cc.last_message_at,
                (SELECT m.message
                 FROM admin_chat_messages m
                 WHERE m.conv_id = cc.conv_id AND m.is_deleted = 0
                 ORDER BY m.sent_at DESC LIMIT 1) AS last_msg,
                (SELECT COUNT(*)
                 FROM admin_chat_messages m
                 WHERE m.conv_id = cc.conv_id
                   AND m.sender_id != :uid1
                   AND m.is_read   = 0
                   AND m.is_deleted = 0) AS unread
            FROM admin_chat_conversations cc
            JOIN users u            ON cc.organizer_id = u.user_id
            LEFT JOIN organizer     og ON u.user_id  = og.user_id
            LEFT JOIN organizations o  ON u.org_id   = o.org_id
            LEFT JOIN clubs         c  ON u.club_id  = c.club_id
            WHERE cc.admin_id = :uid2
            ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC
        ");
        $st->execute([':uid1' => $uid, ':uid2' => $uid]);
    } else {
        $st = $pdo->prepare("
            SELECT
                cc.conv_id,
                cc.admin_id AS other_id,
                CONCAT(COALESCE(a.first_name,''), ' ', COALESCE(a.last_name,'')) AS other_name,
                'Administrator' AS other_sub,
                cc.last_message_at,
                (SELECT m.message
                 FROM admin_chat_messages m
                 WHERE m.conv_id = cc.conv_id AND m.is_deleted = 0
                 ORDER BY m.sent_at DESC LIMIT 1) AS last_msg,
                (SELECT COUNT(*)
                 FROM admin_chat_messages m
                 WHERE m.conv_id    = cc.conv_id
                   AND m.sender_id != :uid1
                   AND m.is_read   = 0
                   AND m.is_deleted = 0) AS unread
            FROM admin_chat_conversations cc
            JOIN users u      ON cc.admin_id = u.user_id
            LEFT JOIN admin a ON u.user_id   = a.user_id
            WHERE cc.organizer_id = :uid2
            ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC
        ");
        $st->execute([':uid1' => $uid, ':uid2' => $uid]);
    }

    api_ok(['conversations' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ============================================================
 *  ACTION: open_conversation
 * ============================================================ */
if ($action === 'open_conversation') {
    $otherId = (int)($_POST['other_id'] ?? 0);
    if (!$otherId) api_err('Missing other_id');

    if ($role === 'admin') {
        $adminId = $uid; $orgId = $otherId;
        $chk = $pdo->prepare(
            "SELECT user_id FROM users WHERE user_id = ? AND role = 'organizer' AND deleted_at IS NULL"
        );
        $chk->execute([$orgId]);
        if (!$chk->fetchColumn()) api_err('Organizer not found', 404);
    } else {
        $orgId = $uid; $adminId = $otherId;
        $chk = $pdo->prepare(
            "SELECT user_id FROM users WHERE user_id = ? AND role = 'admin' AND deleted_at IS NULL"
        );
        $chk->execute([$adminId]);
        if (!$chk->fetchColumn()) api_err('Admin not found', 404);
    }

    // Insert or get existing conversation
    $st = $pdo->prepare("
        INSERT INTO admin_chat_conversations (admin_id, organizer_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE conv_id = LAST_INSERT_ID(conv_id)
    ");
    $st->execute([$adminId, $orgId]);
    $convId = (int)$pdo->lastInsertId();

    if (!$convId) {
        $st2 = $pdo->prepare(
            "SELECT conv_id FROM admin_chat_conversations WHERE admin_id = ? AND organizer_id = ?"
        );
        $st2->execute([$adminId, $orgId]);
        $convId = (int)$st2->fetchColumn();
    }

    if (!$convId) api_err('Could not open conversation', 500);

    api_ok(['conv_id' => $convId]);
}


/* ============================================================
 *  ACTION: fetch_messages
 * ============================================================ */
if ($action === 'fetch_messages') {
    $convId  = (int)($_GET['conv_id']  ?? $_POST['conv_id']  ?? 0);
    $afterId = (int)($_GET['after_id'] ?? $_POST['after_id'] ?? 0);
    if (!$convId) api_err('Missing conv_id');

    verify_conv_access($pdo, $convId, $uid, $role);

    if ($afterId) {
        $st = $pdo->prepare("
            SELECT msg_id, sender_id, message,
                   file_url, file_name, file_type, file_size,
                   is_read, is_deleted, is_edited,
                   DATE_FORMAT(sent_at,'%Y-%m-%d %H:%i:%s') AS sent_at
            FROM admin_chat_messages
            WHERE conv_id = ? AND msg_id > ?
            ORDER BY sent_at ASC
        ");
        $st->execute([$convId, $afterId]);
    } else {
        $st = $pdo->prepare("
            SELECT msg_id, sender_id, message,
                   file_url, file_name, file_type, file_size,
                   is_read, is_deleted, is_edited,
                   DATE_FORMAT(sent_at,'%Y-%m-%d %H:%i:%s') AS sent_at
            FROM admin_chat_messages
            WHERE conv_id = ?
            ORDER BY sent_at ASC
            LIMIT 200
        ");
        $st->execute([$convId]);
    }
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);

    // Reactions
    $reactions = [];
    if ($messages) {
        $ids     = implode(',', array_map('intval', array_column($messages, 'msg_id')));
        $uidSafe = (int)$uid;
        $rSt     = $pdo->query("
            SELECT msg_id, emoji,
                   COUNT(*) AS cnt,
                   MAX(CASE WHEN user_id = {$uidSafe} THEN 1 ELSE 0 END) AS is_mine
            FROM admin_chat_message_reactions
            WHERE msg_id IN ({$ids})
            GROUP BY msg_id, emoji
        ");
        foreach ($rSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reactions[(int)$r['msg_id']][$r['emoji']] = [
                'count' => (int)$r['cnt'],
                'mine'  => (bool)$r['is_mine'],
            ];
        }
    }

    foreach ($messages as &$m) {
        $m['reactions']  = $reactions[(int)$m['msg_id']] ?? new stdClass();
        $m['is_deleted'] = (int)$m['is_deleted'];
        $m['is_edited']  = (int)$m['is_edited'];
    }
    unset($m);

    api_ok(['messages' => $messages]);
}


/* ============================================================
 *  ACTION: send_message
 * ============================================================ */
if ($action === 'send_message') {
    $convId  = (int)($_POST['conv_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if (!$convId) api_err('Missing conv_id');

    verify_conv_access($pdo, $convId, $uid, $role);

    $fileUrl = $fileName = $fileType = $fileSize = null;

    if (!empty($_FILES['attachment']['tmp_name'])) {
        $file    = $_FILES['attachment'];
        $tmpPath = $file['tmp_name'];
        $size    = (int)$file['size'];

        if ($size > 20 * 1024 * 1024) api_err('File too large (max 20 MB)');

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        $allowedMimes = [
            'image/jpeg','image/png','image/gif','image/webp',
            'application/pdf','application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip','application/x-zip-compressed','text/plain',
        ];
        if (!in_array($mimeType, $allowedMimes, true)) api_err('File type not allowed');

        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $safeBase = preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($origName, PATHINFO_FILENAME));
        $newName  = uniqid('achat_', true) . '_' . $safeBase . '.' . $ext;

        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/chat/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if (!move_uploaded_file($tmpPath, $uploadDir . $newName)) api_err('File upload failed');

        $fileUrl  = '/uploads/chat/' . $newName;
        $fileName = $origName;
        $fileType = $mimeType;
        $fileSize = $size;
    }

    if ($message === '' && !$fileUrl) api_err('Empty message');
    if (mb_strlen($message) > 3000)   api_err('Message too long');

    $st = $pdo->prepare("
        INSERT INTO admin_chat_messages
            (conv_id, sender_id, message, file_url, file_name, file_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([$convId, $uid, $message, $fileUrl, $fileName, $fileType, $fileSize]);
    $msgId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE admin_chat_conversations SET last_message_at = NOW() WHERE conv_id = ?")
        ->execute([$convId]);

    api_ok(['msg_id' => $msgId, 'sent_at' => date('Y-m-d H:i:s')]);
}


/* ============================================================
 *  ACTION: unsend_message
 * ============================================================ */
if ($action === 'unsend_message') {
    $msgId = (int)($_POST['msg_id'] ?? 0);
    if (!$msgId) api_err('Missing msg_id');

    $st = $pdo->prepare(
        "SELECT sender_id, conv_id FROM admin_chat_messages WHERE msg_id = ? LIMIT 1"
    );
    $st->execute([$msgId]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$msg)                           api_err('Message not found', 404);
    if ((int)$msg['sender_id'] !== $uid) api_err('You can only unsend your own messages', 403);

    verify_conv_access($pdo, (int)$msg['conv_id'], $uid, $role);

    $pdo->prepare("
        UPDATE admin_chat_messages SET is_deleted = 1, message = '' WHERE msg_id = ?
    ")->execute([$msgId]);

    api_ok();
}


/* ============================================================
 *  ACTION: edit_message
 * ============================================================ */
if ($action === 'edit_message') {
    $msgId      = (int)($_POST['msg_id']  ?? 0);
    $newMessage = trim($_POST['message']  ?? '');

    if (!$msgId)           api_err('Missing msg_id');
    if ($newMessage === '') api_err('Message cannot be empty');
    if (mb_strlen($newMessage) > 3000) api_err('Message too long');

    $st = $pdo->prepare("
        SELECT sender_id, conv_id, sent_at, file_url, is_deleted
        FROM admin_chat_messages
        WHERE msg_id = ? LIMIT 1
    ");
    $st->execute([$msgId]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$msg)                           api_err('Message not found', 404);
    if ((int)$msg['sender_id'] !== $uid) api_err('You can only edit your own messages', 403);
    if ((int)$msg['is_deleted'])         api_err('Cannot edit a deleted message', 400);
    if ($msg['file_url'])                api_err('File messages cannot be edited', 400);

    verify_conv_access($pdo, (int)$msg['conv_id'], $uid, $role);

    $sentAt   = new DateTime($msg['sent_at']);
    $diffSecs = (new DateTime())->getTimestamp() - $sentAt->getTimestamp();
    if ($diffSecs > 15 * 60) api_err('Edit window expired (15 minutes)', 403);

    $pdo->prepare("UPDATE admin_chat_messages SET message = ?, is_edited = 1 WHERE msg_id = ?")
        ->execute([$newMessage, $msgId]);

    api_ok();
}


/* ============================================================
 *  ACTION: react_message
 * ============================================================ */
if ($action === 'react_message') {
    $msgId = (int)($_POST['msg_id'] ?? 0);
    $emoji = trim($_POST['emoji']   ?? '');
    if (!$msgId) api_err('Missing msg_id');

    $allowed = ['👍','❤️','😂','😮','😢','😡'];
    if (!in_array($emoji, $allowed, true)) api_err('Invalid emoji');

    $st = $pdo->prepare("
        SELECT cc.admin_id, cc.organizer_id
        FROM admin_chat_messages m
        JOIN admin_chat_conversations cc ON m.conv_id = cc.conv_id
        WHERE m.msg_id = ? LIMIT 1
    ");
    $st->execute([$msgId]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) api_err('Message not found', 404);
    if ($role === 'admin'     && (int)$conv['admin_id']     !== $uid) api_err('Forbidden', 403);
    if ($role === 'organizer' && (int)$conv['organizer_id'] !== $uid) api_err('Forbidden', 403);

    $chk = $pdo->prepare("
        SELECT reaction_id FROM admin_chat_message_reactions
        WHERE msg_id = ? AND user_id = ? AND emoji = ?
    ");
    $chk->execute([$msgId, $uid, $emoji]);
    $existing = $chk->fetchColumn();

    if ($existing) {
        $pdo->prepare("DELETE FROM admin_chat_message_reactions WHERE reaction_id = ?")
            ->execute([$existing]);
        api_ok(['action' => 'removed']);
    } else {
        $pdo->prepare(
            "INSERT INTO admin_chat_message_reactions (msg_id, user_id, emoji) VALUES (?, ?, ?)"
        )->execute([$msgId, $uid, $emoji]);
        api_ok(['action' => 'added']);
    }
}


/* ============================================================
 *  ACTION: mark_read
 * ============================================================ */
if ($action === 'mark_read') {
    $convId = (int)($_POST['conv_id'] ?? 0);
    if (!$convId) api_err('Missing conv_id');

    verify_conv_access($pdo, $convId, $uid, $role);

    $pdo->prepare("
        UPDATE admin_chat_messages SET is_read = 1
        WHERE conv_id = ? AND sender_id != ? AND is_read = 0
    ")->execute([$convId, $uid]);

    api_ok();
}


/* ============================================================
 *  ACTION: unread_count
 * ============================================================ */
if ($action === 'unread_count') {
    if ($role === 'admin') {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM admin_chat_messages m
            JOIN admin_chat_conversations cc ON m.conv_id = cc.conv_id
            WHERE cc.admin_id = ? AND m.sender_id != ? AND m.is_read = 0 AND m.is_deleted = 0
        ");
    } else {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM admin_chat_messages m
            JOIN admin_chat_conversations cc ON m.conv_id = cc.conv_id
            WHERE cc.organizer_id = ? AND m.sender_id != ? AND m.is_read = 0 AND m.is_deleted = 0
        ");
    }
    $st->execute([$uid, $uid]);
    api_ok(['count' => (int)$st->fetchColumn()]);
}

api_err('Unknown action');