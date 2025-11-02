<?php
session_start();

// Check if the user is logged in, otherwise redirect to the login page
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

// Check for and display error messages passed via session
$errorMessage = '';
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']); // Clear the message after displaying it once
}

// Get the user's name and role from the session
$user_name = htmlspecialchars($_SESSION["user_name"]);
$user_role = isset($_SESSION["user_role"]) ? (int)$_SESSION["user_role"] : 0; // Default to 0 (User) if not set

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen py-12">
    <main class="w-full max-w-3xl mx-auto bg-white p-8 rounded-xl shadow-lg text-center">

        <!-- Header -->
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Asset Management System</h1>
        <p class="text-gray-600 mb-8">Welcome, <span class="font-semibold"><?php echo $user_name; ?></span>!</p>

        <!-- Display Error Messages -->
        <?php if (!empty($errorMessage)): ?>
            <div id="error-alert-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                <span class="block sm:inline"><?php echo htmlspecialchars($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <!-- Button Container -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <a href="product_setup.php" class="bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-indigo-700 transition-transform transform hover:scale-105 duration-300">
                Product Setup
            </a>
            <a href="add_vendor.php" class="bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-indigo-700 transition-transform transform hover:scale-105 duration-300">
                Add Vendor
            </a>
            <a href="Add_Client.php" class="bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-indigo-700 transition-transform transform hover:scale-105 duration-300">
                Add Client
            </a>
            <a href="purchase_product.php" class="bg-green-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-green-700 transition-transform transform hover:scale-105 duration-300">
                Purchased Product
            </a>
             <a href="make_payment.php" class="bg-green-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-green-700 transition-transform transform hover:scale-105 duration-300">
                Make Payment
            </a>
            <a href="product_list.php" class="bg-cyan-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-cyan-700 transition-transform transform hover:scale-105 duration-300">
                View Product
            </a>
            <!-- NEW BUTTON ADDED HERE -->
             <a href="view_vendor.php" class="bg-cyan-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-cyan-700 transition-transform transform hover:scale-105 duration-300">
                View Vendors
            </a>
             <a href="view_client.php" class="bg-cyan-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-cyan-700 transition-transform transform hover:scale-105 duration-300">
                View Clients
            </a>
             <a href="ledger.php" class="bg-cyan-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-cyan-700 transition-transform transform hover:scale-105 duration-300">
                Vendor Ledger
            </a>

            <?php // *** START: Conditional Links for Admin *** ?>
            <?php if ($user_role === 1): ?>
                <a href="create_user.php" class="bg-purple-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-purple-700 transition-transform transform hover:scale-105 duration-300">
                    Create User
                </a>
                <a href="manage_users.php" class="bg-purple-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-purple-700 transition-transform transform hover:scale-105 duration-300">
                    Manage Users
                </a>
            <?php endif; ?>
            <?php // *** END: Conditional Links for Admin *** ?>

            <a href="trash.php" class="bg-gray-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-gray-700 transition-transform transform hover:scale-105 duration-300">
                Trash
            </a>
            <a href="my_profile.php" class="bg-teal-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-teal-700 transition-transform transform hover:scale-105 duration-300">
                My Profile
            </a>
        </div>
        
        <!-- Logout Button -->
        <div class="mt-8 border-t pt-6">
             <a href="logout.php" class="inline-block w-full sm:w-auto px-6 py-3 text-md font-semibold text-white bg-red-600 rounded-lg shadow-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-transform transform hover:scale-105">
                Logout
            </a>
        </div>
    </main>

    <script>
        // Auto-hide error alert box
        const errorAlertBox = document.getElementById('error-alert-box');
        if (errorAlertBox) {
            setTimeout(() => {
                errorAlertBox.style.transition = 'opacity 0.5s ease';
                errorAlertBox.style.opacity = '0';
                setTimeout(() => errorAlertBox.remove(), 500); // Remove after fade out
            }, 5000); // 5 seconds
        }
    </script>
</body>
</html>

