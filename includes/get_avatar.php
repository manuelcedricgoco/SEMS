<?php
/* ============================================================
 *  includes/get_avatar.php
 *  Serves a user's profile image by user_id.
 *  Usage:  <img src="../includes/get_avatar.php?uid=42">
 *
 *  Lookup order:
 *    1. profiles   (students + organizers who updated profile)
 *    2. organizer  (organizer default image)
 *    3. admin      (admin image — was the missing fallback)
 * ============================================================ */
session_start();

// FIX: use __DIR__ so the path always resolves correctly
$pdo = require_once __DIR__ . '/db.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit();
}

$uid = (int)($_GET['uid'] ?? 0);
if (!$uid) {
    http_response_code(400);
    exit();
}

$img  = null;
$mime = 'image/jpeg';

// ── 1. profiles table (students & organizers) ──────────────────
$st = $pdo->prepare("SELECT profile_image FROM profiles WHERE user_id = ? LIMIT 1");
$st->execute([$uid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if ($row && !empty($row['profile_image'])) {
    $img = $row['profile_image'];
}

// ── 2. organizer table fallback ────────────────────────────────
if (!$img) {
    $st2 = $pdo->prepare("SELECT profile_image FROM organizer WHERE user_id = ? LIMIT 1");
    $st2->execute([$uid]);
    $row2 = $st2->fetch(PDO::FETCH_ASSOC);
    if ($row2 && !empty($row2['profile_image'])) {
        $img = $row2['profile_image'];
    }
}

// ── 3. admin table fallback (FIX: was missing) ─────────────────
if (!$img) {
    $st3 = $pdo->prepare("SELECT profile_image FROM admin WHERE user_id = ? LIMIT 1");
    $st3->execute([$uid]);
    $row3 = $st3->fetch(PDO::FETCH_ASSOC);
    if ($row3 && !empty($row3['profile_image'])) {
        $img = $row3['profile_image'];
    }
}

// ── No image found → return transparent 1×1 PNG ───────────────
if (!$img) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    // FIX: return a 404 status so the JS onerror handler fires
    // and the green-circle CSS fallback renders instead of a
    // broken/invisible image sitting on top of it.
    http_response_code(404);
    echo base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAA' .
        'AAYAAjCB0C8AAAAASUVORK5CYII='
    );
    exit();
}

// ── Detect MIME type and stream ────────────────────────────────
$finfo = new finfo(FILEINFO_MIME_TYPE);
$det   = $finfo->buffer($img);
if ($det && strpos($det, 'image/') === 0) {
    $mime = $det;
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=3600');
echo $img;