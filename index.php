<?php
// Start session safely (prevents duplicate session warnings)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If the user is already logged in, redirect to dashboard
if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit();
}

$errorMessage = '';

// Normalize logout / redirect reasons
$reason = $_GET['reason'] ?? '';

switch ($reason) {
    case 'expired':
        $errorMessage = "Your session has expired. Please log in again.";
        break;

    case 'logout':
        $errorMessage = "You have been logged out successfully.";
        break;

    case 'branch_missing':
        $errorMessage = "You must be logged in with an assigned branch to access that page.";
        break;
}

// Handle login submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    require_once 'connection.php';

    $email    = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $sql = "
        SELECT 
            u.user_id,
            u.user_name,
            u.password_hash,
            u.role,
            u.branch_id_fk,
            b.Name AS branch_name
        FROM users AS u
        LEFT JOIN branch AS b ON u.branch_id_fk = b.branch_id
        WHERE u.email = ?
          AND u.is_deleted = FALSE
          AND u.status = FALSE
        LIMIT 1
    ";

    if ($stmt = $conn->prepare($sql)) {

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {

            $stmt->bind_result(
                $user_id,
                $user_name,
                $password_hash,
                $user_role,
                $branch_id,
                $branch_name
            );

            if ($stmt->fetch() && $password_hash !== null) {

                if (password_verify($password, $password_hash)) {

                    // Secure session handling
                    session_regenerate_id(true);

                    $_SESSION['user_id']       = $user_id;
                    $_SESSION['user_name']     = $user_name;
                    $_SESSION['user_role']     = $user_role;
                    $_SESSION['branch_id']     = $branch_id;
                    $_SESSION['branch_name']   = $branch_name;
                    $_SESSION['last_activity'] = time();

                    header("Location: dashboard.php");
                    exit();

                } else {
                    $errorMessage = "Invalid email or password.";
                }
            }

        } else {
            $errorMessage = "Invalid email or password, or account disabled.";
        }

        $stmt->close();

    } else {
        $errorMessage = "An internal error occurred. Please try again later.";
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Protection One AMS - Login</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen">

<div class="w-full max-w-sm mx-auto bg-white p-8 rounded-xl shadow-lg">

    <div class="flex flex-col items-center mb-6">
        <img src="images/logo.png" alt="Protection One AMS Logo"
             class="h-16 w-auto mb-4"
             onerror="this.style.display='none'">
        <h1 class="text-2xl font-bold text-gray-800">Protection One AMS</h1>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 text-center">
            <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-6">

        <input type="email"
               name="username"
               placeholder="Email Address"
               required
               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">

        <div class="relative">
            <input type="password"
                   id="password"
                   name="password"
                   placeholder="Password"
                   required
                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500">

            <button type="button"
                    id="togglePassword"
                    class="absolute inset-y-0 right-0 px-4 text-gray-500">
                👁
            </button>
        </div>

        <button type="submit"
                class="w-full bg-indigo-600 text-white font-semibold py-3 rounded-lg hover:bg-indigo-700 transition">
            Login
        </button>

    </form>

    <div class="text-center mt-6">
        <a href="#" class="text-sm text-indigo-600 hover:underline">
            Forgot Password?
        </a>
    </div>

</div>

<script>
    const pwd = document.getElementById('password');
    const btn = document.getElementById('togglePassword');

    btn.addEventListener('click', () => {
        pwd.type = pwd.type === 'password' ? 'text' : 'password';
    });

    // Clean URL after showing message
    if (window.location.search.includes('reason=')) {
        const cleanUrl = window.location.origin + window.location.pathname;
        window.history.replaceState({}, '', cleanUrl);
    }
</script>

</body>
</html>
