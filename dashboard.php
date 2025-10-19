<?php
// Start the session
session_start();

// Check if the user is logged in, otherwise redirect to the login page
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

// Get the user's name from the session and escape it for security
$user_name = htmlspecialchars($_SESSION["user_name"]);
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
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <main class="w-full max-w-2xl mx-auto bg-white p-8 rounded-xl shadow-lg text-center">
        
        <!-- Header -->
        <h1 class="text-3xl font-bold text-gray-800 mb-2">Product Management Portal</h1>
        <p class="text-gray-600 mb-8">Welcome, <span class="font-semibold"><?php echo $user_name; ?></span>!</p>

        <!-- Button Container -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <a href="purchase_product.php" class="bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-transform transform hover:scale-105 duration-300">
                Purchased Product
            </a>
            <a href="product_list.php" class="bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-transform transform hover:scale-105 duration-300">
                View Product
            </a>
            <a href="create_user.php" class="bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-transform transform hover:scale-105 duration-300">
                Create User
            </a>
        </div>
        
        <!-- Logout Button -->
        <div class="mt-8 border-t pt-6">
             <a href="logout.php" class="inline-block w-full sm:w-auto px-6 py-3 text-md font-semibold text-white bg-red-600 rounded-lg shadow-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-transform transform hover:scale-105">
                Logout
            </a>
        </div>
    </main>
</body>
</html>

