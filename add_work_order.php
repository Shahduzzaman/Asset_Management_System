<?php
ob_start(); // Prevents redirect issues
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Standard session guard include
require_once 'session_guard.php';

$current_user_id = $_SESSION['user_id'];
$idleTimeout = 1800; // Define variable if not in session_guard to prevent JS errors

// --- FLASH MESSAGE HANDLING ---
$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']);

require_once 'connection.php';
// Disable Strict Mode for compatibility with older SQL logic
$conn->query("SET sql_mode=''");

// --- Handle Form Submission (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $order_no = trim($_POST['order_no']);
    $order_date = $_POST['order_date'];
    $client_selection = $_POST['client_id'] ?? ''; 
    
    $client_head_id_fk = null;
    $client_branch_id_fk = null;

    if (!empty($client_selection)) {
        list($type, $id) = explode('_', $client_selection);
        if ($type === 'head') {
            $client_head_id_fk = intval($id);
        } elseif ($type === 'branch') {
            $client_branch_id_fk = intval($id);
        }
    }

    if (empty($order_no) || empty($order_date) || (empty($client_head_id_fk) && empty($client_branch_id_fk))) {
        $_SESSION['errorMessage'] = "Please provide an Order No, Order Date, and select a Client.";
    } else {
        // Table and Column names must match ams (36).sql exactly
        $sql = "INSERT INTO work_order (Order_No, Order_Date, client_head_id_fk, client_branch_id_fk, created_by) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssiii", $order_no, $order_date, $client_head_id_fk, $client_branch_id_fk, $current_user_id);
        
        if ($stmt->execute()) {
            $_SESSION['successMessage'] = "Work Order '$order_no' created successfully!";
        } else {
            if ($conn->errno == 1062) {
                $_SESSION['errorMessage'] = "Error: A Work Order with this Order Number already exists.";
            } else {
                $_SESSION['errorMessage'] = "Error creating Work Order: " . $conn->error;
            }
        }
        $stmt->close();
    }
    
    $conn->close();
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
}

// --- Fetch data for page load ---
// Fix: Updated table and column names to match your SQL schema exactly
$client_list_sql = "
    (SELECT client_head_id as id, Company_Name as name, 'Head Office' as type, 'head' as type_key FROM client_head WHERE is_deleted = FALSE)
    UNION ALL
    (SELECT cb.client_branch_id as id, cb.Branch_Name as name, 'Branch Office' as type, 'branch' as type_key 
     FROM client_branch cb 
     JOIN client_head ch ON cb.client_head_id_fk = ch.client_head_id 
     WHERE cb.is_deleted = FALSE AND ch.is_deleted = FALSE)
    ORDER BY name
";

$result = $conn->query($client_list_sql);

if (!$result) {
    // Debugging line: if this fails, it will tell you the exact SQL error
    die("Database Query Failed: " . $conn->error);
}

$clients = $result->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        <main class="w-full max-w-lg mx-auto">
            <div class="bg-white rounded-2xl shadow-xl p-8">
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Work Order Details</h2>
                </div>

                <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
                <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    <div>
                        <label for="client_id" class="block text-sm font-medium text-gray-700 mb-1">Client <span class="text-red-500">*</span></label>
                        <select id="client_id" name="client_id" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-indigo-500">
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
                        <input type="text" id="order_no" name="order_no" placeholder="e.g., WO-2025-001" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm">
                    </div>

                    <div>
                        <label for="order_date" class="block text-sm font-medium text-gray-700 mb-1">Order Date <span class="text-red-500">*</span></label>
                        <input type="date" id="order_date" name="order_date" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm">
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg shadow-md hover:bg-green-700 transition">
                            Create Work Order
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('order_date').valueAsDate = new Date();
        const alertBox = document.getElementById('alert-box');
        if (alertBox) { setTimeout(() => { alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000); }
    });
    </script>
</body>
</html>