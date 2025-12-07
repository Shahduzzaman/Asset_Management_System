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

require_once 'connection.php';

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get details for a single product
    if ($_GET['action'] === 'get_product_details' && isset($_GET['id'])) {
        $purchase_id = intval($_GET['id']);
        
        // Query 1: Get main product data
        $sql = "SELECT pp.*, v.vendor_name, c.category_name, b.brand_name, m.model_name, u.user_name as creator_name
                FROM purchased_products pp
                JOIN vendors v ON pp.vendor_id = v.vendor_id
                JOIN categories c ON pp.category_id = c.category_id
                JOIN brands b ON pp.brand_id = b.brand_id
                JOIN models m ON pp.model_id = m.model_id
                LEFT JOIN users u ON pp.created_by = u.user_id
                WHERE pp.purchase_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $purchase_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($data) {
            // Query 2: Get all associated serial numbers
            $sql_sl = "SELECT product_sl FROM product_sl WHERE purchase_id_fk = ?";
            $stmt_sl = $conn->prepare($sql_sl);
            $stmt_sl->bind_param("i", $purchase_id);
            $stmt_sl->execute();
            $sl_result = $stmt_sl->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_sl->close();
            
            // Concatenate serials into a string
            $data['serial_numbers'] = implode(', ', array_column($sl_result, 'product_sl'));
            
            $response = ['status' => 'success', 'data' => $data];
        } else {
            $response['message'] = 'Product not found.';
        }
    }
    
    // Action: Update a product (REMOVED serial_number from update)
    if ($_GET['action'] === 'update_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        // Note: Serial number editing is removed from this form.
        $sql = "UPDATE purchased_products SET category_id=?, brand_id=?, model_id=?, quantity=?, unit_price=?, warranty_period=?, vendor_id=?, purchase_date=?, invoice_number=?, is_updated=TRUE WHERE purchase_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiisssisi", 
            $data['category_id'], $data['brand_id'], $data['model_id'], 
            $data['quantity'], $data['unit_price'], $data['warranty_period'], 
            $data['vendor_id'], $data['purchase_date'], $data['invoice_number'], 
            $data['purchase_id']
        );
        if ($stmt->execute()) {
             $response = ['status' => 'success', 'message' => 'Product updated successfully.'];
        } else {
             $response['message'] = 'Database update error: ' . $stmt->error;
        }
    }

    // *** START: FILLED IN MISSING DELETE LOGIC ***
    if ($_GET['action'] === 'delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sql = "UPDATE purchased_products SET is_deleted = TRUE, is_updated = TRUE WHERE purchase_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['purchase_id']);
        if ($stmt->execute()) {
             $response = ['status' => 'success', 'message' => 'Product deleted.'];
        } else {
             $response['message'] = 'Database delete error: ' . $stmt->error;
        }
    }
    // *** END: FILLED IN MISSING DELETE LOGIC ***
    
    // *** START: FILLED IN MISSING GET_LISTS LOGIC ***
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
    // *** END: FILLED IN MISSING GET_LISTS LOGIC ***

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Fetch initial data for page load ---
header('Content-Type: text/html');
// Main query no longer includes serial_number
$products_sql = "SELECT pp.purchase_id, pp.quantity, pp.unit_price, pp.invoice_number, 
                        v.vendor_name, c.category_name, b.brand_name, m.model_name
                 FROM purchased_products pp
                 JOIN vendors v ON pp.vendor_id = v.vendor_id
                 JOIN categories c ON pp.category_id = c.category_id
                 JOIN brands b ON pp.brand_id = b.brand_id
                 JOIN models m ON pp.model_id = m.model_id
                 WHERE pp.is_deleted = FALSE
                 ORDER BY pp.purchase_id DESC";
$products = $conn->query($products_sql)->fetch_all(MYSQLI_ASSOC);
$all_vendors = $conn->query("SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$all_categories = $conn->query("SELECT category_id, category_name FROM categories WHERE is_deleted = FALSE ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } .modal { display: none; } .modal.is-open { display: flex; } #product-table tbody tr { cursor: pointer; } </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Product Inventory</h1>
        </div>
        
        <div class="mb-6"><input type="text" id="search-box" placeholder="Search products..." class="w-full p-3 border-gray-300 rounded-lg"></div>
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table id="product-table" class="min-w-full">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($products as $product): ?>
                        <tr class="hover:bg-gray-50" data-id="<?php echo $product['purchase_id']; ?>">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($product['category_name'] . ' / ' . $product['brand_name'] . ' / ' . $product['model_name']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($product['vendor_name']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($product['quantity']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($product['unit_price']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($product['invoice_number']); ?></td>
                            <td class="px-6 py-4 text-center text-sm space-x-2">
                                <button class="action-btn edit-btn p-2 bg-blue-500 text-white rounded-full hover:bg-blue-600" title="Edit" data-id="<?php echo $product['purchase_id']; ?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg></button>
                                <button class="action-btn delete-btn p-2 bg-red-500 text-white rounded-full hover:bg-red-600" title="Delete" data-id="<?php echo $product['purchase_id']; ?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="view-details-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col"><div class="p-4 border-b flex justify-between items-center"><h2 class="text-xl font-semibold">Product Details</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div><div id="view-modal-body" class="p-6 space-y-4 text-sm overflow-y-auto"></div><div class="p-4 bg-gray-50 border-t text-right"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Close</button></div></div></div>
    <!-- Edit Modal -->
    <div id="edit-product-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-3xl flex flex-col"><div class="p-4 border-b"><h2 class="text-xl font-semibold">Edit Product</h2></div><div class="p-6 space-y-4 overflow-y-auto max-h-[70vh]"><input type="hidden" id="edit-purchase-id"><div class="grid grid-cols-1 md:grid-cols-3 gap-4"><div><label class="block text-sm">Category</label><select id="edit-category-id" class="mt-1 w-full p-2 border-gray-300 rounded-md"></select></div><div><label class="block text-sm">Brand</label><select id="edit-brand-id" class="mt-1 w-full p-2 border-gray-300 rounded-md"></select></div><div><label class="block text-sm">Model</label><select id="edit-model-id" class="mt-1 w-full p-2 border-gray-300 rounded-md"></select></div></div><div class="grid grid-cols-1 md:grid-cols-3 gap-4"><div><label class="block text-sm">Vendor</label><select id="edit-vendor-id" class="mt-1 w-full p-2 border-gray-300 rounded-md"></select></div><div><label class="block text-sm">Purchase Date</label><input type="date" id="edit-purchase-date" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div><div><label class="block text-sm">Invoice #</label><input type="text" id="edit-invoice-number" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div></div><div class="grid grid-cols-1 md:grid-cols-3 gap-4"><div><label class="block text-sm">Quantity</label><input type="number" id="edit-quantity" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div><div><label class="block text-sm">Unit Price</label><input type="number" step="0.01" id="edit-unit-price" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div><div><label class="block text-sm">Warranty</label><input type="text" id="edit-warranty-period" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div></div><div><label class="block text-sm">Serial(s) (Read-only)</label><textarea id="edit-serial-number" readonly class="mt-1 w-full p-2 border-gray-300 rounded-md bg-gray-100 cursor-not-allowed" rows="2"></textarea></div></div><div class="p-4 bg-gray-50 border-t flex justify-end gap-4"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Cancel</button><button id="edit-update-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg">Update Product</button></div></div></div>
    <!-- Delete Modal -->
    <div id="delete-confirm-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 text-center"><h2 class="text-xl font-bold mb-4">Confirm Deletion</h2><p class="mb-6">Are you sure you want to delete this product?</p><input type="hidden" id="delete-purchase-id"><div class="flex justify-center gap-4"><button class="close-modal-btn bg-gray-300 px-6 py-2 rounded-lg">Cancel</button><button id="delete-confirm-btn" class="bg-red-600 text-white font-bold px-6 py-2 rounded-lg">Confirm Delete</button></div></div></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const viewModal = document.getElementById('view-details-modal');
    const editModal = document.getElementById('edit-product-modal');
    const deleteModal = document.getElementById('delete-confirm-modal');
    const allModals = [viewModal, editModal, deleteModal];
    
    document.getElementById('search-box').addEventListener('input', e => {
        const searchTerm = e.target.value.toLowerCase();
        document.querySelectorAll('#product-table tbody tr').forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
        });
    });

    const openModal = modalEl => modalEl.classList.add('is-open');
    const closeModal = modalEl => modalEl.classList.remove('is-open');
    allModals.forEach(modal => modal.querySelectorAll('.close-modal-btn').forEach(btn => btn.addEventListener('click', () => closeModal(modal))));

    const allVendors = <?php echo json_encode($all_vendors); ?>;
    const allCategories = <?php echo json_encode($all_categories); ?>;
    const populateSelect = (el, data, valKey, textKey) => { el.innerHTML = '<option value="">-- Select --</option>'; data.forEach(item => el.add(new Option(item[textKey], item[valKey]))); };
    
    async function setupEditChainedDropdowns(details = {}) {
        const catSelect = document.getElementById('edit-category-id');
        const brandSelect = document.getElementById('edit-brand-id');
        const modelSelect = document.getElementById('edit-model-id');
        populateSelect(document.getElementById('edit-vendor-id'), allVendors, 'vendor_id', 'vendor_name');
        populateSelect(catSelect, allCategories, 'category_id', 'category_name');
        
        catSelect.onchange = async () => {
            brandSelect.disabled = modelSelect.disabled = true; brandSelect.innerHTML = modelSelect.innerHTML = '';
            if (!catSelect.value) return;
            const res = await fetch(`?action=get_lists&list=brands&category_id=${catSelect.value}`); const { data } = await res.json();
            populateSelect(brandSelect, data, 'brand_id', 'brand_name');
            // Check details.brand_id only if catSelect.value matches details.category_id
            if (details.brand_id && catSelect.value == details.category_id) { 
                brandSelect.value = details.brand_id; 
                brandSelect.dispatchEvent(new Event('change')); 
                details.brand_id = null; // Prevent re-triggering
            }
        };
        brandSelect.onchange = async () => {
            modelSelect.disabled = true; modelSelect.innerHTML = '';
            if (!brandSelect.value) return;
            const res = await fetch(`?action=get_lists&list=models&brand_id=${brandSelect.value}`); const { data } = await res.json();
            populateSelect(modelSelect, data, 'model_id', 'model_name');
             // Check details.model_id only if brandSelect.value matches details.brand_id
            if (details.model_id && brandSelect.value == details.original_brand_id) { 
                modelSelect.value = details.model_id;
                details.model_id = null; // Prevent re-triggering
            }
        };

        if (details.category_id) { 
            // Store original IDs to ensure correct selection after async fetches
            details.original_brand_id = details.brand_id; 
            details.original_model_id = details.model_id;
            catSelect.value = details.category_id; 
            catSelect.dispatchEvent(new Event('change')); 
        }
        if (details.vendor_id) document.getElementById('edit-vendor-id').value = details.vendor_id;
    }

    document.getElementById('product-table').addEventListener('click', async e => {
        const row = e.target.closest('tr');
        if (!row || !row.dataset.id) return;
        const id = row.dataset.id;
        
        if (e.target.closest('.action-btn')) {
            e.stopPropagation(); // Stop click from bubbling to the row
            const button = e.target.closest('.action-btn');
            if (button.classList.contains('edit-btn')) {
                const res = await fetch(`?action=get_product_details&id=${id}`); const { data } = await res.json();
                document.getElementById('edit-purchase-id').value = id;
                document.getElementById('edit-purchase-date').value = data.purchase_date;
                document.getElementById('edit-invoice-number').value = data.invoice_number;
                document.getElementById('edit-quantity').value = data.quantity;
                document.getElementById('edit-unit-price').value = data.unit_price;
                document.getElementById('edit-warranty-period').value = data.warranty_period;
                document.getElementById('edit-serial-number').value = data.serial_numbers; // Populate read-only serials
                await setupEditChainedDropdowns(data);
                openModal(editModal);
            } else if (button.classList.contains('delete-btn')) {
                document.getElementById('delete-purchase-id').value = id;
                openModal(deleteModal);
            }
        } else {
            const res = await fetch(`?action=get_product_details&id=${id}`);
            const { data } = await res.json();
            const body = document.getElementById('view-modal-body');
            body.innerHTML = `
                <div class="grid grid-cols-2 gap-4">
                    <p><strong>Product Name:</strong> ${data.category_name} / ${data.brand_name} / ${data.model_name}</p>
                    <p><strong>Vendor:</strong> ${data.vendor_name}</p>
                    <p><strong>Purchase Date:</strong> ${data.purchase_date}</p>
                    <p><strong>Invoice #:</strong> ${data.invoice_number}</p>
                    <p><strong>Quantity:</strong> ${data.quantity}</p>
                    <p><strong>Unit Price:</strong> ${data.unit_price}</p>
                    <p><strong>Warranty:</strong> ${data.warranty_period}</p>
                    <p><strong>Created By:</strong> ${data.creator_name}</p>
                </div>
                <div>
                    <p><strong>Serial Numbers:</strong></p>
                    <p class="text-gray-600 ${data.serial_numbers ? '' : 'italic'}">${data.serial_numbers || 'No serial numbers associated'}</p>
                </div>`;
            openModal(viewModal);
        }
    });
    
    document.getElementById('edit-update-btn').addEventListener('click', async () => {
        const updatedData = {
            purchase_id: document.getElementById('edit-purchase-id').value,
            category_id: document.getElementById('edit-category-id').value, brand_id: document.getElementById('edit-brand-id').value, model_id: document.getElementById('edit-model-id').value,
            vendor_id: document.getElementById('edit-vendor-id').value, purchase_date: document.getElementById('edit-purchase-date').value, invoice_number: document.getElementById('edit-invoice-number').value,
            quantity: document.getElementById('edit-quantity').value, unit_price: document.getElementById('edit-unit-price').value,
            warranty_period: document.getElementById('edit-warranty-period').value
            // serial_number is NOT included
        };
        const res = await fetch('?action=update_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(updatedData) });
        const result = await res.json();
        if(result.status === 'success') {
            alert('Product updated successfully! The page will now reload to show changes.');
            window.location.reload();
        } else { alert('Error: ' + result.message); }
    });

    document.getElementById('delete-confirm-btn').addEventListener('click', async () => {
        const id = document.getElementById('delete-purchase-id').value;
        const res = await fetch('?action=delete_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({purchase_id: id}) });
        const result = await res.json();
        if (result.status === 'success') {
            document.querySelector(`tr[data-id="${id}"]`).remove();
            closeModal(deleteModal);
        } else { alert('Error: ' + result.message); }
    });
});
</script>

</body>
</html>

