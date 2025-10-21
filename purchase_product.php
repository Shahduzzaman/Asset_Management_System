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
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';
$current_user_id = $_SESSION['user_id'];
header('Content-Type: application/json'); // Set header for all API responses

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get dynamic lists for dropdowns
    if ($_GET['action'] === 'get_lists') {
        if ($_GET['list'] === 'brands' && isset($_GET['category_id'])) {
            $sql = "SELECT brand_id, brand_name FROM brands WHERE category_id = ? AND is_deleted = FALSE ORDER BY brand_name";
            $stmt = $conn->prepare($sql); $stmt->bind_param("i", $_GET['category_id']); $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $response = ['status' => 'success', 'data' => $data];
        }
        if ($_GET['list'] === 'models' && isset($_GET['brand_id'])) {
            $sql = "SELECT model_id, model_name FROM models WHERE brand_id = ? AND is_deleted = FALSE ORDER BY model_name";
            $stmt = $conn->prepare($sql); $stmt->bind_param("i", $_GET['brand_id']); $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $response = ['status' => 'success', 'data' => $data];
        }
    }

    // Action: Get details for a single temp product for editing
    if ($_GET['action'] === 'get_temp_product_details' && isset($_GET['id'])) {
        $sql = "SELECT * FROM purchase_temp WHERE temp_purchase_id = ? AND created_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $_GET['id'], $current_user_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) {
            $response = ['status' => 'success', 'data' => $data];
        } else {
            $response['message'] = 'Product not found.';
        }
    }

    // Action: Add a single product to the temporary table
    if ($_GET['action'] === 'add_temp_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sql = "INSERT INTO purchase_temp (vendor_id, purchase_date, invoice_number, category_id, brand_id, model_id, quantity, unit_price, warranty_period, serial_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("issiiisissi", $data['vendor_id'], $data['purchase_date'], $data['invoice_number'], $data['category_id'], $data['brand_id'], $data['model_id'], $data['quantity'], $data['unit_price'], $data['warranty_period'], $data['serial_number'], $current_user_id);
        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $result = $conn->query("SELECT pt.*, c.category_name, b.brand_name, m.model_name FROM purchase_temp pt JOIN categories c ON pt.category_id = c.category_id JOIN brands b ON pt.brand_id = b.brand_id JOIN models m ON pt.model_id = m.model_id WHERE pt.temp_purchase_id = $newId");
            $newRowData = $result->fetch_assoc();
            $response = ['status' => 'success', 'newRow' => $newRowData];
        } else {
            $response['message'] = 'Database error: ' . $stmt->error;
        }
    }
    
    // Action: Update a single temp product
    if ($_GET['action'] === 'update_temp_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sql = "UPDATE purchase_temp SET category_id=?, brand_id=?, model_id=?, quantity=?, unit_price=?, warranty_period=?, serial_number=? WHERE temp_purchase_id=? AND created_by=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiisssii", $data['category_id'], $data['brand_id'], $data['model_id'], $data['quantity'], $data['unit_price'], $data['warranty_period'], $data['serial_number'], $data['temp_id'], $current_user_id);
        if ($stmt->execute()) {
             $result = $conn->query("SELECT pt.*, c.category_name, b.brand_name, m.model_name FROM purchase_temp pt JOIN categories c ON pt.category_id = c.category_id JOIN brands b ON pt.brand_id = b.brand_id JOIN models m ON pt.model_id = m.model_id WHERE pt.temp_purchase_id = " . intval($data['temp_id']));
            $updatedRowData = $result->fetch_assoc();
            $response = ['status' => 'success', 'message' => 'Product updated.', 'updatedRow' => $updatedRowData];
        } else {
             $response['message'] = 'Database update error: ' . $stmt->error;
        }
    }

    // Action: Delete a product from the temporary table
    if ($_GET['action'] === 'delete_temp_product' && isset($_GET['id'])) {
        $tempId = intval($_GET['id']);
        $sql = "DELETE FROM purchase_temp WHERE temp_purchase_id = ? AND created_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $tempId, $current_user_id);
        if ($stmt->execute()) {
            $response = ['status' => 'success'];
        } else {
            $response['message'] = 'Failed to delete item.';
        }
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Handle Final Purchase Submission (Standard POST) ---
$successMessage = ''; $errorMessage = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_purchase'])) {
    header('Content-Type: text/html');
    $conn->begin_transaction();
    try {
        $sql_select = "SELECT * FROM purchase_temp WHERE created_by = ?";
        $stmt_select = $conn->prepare($sql_select);
        $stmt_select->bind_param("i", $current_user_id);
        $stmt_select->execute();
        $temp_products = $stmt_select->get_result();
        if ($temp_products->num_rows === 0) throw new Exception("No products in the list to submit.");
        
        $sql_insert = "INSERT INTO purchased_products (vendor_id, purchase_date, invoice_number, category_id, brand_id, model_id, quantity, unit_price, warranty_period, serial_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        while ($row = $temp_products->fetch_assoc()) {
            $stmt_insert->bind_param("issiiisissi", $row['vendor_id'], $row['purchase_date'], $row['invoice_number'], $row['category_id'], $row['brand_id'], $row['model_id'], $row['quantity'], $row['unit_price'], $row['warranty_period'], $row['serial_number'], $row['created_by']);
            $stmt_insert->execute();
        }

        $sql_delete = "DELETE FROM purchase_temp WHERE created_by = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $current_user_id);
        $stmt_delete->execute();
        
        $conn->commit();
        $successMessage = $temp_products->num_rows . " product(s) have been successfully recorded.";
    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = "Transaction Failed: " . $e->getMessage();
    }
}

// --- Part 3: Fetch initial data for page load ---
header('Content-Type: text/html');
$vendors = $conn->query("SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT category_id, category_name FROM categories WHERE is_deleted = FALSE ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);
$temp_items_sql = "SELECT pt.*, c.category_name, b.brand_name, m.model_name FROM purchase_temp pt JOIN categories c ON pt.category_id = c.category_id JOIN brands b ON pt.brand_id = b.brand_id JOIN models m ON pt.model_id = m.model_id WHERE pt.created_by = ? ORDER BY pt.temp_purchase_id";
$stmt_temp = $conn->prepare($temp_items_sql);
$stmt_temp->bind_param("i", $current_user_id);
$stmt_temp->execute();
$temp_items = $stmt_temp->get_result()->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Product</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } .modal { display: none; } .modal.is-open { display: flex; } </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Record Purchased Products</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>
        <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><strong>Success!</strong> <span><?php echo htmlspecialchars($successMessage); ?></span></div><?php endif; ?>
        <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><strong>Error!</strong> <span><?php echo htmlspecialchars($errorMessage); ?></span></div><?php endif; ?>
        
        <!-- Section 1: Main Purchase Information -->
        <div class="bg-white p-6 rounded-xl shadow-md mb-8">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Main Purchase Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <div class="flex justify-between items-center mb-1"><label for="vendor_id" class="text-sm font-medium text-gray-700">Vendor</label><button type="button" id="add-vendor-btn" class="text-xs bg-blue-500 text-white px-2 py-1 rounded-md hover:bg-blue-600">Add Vendor</button></div>
                    <select id="vendor_id" class="w-full p-3 border-gray-300 rounded-lg"><option value="">-- Select --</option><?php foreach($vendors as $v):?><option value="<?php echo $v['vendor_id'];?>"><?php echo htmlspecialchars($v['vendor_name']);?></option><?php endforeach;?></select>
                </div>
                <div><label for="purchase_date" class="text-sm font-medium text-gray-700">Purchase Date</label><input type="date" id="purchase_date" required class="mt-1 w-full p-3 border-gray-300 rounded-lg"></div>
                <div><label for="invoice_number" class="text-sm font-medium text-gray-700">Invoice Number</label><input type="text" id="invoice_number" placeholder="e.g., INV-12345" required class="mt-1 w-full p-3 border-gray-300 rounded-lg"></div>
            </div>
        </div>

        <!-- Section 2: Product Entry Form (NEW POSITION) -->
        <div class="bg-white p-6 rounded-xl shadow-md mb-8">
            <div class="flex justify-between items-center mb-4 border-b pb-2"><h2 class="text-xl font-semibold text-gray-700">Add a Product</h2><button type="button" id="add-hierarchy-btn" class="text-xs bg-blue-500 text-white px-2 py-1 rounded-md hover:bg-blue-600">Add Category/Brand/Model</button></div>
            <div id="product-entry-form" class="p-4 border rounded-lg bg-gray-50 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium">Category</label><select id="category_id" class="mt-1 w-full p-2 border-gray-300 rounded-md"><option value="">-- Select --</option><?php foreach($categories as $cat):?><option value="<?php echo $cat['category_id'];?>"><?php echo htmlspecialchars($cat['category_name']);?></option><?php endforeach;?></select></div>
                    <div><label class="block text-sm font-medium">Brand</label><select id="brand_id" class="mt-1 w-full p-2 border-gray-300 rounded-md" disabled><option>-- Select Category --</option></select></div>
                    <div><label class="block text-sm font-medium">Model</label><select id="model_id" class="mt-1 w-full p-2 border-gray-300 rounded-md" disabled><option>-- Select Brand --</option></select></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium">Quantity</label><input type="number" id="quantity" placeholder="1" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                    <div><label class="block text-sm font-medium">Unit Price</label><input type="number" step="0.01" id="unit_price" placeholder="0.00" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                    <div><label class="block text-sm font-medium">Warranty</label><input type="text" id="warranty_period" placeholder="e.g., 1 Year" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                </div>
                <div><label class="block text-sm font-medium">Serial Number(s)</label><input type="text" id="serial_number" placeholder="Optional. For multiple, separate with commas." class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div class="text-right"><button type="button" id="add-product-to-list-btn" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700">Add Product to List</button></div>
            </div>
        </div>

        <!-- Section 3: Temporary Product List (NEW POSITION) -->
        <div id="temp-list-container" class="bg-white p-6 rounded-xl shadow-md <?php echo empty($temp_items)?'hidden':'';?>">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Products in Current List</h2>
            <div class="overflow-x-auto"><table class="min-w-full"><thead class="bg-gray-50"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Qty</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unit Price</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Serial</th><th class="px-2 py-2"></th></tr></thead><tbody id="temp-product-tbody" class="divide-y divide-gray-200">
                <?php foreach($temp_items as $item):?>
                <tr id="temp-row-<?php echo $item['temp_purchase_id'];?>">
                    <td class="px-4 py-2 text-sm"><b><?php echo htmlspecialchars($item['category_name'].' / '.$item['brand_name'].' / '.$item['model_name']);?></b></td>
                    <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($item['quantity']);?></td>
                    <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($item['unit_price']);?></td>
                    <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($item['serial_number']);?></td>
                    <td class="px-4 py-2 text-center text-sm space-x-2">
                        <button class="edit-temp-item-btn p-1 bg-blue-500 text-white rounded hover:bg-blue-600" title="Edit" data-id="<?php echo $item['temp_purchase_id'];?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg></button>
                        <button class="remove-temp-item-btn p-1 bg-red-500 text-white rounded hover:bg-red-600" title="Delete" data-id="<?php echo $item['temp_purchase_id'];?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                    </td>
                </tr>
                <?php endforeach;?>
            </tbody></table></div>
        </div>
        
        <!-- Final Submit Button -->
        <div class="mt-8 text-right"><button type="button" id="final-submit-btn" class="bg-green-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-green-700 text-lg">Submit Full Purchase</button></div>
    </div>

    <!-- Modals -->
    <div id="page-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-40"><div class="bg-white rounded-lg shadow-xl w-11/12 max-w-4xl h-5/6 flex flex-col"><div class="p-4 border-b flex justify-between items-center"><h3 id="modal-title" class="text-xl font-semibold"></h3><button id="modal-close-btn" class="text-gray-500 text-2xl font-bold">&times;</button></div><iframe id="modal-iframe" class="w-full h-full border-0"></iframe></div></div>
    <div id="edit-product-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50"><div class="bg-white rounded-lg shadow-xl w-11/12 max-w-2xl flex flex-col p-6 space-y-4"><h2 class="text-xl font-semibold">Edit Product</h2><input type="hidden" id="edit-temp-id"><div class="grid grid-cols-1 md:grid-cols-3 gap-4"><div><label class="block text-sm">Category</label><select id="edit-category_id" class="mt-1 w-full p-2 border-gray-300 rounded-md"></select></div><div><label class="block text-sm">Brand</label><select id="edit-brand_id" class="mt-1 w-full p-2 border-gray-300 rounded-md"></select></div><div><label class="block text-sm">Model</label><select id="edit-model_id" class="mt-1 w-full p-2 border-gray-300 rounded-md"></select></div></div><div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div><label class="block text-sm">Quantity</label><input type="number" id="edit-quantity" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div><div><label class="block text-sm">Unit Price</label><input type="number" step="0.01" id="edit-unit_price" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div><div><label class="block text-sm">Warranty</label><input type="text" id="edit-warranty_period" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div></div><div><label class="block text-sm">Serial(s)</label><input type="text" id="edit-serial_number" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div><div class="flex justify-end gap-4"><button type="button" id="edit-cancel-btn" class="bg-gray-300 px-4 py-2 rounded-lg">Cancel</button><button type="button" id="edit-update-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg">Update Product</button></div></div></div>
    <div id="final-confirmation-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50"><div class="bg-white rounded-lg shadow-xl w-11/12 max-w-3xl flex flex-col p-6"><h2 class="text-2xl font-bold mb-4">Confirm Purchase Submission</h2><div class="grid grid-cols-3 gap-4 mb-4 p-4 bg-gray-50 rounded-lg border text-sm"><p><strong>Vendor:</strong> <span id="confirm-vendor"></span></p><p><strong>Date:</strong> <span id="confirm-date"></span></p><p><strong>Invoice #:</strong> <span id="confirm-invoice"></span></p></div><div class="overflow-y-auto max-h-80 border rounded-lg"><table class="min-w-full"><thead class="bg-gray-50 sticky top-0"><tr><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Qty</th><th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Price</th></tr></thead><tbody id="confirmation-list" class="divide-y"></tbody></table></div><div class="flex justify-end gap-4 mt-6"><button type="button" id="confirm-cancel-btn" class="bg-gray-300 px-6 py-2 rounded-lg">Cancel</button><form id="final-purchase-form" method="POST"><input type="hidden" name="submit_purchase" value="1"><button type="submit" class="bg-green-600 text-white font-bold px-6 py-2 rounded-lg">Confirm & Save</button></form></div></div></div>

<script>
async function vendorAdded(vendorId, vendorName) { document.getElementById('vendor_id').add(new Option(vendorName, vendorId, true, true)); document.getElementById('page-modal').classList.remove('is-open'); }

document.addEventListener('DOMContentLoaded', () => {
    const pageModal = document.getElementById('page-modal');
    const editModal = document.getElementById('edit-product-modal');
    const confirmModal = document.getElementById('final-confirmation-modal');

    const openModal = (modalEl) => modalEl.classList.add('is-open');
    const closeModal = (modalEl) => modalEl.classList.remove('is-open');

    document.getElementById('add-vendor-btn').addEventListener('click', () => { pageModal.querySelector('#modal-title').textContent = 'Add New Vendor'; pageModal.querySelector('#modal-iframe').src = 'add_vendor.php?context=modal'; openModal(pageModal); });
    document.getElementById('add-hierarchy-btn').addEventListener('click', () => { pageModal.querySelector('#modal-title').textContent = 'Product Hierarchy Management'; pageModal.querySelector('#modal-iframe').src = 'product_setup.php?context=modal'; openModal(pageModal); });
    pageModal.querySelector('#modal-close-btn').addEventListener('click', () => closeModal(pageModal));
    editModal.querySelector('#edit-cancel-btn').addEventListener('click', () => closeModal(editModal));
    confirmModal.querySelector('#confirm-cancel-btn').addEventListener('click', () => closeModal(confirmModal));
    
    const populateSelect = (el, data, val, text) => { el.innerHTML = '<option value="">-- Select --</option>'; data.forEach(item => el.add(new Option(item[text], item[val]))); el.disabled = false; };
    const allCategories = <?php echo json_encode($categories); ?>;

    async function setupChainedDropdowns(container, details = {}) {
        const catSelect = container.querySelector('[id*="category_id"]');
        const brandSelect = container.querySelector('[id*="brand_id"]');
        const modelSelect = container.querySelector('[id*="model_id"]');
        
        populateSelect(catSelect, allCategories, 'category_id', 'category_name');
        if(details.category_id) catSelect.value = details.category_id;
        
        catSelect.onchange = async () => {
            brandSelect.disabled = modelSelect.disabled = true;
            if (!catSelect.value) return;
            const res = await fetch(`?action=get_lists&list=brands&category_id=${catSelect.value}`);
            const { data } = await res.json();
            populateSelect(brandSelect, data, 'brand_id', 'brand_name');
            if (details.brand_id && catSelect.value == details.category_id) { brandSelect.value = details.brand_id; brandSelect.dispatchEvent(new Event('change')); }
        };

        brandSelect.onchange = async () => {
            modelSelect.disabled = true;
            if (!brandSelect.value) return;
            const res = await fetch(`?action=get_lists&list=models&brand_id=${brandSelect.value}`);
            const { data } = await res.json();
            populateSelect(modelSelect, data, 'model_id', 'model_name');
            if (details.model_id && brandSelect.value == details.brand_id) modelSelect.value = details.model_id;
        };
        if(details.category_id) catSelect.dispatchEvent(new Event('change'));
    }

    setupChainedDropdowns(document.getElementById('product-entry-form'));

    async function handleAddProduct() {
        const productData = {
            vendor_id: document.getElementById('vendor_id').value, purchase_date: document.getElementById('purchase_date').value, invoice_number: document.getElementById('invoice_number').value,
            category_id: document.getElementById('category_id').value, brand_id: document.getElementById('brand_id').value, model_id: document.getElementById('model_id').value,
            quantity: document.getElementById('quantity').value, unit_price: document.getElementById('unit_price').value, warranty_period: document.getElementById('warranty_period').value, serial_number: document.getElementById('serial_number').value,
        };
        if (!productData.vendor_id || !productData.purchase_date || !productData.invoice_number || !productData.category_id || !productData.brand_id || !productData.model_id || !productData.quantity) {
            alert('Please fill in all main and product fields before adding.'); return;
        }
        const res = await fetch('?action=add_temp_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(productData) });
        const result = await res.json();
        if (result.status === 'success') { updateTempTable(result.newRow, 'add'); resetAddForm(); } else { alert('Error: ' + result.message); }
    }
    document.getElementById('add-product-to-list-btn').addEventListener('click', handleAddProduct);

    function updateTempTable(rowData, mode) {
        const tbody = document.getElementById('temp-product-tbody');
        const newRowHTML = `
            <td class="px-4 py-2 text-sm"><b>${rowData.category_name} / ${rowData.brand_name} / ${rowData.model_name}</b></td>
            <td class="px-4 py-2 text-sm">${rowData.quantity}</td> <td class="px-4 py-2 text-sm">${rowData.unit_price}</td>
            <td class="px-4 py-2 text-sm">${rowData.serial_number}</td>
            <td class="px-4 py-2 text-center text-sm space-x-2">
                <button class="edit-temp-item-btn p-1 bg-blue-500 text-white rounded hover:bg-blue-600" title="Edit" data-id="${rowData.temp_purchase_id}"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg></button>
                <button class="remove-temp-item-btn p-1 bg-red-500 text-white rounded hover:bg-red-600" title="Delete" data-id="${rowData.temp_purchase_id}"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
            </td>`;
        if (mode === 'add') {
            const tr = document.createElement('tr'); tr.id = `temp-row-${rowData.temp_purchase_id}`; tr.innerHTML = newRowHTML; tbody.appendChild(tr);
        } else { document.getElementById(`temp-row-${rowData.temp_purchase_id}`).innerHTML = newRowHTML; }
        document.getElementById('temp-list-container').classList.remove('hidden');
    }

    function resetAddForm() {
        document.getElementById('product-entry-form').querySelectorAll('input, select').forEach(el => { if(el.tagName === 'SELECT' && el.id !== 'category_id') { el.innerHTML = '<option>-- Select --</option>'; el.disabled = true; } else { el.value = ''; }});
    }

    document.getElementById('temp-product-tbody').addEventListener('click', async (e) => {
        const button = e.target.closest('button');
        if (!button) return;
        const tempId = button.dataset.id;
        if (button.classList.contains('remove-temp-item-btn')) {
            if (!confirm('Are you sure?')) return;
            const res = await fetch(`?action=delete_temp_product&id=${tempId}`);
            if ((await res.json()).status === 'success') { document.getElementById(`temp-row-${tempId}`).remove(); if(document.getElementById('temp-product-tbody').rows.length === 0) document.getElementById('temp-list-container').classList.add('hidden'); }
        }
        if (button.classList.contains('edit-temp-item-btn')) {
            const res = await fetch(`?action=get_temp_product_details&id=${tempId}`);
            const { data } = await res.json();
            document.getElementById('edit-temp-id').value = tempId;
            document.getElementById('edit-quantity').value = data.quantity; document.getElementById('edit-unit_price').value = data.unit_price;
            document.getElementById('edit-warranty_period').value = data.warranty_period; document.getElementById('edit-serial_number').value = data.serial_number;
            await setupChainedDropdowns(editModal, data);
            openModal(editModal);
        }
    });

    document.getElementById('edit-update-btn').addEventListener('click', async () => {
        const updatedData = {
            temp_id: document.getElementById('edit-temp-id').value, category_id: document.getElementById('edit-category_id').value,
            brand_id: document.getElementById('edit-brand_id').value, model_id: document.getElementById('edit-model_id').value,
            quantity: document.getElementById('edit-quantity').value, unit_price: document.getElementById('edit-unit_price').value,
            warranty_period: document.getElementById('edit-warranty_period').value, serial_number: document.getElementById('edit-serial_number').value,
        };
        const res = await fetch('?action=update_temp_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(updatedData) });
        const result = await res.json();
        if (result.status === 'success') { updateTempTable(result.updatedRow, 'update'); closeModal(editModal); } else { alert('Error: ' + result.message); }
    });

    document.getElementById('final-submit-btn').addEventListener('click', () => {
        const tempRows = document.querySelectorAll('#temp-product-tbody tr');
        if (tempRows.length === 0) { alert("No products in the list to submit."); return; }
        
        const vendorSelect = document.getElementById('vendor_id');
        document.getElementById('confirm-vendor').textContent = vendorSelect.options[vendorSelect.selectedIndex].text;
        document.getElementById('confirm-date').textContent = document.getElementById('purchase_date').value;
        document.getElementById('confirm-invoice').textContent = document.getElementById('invoice_number').value;

        const confirmationTbody = document.getElementById('confirmation-list');
        confirmationTbody.innerHTML = '';
        tempRows.forEach(row => {
            const cells = row.querySelectorAll('td');
            const newTr = document.createElement('tr');
            newTr.innerHTML = `<td class="px-4 py-2">${cells[0].innerHTML}</td><td class="px-4 py-2">${cells[1].textContent}</td><td class="px-4 py-2">${cells[2].textContent}</td>`;
            confirmationTbody.appendChild(newTr);
        });
        openModal(confirmModal);
    });
});
</script>
</body>
</html>

