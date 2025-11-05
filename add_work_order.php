<?php
session_start();

// --- START: SESSION & SECURITY CHECKS ---
$idleTimeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset(); session_destroy(); header("Location: index.php?reason=idle"); exit();
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php"); exit();
}
$current_user_id = $_SESSION['user_id'];
// --- END: SESSION & SECURITY CHECKS ---

// --- START: FLASH MESSAGE HANDLING ---
$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']);
// --- END: FLASH MESSAGE HANDLING ---

require_once 'connection.php';

// --- Handle Form Submission (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_no = trim($_POST['order_no']);
    $order_date = $_POST['order_date'];
    $client_selection = $_POST['client_id'] ?? ''; // Format: "head_5" or "branch_12"
    
    $client_head_id_fk = null;
    $client_branch_id_fk = null;

    // Parse the client selection
    if (!empty($client_selection)) {
        list($type, $id) = explode('_', $client_selection);
        if ($type === 'head') {
            $client_head_id_fk = intval($id);
        } elseif ($type === 'branch') {
            $client_branch_id_fk = intval($id);
        }
    }

    // Server-side validation
    if (empty($order_no) || empty($order_date) || (empty($client_head_id_fk) && empty($client_branch_id_fk))) {
        $_SESSION['errorMessage'] = "Please provide an Order No, Order Date, and select a Client.";
    } else {
        $sql = "INSERT INTO Work_Order (Order_No, Order_Date, client_head_id_fk, client_branch_id_fk, created_by) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiii", $order_no, $order_date, $client_head_id_fk, $client_branch_id_fk, $current_user_id);
        
        if ($stmt->execute()) {
            $_SESSION['successMessage'] = "Work Order '$order_no' created successfully!";
        } else {
            if ($conn->errno == 1062) { // Duplicate Order_No
                $_SESSION['errorMessage'] = "Error: A Work Order with this Order Number already exists.";
            } else {
                $_SESSION['errorMessage'] = "Error creating Work Order: " . $stmt->error;
            }
        }
        $stmt->close();
    }
    
    $conn->close();
    // Redirect to self to prevent re-submission on reload
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// --- Fetch data for page load (GET request) ---
// Fetch all clients (Head and Branch) for the dropdown
$client_list_sql = "
    (SELECT client_head_id as id, Company_Name as name, 'Head Office' as type, 'head' as type_key FROM Client_Head WHERE is_deleted = FALSE)
    UNION ALL
    (SELECT cb.client_branch_id as id, cb.Branch_Name as name, 'Branch Office' as type, 'branch' as type_key FROM Client_Branch cb JOIN Client_Head ch ON cb.client_head_id_fk = ch.client_head_id WHERE cb.is_deleted = FALSE AND ch.is_deleted = FALSE)
    ORDER BY name
";
$clients = $conn->query($client_list_sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Work Order</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } .modal.is-open { display: flex; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Add New Work Order</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>

        <main class="w-full max-w-lg mx-auto">
            <div class="bg-white rounded-2xl shadow-xl p-8">
                
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Work Order Details</h2>
                </div>

                <!-- Display Success or Error Messages -->
                <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $successMessage; ?></span></div><?php endif; ?>
                <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $errorMessage; ?></span></div><?php endif; ?>

                <!-- Work Order Form -->
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    
                    <div>
                        <label for="client_id" class="block text-sm font-medium text-gray-700 mb-1">Client <span class="text-red-500">*</span></label>
                        <select id="client_id" name="client_id" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">-- Select a Client --</option>
                            <optgroup label="Head Offices">
                                <?php foreach ($clients as $client): if($client['type_key'] === 'head'): ?>
                                <option value="head_<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                            <optgroup label="Branch Offices">
                                 <?php foreach ($clients as $client): if($client['type_key'] === 'branch'): ?>
                                <option value="branch_<?php echo $client['id']; ?>"><?php echo htmlspecialchars($client['name']); ?></option>
                                <?php endif; endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label for="order_no" class="block text-sm font-medium text-gray-700 mb-1">Order No. <span class="text-red-500">*</span></label>
                        <input type="text" id="order_no" name="order_no" placeholder="e.g., WO-2025-001" required
                               class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="order_date" class="block text-sm font-medium text-gray-700 mb-1">Order Date <span class="text-red-500">*</span></label>
                        <input type="date" id="order_date" name="order_date" required
                               class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <!-- Submit Button -->
                    <div class="pt-4">
                        <button type="submit"
                                class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition">
                            Create Work Order
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

<!-- Session Timeout Modal -->
<div id="session-timeout-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl p-6 md:p-8 w-11/12 max-w-md text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4"><svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg></div>
        <h3 class="text-2xl font-bold text-gray-800">Session Expiring Soon</h3>
        <p class="text-gray-600 mt-2">You will be logged out due to inactivity.</p>
        <p class="text-gray-500 text-sm mt-4">Redirecting in <span id="redirect-countdown" class="font-semibold">10</span> seconds...</p>
        <div class="mt-6"><button id="stay-logged-in-btn" class="w-full bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition">Stay Logged In</button></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Set default order date
    document.getElementById('order_date').valueAsDate = new Date();

    // Auto-hide alerts
    const alertBox = document.getElementById('alert-box');
    if (alertBox) { setTimeout(() => { alertBox.style.transition = 'opacity 0.5s ease'; alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000); }

    // --- Session Timeout Logic ---
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
                // Ping the current page to keep the server session alive
                await fetch('add_work_order.php?action=keep_alive');
                startTimer();
            } catch (error) { window.location.href = 'logout.php?reason=idle'; }
        });
        startTimer();
    })();
});
</script>

</body>
</html>
