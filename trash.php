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
// Get user role from session
$current_user_id = $_SESSION['user_id'];
$user_role = isset($_SESSION['user_role']) ? (int)$_SESSION['user_role'] : 0;
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get details for a single product (works for deleted items too)
    if ($_GET['action'] === 'get_product_details' && isset($_GET['id'])) {
        $purchase_id = intval($_GET['id']);
        
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
        
        if ($data) {
             $sql_sl = "SELECT product_sl FROM product_sl WHERE purchase_id_fk = ?";
            $stmt_sl = $conn->prepare($sql_sl);
            $stmt_sl->bind_param("i", $purchase_id);
            $stmt_sl->execute();
            $sl_result = $stmt_sl->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_sl->close();
            $data['serial_numbers'] = implode(', ', array_column($sl_result, 'product_sl'));
            
            $response = ['status' => 'success', 'data' => $data];
        } else {
            $response['message'] = 'Product not found.';
        }
        $stmt->close();
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

    // --- NEW ACTION: PERMANENT DELETE (Admin Only) ---
    if ($_GET['action'] === 'permanent_delete_product' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Server-side role check
        if ($user_role != 1) {
             $response['message'] = 'Access Denied: You do not have permission to perform this action.';
        } else {
            $data = json_decode(file_get_contents('php://input'), true);
            $purchase_id = intval($data['purchase_id']);

            try {
                // This will also trigger ON DELETE CASCADE for product_sl records
                $sql = "DELETE FROM purchased_products WHERE purchase_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $purchase_id);
                
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'Product permanently deleted.'];
                } else {
                    $response['message'] = 'Database delete error: ' . $stmt->error;
                }
            } catch (mysqli_sql_exception $e) {
                 // Catch Foreign Key constraint violations (e.g., if a return record points to a serial)
                if ($e->getCode() == 1451) {
                    $response['message'] = 'Cannot delete: This product has associated records (like returns) that must be handled first.';
                } else {
                    $response['message'] = 'Database error: ' . $e->getMessage();
                }
            }
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
    <style> body { font-family: 'Inter', sans-serif; } .modal { display: none; } .modal.is-open { display: flex; } #product-table tbody tr { cursor: pointer; } </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Trash / Deleted Products</h1>
        </div>
        
        <div class="mb-6"><input type="text" id="search-box" placeholder="Search in trash..." class="w-full p-3 border-gray-300 rounded-lg"></div>
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
                            <td class="px-6 py-4 text-center text-sm space-x-2">
                                <button class="action-btn restore-btn p-2 bg-green-500 text-white rounded-full hover:bg-green-600" title="Restore" data-id="<?php echo $product['purchase_id']; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
                                </button>
                                <?php if ($user_role === 1): // Admin-only delete button ?>
                                <button class="action-btn permanent-delete-btn p-2 bg-red-700 text-white rounded-full hover:bg-red-800" title="Permanently Delete" data-id="<?php echo $product['purchase_id']; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                         <?php if (empty($products)): ?>
                            <tr><td colspan="5" class="text-center py-10 text-gray-500">The trash is empty.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="view-details-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col"><div class="p-4 border-b flex justify-between items-center"><h2 class="text-xl font-semibold">Product Details</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div><div id="view-modal-body" class="p-6 space-y-4 text-sm overflow-y-auto"></div><div class="p-4 bg-gray-50 border-t text-right"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Close</button></div></div></div>
    <div id="restore-confirm-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 text-center"><h2 class="text-xl font-bold mb-4">Confirm Restore</h2><p class="mb-6">Are you sure you want to restore this product to the main inventory?</p><input type="hidden" id="restore-purchase-id"><div class="flex justify-center gap-4"><button class="close-modal-btn bg-gray-300 px-6 py-2 rounded-lg">Cancel</button><button id="restore-confirm-btn" class="bg-green-600 text-white font-bold px-6 py-2 rounded-lg">Confirm Restore</button></div></div></div>
    <!-- New Permanent Delete Modal -->
    <div id="permanent-delete-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4"><div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 text-center"><h2 class="text-xl font-bold mb-4 text-red-700">Confirm Permanent Deletion</h2><p class="mb-6">Are you absolutely sure? This action is irreversible and will permanently delete the product and all associated serial numbers.</p><input type="hidden" id="permanent-delete-id"><div class="flex justify-center gap-4"><button class="close-modal-btn bg-gray-300 px-6 py-2 rounded-lg">Cancel</button><button id="permanent-delete-confirm-btn" class="bg-red-700 text-white font-bold px-6 py-2 rounded-lg">Delete Permanently</button></div></div></div>


<script>
document.addEventListener('DOMContentLoaded', () => {
    const viewModal = document.getElementById('view-details-modal');
    const restoreModal = document.getElementById('restore-confirm-modal');
    const deleteModal = document.getElementById('permanent-delete-modal');
    const allModals = [viewModal, restoreModal, deleteModal];
    
    document.getElementById('search-box').addEventListener('input', e => {
        const searchTerm = e.target.value.toLowerCase();
        document.querySelectorAll('#product-table tbody tr').forEach(row => {
            if (row.querySelector('td[colspan]')) return; 
            row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
        });
    });

    const openModal = modalEl => modalEl.classList.add('is-open');
    const closeModal = modalEl => modalEl.classList.remove('is-open');
    allModals.forEach(modal => modal.querySelectorAll('.close-modal-btn').forEach(btn => btn.addEventListener('click', () => closeModal(modal))));

    document.getElementById('product-table').addEventListener('click', async e => {
        const row = e.target.closest('tr');
        if (!row || !row.dataset.id) return;
        const id = row.dataset.id;
        
        if (e.target.closest('.action-btn')) {
            e.stopPropagation(); // Stop click from bubbling to the row
            const button = e.target.closest('.action-btn');
            
            if (button.classList.contains('restore-btn')) {
                document.getElementById('restore-purchase-id').value = id;
                openModal(restoreModal);
            }
            if (button.classList.contains('permanent-delete-btn')) {
                document.getElementById('permanent-delete-id').value = id;
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
    
    document.getElementById('restore-confirm-btn').addEventListener('click', async () => {
        const id = document.getElementById('restore-purchase-id').value;
        const res = await fetch('?action=restore_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({purchase_id: id}) });
        const result = await res.json();
        if (result.status === 'success') {
            document.querySelector(`tr[data-id="${id}"]`).remove();
            closeModal(restoreModal);
        } else { alert('Error: ' + result.message); }
    });

    document.getElementById('permanent-delete-confirm-btn').addEventListener('click', async () => {
        const id = document.getElementById('permanent-delete-id').value;
        const res = await fetch('?action=permanent_delete_product', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({purchase_id: id}) });
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

