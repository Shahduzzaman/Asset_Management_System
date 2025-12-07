<?php
// Start the session and check if the user is logged in
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

// Include the database connection
require_once 'connection.php';

// --- Part 1: Handle API requests for dynamic dropdowns ---
// This part of the code responds to JavaScript fetch requests
if (isset($_GET['get'])) {
    header('Content-Type: application/json');
    $response = [];

    // Request to get brands for a specific category
    if ($_GET['get'] === 'brands' && isset($_GET['category_id'])) {
        $categoryId = intval($_GET['category_id']);
        $sql = "SELECT brand_id, brand_name FROM brands WHERE category_id = ? AND is_deleted = FALSE ORDER BY brand_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $response[] = $row;
        }
        $stmt->close();
    }

    // Request to get models for a specific brand
    if ($_GET['get'] === 'models' && isset($_GET['brand_id'])) {
        $brandId = intval($_GET['brand_id']);
        $sql = "SELECT model_id, model_name FROM models WHERE brand_id = ? AND is_deleted = FALSE ORDER BY model_name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $brandId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $response[] = $row;
        }
        $stmt->close();
    }

    echo json_encode($response);
    $conn->close();
    exit(); // Stop script execution after sending JSON data
}


// --- Part 2: Handle Form Submissions (POST requests) ---
$successMessage = '';
$errorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $created_by = $_SESSION['user_id'];

    // Action: Add a new category
    if ($action === 'add_category' && !empty($_POST['category_name'])) {
        $categoryName = trim($_POST['category_name']);
        $sql = "INSERT INTO categories (category_name, created_by) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $categoryName, $created_by);
        if ($stmt->execute()) {
            $successMessage = "Category '$categoryName' added successfully!";
        } else {
            $errorMessage = "Error adding category: " . $stmt->error;
        }
        $stmt->close();
    }

    // Action: Add a new brand
    elseif ($action === 'add_brand' && !empty($_POST['brand_name']) && !empty($_POST['category_id_for_brand'])) {
        $brandName = trim($_POST['brand_name']);
        $categoryId = intval($_POST['category_id_for_brand']);
        $sql = "INSERT INTO brands (category_id, brand_name, created_by) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isi", $categoryId, $brandName, $created_by);
        if ($stmt->execute()) {
            $successMessage = "Brand '$brandName' added successfully!";
        } else {
            $errorMessage = "Error adding brand: " . $stmt->error;
        }
        $stmt->close();
    }

    // Action: Add a new model
    elseif ($action === 'add_model' && !empty($_POST['model_name']) && !empty($_POST['category_id_for_model']) && !empty($_POST['brand_id_for_model'])) {
        $modelName = trim($_POST['model_name']);
        $categoryId = intval($_POST['category_id_for_model']);
        $brandId = intval($_POST['brand_id_for_model']);
        $sql = "INSERT INTO models (category_id, brand_id, model_name, created_by) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisi", $categoryId, $brandId, $modelName, $created_by);
        if ($stmt->execute()) {
            $successMessage = "Model '$modelName' added successfully!";
        } else {
            $errorMessage = "Error adding model: " . $stmt->error;
        }
        $stmt->close();
    }
    
    // Handle cases where required fields are missing
    elseif (empty($errorMessage)) {
        $errorMessage = "A required field was missing. Please try again.";
    }
}

// --- Part 3: Fetch initial data for the page load ---
$categories = [];
$sql = "SELECT category_id, category_name FROM categories WHERE is_deleted = FALSE ORDER BY category_name";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}
$conn->close();

// Check if the page is loaded in a modal context
$isModal = isset($_GET['context']) && $_GET['context'] === 'modal';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .hidden-form { max-height: 0; overflow: hidden; transition: max-height 0.5s ease-in-out; }
        .visible-form { max-height: 500px; }
        /* Adjustments for running inside an iframe */
        <?php if ($isModal): ?>
        body { background-color: #f9fafb; }
        html, body { height: 100%; overflow: auto; }
        <?php endif; ?>
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        
        <!-- Header and Navigation -->
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Product Hierarchy Management</h1>
        </div>

        <!-- Success and Error Messages -->
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

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            
            <!-- Column 1: Category Management -->
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">1. Select or Add Category</h2>
                <div class="space-y-4">
                    <label for="categorySelect" class="block text-sm font-medium text-gray-700">Existing Categories</label>
                    <select id="categorySelect" name="category_id" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <option value="">-- Select a Category --</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                        <?php endforeach; ?>
                        <option value="add_new" class="font-bold text-indigo-600">-- Add New Category --</option>
                    </select>

                    <!-- Hidden form to add a new category -->
                    <div id="newCategoryForm" class="hidden-form">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?><?php echo $isModal ? '?context=modal' : ''; ?>" class="space-y-4 pt-4 border-t">
                            <input type="hidden" name="action" value="add_category">
                            <div>
                                <label for="category_name" class="block text-sm font-medium text-gray-700">New Category Name</label>
                                <input type="text" name="category_name" id="category_name" placeholder="e.g., Laptops, Monitors" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-300">Save Category</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Column 2: Brand Management -->
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">2. Select or Add Brand</h2>
                <div class="space-y-4">
                    <label for="brandSelect" class="block text-sm font-medium text-gray-700">Brands</label>
                    <select id="brandSelect" name="brand_id" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" disabled>
                        <option value="">-- Select a Category First --</option>
                    </select>

                    <!-- Hidden form to add a new brand -->
                    <div id="newBrandForm" class="hidden-form">
                         <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?><?php echo $isModal ? '?context=modal' : ''; ?>" class="space-y-4 pt-4 border-t">
                            <input type="hidden" name="action" value="add_brand">
                            <input type="hidden" name="category_id_for_brand" id="category_id_for_brand">
                            <div>
                                <label for="brand_name" class="block text-sm font-medium text-gray-700">New Brand Name</label>
                                <input type="text" name="brand_name" id="brand_name" placeholder="e.g., Dell, Samsung" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-300">Save Brand</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Column 3: Model Management -->
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">3. Select or Add Model</h2>
                <div class="space-y-4">
                    <label for="modelSelect" class="block text-sm font-medium text-gray-700">Models</label>
                    <select id="modelSelect" name="model_id" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" disabled>
                        <option value="">-- Select a Brand First --</option>
                    </select>

                    <!-- Hidden form to add a new model -->
                    <div id="newModelForm" class="hidden-form">
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?><?php echo $isModal ? '?context=modal' : ''; ?>" class="space-y-4 pt-4 border-t">
                            <input type="hidden" name="action" value="add_model">
                            <input type="hidden" name="category_id_for_model" id="category_id_for_model">
                            <input type="hidden" name="brand_id_for_model" id="brand_id_for_model">
                            <div>
                                <label for="model_name" class="block text-sm font-medium text-gray-700">New Model Name</label>
                                <input type="text" name="model_name" id="model_name" placeholder="e.g., XPS 13, Odyssey G9" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition duration-300">Save Model</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const categorySelect = document.getElementById('categorySelect');
    const brandSelect = document.getElementById('brandSelect');
    const modelSelect = document.getElementById('modelSelect');
    const newCategoryForm = document.getElementById('newCategoryForm');
    const newBrandForm = document.getElementById('newBrandForm');
    const newModelForm = document.getElementById('newModelForm');

    const resetSelect = (selectElement, defaultText) => {
        selectElement.innerHTML = `<option value="">${defaultText}</option>`;
        selectElement.disabled = true;
    };
    
    const populateSelect = (selectElement, data, valueField, textField, addNewOption) => {
        data.forEach(item => {
            const option = document.createElement('option');
            option.value = item[valueField];
            option.textContent = item[textField];
            selectElement.appendChild(option);
        });
        if (addNewOption) {
             selectElement.innerHTML += `<option value="add_new" class="font-bold text-indigo-600">-- ${addNewOption} --</option>`;
        }
        selectElement.disabled = false;
    };

    categorySelect.addEventListener('change', async () => {
        const categoryId = categorySelect.value;
        newCategoryForm.classList.remove('visible-form');
        newBrandForm.classList.remove('visible-form');
        newModelForm.classList.remove('visible-form');
        resetSelect(brandSelect, '-- Select a Category First --');
        resetSelect(modelSelect, '-- Select a Brand First --');

        if (categoryId === 'add_new') {
            newCategoryForm.classList.add('visible-form');
        } else if (categoryId) {
            try {
                const response = await fetch(`product_setup.php?get=brands&category_id=${categoryId}`);
                const brands = await response.json();
                resetSelect(brandSelect, '-- Select a Brand --');
                populateSelect(brandSelect, brands, 'brand_id', 'brand_name', 'Add New Brand');
            } catch (error) {
                console.error('Error fetching brands:', error);
                resetSelect(brandSelect, '-- Error loading brands --');
            }
        }
    });

    brandSelect.addEventListener('change', async () => {
        const brandId = brandSelect.value;
        const categoryId = categorySelect.value;
        newBrandForm.classList.remove('visible-form');
        newModelForm.classList.remove('visible-form');
        resetSelect(modelSelect, '-- Select a Brand First --');

        if (brandId === 'add_new') {
            document.getElementById('category_id_for_brand').value = categoryId;
            newBrandForm.classList.add('visible-form');
        } else if (brandId) {
            try {
                const response = await fetch(`product_setup.php?get=models&brand_id=${brandId}`);
                const models = await response.json();
                resetSelect(modelSelect, '-- Select a Model --');
                populateSelect(modelSelect, models, 'model_id', 'model_name', 'Add New Model');
            } catch (error) {
                console.error('Error fetching models:', error);
                resetSelect(modelSelect, '-- Error loading models --');
            }
        }
    });

    modelSelect.addEventListener('change', () => {
        const modelId = modelSelect.value;
        const categoryId = categorySelect.value;
        const brandId = brandSelect.value;
        newModelForm.classList.remove('visible-form');

        if (modelId === 'add_new') {
            document.getElementById('category_id_for_model').value = categoryId;
            document.getElementById('brand_id_for_model').value = brandId;
            newModelForm.classList.add('visible-form');
        }
    });
    
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

