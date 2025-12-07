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
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';

$successMessage = '';
$errorMessage = '';
$newVendorId = null;
$newVendorName = '';

// Check if the page is loaded in a modal context
$isModal = isset($_GET['context']) && $_GET['context'] === 'modal';


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $vendor_name = trim($_POST['vendor_name']);
    $contact_person = trim($_POST['contact_person']);
    $address = trim($_POST['address']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $created_by = $_SESSION['user_id'];

    if (empty($vendor_name)) {
        $errorMessage = "Vendor Name is a required field.";
    } else {
        $sql = "INSERT INTO vendors (vendor_name, contact_person, address, phone, email, created_by) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("sssssi", $vendor_name, $contact_person, $address, $phone, $email, $created_by);
            if ($stmt->execute()) {
                $newVendorId = $stmt->insert_id;
                $newVendorName = $vendor_name;
                $successMessage = "Vendor added successfully!";
            } else {
                $errorMessage = "Error adding vendor: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errorMessage = "Error preparing statement: " . $conn->error;
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Vendor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        <?php if ($isModal): ?>
        /* Adjustments for running inside an iframe */
        body { background-color: #f9fafb; }
        html, body { height: 100%; overflow: auto; }
        <?php endif; ?>
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        
        <!-- Header and Navigation -->
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Add New Vendor</h1>
        </div>

        <main class="w-full max-w-2xl mx-auto">
             <div class="bg-white rounded-2xl shadow-xl p-8">
                
                <?php if (!$isModal): ?>
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Vendor Information</h2>
                    <p class="text-gray-500 mt-2">Please fill out the form to add a new vendor to the system.</p>
                </div>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><strong>Error!</strong> <span><?php echo $errorMessage; ?></span></div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?><?php echo $isModal ? '?context=modal' : ''; ?>" method="POST" class="space-y-5">
                    <div>
                        <label for="vendor_name" class="block text-sm font-medium text-gray-700">Vendor Name <span class="text-red-500">*</span></label>
                        <input type="text" id="vendor_name" name="vendor_name" required class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="contact_person" class="block text-sm font-medium text-gray-700">Contact Person</label>
                        <input type="text" id="contact_person" name="contact_person" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                        <input type="email" id="email" name="email" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                        <textarea id="address" name="address" rows="3" class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">Save Vendor</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <?php if ($successMessage && $newVendorId): ?>
    <script>
        try {
            window.parent.vendorAdded(<?php echo $newVendorId; ?>, '<?php echo addslashes($newVendorName); ?>');
        } catch (e) {
            console.error("Could not communicate with parent window.", e);
            alert('Vendor Added Successfully!');
        }
    </script>
    <?php endif; ?>

</body>
</html>

