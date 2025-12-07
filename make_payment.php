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

// --- Part 1: API Request Handler (AJAX for Invoice Search) ---
if (isset($_GET['action']) && $_GET['action'] === 'search_invoices') {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid parameters'];
    
    if (isset($_GET['vendor_id']) && isset($_GET['query'])) {
        $vendorId = intval($_GET['vendor_id']);
        $query = trim($_GET['query']) . '%'; // Add wildcard for search
        
        // Select distinct invoice numbers matching the vendor and query
        $sql = "SELECT DISTINCT invoice_number 
                FROM purchased_products 
                WHERE vendor_id = ? AND invoice_number LIKE ? 
                AND is_deleted = FALSE 
                ORDER BY invoice_number 
                LIMIT 10"; // Limit results for performance
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $vendorId, $query);
        $stmt->execute();
        $result = $stmt->get_result();
        $invoices = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $response = ['status' => 'success', 'data' => $invoices];
    }

    echo json_encode($response);
    $conn->close();
    exit();
}


// --- Part 2: Handle Main Form Submission (POST) ---
$successMessage = ''; $errorMessage = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_payment'])) {
    header('Content-Type: text/html'); // Set back for page load

    $vendor_id = intval($_POST['vendor_id']);
    $invoice_number = trim($_POST['invoice_number']);
    $payment_date = $_POST['payment_date'];
    $debit_amount = $_POST['debit_amount']; // Should be validated as numeric
    $payment_method = $_POST['payment_method'] ?? ''; // Radio button value
    $payment_info_no = trim($_POST['payment_info_no']);
    $remarks = trim($_POST['remarks']);

    // Basic Server-side Validation
    if (empty($vendor_id) || empty($payment_date) || empty($debit_amount) || empty($payment_method)) {
        $errorMessage = "Please fill in all required fields (Vendor, Date, Amount, Method).";
    } elseif (!is_numeric($debit_amount) || $debit_amount <= 0) {
        $errorMessage = "Debit amount must be a positive number.";
    } else {
        $sql = "INSERT INTO payment_table (vendor_id, invoice_number, payment_date, debit_amount, payment_method, payment_info_no, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        // Bind parameters: i = integer, s = string, d = double (for decimal)
        $stmt->bind_param("issdsssi", 
            $vendor_id, 
            $invoice_number, 
            $payment_date, 
            $debit_amount, 
            $payment_method, 
            $payment_info_no, 
            $remarks, 
            $current_user_id
        );

        if ($stmt->execute()) {
            $successMessage = "Payment recorded successfully for invoice #" . htmlspecialchars($invoice_number) . ".";
        } else {
            $errorMessage = "Error recording payment: " . $stmt->error;
        }
        $stmt->close();
    }
}

// --- Part 3: Fetch initial data for page load ---
header('Content-Type: text/html'); // Ensure HTML output
$vendors = $conn->query("SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Payment</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        /* Style for the datalist dropdown */
        #invoice-list option { padding: 4px; } 
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">

        <main class="w-full max-w-2xl mx-auto">
            <div class="bg-white rounded-2xl shadow-xl p-8">
                
                <div class="text-center mb-8">
                    <h2 class="text-2xl font-bold text-gray-800">Payment Details</h2>
                    <p class="text-gray-500 mt-2">Enter the details for the payment made to a vendor.</p>
                </div>

                <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $successMessage; ?></span></div><?php endif; ?>
                <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $errorMessage; ?></span></div><?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    
                    <div>
                        <label for="vendor_id" class="block text-sm font-medium text-gray-700 mb-1">Vendor <span class="text-red-500">*</span></label>
                        <select id="vendor_id" name="vendor_id" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">-- Select Vendor --</option>
                            <?php foreach ($vendors as $vendor): ?>
                            <option value="<?php echo $vendor['vendor_id']; ?>"><?php echo htmlspecialchars($vendor['vendor_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="relative">
                        <label for="invoice_number" class="block text-sm font-medium text-gray-700 mb-1">Purchased Invoice Number</label>
                        <input type="text" id="invoice_number" name="invoice_number" list="invoice-list" placeholder="Type to search invoices..." class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" autocomplete="off">
                        <datalist id="invoice-list"></datalist>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label for="payment_date" class="block text-sm font-medium text-gray-700 mb-1">Payment Date <span class="text-red-500">*</span></label>
                            <input type="date" id="payment_date" name="payment_date" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="debit_amount" class="block text-sm font-medium text-gray-700 mb-1">Debit Amount <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" id="debit_amount" name="debit_amount" placeholder="0.00" required class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div>
                         <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method <span class="text-red-500">*</span></label>
                         <div class="flex flex-wrap gap-x-6 gap-y-2">
                             <label class="inline-flex items-center"><input type="radio" name="payment_method" value="Check" class="form-radio text-indigo-600" required><span class="ml-2">Check</span></label>
                             <label class="inline-flex items-center"><input type="radio" name="payment_method" value="Cash" class="form-radio text-indigo-600"><span class="ml-2">Cash</span></label>
                             <label class="inline-flex items-center"><input type="radio" name="payment_method" value="Transfer" class="form-radio text-indigo-600"><span class="ml-2">Transfer</span></label>
                             <label class="inline-flex items-center"><input type="radio" name="payment_method" value="Deposit" class="form-radio text-indigo-600"><span class="ml-2">Deposit</span></label>
                         </div>
                    </div>
                    
                    <div>
                        <label for="payment_info_no" class="block text-sm font-medium text-gray-700 mb-1">Payment Info No.</label>
                        <input type="text" id="payment_info_no" name="payment_info_no" placeholder="e.g., Check #, Transaction ID" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="remarks" class="block text-sm font-medium text-gray-700 mb-1">Remarks</label>
                        <textarea id="remarks" name="remarks" rows="3" placeholder="Optional notes about the payment" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                    </div>

                    <div class="pt-4">
                        <button type="submit" name="submit_payment" class="w-full bg-green-600 text-white font-bold py-3 px-4 rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition">Record Payment</button>
                    </div>
                </form>
            </div>
        </main>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const vendorSelect = document.getElementById('vendor_id');
    const invoiceInput = document.getElementById('invoice_number');
    const invoiceList = document.getElementById('invoice-list');
    let searchTimeout;

    invoiceInput.addEventListener('input', () => {
        const vendorId = vendorSelect.value;
        const query = invoiceInput.value.trim();

        // Clear previous timeout
        clearTimeout(searchTimeout);
        invoiceList.innerHTML = ''; // Clear previous suggestions

        if (vendorId && query.length > 0) { // Require vendor and at least 1 char
            // Debounce the search: wait 300ms after typing stops
            searchTimeout = setTimeout(async () => {
                try {
                    const response = await fetch(`?action=search_invoices&vendor_id=${vendorId}&query=${encodeURIComponent(query)}`);
                    const result = await response.json();

                    if (result.status === 'success' && result.data.length > 0) {
                        result.data.forEach(item => {
                            const option = document.createElement('option');
                            option.value = item.invoice_number;
                            invoiceList.appendChild(option);
                        });
                    }
                } catch (error) {
                    console.error("Error fetching invoices:", error);
                }
            }, 300);
        }
    });

    // Clear invoice suggestions if vendor changes
    vendorSelect.addEventListener('change', () => {
        invoiceInput.value = ''; // Clear input
        invoiceList.innerHTML = ''; // Clear suggestions
    });

    // Auto-hide alert messages
    const alertBox = document.getElementById('alert-box');
    if (alertBox) { setTimeout(() => { alertBox.style.transition = 'opacity 0.5s ease'; alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000); }
});
</script>

</body>
</html>
