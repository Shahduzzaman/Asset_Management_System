<?php
session_start();

// --- START: SESSION & SECURITY CHECKS ---
$idleTimeout = 1800; // Set timeout duration in seconds (1800s = 30 minutes)

// Check for idle timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset();
    session_destroy();
    header("Location: index.php?reason=idle");
    exit();
}
$_SESSION['last_activity'] = time();

// Check if user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';

$successMessage = '';
$errorMessage = '';

// --- Handle Form Submission (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve form data
    $vendor_name = trim($_POST['vendor_name']);
    $contact_person = trim($_POST['contact_person']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $created_by = $_SESSION['user_id'];

    // Server-side validation
    if (empty($vendor_name)) {
        $errorMessage = "Vendor Name is a required field.";
    } else {
        // Prepare SQL INSERT statement to prevent SQL injection
        $sql = "INSERT INTO vendors (vendor_name, contact_person, address, phone, email, created_by) VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            // Bind parameters ('sssssi' denotes the data types: string, string, string, string, string, integer)
            $stmt->bind_param("sssssi", $vendor_name, $contact_person, $address, $phone, $email, $created_by);
            
            // Execute the statement and check for success
            if ($stmt->execute()) {
                $successMessage = "Vendor '" . htmlspecialchars($vendor_name) . "' has been added successfully!";
            } else {
                $errorMessage = "Error adding vendor: " . $stmt->error;
            }
            
            $stmt->close();
        } else {
            $errorMessage = "Error preparing statement: " . $conn->error;
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Vendor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        
        <!-- Header and Navigation -->
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Add New Vendor</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition">&larr; Back to Dashboard</a>
        </div>

        <main class="w-full max-w-2xl mx-auto">
            <div class="bg-white rounded-2xl shadow-xl p-8">
                
                <!-- Page Sub-header -->
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Vendor Information</h2>
                    <p class="text-gray-500 mt-2">Please fill out the form to add a new vendor to the system.</p>
                </div>

                <!-- Display Success or Error Messages -->
                <?php if ($successMessage): ?>
                <div id="alert-box" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <strong class="font-bold">Success!</strong>
                    <span class="block sm:inline"><?php echo $successMessage; ?></span>
                </div>
                <?php endif; ?>
                <?php if ($errorMessage): ?>
                <div id="alert-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <strong class="font-bold">Error!</strong>
                    <span class="block sm:inline"><?php echo $errorMessage; ?></span>
                </div>
                <?php endif; ?>

                <!-- Vendor Form -->
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    
                    <div>
                        <label for="vendor_name" class="block text-sm font-medium text-gray-700 mb-1">Vendor Name <span class="text-red-500">*</span></label>
                        <input type="text" id="vendor_name" name="vendor_name" placeholder="" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                    </div>

                    <div>
                        <label for="contact_person" class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                        <input type="text" id="contact_person" name="contact_person" placeholder=""
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                        <input type="email" id="email" name="email" placeholder=""
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                    </div>

                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                        <input type="tel" id="phone" name="phone" placeholder=""
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                    </div>

                    <div>
                        <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea id="address" name="address" rows="3" placeholder=""
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition"></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-4">
                        <button type="submit"
                                class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-transform transform hover:scale-105 duration-300">
                            Save Vendor
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

<!-- ================================================================= -->
<!--    SESSION TIMEOUT MODAL AND SCRIPT (Consistent across pages)   -->
<!-- ================================================================= -->
<div id="session-timeout-modal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl p-6 md:p-8 w-11/12 max-w-md text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4">
            <svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg>
        </div>
        <h3 class="text-2xl font-bold text-gray-800">Session Expiring Soon</h3>
        <p class="text-gray-600 mt-2">You will be logged out due to inactivity.</p>
        <p class="text-gray-500 text-sm mt-4">Redirecting in <span id="redirect-countdown" class="font-semibold">10</span> seconds...</p>
        <div class="mt-6">
            <button id="stay-logged-in-btn" class="w-full bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">Stay Logged In</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Auto-hide alert messages
    const alertBox = document.getElementById('alert-box');
    if (alertBox) {
        setTimeout(() => {
            alertBox.style.transition = 'opacity 0.5s ease';
            alertBox.style.opacity = '0';
            setTimeout(() => alertBox.remove(), 500);
        }, 5000);
    }
});

// --- Session Timeout Logic ---
(function() {
    const sessionModal = document.getElementById('session-timeout-modal');
    const stayLoggedInBtn = document.getElementById('stay-logged-in-btn');
    const countdownElement = document.getElementById('redirect-countdown');
    const idleTimeout = <?php echo $idleTimeout; ?> * 1000;
    const redirectDelay = 10000;
    let timeoutId, countdownInterval;

    function showTimeoutModal() {
        sessionModal.classList.remove('hidden');
        let countdown = redirectDelay / 1000;
        countdownElement.textContent = countdown;
        countdownInterval = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                window.location.href = 'logout.php?reason=idle';
            }
        }, 1000);
    }

    function startTimer() {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(showTimeoutModal, idleTimeout - redirectDelay);
    }

    stayLoggedInBtn.addEventListener('click', async () => {
        clearInterval(countdownInterval);
        sessionModal.classList.add('hidden');
        try {
            // Ping the current page to keep the server session alive
            await fetch('add_vendor.php?action=keep_alive');
            startTimer();
        } catch (error) {
            console.error('Failed to extend session:', error);
            window.location.href = 'logout.php?reason=idle';
        }
    });

    startTimer();
})();
</script>

</body>
</html>
