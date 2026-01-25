<?php
ob_start(); 
require_once 'session_guard.php';

$current_user_id = $_SESSION['user_id'];

require_once 'connection.php';

$conn->query("SET sql_mode=''");

$user_role = 0;
if(isset($_SESSION['user_role'])) {
    $user_role = (int)$_SESSION['user_role'];
} else {
    $sql_role_check = "SELECT role FROM users WHERE user_id = ?";
    $stmt_role_check = $conn->prepare($sql_role_check);
    $stmt_role_check->bind_param("i", $current_user_id);
    $stmt_role_check->execute();
    $result_role_check = $stmt_role_check->get_result();
    if($row_role = $result_role_check->fetch_assoc()) {
        $user_role = $row_role['role'];
        $_SESSION['user_role'] = $user_role;
    }
    $stmt_role_check->close();
}

if ($user_role != 1) {
    $_SESSION['error_message'] = "Access Denied: You do not have permission to manage branches.";
    header("Location: dashboard.php");
    exit();
}

$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']);

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'keep_alive') {
        echo json_encode(['status' => 'success']);
    }
    $conn->close();
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['Name']);
    $address = !empty($_POST['Address']) ? trim($_POST['Address']) : null;
    $email = !empty($_POST['Email']) ? trim($_POST['Email']) : null;
    $phone = !empty($_POST['Phone']) ? trim($_POST['Phone']) : null;

    if (empty($name)) {
        $_SESSION['errorMessage'] = "Branch Name is a required field.";
    } else {
        $sql = "INSERT INTO branch (Name, Address, Email, Phone, created_by) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssi", $name, $address, $email, $phone, $current_user_id);
        
        if ($stmt->execute()) {
            $_SESSION['successMessage'] = "Branch '$name' added successfully!";
        } else {
            $_SESSION['errorMessage'] = "Error adding branch: " . $stmt->error;
        }
        $stmt->close();
    }
    
    $conn->close();
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

$conn->close();
$idleTimeout = 1800; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Branch</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } .modal.is-open { display: flex; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <main class="w-full max-w-lg mx-auto">
            <div class="bg-white rounded-2xl shadow-xl p-8">
                
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Branch Details</h2>
                    <p class="text-gray-500 mt-2">Create a new company branch or location.</p>
                </div>

                <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $successMessage; ?></span></div><?php endif; ?>
                <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $errorMessage; ?></span></div><?php endif; ?>

                <form id="head-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    <div>
                        <label for="Name" class="block text-sm font-medium text-gray-700 mb-1">Branch Name <span class="text-red-500">*</span></label>
                        <input type="text" id="Name" name="Name" placeholder="e.g., Main Branch, Warehouse" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="Phone" class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="tel" id="Phone" name="Phone" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm">
                    </div>
                    <div>
                        <label for="Email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" id="Email" name="Email" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm">
                    </div>
                    <div>
                        <label for="Address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea id="Address" name="Address" rows="3" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm"></textarea>
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-blue-700 transition duration-200">Save Branch</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

<div id="session-timeout-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl p-6 md:p-8 w-11/12 max-w-md text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4">
            <svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg>
        </div>
        <h3 class="text-2xl font-bold text-gray-800">Session Expiring Soon</h3>
        <p class="text-gray-600 mt-2">You will be logged out due to inactivity.</p>
        <p class="text-gray-500 text-sm mt-4">Redirecting in <span id="redirect-countdown" class="font-semibold">10</span> seconds...</p>
        <div class="mt-6"><button id="stay-logged-in-btn" class="w-full bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition">Stay Logged In</button></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const alertBox = document.getElementById('alert-box');
    if (alertBox) { setTimeout(() => { alertBox.style.transition = 'opacity 0.5s ease'; alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000); }

    (function() {
        const sessionModal = document.getElementById('session-timeout-modal');
        if (!sessionModal) return; 

        const stayLoggedInBtn = document.getElementById('stay-logged-in-btn');
        const countdownElement = document.getElementById('redirect-countdown');
        const idleTimeout = <?php echo $idleTimeout; ?> * 1000;
        const redirectDelay = 10000;
        let timeoutId, countdownInterval;

        function showTimeoutModal() {
            sessionModal.classList.add('is-open');
            let countdown = redirectDelay / 1000;
            countdownElement.textContent = countdown;
            countdownInterval = setInterval(() => {
                countdown--;
                countdownElement.textContent = countdown;
                if (countdown <= 0) { clearInterval(countdownInterval); window.location.href = 'logout.php?reason=idle'; }
            }, 1000);
        }
        function startTimer() { clearTimeout(timeoutId); timeoutId = setTimeout(showTimeoutModal, idleTimeout - redirectDelay); }
        
        stayLoggedInBtn.addEventListener('click', async () => {
            clearInterval(countdownInterval);
            sessionModal.classList.remove('is-open');
            try {
                await fetch('add_branch.php?action=keep_alive');
                startTimer();
            } catch (error) { window.location.href = 'logout.php?reason=idle'; }
        });
        startTimer();
    })();
});
</script>
</body>
</html>