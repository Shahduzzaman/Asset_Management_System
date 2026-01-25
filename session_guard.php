<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$idleTimeout = 1800; // 30 minutes
$sessionExpired = false;

if (!isset($_SESSION['user_id'])) {
    $sessionExpired = true;
}

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity'] > $idleTimeout)
) {
    $sessionExpired = true;
}

if ($sessionExpired) {
    session_unset();
    session_destroy();

    if (isset($_GET['iframe'])) {
        echo '<script>window.top.location.href="index.php?reason=expired";</script>';
    } else {
        header("Location: index.php?reason=expired");
    }
    exit();
}

$_SESSION['last_activity'] = time();
