<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Completely clear session data
$_SESSION = [];

// Destroy session cookie (important)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Always force top-level redirect (iframe-safe)
echo '<script>window.top.location.href = "index.php?reason=logout";</script>';
exit;
?>

