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

// --- Part 1: Handle API requests for dynamic dropdowns (AJAX) ---
if (isset($_GET['get'])) {
    header('Content-Type: application/json');
    $response = [];

    if ($_GET['get'] === 'brands' && isset($_GET['category_id'])) {
        $categoryId = intval($_GET['category_id']);
        $sql = "SELECT brand_id, brand_name FROM brands WHERE category_id = ? AND is_deleted = FALSE ORDER BY brand_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $response[] = $row; }
        $stmt->close();
    }

    if ($_GET['get'] === 'models' && isset($_GET['brand_id'])) {
        $brandId = intval($_GET['brand_id']);
        $sql = "SELECT model_id, model_name FROM models WHERE brand_id = ? AND is_deleted = FALSE ORDER BY model_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $brandId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) { $response[] = $row; }
        $stmt->close();
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Handle Main Form Submission (POST) ---
$successMessage = '';
$errorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_purchase'])) {
    $vendor_id = intval($_POST['vendor_id']);
    $purchase_date = $_POST['purchase_date'];
    $invoice_number = trim($_POST['invoice_number']);
    $created_by = $_SESSION['user_id'];

    // Retrieve product line arrays from the form
    $category_ids = $_POST['category_id'] ?? [];
    $brand_ids = $_POST['brand_id'] ?? [];
    $model_ids = $_POST['model_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    $warranty_periods = $_POST['warranty_period'] ?? [];
    $serial_numbers = $_POST['serial_number'] ?? [];

    // Begin a database transaction
    $conn->begin_transaction();

    try {
        $sql = "INSERT INTO purchased_products (vendor_id, purchase_date, invoice_number, category_id, brand_id, model_id, quantity, unit_price, warranty_period, serial_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        // Loop through each product submitted
        $productsAdded = 0;
        for ($i = 0; $i < count($category_ids); $i++) {
            // Basic check to ensure essential data exists for the row
            if (empty($category_ids[$i]) || empty($brand_ids[$i]) || empty($model_ids[$i]) || empty($quantities[$i])) {
                continue; // Skip empty/incomplete rows
            }

            $stmt->bind_param("issiiisissi",
                $vendor_id,
                $purchase_date,
                $invoice_number,
                $category_ids[$i],
                $brand_ids[$i],
                $model_ids[$i],
                $quantities[$i],
                $unit_prices[$i],
                $warranty_periods[$i],
                $serial_numbers[$i],
                $created_by
            );
            $stmt->execute();
            $productsAdded++;
        }
        
        // If the execution failed at any point, an exception would be thrown
        // If we reach here, it means all inserts were successful, so we commit them.
        $conn->commit();
        $successMessage = "$productsAdded product(s) under invoice #$invoice_number have been successfully recorded.";

    } catch (mysqli_sql_exception $exception) {
        // If any query fails, roll back all changes
        $conn->rollback();
        $errorMessage = "Transaction Failed: " . $exception->getMessage();
    }
    
    $stmt->close();
}


// --- Part 3: Fetch initial data for the page load ---
$vendors = [];
$sql_vendors = "SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name";
$result_vendors = $conn->query($sql_vendors);
if ($result_vendors) { while ($row = $result_vendors->fetch_assoc()) { $vendors[] = $row; } }

$categories = [];
$sql_categories = "SELECT category_id, category_name FROM categories WHERE is_deleted = FALSE ORDER BY category_name";
$result_categories = $conn->query($sql_categories);
if ($result_categories) { while ($row = $result_categories->fetch_assoc()) { $categories[] = $row; } }

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
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Record Purchased Products</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition">&larr; Back to Dashboard</a>
        </div>
        
        <?php if ($successMessage): ?>
        <div id="alert-box" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
            <strong class="font-bold">Success!</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($successMessage); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
        <div id="alert-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
            <strong class="font-bold">Error!</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($errorMessage); ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <!-- Section 1: Main Purchase Details -->
            <div class="bg-white p-6 rounded-xl shadow-md mb-8">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Main Purchase Information</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="vendor_id" class="block text-sm font-medium text-gray-700">Vendor</label>
                        <select id="vendor_id" name="vendor_id" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
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

            <!-- Section 2: Product Line Items -->
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Product Details</h2>
                <div id="product-lines-container" class="space-y-6">
                    <!-- Initial product line item -->
                    <div class="p-4 border rounded-lg product-entry space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Category</label>
                                <select name="category_id[]" required class="category-select mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="">-- Select --</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['category_id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                             <div>
                                <label class="block text-sm font-medium text-gray-700">Brand</label>
                                <select name="brand_id[]" required class="brand-select mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm" disabled>
                                    <option value="">-- Select Category First --</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Model</label>
                                <select name="model_id[]" required class="model-select mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm" disabled>
                                    <option value="">-- Select Brand First --</option>
                                </select>
                            </div>
                        </div>
                         <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Quantity</label>
                                <input type="number" name="quantity[]" placeholder="1" required class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Unit Price</label>
                                <input type="number" step="0.01" name="unit_price[]" placeholder="0.00" required class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Warranty</label>
                                <input type="text" name="warranty_period[]" placeholder="e.g., 1 Year" class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Serial Number</label>
                            <input type="text" name="serial_number[]" placeholder="Optional. For multiple serials, separate with commas." class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                        </div>
                    </div>
                </div>
                <!-- Action buttons for product lines -->
                <div class="mt-6 flex justify-end">
                    <button type="button" id="add-more-product" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition">Add More Products</button>
                </div>
            </div>
            
            <!-- Final Submit Button -->
            <div class="mt-8 text-right">
                <button type="submit" name="submit_purchase" class="bg-green-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-green-700 transition text-lg">Submit Purchase</button>
            </div>
        </form>
    </div>

<!-- Template for new product lines (hidden) -->
<template id="product-line-template">
    <div class="p-4 border rounded-lg product-entry space-y-4 relative">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Category</label>
                <select name="category_id[]" required class="category-select mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                    <option value="">-- Select --</option>
                    <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category['category_id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
             <div>
                <label class="block text-sm font-medium text-gray-700">Brand</label>
                <select name="brand_id[]" required class="brand-select mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm" disabled>
                    <option value="">-- Select Category First --</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Model</label>
                <select name="model_id[]" required class="model-select mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm" disabled>
                    <option value="">-- Select Brand First --</option>
                </select>
            </div>
        </div>
         <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Quantity</label>
                <input type="number" name="quantity[]" placeholder="1" required class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Unit Price</label>
                <input type="number" step="0.01" name="unit_price[]" placeholder="0.00" required class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Warranty</label>
                <input type="text" name="warranty_period[]" placeholder="e.g., 1 Year" class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Serial Number</label>
            <input type="text" name="serial_number[]" placeholder="Optional. For multiple serials, separate with commas." class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
        </div>
        <button type="button" class="remove-product-btn absolute -top-3 -right-3 bg-red-600 text-white rounded-full h-7 w-7 flex items-center justify-center font-bold text-sm hover:bg-red-700">&times;</button>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('product-lines-container');

    // Function to populate a select dropdown
    const populateSelect = (selectElement, data, valueField, textField) => {
        selectElement.innerHTML = '<option value="">-- Select --</option>'; // Reset
        data.forEach(item => {
            const option = document.createElement('option');
            option.value = item[valueField];
            option.textContent = item[textField];
            selectElement.appendChild(option);
        });
        selectElement.disabled = false;
    };

    // Use event delegation for dynamically added elements
    container.addEventListener('change', async (event) => {
        const target = event.target;
        const productEntry = target.closest('.product-entry');
        if (!productEntry) return;

        // --- Handle Category Change ---
        if (target.matches('.category-select')) {
            const categoryId = target.value;
            const brandSelect = productEntry.querySelector('.brand-select');
            const modelSelect = productEntry.querySelector('.model-select');
            
            // Reset dependent dropdowns
            brandSelect.innerHTML = '<option value="">-- Loading... --</option>';
            brandSelect.disabled = true;
            modelSelect.innerHTML = '<option value="">-- Select Brand First --</option>';
            modelSelect.disabled = true;

            if (categoryId) {
                try {
                    const response = await fetch(`purchase_product.php?get=brands&category_id=${categoryId}`);
                    const brands = await response.json();
                    populateSelect(brandSelect, brands, 'brand_id', 'brand_name');
                } catch (error) {
                    console.error('Error fetching brands:', error);
                    brandSelect.innerHTML = '<option value="">-- Error --</option>';
                }
            } else {
                 brandSelect.innerHTML = '<option value="">-- Select Category First --</option>';
            }
        }

        // --- Handle Brand Change ---
        if (target.matches('.brand-select')) {
            const brandId = target.value;
            const modelSelect = productEntry.querySelector('.model-select');
            
            modelSelect.innerHTML = '<option value="">-- Loading... --</option>';
            modelSelect.disabled = true;

            if (brandId) {
                try {
                    const response = await fetch(`purchase_product.php?get=models&brand_id=${brandId}`);
                    const models = await response.json();
                    populateSelect(modelSelect, models, 'model_id', 'model_name');
                } catch (error) {
                    console.error('Error fetching models:', error);
                    modelSelect.innerHTML = '<option value="">-- Error --</option>';
                }
            } else {
                modelSelect.innerHTML = '<option value="">-- Select Brand First --</option>';
            }
        }
    });

    // Handle "Remove" button clicks
    container.addEventListener('click', (event) => {
        if (event.target.matches('.remove-product-btn')) {
            event.target.closest('.product-entry').remove();
        }
    });

    // Handle "Add More Products" button click
    document.getElementById('add-more-product').addEventListener('click', () => {
        const template = document.getElementById('product-line-template');
        const clone = template.content.cloneNode(true);
        container.appendChild(clone);
    });
    
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
</script>

</body>
</html>

