<?php
session_start();

// --- START: SESSION & SECURITY CHECKS ---
$idleTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset(); session_destroy(); header("Location: index.php?reason=idle"); exit();
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php"); exit();
}
$current_user_id = $_SESSION['user_id'];
$current_user_name = $_SESSION['user_name'];
// Get user role for price validation
$user_role = isset($_SESSION['user_role']) ? (int)$_SESSION['user_role'] : 0;
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';
// *** NOTE: Default content type is HTML. JSON header is now MOVED inside the API block. ***

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json'); // <-- *** FIX: Header moved HERE ***
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Search for Clients (Head & Branch)
    if ($_GET['action'] === 'search_client' && isset($_GET['query'])) {
        $query = trim($_GET['query']) . '%';
        $sql = "(SELECT client_head_id as id, Company_Name as name, 'Head Office' as type, Department as subtext, 'head' as type_key FROM Client_Head WHERE Company_Name LIKE ? AND is_deleted = FALSE)
                UNION ALL
                (SELECT cb.client_branch_id as id, cb.Branch_Name as name, 'Branch Office' as type, ch.Company_Name as subtext, 'branch' as type_key FROM Client_Branch cb JOIN Client_Head ch ON cb.client_head_id_fk = ch.client_head_id WHERE cb.Branch_Name LIKE ? AND cb.is_deleted = FALSE AND ch.is_deleted = FALSE)
                LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $query, $query);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $response = ['status' => 'success', 'data' => $data];
    }

    // Action: Create Work Order
    if ($_GET['action'] === 'create_work_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // *** NEW: Get client IDs from payload ***
        $client_head_id_fk = $data['client_head_id'] ? intval($data['client_head_id']) : null;
        $client_branch_id_fk = $data['client_branch_id'] ? intval($data['client_branch_id']) : null;

        if (!empty($data['Order_No']) && !empty($data['Order_Date']) && (!empty($client_head_id_fk) || !empty($client_branch_id_fk))) {
            // *** NEW: Insert client IDs into Work_Order table ***
            $sql = "INSERT INTO Work_Order (Order_No, Order_Date, client_head_id_fk, client_branch_id_fk, created_by) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssiii", $data['Order_No'], $data['Order_Date'], $client_head_id_fk, $client_branch_id_fk, $current_user_id);
            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                $response = ['status' => 'success', 'work_order_id' => $new_id, 'order_no' => $data['Order_No']];
            } else {
                $response['message'] = 'Failed to create work order (might be duplicate Order No): ' . $stmt->error;
            }
        } else { $response['message'] = 'Client, Order No, and Date are required.'; }
    }

    // Action: Get product availability & prices
    if ($_GET['action'] === 'get_product_info') {
        $cat_id = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);
        $brand_id = filter_input(INPUT_GET, 'brand_id', FILTER_VALIDATE_INT);
        $model_id = filter_input(INPUT_GET, 'model_id', FILTER_VALIDATE_INT);
        $info = [];

        $where_cat = $cat_id ? "m.category_id = $cat_id" : "1=1";
        $where_brand = $brand_id ? "m.brand_id = $brand_id" : "1=1";
        $where_model = $model_id ? "m.model_id = $model_id" : "1=1";

        $sql_purchased = "SELECT SUM(pp.quantity) as total_purchased
                          FROM purchased_products pp
                          JOIN models m ON pp.model_id = m.model_id
                          WHERE pp.is_deleted = FALSE AND $where_cat AND $where_brand AND $where_model";
        $total_purchased_res = $conn->query($sql_purchased);
        $total_purchased = $total_purchased_res ? (int)$total_purchased_res->fetch_object()->total_purchased : 0;
        
        $sql_sold = "SELECT SUM(sp.Quantity) as total_sold
                     FROM Sold_Product sp
                     JOIN models m ON sp.model_id_fk = m.model_id
                     WHERE sp.is_deleted = FALSE AND $where_cat AND $where_brand AND $where_model";
        $total_sold_res = $conn->query($sql_sold);
        $total_sold = $total_sold_res ? (int)$total_sold_res->fetch_object()->total_sold : 0;
        
        $info['available'] = $total_purchased - $total_sold;

        if ($model_id) {
            $sql_prices = "SELECT AVG(unit_price) as avg_price, MAX(unit_price) as max_price FROM purchased_products WHERE model_id = ? AND is_deleted = FALSE";
            $stmt_prices = $conn->prepare($sql_prices);
            $stmt_prices->bind_param("i", $model_id);
            $stmt_prices->execute();
            $prices = $stmt_prices->get_result()->fetch_assoc();
            $info['avg_price'] = $prices['avg_price'] ? number_format($prices['avg_price'], 2, '.', '') : '0.00';
            $info['max_price'] = $prices['max_price'] ? number_format($prices['max_price'], 2, '.', '') : '0.00';
            $stmt_prices->close();
            
            $sql_serials = "SELECT sl_id, product_sl FROM product_sl WHERE model_id_fk = ? AND status = 0";
            $stmt_serials = $conn->prepare($sql_serials);
            $stmt_serials->bind_param("i", $model_id);
            $stmt_serials->execute();
            $info['serials'] = $stmt_serials->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_serials->close();
        }
        $response = ['status' => 'success', 'data' => $info];
    }
    
    // Action: Add product to temp table
    if ($_GET['action'] === 'add_temp_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $serial_ids = $data['product_sl_ids'] ?? []; // Expect an array
        $quantity = intval($data['quantity']);

        // Server-side validation
        if(floatval($data['sold_unit_price']) < floatval($data['avg_price']) && $user_role != 1) {
             $response['message'] = 'Error: Price is below average. Admin permission required.';
             echo json_encode($response); $conn->close(); exit();
        }

        $sql_insert = "INSERT INTO temp_sold_product (created_by, client_head_id_fk, client_branch_id_fk, sold_date, work_order_id_fk, model_id_fk, product_sl_id_fk, Remarks, Avg_Max_Price, Quantity, Sold_Unit_Price) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);

        $sql_details = "SELECT c.category_name, b.brand_name, m.model_name FROM models m JOIN brands b ON m.brand_id = b.brand_id JOIN categories c ON m.category_id = c.category_id WHERE m.model_id = ?";
        $stmt_details = $conn->prepare($sql_details);
        $stmt_details->bind_param("i", $data['model_id']);
        $stmt_details->execute();
        $details = $stmt_details->get_result()->fetch_assoc();
        $details_text = $details['category_name'] . ' / ' . $details['brand_name'] . ' / ' . $details['model_name'];

        $new_rows = [];
        $conn->begin_transaction();
        try {
            if (count($serial_ids) > 0) {
                // Case 1: Serialized products. Insert one row per serial.
                foreach ($serial_ids as $sl_id) {
                    $sl_id_int = intval($sl_id);
                    $sql_check = "SELECT product_sl FROM product_sl WHERE sl_id = ? AND status = 0";
                    $stmt_check = $conn->prepare($sql_check);
                    $stmt_check->bind_param("i", $sl_id_int); $stmt_check->execute();
                    $sl_result = $stmt_check->get_result();
                    if ($sl_result->num_rows == 0) {
                        throw new Exception("Serial ID $sl_id_int is no longer available.");
                    }
                    $serial_number = $sl_result->fetch_object()->product_sl;
                    
                    // types: created_by(i), client_head_id(i), client_branch_id(i), sold_date(s), work_order_id(i), model_id(i), product_sl_id(i), Remarks(s), Avg_Max_Price(s), Quantity(i), Sold_Unit_Price(d)
                    $stmt_insert->bind_param("iiisiiissid", $current_user_id, $data['client_head_id'], $data['client_branch_id'], $data['sold_date'], $data['work_order_id'], $data['model_id'], $sl_id_int, $data['remarks'], $data['avg_max_price'], 1, $data['sold_unit_price']);
                    $stmt_insert->execute();
                    $new_rows[] = ['temp_id' => $conn->insert_id, 'details_text' => $details_text . " (SN: $serial_number)", 'quantity' => 1, 'unit_price' => $data['sold_unit_price'], 'total_price' => $data['sold_unit_price']];
                }
            } else {
                // Case 2: Non-serialized product
                // For non-serialized products product_sl_id will be NULL
                $stmt_insert->bind_param("iiisiiissid", $current_user_id, $data['client_head_id'], $data['client_branch_id'], $data['sold_date'], $data['work_order_id'], $data['model_id'], $null_sl = null, $data['remarks'], $data['avg_max_price'], $data['quantity'], $data['sold_unit_price']);
                $stmt_insert->execute();
                $new_rows[] = ['temp_id' => $conn->insert_id, 'details_text' => $details_text, 'quantity' => $data['quantity'], 'unit_price' => $data['sold_unit_price'], 'total_price' => $data['quantity'] * $data['sold_unit_price']];
            }
            $conn->commit();
            $response = ['status' => 'success', 'newRows' => $new_rows]; // Send back array of new rows
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = "Error: " . $e->getMessage();
        }
    }
    
    // Action: Get temp list on page load
    if ($_GET['action'] === 'get_temp_list') {
        $sql = "SELECT t.*, c.category_name, b.brand_name, m.model_name, ps.product_sl 
                FROM temp_sold_product t
                JOIN models m ON t.model_id_fk = m.model_id
                JOIN brands b ON m.brand_id = b.brand_id
                JOIN categories c ON m.category_id = c.category_id
                LEFT JOIN product_sl ps ON t.product_sl_id_fk = ps.sl_id
                WHERE t.created_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $response = ['status' => 'success', 'data' => $data];
    }
    
    // Action: Delete temp product
    if ($_GET['action'] === 'delete_temp_product' && isset($_GET['id'])) {
        $sql = "DELETE FROM temp_sold_product WHERE temp_sold_id = ? AND created_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $_GET['id'], $current_user_id);
        if ($stmt->execute()) $response = ['status' => 'success'];
        else $response['message'] = 'Failed to delete';
    }
    
    // Action: Final Sale Submission
    if ($_GET['action'] === 'submit_sale' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $conn->begin_transaction();
        try {
            // 1. Generate new Invoice Number
            $prefix = "P1WQINV";
            $sql_inv_count = "SELECT COUNT(*) as inv_count FROM Invoice WHERE Invoice_No LIKE '$prefix%'";
            $count = (int)$conn->query($sql_inv_count)->fetch_object()->inv_count;
            $new_inv_num = $prefix . sprintf('%06d', $count + 1);

            // 2. Insert into Invoice table
            $tax_amount = $data['total_inc_tax'] - $data['total_ex_tax'];
            $tax_percentage = $data['tax_percent'];
            
            $sql_inv = "INSERT INTO Invoice (Invoice_No, IncludingTax_TotalPrice, ExcludingTax_TotalPrice, Tax_Amount, Tax_Percentage, created_by) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_inv = $conn->prepare($sql_inv);
            $stmt_inv->bind_param("sddddi", $new_inv_num, $data['total_inc_tax'], $data['total_ex_tax'], $tax_amount, $tax_percentage, $current_user_id);
            $stmt_inv->execute();
            $new_invoice_id = $conn->insert_id;

            // 3. Get temp products
            $sql_temp = "SELECT * FROM temp_sold_product WHERE created_by = ?";
            $stmt_temp = $conn->prepare($sql_temp);
            $stmt_temp->bind_param("i", $current_user_id);
            $stmt_temp->execute();
            $temp_products = $stmt_temp->get_result()->fetch_all(MYSQLI_ASSOC);
            if (empty($temp_products)) throw new Exception("No products in the list.");

            // 4. Insert each item into Sold_Product and update serial status
            $sql_sold = "INSERT INTO Sold_Product (client_head_id_fk, client_branch_id_fk, sold_date, work_order_id_fk, product_sl_id_fk, model_id_fk, Remarks, Avg_Max_Price, Quantity, Sold_Unit_Price, invoice_id_fk, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_sold = $conn->prepare($sql_sold);
            
            $sql_update_sl = "UPDATE product_sl SET status = 1 WHERE sl_id = ?"; // 1 = is_Sold
            $stmt_update_sl = $conn->prepare($sql_update_sl);
            
            foreach ($temp_products as $item) {
                // types: client_head_id(i), client_branch_id(i), sold_date(s), work_order_id(i), product_sl_id(i), model_id(i), Remarks(s), Avg_Max_Price(s), Quantity(i), Sold_Unit_Price(d), invoice_id(i), created_by(i)
                $stmt_sold->bind_param("iisiiissidii", 
                    $item['client_head_id_fk'], $item['client_branch_id_fk'], $item['sold_date'], $item['work_order_id_fk'],
                    $item['product_sl_id_fk'], $item['model_id_fk'], $item['Remarks'], $item['Avg_Max_Price'], 
                    $item['Quantity'], $item['Sold_Unit_Price'], $new_invoice_id, $current_user_id
                );
                $stmt_sold->execute();
                
                // If a serial was part of this sale, update its status
                if (!empty($item['product_sl_id_fk'])) {
                    $stmt_update_sl->bind_param("i", $item['product_sl_id_fk']);
                    $stmt_update_sl->execute();
                }
            }

            // 5. Clear temp table
            $sql_clear = "DELETE FROM temp_sold_product WHERE created_by = ?";
            $stmt_clear = $conn->prepare($sql_clear);
            $stmt_clear->bind_param("i", $current_user_id);
            $stmt_clear->execute();

            // 6. Commit transaction
            $conn->commit();
            $response = ['status' => 'success', 'message' => 'Sale recorded successfully!', 'new_invoice_no' => $new_inv_num];

        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = "Transaction Failed: " . $e->getMessage();
        }
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Handle Final Purchase Submission (Standard POST) ---
$successMessage = ''; $errorMessage = '';


// --- Part 3: Fetch initial data for page load ---
$categories_result = $conn->query("SELECT category_id, category_name FROM categories WHERE is_deleted = FALSE ORDER BY category_name");
$categories = $categories_result ? $categories_result->fetch_all(MYSQLI_ASSOC) : [];

$brands_result = $conn->query("SELECT brand_id, brand_name, category_id FROM brands WHERE is_deleted = FALSE ORDER BY brand_name");
$brands = $brands_result ? $brands_result->fetch_all(MYSQLI_ASSOC) : [];

$models_result = $conn->query("SELECT model_id, model_name, brand_id FROM models WHERE is_deleted = FALSE ORDER BY model_name");
$models = $models_result ? $models_result->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sell Product</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } .modal.is-open { display: flex; }
        #client-search-results div:hover { background-color: #f3f4f6; }
        .available-qty { font-size: 0.75rem; color: #16a34a; font-weight: 500; height: 1rem; }
        .price-display { font-size: 0.75rem; color: #4b5563; height: 1rem; }
        .qty-warning { border-color: #ef4444 !important; }
        /* NEW Serial Styling */
        #serial-temp-list span { background-color: #e0e7ff; color: #4338ca; padding: 2px 6px; border-radius: 4px; font-size: 0.875rem; margin-right: 4px; margin-bottom: 4px; display: inline-flex; align-items: center; }
    #serial-temp-list span button { margin-left: 4px; color: #4338ca; opacity: 0.7; }
    #serial-temp-list span button:hover { opacity: 1; }
        @media print { /* Print styles removed as requested */ }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6 no-print">
            <h1 class="text-3xl font-bold text-gray-800">Sell Product / Create Invoice</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>
        
        <div id="global-message-container"></div>

        <!-- Section 1: Main Details -->
        <div class="bg-white p-6 rounded-xl shadow-md mb-8 no-print">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Main Sale Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Client Search -->
                <div>
                    <label for="client-search" class="block text-sm font-medium text-gray-700 mb-1">Client <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="text" id="client-search" placeholder="Search Head Office or Branch..." required class="w-full p-3 border border-gray-300 rounded-lg" autocomplete="off">
                        <button type="button" id="clear-client-btn" class="absolute right-3 top-3 text-gray-400 hover:text-gray-600 hidden">&times;</button>
                    </div>
                    <div id="client-search-results" class="border border-gray-300 rounded-b-lg -mt-1 bg-white max-h-40 overflow-y-auto hidden"></div>
                    <input type="hidden" id="client-head-id">
                    <input type="hidden" id="client-branch-id">
                </div>
                <!-- Sold Date -->
                <div>
                    <label for="sold-date" class="block text-sm font-medium text-gray-700 mb-1">Sold Date <span class="text-red-500">*</span></label>
                    <input type="date" id="sold-date" required class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <!-- Work Order -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Work Order <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="text" id="work-order-display" placeholder="No Work Order selected" readonly class="w-full p-3 border border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed">
                        <button type="button" id="add-wo-btn" class="absolute right-2 top-2 text-xs bg-blue-500 text-white px-2 py-1 rounded-md hover:bg-blue-600">Add New</button>
                        <input type="hidden" id="work-order-id">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 2: Product Entry Form -->
        <div class="bg-white p-6 rounded-xl shadow-md mb-8 no-print">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Add Product to Sale</h2>
            <div id="product-entry-form" class="p-4 border rounded-lg bg-gray-50 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium">Category</label>
                        <select id="category-id" class="mt-1 w-full p-2 border-gray-300 rounded-md"><option value="">-- Select --</option><?php foreach($categories as $cat):?><option value="<?php echo $cat['category_id'];?>"><?php echo htmlspecialchars($cat['category_name']);?></option><?php endforeach;?></select>
                        <span id="cat-available" class="available-qty"></span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Brand</label>
                        <select id="brand-id" class="mt-1 w-full p-2 border-gray-300 rounded-md" disabled><option>-- Select Category --</option></select>
                        <span id="brand-available" class="available-qty"></span>
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Model</label>
                        <select id="model-id" class="mt-1 w-full p-2 border-gray-300 rounded-md" disabled><option>-- Select Brand --</option></select>
                        <span id="model-available" class="available-qty"></span>
                    </div>
                </div>
                
                <!-- START: New Serial/Qty Section -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium">Serial Number(s)</label>
                        <div class="flex">
                            <input type="text" id="serial-search" list="serial-list" placeholder="Type or select serial..." class="w-full p-2 border-gray-300 rounded-l-md" autocomplete="off" disabled>
                            <datalist id="serial-list"></datalist>
                            <button type="button" id="add-serial-btn" class="px-3 bg-indigo-600 text-white rounded-r-md text-sm hover:bg-indigo-700" disabled>+</button>
                        </div>
                         <div id="serial-temp-list" class="mt-2 flex flex-wrap gap-1">
                            <!-- JS-generated serial tags will go here -->
                         </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Quantity</label>
                        <input type="number" id="quantity" value="1" placeholder="1" class="mt-1 w-full p-2 border-gray-300 rounded-md">
                        <input type="hidden" id="model-available-hidden">
                    </div>
                </div>
                <!-- END: New Serial/Qty Section -->

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                     <div>
                        <label class="block text-sm font-medium">Sold Unit Price</label>
                        <input type="number" step="0.01" id="sold-unit-price" placeholder="0.00" class="mt-1 w-full p-2 border-gray-300 rounded-md">
                        <span id="price-display" class="price-display"></span>
                        <input type="hidden" id="avg-max-price">
                    </div>
                    <div>
                        <label class="block text-sm font-medium">Remarks</label>
                        <input type="text" id="remarks" placeholder="Optional" class="mt-1 w-full p-2 border-gray-300 rounded-md">
                    </div>
                </div>
                <div class="text-right"><button type="button" id="add-product-to-list-btn" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700">Add to List</button></div>
            </div>
        </div>

        <!-- Section 3: Temporary List & Totals -->
        <div class="bg-white p-6 rounded-xl shadow-md mb-8 no-print">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Products in This Sale</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Qty</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unit Price</th><th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total Price</th><th class="px-2 py-2"></th></tr></thead>
                    <tbody id="temp-sold-tbody" class="divide-y divide-gray-200">
                        <!-- JS will populate this -->
                    </tbody>
                </table>
            </div>
            <!-- Totals Section -->
            <div class="mt-6 flex justify-end">
                <div class="w-full max-w-sm space-y-3">
                    <div class="flex justify-between text-sm font-medium text-gray-700">
                        <span>Subtotal (Excl. Tax)</span>
                        <span id="subtotal-display">0.00</span>
                    </div>
                    <div class="flex justify-between items-center text-sm font-medium text-gray-700">
                        <label for="tax-percent">Tax (%)</label>
                        <input type="number" id="tax-percent" value="0" class="w-20 p-1 border border-gray-300 rounded-md text-right">
                    </div>
                    <div class="flex justify-between text-lg font-bold text-gray-900 border-t pt-3">
                        <span>Grand Total (Inc. Tax)</span>
                        <span id="grandtotal-display">0.00</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 4: Submit -->
        <div class="mt-8 text-right no-print">
            <button type="button" id="final-sell-btn" class="bg-green-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-green-700 text-lg">Sell Products & Generate Invoice</button>
        </div>
    </div>

    <!-- Modals -->
    <!-- Work Order Modal -->
    <div id="wo-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 space-y-4">
            <h2 class="text-xl font-semibold">Add New Work Order</h2>
            <div><label class="block text-sm">Order No <span class="text-red-500">*</span></label><input type="text" id="wo-order-no" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
            <div><label class="block text-sm">Order Date <span class="text-red-500">*</span></label><input type="date" id="wo-order-date" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
            <div class="flex justify-end gap-4"><button type="button" id="wo-cancel-btn" class="bg-gray-300 px-4 py-2 rounded-lg">Cancel</button><button type="button" id="wo-create-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg">Create</button></div>
        </div>
    </div>

    <!-- Final Confirmation Modal -->
    <div id="confirm-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl flex flex-col p-6">
            <h2 class="text-2xl font-bold mb-4">Confirm Sale</h2>
            <div id="confirm-memo-preview" class="p-4 border rounded-lg bg-gray-50 overflow-y-auto max-h-[60vh]">
                <!-- JS will populate this memo preview -->
            </div>
            <div class="flex justify-end gap-4 mt-6"><button type="button" id="confirm-cancel-btn" class="bg-gray-300 px-6 py-2 rounded-lg">Cancel</button><button type="button" id="confirm-submit-btn" class="bg-green-600 text-white font-bold px-6 py-2 rounded-lg">Confirm & Save</button></div>
        </div>
    </div>
    
    <!-- JS Data for product dropdowns -->
    <script>
        const allBrands = <?php echo json_encode($brands); ?>;
        const allModels = <?php echo json_encode($models); ?>;
        const isAdmin = <?php echo $user_role === 1 ? 'true' : 'false'; ?>;
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        // --- Globals & State ---
        let tempSoldList = [];
        let searchTimeout;
        const currentUserName = "<?php echo htmlspecialchars($current_user_name); ?>";
        let availableSerials = []; // Full list from server
        let currentSerials = []; // Client-side list of serials for *this* item
        let currentAvgPrice = 0;

        // --- Element References ---
        const clientSearchBox = document.getElementById('client-search');
        const clientResultsBox = document.getElementById('client-search-results');
        const clientHeadId = document.getElementById('client-head-id');
        const clientBranchId = document.getElementById('client-branch-id');
        const clientClearBtn = document.getElementById('clear-client-btn');
        const soldDate = document.getElementById('sold-date');
        const workOrderDisplay = document.getElementById('work-order-display');
        const workOrderId = document.getElementById('work-order-id');
        const addWoBtn = document.getElementById('add-wo-btn');
        const woModal = document.getElementById('wo-modal');
        const woCreateBtn = document.getElementById('wo-create-btn');
        const woCancelBtn = document.getElementById('wo-cancel-btn');
        
        const catSelect = document.getElementById('category-id');
        const brandSelect = document.getElementById('brand-id');
        const modelSelect = document.getElementById('model-id');
        const catAvail = document.getElementById('cat-available');
        const brandAvail = document.getElementById('brand-available');
        const modelAvail = document.getElementById('model-available');
        const quantityInput = document.getElementById('quantity');
        const modelAvailHidden = document.getElementById('model-available-hidden');
        
        const serialSearch = document.getElementById('serial-search');
        const serialList = document.getElementById('serial-list');
        const addSerialBtn = document.getElementById('add-serial-btn');
        const serialTempList = document.getElementById('serial-temp-list');
        
        const priceDisplay = document.getElementById('price-display');
        const avgMaxPrice = document.getElementById('avg-max-price');
        const unitPriceInput = document.getElementById('sold-unit-price');
        const remarksInput = document.getElementById('remarks');
        
        const addToListBtn = document.getElementById('add-product-to-list-btn');
        const tempTbody = document.getElementById('temp-sold-tbody');
        const subtotalDisplay = document.getElementById('subtotal-display');
        const taxPercentInput = document.getElementById('tax-percent');
        const grandtotalDisplay = document.getElementById('grandtotal-display');
        
        const finalSellBtn = document.getElementById('final-sell-btn');
        const confirmModal = document.getElementById('confirm-modal');
        const confirmMemoPreview = document.getElementById('confirm-memo-preview');
        const confirmCancelBtn = document.getElementById('confirm-cancel-btn');
        const confirmSubmitBtn = document.getElementById('confirm-submit-btn');

        // --- Utility Functions ---
        const showGlobalMsg = (msg, isSuccess = true) => {
            const container = document.getElementById('global-message-container');
            const color = isSuccess ? 'green' : 'red';
            container.innerHTML = `<div id="alert-box" class="bg-${color}-100 border-${color}-400 text-${color}-700 px-4 py-3 rounded-lg mb-6"><span>${msg}</span></div>`;
            const alertBox = document.getElementById('alert-box');
            if (alertBox) setTimeout(() => { alertBox.style.transition = 'opacity 0.5s ease'; alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000);
        };
        soldDate.valueAsDate = new Date(); // Set default sold date

        // --- Client Search ---
        clientSearchBox.addEventListener('input', () => {
            const query = clientSearchBox.value.trim();
            clientResultsBox.innerHTML = '';
            if (query.length < 1) { clientResultsBox.classList.add('hidden'); return; }
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(async () => {
                const res = await fetch(`?action=search_client&query=${encodeURIComponent(query)}`);
                const result = await res.json();
                if (result.status === 'success' && result.data.length > 0) {
                    result.data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'p-3 border-t cursor-pointer';
                        div.innerHTML = `<strong class="block">${item.name}</strong><small class="text-gray-500">${item.type} (${item.subtext || ''})</small>`;
                        div.addEventListener('click', () => selectClient(item));
                        clientResultsBox.appendChild(div);
                    });
                    clientResultsBox.classList.remove('hidden');
                } else {
                    clientResultsBox.innerHTML = `<div class="p-3 text-gray-500">No results found.</div>`;
                }
            }, 300);
        });
        function selectClient(item) {
            clientSearchBox.value = `${item.name} (${item.type})`;
            clientHeadId.value = (item.type_key === 'head') ? item.id : '';
            clientBranchId.value = (item.type_key === 'branch') ? item.id : '';
            clientSearchBox.readOnly = true; clientResultsBox.classList.add('hidden'); clientClearBtn.classList.remove('hidden');
        }
        clientClearBtn.addEventListener('click', () => {
            clientSearchBox.value = ''; clientHeadId.value = ''; clientBranchId.value = '';
            clientSearchBox.readOnly = false; clientClearBtn.classList.add('hidden');
        });

        // --- Work Order Modal ---
        addWoBtn.addEventListener('click', () => {
            // Check if client is selected first
            if (!clientHeadId.value && !clientBranchId.value) {
                alert('Please select a Client before adding a Work Order.');
                return;
            }
            document.getElementById('wo-order-no').value = '';
            document.getElementById('wo-order-date').valueAsDate = new Date();
            woModal.classList.add('is-open');
        });
        woCancelBtn.addEventListener('click', () => woModal.classList.remove('is-open'));
        woCreateBtn.addEventListener('click', async () => {
            const orderNo = document.getElementById('wo-order-no').value;
            const orderDate = document.getElementById('wo-order-date').value;
            if (!orderNo || !orderDate) { alert('Please enter Order No and Date'); return; }
            
            const res = await fetch('?action=create_work_order', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ 
                    Order_No: orderNo, 
                    Order_Date: orderDate,
                    client_head_id: clientHeadId.value || null,
                    client_branch_id: clientBranchId.value || null
                })
            });
            const result = await res.json();
            if (result.status === 'success') {
                workOrderId.value = result.work_order_id;
                workOrderDisplay.value = result.order_no;
                woModal.classList.remove('is-open');
            } else { alert('Error: ' + result.message); }
        });

        // --- Product Dropdowns & Availability ---
        async function getAvailability(catId = null, brandId = null, modelId = null) {
            let url = `?action=get_product_info`;
            if (catId) url += `&category_id=${catId}`;
            if (brandId) url += `&brand_id=${brandId}`;
            if (modelId) url += `&model_id=${modelId}`;
            const res = await fetch(url);
            const result = await res.json();
            if (result.status === 'success') return result.data;
            return null;
        }

        catSelect.addEventListener('change', async () => {
            const catId = catSelect.value;
            brandSelect.innerHTML = '<option value="">-- Select Category --</option>'; modelSelect.innerHTML = '<option value="">-- Select Brand --</option>';
            brandSelect.disabled = true; modelSelect.disabled = true;
            catAvail.textContent = ''; brandAvail.textContent = ''; modelAvail.textContent = ''; priceDisplay.textContent = ''; serialList.innerHTML = '';
            if (!catId) return;
            const info = await getAvailability(catId);
            if(info) catAvail.textContent = `Available: ${info.available}`;
            const filteredBrands = allBrands.filter(b => b.category_id == catId);
            filteredBrands.forEach(b => brandSelect.add(new Option(b.brand_name, b.brand_id)));
            brandSelect.disabled = false; brandSelect.innerHTML = '<option value="">-- Select Brand --</option>' + brandSelect.innerHTML;
        });

        brandSelect.addEventListener('change', async () => {
            const brandId = brandSelect.value;
            const catId = catSelect.value;
            modelSelect.innerHTML = '<option value="">-- Select Brand --</option>'; modelSelect.disabled = true;
            brandAvail.textContent = ''; modelAvail.textContent = ''; priceDisplay.textContent = ''; serialList.innerHTML = '';
            if (!brandId) return;
            const info = await getAvailability(catId, brandId);
            if(info) brandAvail.textContent = `Available: ${info.available}`;
            const filteredModels = allModels.filter(m => m.brand_id == brandId);
            filteredModels.forEach(m => modelSelect.add(new Option(m.model_name, m.model_id)));
            modelSelect.disabled = false; modelSelect.innerHTML = '<option value="">-- Select Model --</option>' + modelSelect.innerHTML;
        });

        modelSelect.addEventListener('change', async () => {
            const modelId = modelSelect.value;
            const brandId = brandSelect.value;
            const catId = catSelect.value;
            modelAvail.textContent = ''; priceDisplay.textContent = ''; serialList.innerHTML = ''; availableSerials = [];
            quantityInput.value = '1'; quantityInput.readOnly = false;
            serialSearch.disabled = true; addSerialBtn.disabled = true;
            currentSerials = []; updateSerialTempListUI(); // Clear temp serials
            
            if (!modelId) return;

            const info = await getAvailability(catId, brandId, modelId);
            if (info) {
                modelAvail.textContent = `Available: ${info.available}`;
                modelAvailHidden.value = info.available;
                priceDisplay.textContent = `Avg: ${info.avg_price}, Max: ${info.max_price}`;
                avgMaxPrice.value = info.avg_price; // Store only AVG
                currentAvgPrice = parseFloat(info.avg_price) || 0; // Store for validation
                unitPriceInput.value = info.avg_price;
                
                // Populate serials
                availableSerials = info.serials;
                if (availableSerials.length > 0) {
                    serialSearch.disabled = false; addSerialBtn.disabled = false;
                    serialList.innerHTML = ''; // Clear old options
                    info.serials.forEach(sl => {
                        const option = document.createElement('option');
                        option.value = sl.product_sl;
                        option.dataset.id = sl.sl_id;
                        serialList.appendChild(option);
                    });
                }
                checkQuantityWarning();
            }
        });

        // --- Serial & Quantity Logic ---
        addSerialBtn.addEventListener('click', () => {
            const serialValue = serialSearch.value;
            const matchingSerial = availableSerials.find(sl => sl.product_sl === serialValue);
            
            if (matchingSerial) {
                // Check if already added
                if (currentSerials.find(sl => sl.sl_id === matchingSerial.sl_id)) {
                    alert('Serial already added to this item.');
                    serialSearch.value = '';
                    return;
                }
                
                // Add to client-side temp list
                currentSerials.push(matchingSerial);
                
                // Remove from available list
                availableSerials = availableSerials.filter(sl => sl.sl_id !== matchingSerial.sl_id);
                updateDatalist();
                updateSerialTempListUI();
                
                serialSearch.value = '';
            } else {
                alert('Invalid or unavailable serial number.');
            }
        });

        serialTempList.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-serial-btn')) {
                const sl_id_to_remove = e.target.dataset.id;
                const serialObj = currentSerials.find(sl => sl.sl_id == sl_id_to_remove);
                
                if (serialObj) {
                    // Remove from current list
                    currentSerials = currentSerials.filter(sl => sl.sl_id != sl_id_to_remove);
                    // Add back to available list
                    availableSerials.push(serialObj);
                    
                    updateDatalist();
                    updateSerialTempListUI();
                }
            }
        });

        function updateDatalist() {
            serialList.innerHTML = '';
            availableSerials.forEach(sl => {
                const option = document.createElement('option');
                option.value = sl.product_sl;
                option.dataset.id = sl.sl_id;
                serialList.appendChild(option);
            });
        }
        
        function updateSerialTempListUI() {
            serialTempList.innerHTML = '';
            currentSerials.forEach(sl => {
                const span = document.createElement('span');
                span.innerHTML = `${sl.product_sl} <button type="button" class="remove-serial-btn" data-id="${sl.sl_id}">&times;</button>`;
                serialTempList.appendChild(span);
            });
            
            if (currentSerials.length > 0) {
                quantityInput.value = currentSerials.length;
                quantityInput.readOnly = true;
            } else {
                quantityInput.value = '1';
                quantityInput.readOnly = false;
            }
        }

        quantityInput.addEventListener('input', () => {
            // If user types, deselect all serials
            availableSerials = [...availableSerials, ...currentSerials]; // Add back all serials to available list
            currentSerials = []; // Clear current list
            updateSerialTempListUI(); // Clears tags and unlocks qty
            updateDatalist(); // Repopulates datalist
            checkQuantityWarning();
        });

        function checkQuantityWarning() {
            const qty = parseInt(quantityInput.value);
            const avail = parseInt(modelAvailHidden.value);
            if (qty > avail) {
                quantityInput.classList.add('qty-warning');
                modelAvail.textContent = `Warning: Only ${avail} available!`;
                modelAvail.classList.add('text-red-600');
            } else {
                quantityInput.classList.remove('qty-warning');
                modelAvail.textContent = `Available: ${avail}`;
                modelAvail.classList.remove('text-red-600');
            }
        }
        
        // --- Add/Remove from Temp List & Recalculate ---
        addToListBtn.addEventListener('click', async () => {
            const availableStock = parseInt(modelAvailHidden.value);
            if (availableStock < 1) {
                alert('This product is out of stock.'); return;
            }
            
            const soldPrice = parseFloat(unitPriceInput.value);
            const avgPrice = parseFloat(avgMaxPrice.value);
            if (soldPrice < avgPrice && !isAdmin) {
                alert('Price is below average. Admin permission required to add this item.'); return;
            }
            
            const selectedSerialIDs = currentSerials.map(sl => sl.sl_id);
            const quantity = parseInt(quantityInput.value);
            
            if (selectedSerialIDs.length === 0 && quantity > availableStock) {
                 alert('Quantity exceeds available stock.'); return;
            }
            if (selectedSerialIDs.length > 0 && selectedSerialIDs.length > availableStock) {
                 alert('Selected serials exceed available stock.'); return;
            }

            const postData = {
                client_head_id: clientHeadId.value || null, client_branch_id: clientBranchId.value || null,
                sold_date: soldDate.value, work_order_id: workOrderId.value,
                model_id: modelSelect.value, 
                quantity: quantity,
                sold_unit_price: unitPriceInput.value, 
                avg_price: avgMaxPrice.value, // Pass avg price for server validation
                avg_max_price: priceDisplay.textContent,
                remarks: remarksInput.value,
                product_sl_ids: selectedSerialIDs // Send array of selected serial IDs
            };
            
            if (!postData.client_head_id && !postData.client_branch_id) { alert('Please select a client.'); return; }
            if (!postData.sold_date) { alert('Please select a sold date.'); return; }
            if (!postData.work_order_id) { alert('Please select/create a work order.'); return; }
            if (!postData.model_id) { alert('Please select a product model.'); return; }
            if (!postData.quantity || postData.quantity < 1) { alert('Please enter a valid quantity.'); return; }

            addToListBtn.disabled = true; addToListBtn.textContent = 'Adding...';
            const res = await fetch('?action=add_temp_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(postData) });
            const result = await res.json();
            
            if (result.status === 'success') {
                result.newRows.forEach(item => {
                    tempSoldList.push(item);
                    appendRowToTempTable(item);
                });
                resetProductForm();
                updateTotals();
            } else { alert('Error: ' + result.message); }
            addToListBtn.disabled = false; addToListBtn.textContent = 'Add to List';
        });
        
        function appendRowToTempTable(item) {
            const tr = document.createElement('tr');
            tr.id = `temp-row-${item.temp_id}`;
            tr.innerHTML = `
                <td class="px-4 py-2 text-sm"><b>${item.details_text}</b></td>
                <td class="px-4 py-2 text-sm">${item.quantity}</td>
                <td class="px-4 py-2 text-sm">${parseFloat(item.unit_price).toFixed(2)}</td>
                <td class="px-4 py-2 text-sm text-right font-medium">${parseFloat(item.total_price).toFixed(2)}</td>
                <td class="px-2 py-2 text-center"><button class="remove-temp-btn text-red-500" data-id="${item.temp_id}">&times;</button></td>
            `;
            tempTbody.appendChild(tr);
        }
        
        function resetProductForm() {
            catSelect.value = ''; brandSelect.innerHTML = '<option>-- Select Category --</option>'; brandSelect.disabled = true;
            modelSelect.innerHTML = '<option>-- Select Brand --</option>'; modelSelect.disabled = true;
            catAvail.textContent = ''; brandAvail.textContent = ''; modelAvail.textContent = ''; priceDisplay.textContent = '';
            quantityInput.value = '1'; quantityInput.readOnly = false;
            serialSearch.value = ''; serialSearch.disabled = true; serialList.innerHTML = ''; addSerialBtn.disabled = true;
            serialTempList.innerHTML = ''; currentSerials = []; availableSerials = [];
            unitPriceInput.value = ''; remarksInput.value = ''; avgMaxPrice.value = ''; modelAvailHidden.value = '';
        }
        
        tempTbody.addEventListener('click', async (e) => {
            if (e.target.classList.contains('remove-temp-btn')) {
                const tempId = e.target.dataset.id;
                if (!confirm('Remove this item?')) return;
                const res = await fetch(`?action=delete_temp_product&id=${tempId}`);
                const result = await res.json();
                if (result.status === 'success') {
                    tempSoldList = tempSoldList.filter(item => item.temp_id != tempId);
                    document.getElementById(`temp-row-${tempId}`).remove();
                    updateTotals();
                } else { alert('Error: ' + result.message); }
            }
        });

        // --- Totals Calculation ---
        taxPercentInput.addEventListener('input', updateTotals);
        function updateTotals() {
            let subtotal = 0;
            tempSoldList.forEach(item => { subtotal += parseFloat(item.total_price); });
            const taxPercent = parseFloat(taxPercentInput.value) || 0;
            const taxAmount = subtotal * (taxPercent / 100);
            const grandTotal = subtotal + taxAmount;
            subtotalDisplay.textContent = subtotal.toFixed(2);
            grandtotalDisplay.textContent = grandTotal.toFixed(2);
        }
        
        // --- Load Temp Data on Page Load ---
        async function loadTempData() {
            const res = await fetch('?action=get_temp_list');
            const result = await res.json();
            if (result.status === 'success' && result.data.length > 0) {
                result.data.forEach(item => {
                    let detailsText = `${item.category_name} / ${item.brand_name} / ${item.model_name}`;
                    if(item.product_sl) {
                        detailsText += ` (SN: ${item.product_sl})`;
                    }
                    const rowData = {
                        temp_id: item.temp_sold_id,
                        details_text: detailsText,
                        quantity: item.Quantity,
                        unit_price: item.Sold_Unit_Price,
                        total_price: item.Quantity * item.Sold_Unit_Price
                    };
                    tempSoldList.push(rowData);
                    appendRowToTempTable(rowData);
                });
                updateTotals();
                const firstItem = result.data[0];
                if(firstItem.client_head_id_fk) selectClient({id: firstItem.client_head_id_fk, name: 'Client (Loaded)', type: 'Head Office', type_key: 'head'});
                if(firstItem.client_branch_id_fk) selectClient({id: firstItem.client_branch_id_fk, name: 'Client (Loaded)', type: 'Branch Office', type_key: 'branch'});
                soldDate.value = firstItem.sold_date;
                workOrderId.value = firstItem.work_order_id_fk;
                workOrderDisplay.value = 'WO (Loaded)'; // We don't have the number, just show loaded
            }
        }
        loadTempData(); // Call on page load

        // --- Final Sale & Memo Logic ---
        finalSellBtn.addEventListener('click', () => {
            if (tempSoldList.length === 0) { alert('Please add at least one product to the list.'); return; }
            if (!workOrderId.value) { alert('Please create or select a Work Order.'); return; }
            if (!clientHeadId.value && !clientBranchId.value) { alert('Please select a Client.'); return; }
            
            const subtotal = parseFloat(subtotalDisplay.textContent);
            const tax = parseFloat(taxPercentInput.value) || 0;
            const total = parseFloat(grandtotalDisplay.textContent);
            
            // Build Memo Preview
            let itemsHtml = '';
            tempSoldList.forEach((item, i) => {
                itemsHtml += `<tr><td class="border p-2">${i+1}</td><td class="border p-2">${item.details_text}</td><td class="border p-2 text-right">${item.quantity}</td><td class="border p-2 text-right">${parseFloat(item.unit_price).toFixed(2)}</td><td class="border p-2 text-right">${parseFloat(item.total_price).toFixed(2)}</td></tr>`;
            });
            confirmMemoPreview.innerHTML = `
                <h3 class="text-xl font-bold text-center mb-4">Sale Confirmation</h3>
                <div class="text-sm space-y-1 mb-4">
                    <p><strong>Client:</strong> ${clientSearchBox.value}</p><p><strong>Sold Date:</strong> ${soldDate.value}</p><p><strong>Work Order:</strong> ${workOrderDisplay.value}</p>
                </div>
                <table class="w-full border-collapse text-sm"><thead class="bg-gray-200"><tr><th class="border p-2">SL</th><th class="border p-2">Details</th><th class="border p-2 text-right">Qty</th><th class="border p-2 text-right">Unit Price</th><th class="border p-2 text-right">Total</th></tr></thead><tbody>${itemsHtml}</tbody></table>
                <div class="flex justify-end mt-4"><div class="w-64 space-y-1 text-sm">
                    <div class="flex justify-between"><span class="font-medium">Subtotal:</span><span>${subtotal.toFixed(2)}</span></div>
                    <div class="flex justify-between"><span class="font-medium">Tax @ ${tax}%:</span><span>${(total - subtotal).toFixed(2)}</span></div>
                    <div class="flex justify-between font-bold text-base border-t pt-1"><span class="font-bold">Grand Total:</span><span>${total.toFixed(2)}</span></div>
                </div></div>`;
            confirmModal.classList.add('is-open');
        });
        
        confirmCancelBtn.addEventListener('click', () => confirmModal.classList.remove('is-open'));
        
        confirmSubmitBtn.addEventListener('click', async () => {
            confirmSubmitBtn.disabled = true; confirmSubmitBtn.textContent = 'Saving...';
            const postData = { 
                total_ex_tax: parseFloat(subtotalDisplay.textContent), 
                total_inc_tax: parseFloat(grandtotalDisplay.textContent),
                tax_percent: parseFloat(taxPercentInput.value) || 0 // Send tax percent
            };
            const res = await fetch('?action=submit_sale', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(postData) });
            const result = await res.json();
            
            if (result.status === 'success') {
                confirmModal.classList.remove('is-open');
                showGlobalMsg('Sale recorded successfully! Invoice: ' + result.new_invoice_no);
                // buildPrintMemo(result.new_invoice_no); // Print logic removed
                // printModal.classList.add('is-open'); // Print logic removed
                
                // Clear everything
                tempSoldList = []; tempTbody.innerHTML = ''; updateTotals(); taxPercentInput.value = '0';
                clientClearBtn.click(); soldDate.valueAsDate = new Date();
                workOrderId.value = ''; workOrderDisplay.value = '';
            } else { alert('Error: ' + (result.message || 'Something went wrong')); }
            confirmSubmitBtn.disabled = false; confirmSubmitBtn.textContent = 'Confirm & Save';
        });

        // Print functions removed as requested
    });

    // --- Session Timeout Logic (IIFE) ---
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
                await fetch('?action=keep_alive');
                startTimer();
            } catch (error) { window.location.href = 'logout.php?reason=idle'; }
        });
        startTimer();
    })();
</script>

</body>
</html>