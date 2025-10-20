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
        if ($_GET['list'] === 'vendors') {
            $sql = "SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name";
            $result = $conn->query($sql);
            $data = $result->fetch_all(MYSQLI_ASSOC);
            $response = ['status' => 'success', 'data' => $data];
        }
        if ($_GET['list'] === 'brands' && isset($_GET['category_id'])) {
            $sql = "SELECT brand_id, brand_name FROM brands WHERE category_id = ? AND is_deleted = FALSE ORDER BY brand_name";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $_GET['category_id']);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $response = ['status' => 'success', 'data' => $data];
        }
        if ($_GET['list'] === 'models' && isset($_GET['brand_id'])) {
            $sql = "SELECT model_id, model_name FROM models WHERE brand_id = ? AND is_deleted = FALSE ORDER BY model_name";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $_GET['brand_id']);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $response = ['status' => 'success', 'data' => $data];
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
            // Fetch the newly added row to send back to the client
            $result = $conn->query("SELECT pt.*, c.category_name, b.brand_name, m.model_name FROM purchase_temp pt JOIN categories c ON pt.category_id = c.category_id JOIN brands b ON pt.brand_id = b.brand_id JOIN models m ON pt.model_id = m.model_id WHERE pt.temp_purchase_id = $newId");
            $newRowData = $result->fetch_assoc();
            $response = ['status' => 'success', 'message' => 'Product added to list.', 'newRow' => $newRowData];
        } else {
            $response['message'] = 'Database error: ' . $stmt->error;
        }
    }
    
    // Action: Delete a product from the temporary table
    if ($_GET['action'] === 'delete_temp_product' && isset($_GET['id'])) {
        $tempId = intval($_GET['id']);
        $sql = "DELETE FROM purchase_temp WHERE temp_purchase_id = ? AND created_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $tempId, $current_user_id);
        if ($stmt->execute()) {
            $response = ['status' => 'success', 'message' => 'Product removed from list.'];
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
    header('Content-Type: text/html'); // Switch back to HTML for the page reload
    
    $conn->begin_transaction();
    try {
        // Step 1: Select all temp items for the user
        $sql_select = "SELECT * FROM purchase_temp WHERE created_by = ?";
        $stmt_select = $conn->prepare($sql_select);
        $stmt_select->bind_param("i", $current_user_id);
        $stmt_select->execute();
        $temp_products = $stmt_select->get_result();

        if ($temp_products->num_rows === 0) {
            throw new Exception("No products in the list to submit.");
        }

        // Step 2: Insert each item into the final purchased_products table
        $sql_insert = "INSERT INTO purchased_products (vendor_id, purchase_date, invoice_number, category_id, brand_id, model_id, quantity, unit_price, warranty_period, serial_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        
        while ($row = $temp_products->fetch_assoc()) {
            $stmt_insert->bind_param("issiiisissi", $row['vendor_id'], $row['purchase_date'], $row['invoice_number'], $row['category_id'], $row['brand_id'], $row['model_id'], $row['quantity'], $row['unit_price'], $row['warranty_period'], $row['serial_number'], $row['created_by']);
            $stmt_insert->execute();
        }

        // Step 3: Delete the items from the temp table
        $sql_delete = "DELETE FROM purchase_temp WHERE created_by = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $current_user_id);
        $stmt_delete->execute();

        // Step 4: Commit the transaction
        $conn->commit();
        $successMessage = $temp_products->num_rows . " product(s) have been successfully recorded.";

    } catch (Exception $e) {
        $conn->rollback();
        $errorMessage = "Transaction Failed: " . $e->getMessage();
    }
    // Continue to load the HTML part of the page
}


// --- Part 3: Fetch initial data for page load ---
header('Content-Type: text/html'); // Ensure we output HTML from here

$vendors = $conn->query("SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT category_id, category_name FROM categories WHERE is_deleted = FALSE ORDER BY category_name")->fetch_all(MYSQLI_ASSOC);

// Fetch existing temp products for the user
$temp_items_sql = "SELECT pt.*, c.category_name, b.brand_name, m.model_name 
                   FROM purchase_temp pt
                   JOIN categories c ON pt.category_id = c.category_id
                   JOIN brands b ON pt.brand_id = b.brand_id
                   JOIN models m ON pt.model_id = m.model_id
                   WHERE pt.created_by = ? ORDER BY pt.temp_purchase_id";
$stmt_temp = $conn->prepare($temp_items_sql);
$stmt_temp->bind_param("i", $current_user_id);
$stmt_temp->execute();
$temp_items = $stmt_temp->get_result()->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Product</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; }
        .modal.is-open { display: flex; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Record Purchased Products</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition">&larr; Back to Dashboard</a>
        </div>
        
        <?php if ($successMessage): ?>
        <div id="alert-box" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6"><strong>Success!</strong> <span><?php echo htmlspecialchars($successMessage); ?></span></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
        <div id="alert-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6"><strong>Error!</strong> <span><?php echo htmlspecialchars($errorMessage); ?></span></div>
        <?php endif; ?>

        <!-- Section 1: Main Purchase Details -->
        <div class="bg-white p-6 rounded-xl shadow-md mb-8">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Main Purchase Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <label for="vendor_id" class="block text-sm font-medium text-gray-700">Vendor</label>
                        <button type="button" id="add-vendor-btn" class="text-xs bg-blue-500 text-white px-2 py-1 rounded-md hover:bg-blue-600">Add Vendor</button>
                    </div>
                    <select id="vendor_id" name="vendor_id" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">-- Select a Vendor --</option>
                        <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['vendor_id']; ?>"><?php echo htmlspecialchars($vendor['vendor_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="purchase_date" class="block text-sm font-medium text-gray-700">Purchase Date</label>
                    <input type="date" id="purchase_date" name="purchase_date" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="invoice_number" class="block text-sm font-medium text-gray-700">Invoice Number</label>
                    <input type="text" id="invoice_number" name="invoice_number" placeholder="e.g., INV-12345" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>
        </div>

        <!-- Section 2: Temporary Product List -->
        <div id="temp-list-container" class="bg-white p-6 rounded-xl shadow-md mb-8 <?php echo empty($temp_items) ? 'hidden' : ''; ?>">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Products in Current Purchase List</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Serial</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody id="temp-product-tbody" class="bg-white divide-y divide-gray-200">
                        <?php foreach ($temp_items as $item): ?>
                        <tr id="temp-row-<?php echo $item['temp_purchase_id']; ?>">
                            <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-800"><b><?php echo htmlspecialchars($item['category_name'] . ' / ' . $item['brand_name'] . ' / ' . $item['model_name']); ?></b></td>
                            <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($item['quantity']); ?></td>
                            <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($item['unit_price']); ?></td>
                            <td class="px-4 py-2 text-sm"><?php echo htmlspecialchars($item['serial_number']); ?></td>
                            <td class="px-2 py-2 text-center">
                                <button type="button" class="remove-temp-item-btn text-red-500 hover:text-red-700" data-id="<?php echo $item['temp_purchase_id']; ?>">&times;</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 3: Product Entry Form -->
        <div class="bg-white p-6 rounded-xl shadow-md">
            <div class="flex justify-between items-center mb-4 border-b pb-2">
                 <h2 class="text-xl font-semibold text-gray-700">Add a Product</h2>
                 <button type="button" id="add-hierarchy-btn" class="text-sm bg-gray-200 text-gray-700 px-3 py-1 rounded-md hover:bg-gray-300">Add Category/Brand/Model</button>
            </div>
            <div id="product-entry-form" class="p-4 border rounded-lg bg-gray-50 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium">Category</label><select id="category_id" class="category-select mt-1 w-full p-2 border-gray-300 rounded-md"><option value="">-- Select --</option><?php foreach ($categories as $cat): ?><option value="<?php echo $cat['category_id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option><?php endforeach; ?></select></div>
                    <div><label class="block text-sm font-medium">Brand</label><select id="brand_id" class="brand-select mt-1 w-full p-2 border-gray-300 rounded-md" disabled><option>-- Select Category --</option></select></div>
                    <div><label class="block text-sm font-medium">Model</label><select id="model_id" class="model-select mt-1 w-full p-2 border-gray-300 rounded-md" disabled><option>-- Select Brand --</option></select></div>
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
        
        <!-- Final Submit Button -->
        <form id="final-purchase-form" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="mt-8 text-right">
             <input type="hidden" name="submit_purchase" value="1">
            <button type="button" id="final-submit-btn" class="bg-green-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-green-700 text-lg">Submit Full Purchase</button>
        </form>
    </div>

    <!-- Modals -->
    <div id="page-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-40">
        <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-4xl h-5/6 flex flex-col">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 id="modal-title" class="text-xl font-semibold">Loading...</h3>
                <button id="modal-close-btn" class="text-gray-500 hover:text-gray-800 text-2xl font-bold">&times;</button>
            </div>
            <iframe id="modal-iframe" class="w-full h-full border-0"></iframe>
        </div>
    </div>
    
<script>
// --- Global Scope Functions for Popups ---
async function vendorAdded(vendorId, vendorName) {
    const vendorSelect = document.getElementById('vendor_id');
    const newOption = new Option(vendorName, vendorId, true, true);
    vendorSelect.add(newOption);
    document.getElementById('page-modal').classList.remove('is-open');
}

document.addEventListener('DOMContentLoaded', () => {
    // --- Element References ---
    const modal = document.getElementById('page-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalIframe = document.getElementById('modal-iframe');
    const modalCloseBtn = document.getElementById('modal-close-btn');

    // --- Modal Control ---
    const openModal = (url, title) => {
        modalTitle.textContent = title;
        modalIframe.src = url;
        modal.classList.add('is-open');
    };
    const closeModal = () => {
        modalIframe.src = 'about:blank';
        modal.classList.remove('is-open');
    };

    document.getElementById('add-vendor-btn').addEventListener('click', () => openModal('add_vendor.php', 'Add New Vendor'));
    document.getElementById('add-hierarchy-btn').addEventListener('click', () => openModal('product_setup.php?context=modal', 'Product Hierarchy Management'));
    modalCloseBtn.addEventListener('click', closeModal);

    // --- Product Entry Form Logic ---
    const entryForm = document.getElementById('product-entry-form');
    const categorySelect = entryForm.querySelector('#category_id');
    const brandSelect = entryForm.querySelector('#brand_id');
    const modelSelect = entryForm.querySelector('#model_id');
    
    const populateSelect = (selectEl, data, valKey, textKey) => {
        selectEl.innerHTML = '<option value="">-- Select --</option>';
        data.forEach(item => selectEl.add(new Option(item[textKey], item[valKey])));
        selectEl.disabled = false;
    };

    categorySelect.addEventListener('change', async () => {
        brandSelect.disabled = true; modelSelect.disabled = true;
        if (!categorySelect.value) return;
        const res = await fetch(`?action=get_lists&list=brands&category_id=${categorySelect.value}`);
        const { data } = await res.json();
        populateSelect(brandSelect, data, 'brand_id', 'brand_name');
    });

    brandSelect.addEventListener('change', async () => {
        modelSelect.disabled = true;
        if (!brandSelect.value) return;
        const res = await fetch(`?action=get_lists&list=models&brand_id=${brandSelect.value}`);
        const { data } = await res.json();
        populateSelect(modelSelect, data, 'model_id', 'model_name');
    });

    // Reusable function to add the current product entry to the temp list
    async function addProductToList() {
        const productData = {
            vendor_id: document.getElementById('vendor_id').value,
            purchase_date: document.getElementById('purchase_date').value,
            invoice_number: document.getElementById('invoice_number').value,
            category_id: categorySelect.value,
            brand_id: brandSelect.value,
            model_id: modelSelect.value,
            quantity: document.getElementById('quantity').value,
            unit_price: document.getElementById('unit_price').value,
            warranty_period: document.getElementById('warranty_period').value,
            serial_number: document.getElementById('serial_number').value,
        };

        if (!productData.vendor_id || !productData.purchase_date || !productData.invoice_number || !productData.category_id || !productData.brand_id || !productData.model_id || !productData.quantity) {
            alert('Please fill in all main and product fields before adding to the list.');
            return false;
        }

        const res = await fetch('?action=add_temp_product', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(productData)
        });
        const result = await res.json();

        if (result.status === 'success') {
            const newRow = result.newRow;
            const tbody = document.getElementById('temp-product-tbody');
            const tr = document.createElement('tr');
            tr.id = `temp-row-${newRow.temp_purchase_id}`;
            tr.innerHTML = `
                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-800"><b>${newRow.category_name} / ${newRow.brand_name} / ${newRow.model_name}</b></td>
                <td class="px-4 py-2 text-sm">${newRow.quantity}</td>
                <td class="px-4 py-2 text-sm">${newRow.unit_price}</td>
                <td class="px-4 py-2 text-sm">${newRow.serial_number}</td>
                <td class="px-2 py-2 text-center"><button type="button" class="remove-temp-item-btn text-red-500" data-id="${newRow.temp_purchase_id}">&times;</button></td>
            `;
            tbody.appendChild(tr);
            document.getElementById('temp-list-container').classList.remove('hidden');
            
            // Reset form fields
            categorySelect.value = ''; brandSelect.innerHTML = '<option>-- Select Category --</option>'; brandSelect.disabled = true;
            modelSelect.innerHTML = '<option>-- Select Brand --</option>'; modelSelect.disabled = true;
            document.getElementById('quantity').value = ''; document.getElementById('unit_price').value = '';
            document.getElementById('warranty_period').value = ''; document.getElementById('serial_number').value = '';
            return true;
        } else {
            alert('Error: ' + result.message);
            return false;
        }
    }
    
    document.getElementById('add-product-to-list-btn').addEventListener('click', addProductToList);

    // --- FINAL SUBMIT LOGIC ---
    document.getElementById('final-submit-btn').addEventListener('click', async () => {
        const finalForm = document.getElementById('final-purchase-form');
        const productFields = [categorySelect, brandSelect, modelSelect, document.getElementById('quantity')];
        const isEntryFormPopulated = productFields.some(field => field.value);

        // If there are values in the entry form, try to add them first
        if (isEntryFormPopulated) {
            const added = await addProductToList();
            if (!added) {
                // If adding fails (e.g., validation error), stop the submission.
                return;
            }
        }
        
        // After attempting to add, check if there are any items in the list
        const tempTableBody = document.getElementById('temp-product-tbody');
        if (tempTableBody.rows.length === 0) {
            alert("There are no products in the list to submit. Please add at least one product.");
            return;
        }

        // If everything is fine, submit the form
        if (confirm("Are you sure you want to submit this entire purchase?")) {
            finalForm.submit();
        }
    });

    // --- Remove Product from Temp List Logic ---
    document.getElementById('temp-product-tbody').addEventListener('click', async (e) => {
        if (e.target.classList.contains('remove-temp-item-btn')) {
            const tempId = e.target.dataset.id;
            if (!confirm('Are you sure you want to remove this item?')) return;
            
            const res = await fetch(`?action=delete_temp_product&id=${tempId}`);
            const result = await res.json();
            
            if (result.status === 'success') {
                document.getElementById(`temp-row-${tempId}`).remove();
                if(document.getElementById('temp-product-tbody').rows.length === 0) {
                     document.getElementById('temp-list-container').classList.add('hidden');
                }
            } else {
                alert('Error: ' + result.message);
            }
        }
    });
});
</script>

</body>
</html>

