<?php
require_once 'session_guard.php';
require_once 'connection.php';

// 1. ACCESS CONTROL: Only Admins
if (!isset($_SESSION['user_role']) || (int)$_SESSION['user_role'] !== 1) {
    $_SESSION['errorMessage'] = "Access Denied: Only Admins can edit work orders.";
    header("Location: work_order_list.php");
    exit();
}

$error = '';
$success = '';

// 2. HANDLE FORM SUBMISSION (UPDATE HEADER ONLY)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['work_order_id'])) {
    $wo_id = (int)$_POST['work_order_id'];
    $order_no = trim($_POST['Order_No']);
    $order_date = $_POST['Order_Date'];
    
    // Client Processing
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

    // Update Header (Including Client)
    $sql_update_header = "UPDATE work_order SET Order_No = ?, Order_Date = ?, client_head_id_fk = ?, client_branch_id_fk = ?, is_updated = 1 WHERE work_order_id = ?";
    $stmt = $conn->prepare($sql_update_header);
    $stmt->bind_param("ssiii", $order_no, $order_date, $client_head_id_fk, $client_branch_id_fk, $wo_id);
    
    if ($stmt->execute()) {
        $success = "Order Header updated successfully.";
    } else {
        $error = "Error updating order: " . $stmt->error;
    }
    $stmt->close();
}

// 3. FETCH DATA FOR FORM
$work_order_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['work_order_id']) ? (int)$_POST['work_order_id'] : 0);

if ($work_order_id === 0) {
    header("Location: work_order_list.php");
    exit();
}

// Fetch Header Info
$sql = "SELECT * FROM work_order WHERE work_order_id = ? AND is_deleted = 0";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $work_order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) { die("Order not found or deleted."); }

// Determine Current Client Key for Dropdown Selection
$current_client_key = '';
if (!empty($order['client_head_id_fk'])) {
    $current_client_key = 'head_' . $order['client_head_id_fk'];
} elseif (!empty($order['client_branch_id_fk'])) {
    $current_client_key = 'branch_' . $order['client_branch_id_fk'];
}

// Fetch Items (FOR DISPLAY ONLY - READ ONLY)
$sql_items = "SELECT sp.*, m.model_name, b.brand_name 
              FROM sold_product sp 
              LEFT JOIN models m ON sp.model_id_fk = m.model_id 
              LEFT JOIN brands b ON m.brand_id = b.brand_id
              WHERE sp.work_order_id_fk = ? AND sp.is_deleted = 0";
$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $work_order_id);
$stmt_items->execute();
$items_result = $stmt_items->get_result();

// Fetch Client List for Dropdown
$client_list_sql = "
    (SELECT client_head_id as id, Company_Name as name, 'Head Office' as type, 'head' as type_key FROM client_head WHERE is_deleted = 0)
    UNION ALL
    (SELECT cb.client_branch_id as id, cb.Branch_Name as name, 'Branch Office' as type, 'branch' as type_key FROM client_branch cb JOIN client_head ch ON cb.client_head_id_fk = ch.client_head_id WHERE cb.is_deleted = 0 AND ch.is_deleted = 0)
    ORDER BY name
";
$clients = $conn->query($client_list_sql)->fetch_all(MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Work Order</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 min-h-screen py-8">

<div class="container mx-auto px-4 max-w-4xl">
    
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Edit Work Order Header</h1>
        <a href="work_order_list.php" class="text-sm text-gray-600 hover:text-gray-900">&larr; Back to List</a>
    </div>

    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4"><?php echo $error; ?></div>
    <?php endif; ?>

    <form action="" method="POST" class="bg-white shadow-md rounded-xl p-6">
        <input type="hidden" name="work_order_id" value="<?php echo $work_order_id; ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8 border-b pb-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Order No</label>
                <input type="text" name="Order_No" value="<?php echo htmlspecialchars($order['Order_No']); ?>" required 
                       class="w-full rounded-md border-gray-300 shadow-sm border p-2 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Order Date</label>
                <input type="date" name="Order_Date" value="<?php echo $order['Order_Date']; ?>" required 
                       class="w-full rounded-md border-gray-300 shadow-sm border p-2 focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div class="md:col-span-2">
                <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">Client</label>
                <select id="client_id" name="client_id" required class="w-full rounded-md border-gray-300 shadow-sm border p-2 focus:border-blue-500 focus:ring-blue-500">
                    <option value="">-- Select a Client --</option>
                    <optgroup label="Head Offices">
                        <?php foreach ($clients as $client): if($client['type_key'] === 'head'): 
                            $val = "head_" . $client['id'];
                            $selected = ($val === $current_client_key) ? 'selected' : '';
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($client['name']); ?></option>
                        <?php endif; endforeach; ?>
                    </optgroup>
                    <optgroup label="Branch Offices">
                            <?php foreach ($clients as $client): if($client['type_key'] === 'branch'): 
                            $val = "branch_" . $client['id'];
                            $selected = ($val === $current_client_key) ? 'selected' : '';
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($client['name']); ?></option>
                        <?php endif; endforeach; ?>
                    </optgroup>
                </select>
            </div>
        </div>

        <div class="flex justify-end mb-8">
            <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition duration-150">
                Save Header Changes
            </button>
        </div>

        <h2 class="text-lg font-semibold text-gray-800 mb-4">Order Items (Read Only)</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 border rounded-lg bg-gray-50">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remarks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if ($items_result->num_rows > 0): ?>
                        <?php while($item = $items_result->fetch_assoc()): ?>
                            <tr>
                                <td class="px-4 py-3 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($item['brand_name'] . ' ' . $item['model_name']); ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                    <?php echo $item['Quantity']; ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-900 text-right">
                                    <?php echo number_format($item['Sold_Unit_Price'], 2); ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 italic">
                                    <?php echo htmlspecialchars($item['Remarks'] ?? '-'); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="p-4 text-center text-gray-500">No items associated with this order.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="text-xs text-gray-400 mt-2">* Items cannot be edited here. Please contact support or create a new order for item changes.</p>
        </div>

    </form>
</div>
</body>
</html>