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

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get details for a single product (works for deleted items too)
    if ($_GET['action'] === 'get_product_details' && isset($_GET['id'])) {
        $sql = "SELECT pp.*, v.vendor_name, c.category_name, b.brand_name, m.model_name, u.user_name as creator_name
                FROM purchased_products pp
                JOIN vendors v ON pp.vendor_id = v.vendor_id
                JOIN categories c ON pp.category_id = c.category_id
                JOIN brands b ON pp.brand_id = b.brand_id
                JOIN models m ON pp.model_id = m.model_id
                LEFT JOIN users u ON pp.created_by = u.user_id
                WHERE pp.purchase_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_GET['id']);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) $response = ['status' => 'success', 'data' => $data];
        else $response['message'] = 'Product not found.';
    }

    // Action: Restore a product (sets is_deleted to FALSE)
    if ($_GET['action'] === 'restore_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sql = "UPDATE purchased_products SET is_deleted = FALSE, is_updated = TRUE WHERE purchase_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['purchase_id']);
        if ($stmt->execute()) {
             $response = ['status' => 'success', 'message' => 'Product restored successfully.'];
        } else {
             $response['message'] = 'Database restore error: ' . $stmt->error;
        }
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Fetch initial data for page load ---
header('Content-Type: text/html');
// THE KEY CHANGE IS HERE: is_deleted = TRUE
$products_sql = "SELECT pp.purchase_id, pp.quantity, pp.unit_price, pp.invoice_number, 
                        v.vendor_name, c.category_name, b.brand_name, m.model_name
                 FROM purchased_products pp
                 JOIN vendors v ON pp.vendor_id = v.vendor_id
                 JOIN categories c ON pp.category_id = c.category_id
                 JOIN brands b ON pp.brand_id = b.brand_id
                 JOIN models m ON pp.model_id = m.model_id
                 WHERE pp.is_deleted = TRUE
                 ORDER BY pp.purchase_id DESC";
$products = $conn->query($products_sql)->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash / Deleted Products</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } 
        .modal.is-open { display: flex; }
        #product-table tbody tr { cursor: pointer; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Trash / Deleted Products</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>

        <!-- Search Bar -->
        <div class="mb-6">
            <input type="text" id="search-box" placeholder="Search in trash..." class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>

        <!-- Product Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table id="product-table" class="min-w-full">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Qty</th>
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
                            <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($product['invoice_number']); ?></td>
                            <td class="px-6 py-4 text-center text-sm">
                                <button class="action-btn restore-btn p-2 bg-green-500 text-white rounded-full hover:bg-green-600" title="Restore" data-id="<?php echo $product['purchase_id']; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                         <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-10 text-gray-500">The trash is empty.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="view-details-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col"><div class="p-4 border-b flex justify-between items-center"><h2 class="text-xl font-semibold">Product Details</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div><div id="view-modal-body" class="p-6 space-y-4 text-sm overflow-y-auto"></div><div class="p-4 bg-gray-50 border-t text-right"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Close</button></div></div></div>
    <div id="restore-confirm-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 text-center"><h2 class="text-xl font-bold mb-4">Confirm Restore</h2><p class="mb-6">Are you sure you want to restore this product to the main inventory?</p><input type="hidden" id="restore-purchase-id"><div class="flex justify-center gap-4"><button class="close-modal-btn bg-gray-300 px-6 py-2 rounded-lg">Cancel</button><button id="restore-confirm-btn" class="bg-green-600 text-white font-bold px-6 py-2 rounded-lg">Confirm Restore</button></div></div></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const viewModal = document.getElementById('view-details-modal');
    const restoreModal = document.getElementById('restore-confirm-modal');
    const allModals = [viewModal, restoreModal];
    
    // --- Live Search ---
    document.getElementById('search-box').addEventListener('input', e => {
        const searchTerm = e.target.value.toLowerCase();
        document.querySelectorAll('#product-table tbody tr').forEach(row => {
            if (row.querySelector('td[colspan]')) return; // Ignore the 'empty' message row
            row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
        });
    });

    // --- Modal Controls ---
    const openModal = modalEl => modalEl.classList.add('is-open');
    const closeModal = modalEl => modalEl.classList.remove('is-open');
    allModals.forEach(modal => modal.querySelectorAll('.close-modal-btn').forEach(btn => btn.addEventListener('click', () => closeModal(modal))));

    // --- Main Table Event Delegation ---
    document.getElementById('product-table').addEventListener('click', async e => {
        const row = e.target.closest('tr');
        if (!row || !row.dataset.id) return;
        const id = row.dataset.id;
        
        // Handle Action Button Clicks
        if (e.target.closest('.action-btn')) {
            const button = e.target.closest('.action-btn');
            if (button.classList.contains('restore-btn')) {
                document.getElementById('restore-purchase-id').value = id;
                openModal(restoreModal);
            }
        } else { // Handle Row Click for View Details
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
                    <p><strong>Serial Number:</strong> ${data.serial_number}</p>
                    <p><strong>Created By:</strong> ${data.creator_name}</p>
                    <p><strong>Created At:</strong> ${new Date(data.created_at).toLocaleString()}</p>
                </div>`;
            openModal(viewModal);
        }
    });
    
    // --- Restore Confirmation Button Listener ---
    document.getElementById('restore-confirm-btn').addEventListener('click', async () => {
        const id = document.getElementById('restore-purchase-id').value;
        const res = await fetch('?action=restore_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({purchase_id: id}) });
        const result = await res.json();
        if (result.status === 'success') {
            document.querySelector(`tr[data-id="${id}"]`).remove();
            closeModal(restoreModal);
        } else { alert('Error: ' + result.message); }
    });
});
</script>

</body>
</html>
