<?php
/* ============================================================
 *  includes/chat_api.php — SEMS Chat Backend
 *  Place this file at:  includes/chat_api.php
 *
 *  Actions (POST ?action=xxx):
 *    get_contacts        – list of people the caller can talk to
 *    get_conversations   – list of active threads for caller
 *    open_conversation   – get or create a conv_id
 *    fetch_messages      – paginated messages for a conv_id
 *    send_message        – post a message (text and/or file)
 *    react_message       – toggle an emoji reaction on a message
 *    unsend_message      – soft-delete a message (sender only)
 *    edit_message        – edit a text message within 15 minutes (sender only)
 *    mark_read           – mark messages in a conv as read
 *    unread_count        – total unread badge for header
 *
 *  NEW DB OBJECTS REQUIRED — run once on your DB:
 * ─────────────────────────────────────────────────────────────
 *  ALTER TABLE chat_messages
 *      ADD COLUMN file_url    VARCHAR(512)  NULL AFTER message,
 *      ADD COLUMN file_name   VARCHAR(255)  NULL AFTER file_url,
 *      ADD COLUMN file_type   VARCHAR(100)  NULL AFTER file_name,
 *      ADD COLUMN file_size   INT UNSIGNED  NULL AFTER file_type,
 *      ADD COLUMN is_deleted  TINYINT(1)    NOT NULL DEFAULT 0 AFTER file_size,
 *      ADD COLUMN is_edited   TINYINT(1)    NOT NULL DEFAULT 0 AFTER is_deleted;
 *
 *  -- If file columns already exist, just add the two new ones:
 *  ALTER TABLE chat_messages
 *      ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0,
 *      ADD COLUMN IF NOT EXISTS is_edited  TINYINT(1) NOT NULL DEFAULT 0;
 *
 *  CREATE TABLE IF NOT EXISTS chat_message_reactions (
 *      reaction_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *      msg_id       INT UNSIGNED NOT NULL,
 *      user_id      INT UNSIGNED NOT NULL,
 *      emoji        VARCHAR(10)  NOT NULL,
 *      created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
 *      UNIQUE KEY uq_msg_user_emoji (msg_id, user_id, emoji),
 *      KEY idx_msg (msg_id)
 *  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 *
 *  Also create this directory (writable by your web server):
 *      <docroot>/uploads/chat/
 * ============================================================ */

session_start();
$pdo = require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth guard ────────────────────────────────────────────────
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit();
}

$uid  = (int) $_SESSION['user_id'];
$role = $_SESSION['role']; // 'student' | 'organizer'

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ── Helper: JSON out ──────────────────────────────────────────
function api_ok(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data));
    exit();
}
function api_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit();
}

// ── Helper: fetch caller's scope ─────────────────────────────
function get_caller_scope(PDO $pdo, int $uid, string $role): array {
    if ($role === 'organizer') {
        $st = $pdo->prepare("
            SELECT u.org_id, u.club_id, u.dept_id,
                   o.scope AS org_scope
            FROM users u
            LEFT JOIN organizations o ON u.org_id = o.org_id
            WHERE u.user_id = ?
        ");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'org_id'    => $r['org_id']    ? (int)$r['org_id']    : null,
            'club_id'   => $r['club_id']   ? (int)$r['club_id']   : null,
            'dept_id'   => $r['dept_id']   ? (int)$r['dept_id']   : null,
            'org_scope' => $r['org_scope'] ?? null,
        ];
    } else {
        $st = $pdo->prepare("SELECT org_id, club_id, dept_id FROM users WHERE user_id = ?");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'org_id'  => $r['org_id']  ? (int)$r['org_id']  : null,
            'club_id' => $r['club_id'] ? (int)$r['club_id'] : null,
            'dept_id' => $r['dept_id'] ? (int)$r['dept_id'] : null,
        ];
    }
}

// ── Helper: check if organizer can talk to student ────────────
function organizer_can_reach(PDO $pdo, int $orgUserId, int $stuUserId): bool {
    $st = $pdo->prepare("
        SELECT u.org_id, u.club_id, u.dept_id, o.scope
        FROM users u
        LEFT JOIN organizations o ON u.org_id = o.org_id
        WHERE u.user_id = ?
    ");
    $st->execute([$orgUserId]);
    $org = $st->fetch(PDO::FETCH_ASSOC);
    if (!$org) return false;

    $st2 = $pdo->prepare("SELECT dept_id, club_id FROM users WHERE user_id = ? AND deleted_at IS NULL AND role = 'student'");
    $st2->execute([$stuUserId]);
    $stu = $st2->fetch(PDO::FETCH_ASSOC);
    if (!$stu) return false;

    if ($org['org_id'] && $org['scope'] === 'all')  return true;
    if ($org['org_id'] && $org['scope'] === 'dept') return (int)$org['dept_id'] === (int)$stu['dept_id'];
    if ($org['club_id'])                             return (int)$org['club_id'] === (int)$stu['club_id'];
    return false;
}

// ── Helper: check if student can talk to organizer ────────────
function student_can_reach(PDO $pdo, int $stuUserId, int $orgUserId): bool {
    return organizer_can_reach($pdo, $orgUserId, $stuUserId);
}

// ── Helper: verify caller belongs to conversation ─────────────
function verify_conv_access(PDO $pdo, int $convId, int $uid, string $role): array {
    $st = $pdo->prepare("SELECT organizer_id, student_id FROM chat_conversations WHERE conv_id = ?");
    $st->execute([$convId]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) api_err('Conversation not found', 404);
    if ($role === 'organizer' && (int)$conv['organizer_id'] !== $uid) api_err('Forbidden', 403);
    if ($role === 'student'   && (int)$conv['student_id']   !== $uid) api_err('Forbidden', 403);
    return $conv;
}


/* ============================================================
 *  ACTION: get_contacts
 * ============================================================ */
if ($action === 'get_contacts') {
    if ($role === 'organizer') {
        $scope = get_caller_scope($pdo, $uid, 'organizer');

        if ($scope['org_id'] && $scope['org_scope'] === 'all') {
            $st = $pdo->prepare("
                SELECT u.user_id,
                       CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) AS full_name,
                       p.student_number, d.dept_name, p.year_level, p.section
                FROM users u
                LEFT JOIN profiles p    ON u.user_id = p.user_id
                LEFT JOIN departments d ON u.dept_id  = d.dept_id
                WHERE u.role = 'student' AND u.deleted_at IS NULL
                ORDER BY p.last_name, p.first_name
            ");
            $st->execute();
        } elseif ($scope['org_id'] && $scope['org_scope'] === 'dept') {
            $st = $pdo->prepare("
                SELECT u.user_id,
                       CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) AS full_name,
                       p.student_number, d.dept_name, p.year_level, p.section
                FROM users u
                LEFT JOIN profiles p    ON u.user_id = p.user_id
                LEFT JOIN departments d ON u.dept_id  = d.dept_id
                WHERE u.role = 'student' AND u.deleted_at IS NULL AND u.dept_id = ?
                ORDER BY p.last_name, p.first_name
            ");
            $st->execute([$scope['dept_id']]);
        } elseif ($scope['club_id']) {
            $st = $pdo->prepare("
                SELECT u.user_id,
                       CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) AS full_name,
                       p.student_number, d.dept_name, p.year_level, p.section
                FROM users u
                LEFT JOIN profiles p    ON u.user_id = p.user_id
                LEFT JOIN departments d ON u.dept_id  = d.dept_id
                WHERE u.role = 'student' AND u.deleted_at IS NULL AND u.club_id = ?
                ORDER BY p.last_name, p.first_name
            ");
            $st->execute([$scope['club_id']]);
        } else {
            api_ok(['contacts' => []]);
        }

        api_ok(['contacts' => $st->fetchAll(PDO::FETCH_ASSOC)]);

    } else {
        // Student → list reachable organizers
        $scope      = get_caller_scope($pdo, $uid, 'student');
        $conditions = ["(o.scope = 'all')"];
        $params     = [];

        if ($scope['dept_id']) {
            $conditions[] = "(o.scope = 'dept' AND u.dept_id = ?)";
            $params[]      = $scope['dept_id'];
        }
        if ($scope['club_id']) {
            $conditions[] = "(u.club_id = ? AND u.org_id IS NULL)";
            $params[]      = $scope['club_id'];
        }

        $where = implode(' OR ', $conditions);
        $st = $pdo->prepare("
            SELECT u.user_id,
                   CONCAT(COALESCE(og.first_name,''), ' ', COALESCE(og.last_name,'')) AS full_name,
                   COALESCE(o.org_name, c.club_name, 'Organizer') AS group_name,
                   og.position
            FROM users u
            LEFT JOIN organizer     og ON u.user_id = og.user_id
            LEFT JOIN organizations o  ON u.org_id  = o.org_id
            LEFT JOIN clubs         c  ON u.club_id = c.club_id
            WHERE u.role = 'organizer' AND u.deleted_at IS NULL AND ($where)
            ORDER BY group_name, og.last_name
        ");
        $st->execute($params);
        api_ok(['contacts' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
}


/* ============================================================
 *  ACTION: get_conversations
 * ============================================================ */
if ($action === 'get_conversations') {
    if ($role === 'organizer') {
        $st = $pdo->prepare("
            SELECT
                cc.conv_id,
                cc.student_id  AS other_id,
                CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) AS other_name,
                p.student_number AS other_sub,
                cc.last_message_at,
                (SELECT cm.message FROM chat_messages cm
                 WHERE cm.conv_id = cc.conv_id AND cm.is_deleted = 0
                 ORDER BY cm.sent_at DESC LIMIT 1) AS last_msg,
                (SELECT COUNT(*) FROM chat_messages cm
                 WHERE cm.conv_id = cc.conv_id AND cm.is_read = 0
                 AND cm.sender_id != ? AND cm.is_deleted = 0) AS unread
            FROM chat_conversations cc
            JOIN users u    ON cc.student_id = u.user_id
            LEFT JOIN profiles p ON u.user_id = p.user_id
            WHERE cc.organizer_id = ?
            ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC
        ");
        $st->execute([$uid, $uid]);
    } else {
        $st = $pdo->prepare("
            SELECT
                cc.conv_id,
                cc.organizer_id AS other_id,
                CONCAT(COALESCE(og.first_name,''), ' ', COALESCE(og.last_name,'')) AS other_name,
                COALESCE(o.org_name, c.club_name, og.position) AS other_sub,
                cc.last_message_at,
                (SELECT cm.message FROM chat_messages cm
                 WHERE cm.conv_id = cc.conv_id AND cm.is_deleted = 0
                 ORDER BY cm.sent_at DESC LIMIT 1) AS last_msg,
                (SELECT COUNT(*) FROM chat_messages cm
                 WHERE cm.conv_id = cc.conv_id AND cm.is_read = 0
                 AND cm.sender_id != ? AND cm.is_deleted = 0) AS unread
            FROM chat_conversations cc
            JOIN users u       ON cc.organizer_id = u.user_id
            LEFT JOIN organizer     og ON u.user_id  = og.user_id
            LEFT JOIN organizations o  ON u.org_id   = o.org_id
            LEFT JOIN clubs         c  ON u.club_id  = c.club_id
            WHERE cc.student_id = ?
            ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC
        ");
        $st->execute([$uid, $uid]);
    }

    api_ok(['conversations' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}


/* ============================================================
 *  ACTION: open_conversation
 * ============================================================ */
if ($action === 'open_conversation') {
    $otherId = (int)($_POST['other_id'] ?? 0);
    if (!$otherId) api_err('Missing other_id');

    if ($role === 'organizer') {
        $orgId = $uid; $stuId = $otherId;
        if (!organizer_can_reach($pdo, $orgId, $stuId)) api_err('Not permitted to message this student', 403);
    } else {
        $stuId = $uid; $orgId = $otherId;
        if (!student_can_reach($pdo, $stuId, $orgId)) api_err('Not permitted to message this organizer', 403);
    }

    $st = $pdo->prepare("
        INSERT INTO chat_conversations (organizer_id, student_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE conv_id = LAST_INSERT_ID(conv_id)
    ");
    $st->execute([$orgId, $stuId]);
    $convId = (int)$pdo->lastInsertId();

    if (!$convId) {
        $st2 = $pdo->prepare("SELECT conv_id FROM chat_conversations WHERE organizer_id = ? AND student_id = ?");
        $st2->execute([$orgId, $stuId]);
        $convId = (int)$st2->fetchColumn();
    }

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
            FROM chat_messages
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
            FROM chat_messages
            WHERE conv_id = ?
            ORDER BY sent_at ASC
            LIMIT 200
        ");
        $st->execute([$convId]);
    }
    $messages = $st->fetchAll(PDO::FETCH_ASSOC);

    // ── Fetch reactions for this batch ────────────────────────
    $reactions = [];
    if ($messages) {
        $ids     = implode(',', array_map('intval', array_column($messages, 'msg_id')));
        $uidSafe = (int)$uid;
        $rSt = $pdo->query("
            SELECT msg_id, emoji,
                   COUNT(*)  AS cnt,
                   MAX(CASE WHEN user_id = {$uidSafe} THEN 1 ELSE 0 END) AS is_mine
            FROM chat_message_reactions
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

    $fileUrl  = null;
    $fileName = null;
    $fileType = null;
    $fileSize = null;

    if (!empty($_FILES['attachment']['tmp_name'])) {
        $file    = $_FILES['attachment'];
        $tmpPath = $file['tmp_name'];
        $size    = (int)$file['size'];

        if ($size > 20 * 1024 * 1024) api_err('File too large (max 20 MB)');

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);

        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip', 'application/x-zip-compressed',
            'text/plain',
        ];
        if (!in_array($mimeType, $allowedMimes, true)) api_err('File type not allowed');

        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $safeBase = preg_replace('/[^a-z0-9_\-]/i', '_', pathinfo($origName, PATHINFO_FILENAME));
        $newName  = uniqid('chat_', true) . '_' . $safeBase . '.' . $ext;

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
        INSERT INTO chat_messages (conv_id, sender_id, message, file_url, file_name, file_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $st->execute([$convId, $uid, $message, $fileUrl, $fileName, $fileType, $fileSize]);
    $msgId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE chat_conversations SET last_message_at = NOW() WHERE conv_id = ?")
        ->execute([$convId]);

    api_ok(['msg_id' => $msgId, 'sent_at' => date('Y-m-d H:i:s')]);
}


/* ============================================================
 *  ACTION: unsend_message
 *  Soft-deletes the message so both parties see the unsent
 *  notice on their next poll. Only the original sender can
 *  unsend their own message.
 * ============================================================ */
if ($action === 'unsend_message') {
    $msgId = (int)($_POST['msg_id'] ?? 0);
    if (!$msgId) api_err('Missing msg_id');

    // Fetch the message to verify ownership
    $st = $pdo->prepare("
        SELECT sender_id, conv_id
        FROM chat_messages
        WHERE msg_id = ?
        LIMIT 1
    ");
    $st->execute([$msgId]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$msg)                           api_err('Message not found', 404);
    if ((int)$msg['sender_id'] !== $uid) api_err('You can only unsend your own messages', 403);

    // Verify caller is part of this conversation
    verify_conv_access($pdo, (int)$msg['conv_id'], $uid, $role);

    // Soft-delete: blank text + flag as deleted
    $pdo->prepare("
        UPDATE chat_messages
        SET is_deleted = 1, message = ''
        WHERE msg_id = ?
    ")->execute([$msgId]);

    api_ok();
}


/* ============================================================
 *  ACTION: edit_message
 *  Lets the original sender fix a text message within the
 *  15-minute edit window. File messages cannot be edited.
 * ============================================================ */
if ($action === 'edit_message') {
    $msgId      = (int)($_POST['msg_id']  ?? 0);
    $newMessage = trim($_POST['message']  ?? '');

    if (!$msgId)           api_err('Missing msg_id');
    if ($newMessage === '') api_err('Message cannot be empty');
    if (mb_strlen($newMessage) > 3000) api_err('Message too long');

    // Fetch the message to validate ownership, timing, and type
    $st = $pdo->prepare("
        SELECT sender_id, conv_id, sent_at, file_url, is_deleted
        FROM chat_messages
        WHERE msg_id = ?
        LIMIT 1
    ");
    $st->execute([$msgId]);
    $msg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$msg)                           api_err('Message not found', 404);
    if ((int)$msg['sender_id'] !== $uid) api_err('You can only edit your own messages', 403);
    if ((int)$msg['is_deleted'])         api_err('Cannot edit a deleted message', 400);
    if ($msg['file_url'])                api_err('File messages cannot be edited', 400);

    // Verify caller is part of this conversation
    verify_conv_access($pdo, (int)$msg['conv_id'], $uid, $role);

    // Enforce the 15-minute window server-side
    $sentAt   = new DateTime($msg['sent_at']);
    $now      = new DateTime();
    $diffSecs = $now->getTimestamp() - $sentAt->getTimestamp();
    if ($diffSecs > 15 * 60) api_err('Edit window expired (15 minutes)', 403);

    $pdo->prepare("
        UPDATE chat_messages
        SET message = ?, is_edited = 1
        WHERE msg_id = ?
    ")->execute([$newMessage, $msgId]);

    api_ok();
}


/* ============================================================
 *  ACTION: react_message
 * ============================================================ */
if ($action === 'react_message') {
    $msgId = (int)($_POST['msg_id'] ?? 0);
    $emoji = trim($_POST['emoji']   ?? '');
    if (!$msgId) api_err('Missing msg_id');

    $allowed = ['👍', '❤️', '😂', '😮', '😢', '😡'];
    if (!in_array($emoji, $allowed, true)) api_err('Invalid emoji');

    $st = $pdo->prepare("
        SELECT cc.organizer_id, cc.student_id
        FROM chat_messages cm
        JOIN chat_conversations cc ON cm.conv_id = cc.conv_id
        WHERE cm.msg_id = ?
        LIMIT 1
    ");
    $st->execute([$msgId]);
    $conv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$conv) api_err('Message not found', 404);
    if ($role === 'organizer' && (int)$conv['organizer_id'] !== $uid) api_err('Forbidden', 403);
    if ($role === 'student'   && (int)$conv['student_id']   !== $uid) api_err('Forbidden', 403);

    $chk = $pdo->prepare("
        SELECT reaction_id FROM chat_message_reactions
        WHERE msg_id = ? AND user_id = ? AND emoji = ?
    ");
    $chk->execute([$msgId, $uid, $emoji]);
    $existing = $chk->fetchColumn();

    if ($existing) {
        $pdo->prepare("DELETE FROM chat_message_reactions WHERE reaction_id = ?")
            ->execute([$existing]);
        api_ok(['action' => 'removed']);
    } else {
        $pdo->prepare("
            INSERT INTO chat_message_reactions (msg_id, user_id, emoji)
            VALUES (?, ?, ?)
        ")->execute([$msgId, $uid, $emoji]);
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
        UPDATE chat_messages SET is_read = 1
        WHERE conv_id = ? AND sender_id != ? AND is_read = 0
    ")->execute([$convId, $uid]);

    api_ok();
}


/* ============================================================
 *  ACTION: unread_count
 * ============================================================ */
if ($action === 'unread_count') {
    if ($role === 'organizer') {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM chat_messages cm
            JOIN chat_conversations cc ON cm.conv_id = cc.conv_id
            WHERE cc.organizer_id = ? AND cm.sender_id != ?
              AND cm.is_read = 0 AND cm.is_deleted = 0
        ");
    } else {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM chat_messages cm
            JOIN chat_conversations cc ON cm.conv_id = cc.conv_id
            WHERE cc.student_id = ? AND cm.sender_id != ?
              AND cm.is_read = 0 AND cm.is_deleted = 0
        ");
    }
    $st->execute([$uid, $uid]);
    api_ok(['count' => (int)$st->fetchColumn()]);
}

api_err('Unknown action');