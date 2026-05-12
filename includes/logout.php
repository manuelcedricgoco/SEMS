<?php
session_start();

// ===============================
// ✅ DESTROY ALL SESSION DATA
// ===============================
$_SESSION = []; // clear session array

// ===============================
// ✅ DESTROY SESSION
// ===============================
session_destroy();

// ===============================
// ✅ OPTIONAL: DELETE SESSION COOKIE
// ===============================
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// ===============================
// ✅ REDIRECT TO LOGIN PAGE
// ===============================
header("Location: auth.php"); // 🔁 change if your login page is different
exit;