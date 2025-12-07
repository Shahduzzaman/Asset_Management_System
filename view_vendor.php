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
$user_role = isset($_SESSION['user_role']) ? (int)$_SESSION['user_role'] : 0; // Get user role
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get details for a single vendor
    if ($_GET['action'] === 'get_vendor_details' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $sql = "SELECT * FROM vendors WHERE vendor_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($data) $response = ['status' => 'success', 'data' => $data];
        else $response['message'] = 'Vendor not found.';
    }

    // Action: Update a vendor (Admin Only)
    if ($_GET['action'] === 'update_vendor' && $_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 1) {
        $data = json_decode(file_get_contents('php://input'), true);
        try {
            $sql = "UPDATE vendors SET vendor_name=?, contact_person=?, address=?, phone=?, email=?, is_updated=TRUE WHERE vendor_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", 
                $data['vendor_name'], $data['contact_person'], $data['address'], 
                $data['phone'], $data['email'], $data['vendor_id']
            );
            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Vendor updated successfully.'];
            } else {
                $response['message'] = 'Database error: ' . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $response['message'] = 'Error: ' . $e->getMessage();
        }
    }
    
    // Action: Soft delete a vendor (Admin Only)
    if ($_GET['action'] === 'delete_vendor' && $_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 1) {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = intval($data['vendor_id']);
        $sql = "UPDATE vendors SET is_deleted = TRUE WHERE vendor_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $response = ['status' => 'success', 'message' => 'Vendor deleted.'];
        } else {
            $response['message'] = 'Database error: ' . $stmt->error;
        }
        $stmt->close();
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Fetch initial data for page load ---
header('Content-Type: text/html');
$vendors_sql = "SELECT * FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name";
$vendors = $conn->query($vendors_sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Vendors</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } .modal.is-open { display: flex; }
        #vendor-table tbody tr { cursor: pointer; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Vendor List</h1>
        </div>
        
        <!-- Search Bar -->
        <div class="mb-6">
            <input type="text" id="search-box" placeholder="Search vendors by name, contact, email, or phone..." class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>

        <!-- Global Messages Area -->
        <div id="global-message" class="mb-6"></div>

        <!-- Vendor Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table id="vendor-table" class="min-w-full">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendor Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact Person</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                            <?php if ($user_role === 1): // Admin Only Actions ?>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($vendors)): ?>
                            <tr><td colspan="<?php echo $user_role === 1 ? '6' : '5'; ?>" class="text-center py-10 text-gray-500">No vendors found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vendors as $vendor): ?>
                            <tr class="hover:bg-gray-50 vendor-row" data-id="<?php echo $vendor['vendor_id']; ?>">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900 vendor-name"><?php echo htmlspecialchars($vendor['vendor_name']); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600 contact-person"><?php echo htmlspecialchars($vendor['contact_person'] ?? ''); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600 phone"><?php echo htmlspecialchars($vendor['phone'] ?? ''); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600 email"><?php echo htmlspecialchars($vendor['email'] ?? ''); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600 address truncate max-w-sm" title="<?php echo htmlspecialchars($vendor['address'] ?? ''); ?>"><?php echo htmlspecialchars($vendor['address'] ?? ''); ?></td>
                                <?php if ($user_role === 1): // Admin Only Actions ?>
                                <td class="px-6 py-4 text-center text-sm space-x-2">
                                    <button class="action-btn edit-btn p-2 bg-blue-500 text-white rounded-full hover:bg-blue-600" title="Edit" data-id="<?php echo $vendor['vendor_id']; ?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg></button>
                                    <button class="action-btn delete-btn p-2 bg-red-500 text-white rounded-full hover:bg-red-600" title="Delete" data-id="<?php echo $vendor['vendor_id']; ?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- View Details Modal -->
    <div id="view-details-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg flex flex-col">
            <div class="p-4 border-b flex justify-between items-center"><h2 id="view-title" class="text-xl font-semibold">Vendor Details</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div>
            <div id="view-modal-body" class="p-6 space-y-3 text-sm overflow-y-auto"></div>
            <div class="p-4 bg-gray-50 border-t text-right"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Close</button></div>
        </div>
    </div>
    
    <!-- Edit Modal (Admin Only) -->
    <?php if ($user_role === 1): ?>
    <div id="edit-vendor-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-xl flex flex-col">
            <div class="p-4 border-b flex justify-between items-center"><h2 id="edit-title" class="text-xl font-semibold">Edit Vendor</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div>
            <input type="hidden" id="edit-vendor-id">

            <form id="edit-vendor-form" class="p-6 space-y-4 overflow-y-auto max-h-[70vh]">
                <div><label class="block text-sm">Vendor Name <span class="text-red-500">*</span></label><input type="text" id="edit-vendor-name" required class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div><label class="block text-sm">Contact Person</label><input type="text" id="edit-contact-person" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm">Phone</label><input type="tel" id="edit-phone" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                    <div><label class="block text-sm">Email</label><input type="email" id="edit-email" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                </div>
                <div><label class="block text-sm">Address</label><textarea id="edit-address" rows="3" class="mt-1 w-full p-2 border-gray-300 rounded-md"></textarea></div>
            </form>

            <div class="p-4 bg-gray-50 border-t flex justify-end gap-4"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Cancel</button><button id="edit-save-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg">Save Changes</button></div>
        </div>
    </div>

    <!-- Delete Modal (Admin Only) -->
    <div id="delete-confirm-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 text-center">
            <h2 class="text-xl font-bold mb-4">Confirm Deletion</h2>
            <p class="mb-6">Are you sure you want to delete this vendor?</p>
            <input type="hidden" id="delete-vendor-id">
            <div class="flex justify-center gap-4">
                <button class="close-modal-btn bg-gray-300 px-6 py-2 rounded-lg">Cancel</button>
                <button id="delete-confirm-btn" class="bg-red-600 text-white font-bold px-6 py-2 rounded-lg">Confirm Delete</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const viewModal = document.getElementById('view-details-modal');
    const allModals = [viewModal];
    
    // --- Admin-only variables ---
    const isAdmin = <?php echo $user_role === 1 ? 'true' : 'false'; ?>;
    let editModal, deleteModal;
    if (isAdmin) {
        editModal = document.getElementById('edit-vendor-modal');
        deleteModal = document.getElementById('delete-confirm-modal');
        allModals.push(editModal, deleteModal);
    }
    
    // --- Live Search ---
    const searchBox = document.getElementById('search-box');
    const vendorTableBody = document.querySelector('#vendor-table tbody');
    const filterTable = () => {
        const searchTerm = searchBox.value.toLowerCase();
        vendorTableBody.querySelectorAll('tr').forEach(row => {
            if (row.querySelector('td[colspan]')) return;
            const isMatch = row.textContent.toLowerCase().includes(searchTerm);
            row.style.display = isMatch ? '' : 'none';
        });
    };
    searchBox.addEventListener('input', filterTable);

    // --- Modal Controls ---
    const openModal = modalEl => modalEl.classList.add('is-open');
    const closeModal = modalEl => modalEl.classList.remove('is-open');
    allModals.forEach(modal => modal.querySelectorAll('.close-modal-btn').forEach(btn => btn.addEventListener('click', () => closeModal(modal))));

    // --- Global Message Function ---
    function showGlobalMessage(message, isSuccess = true) {
        const messageDiv = document.getElementById('global-message');
        const alertClass = isSuccess ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700';
        messageDiv.innerHTML = `<div class="${alertClass} px-4 py-3 rounded-lg"><span>${message}</span></div>`;
        setTimeout(() => messageDiv.innerHTML = '', 5000);
    }
    
    // --- Function to open View Modal (Global Scope) ---
    async function openViewModal(id) {
        try {
            const res = await fetch(`?action=get_vendor_details&id=${id}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            
            const data = result.data;
            const body = document.getElementById('view-modal-body');
            document.getElementById('view-title').textContent = `Details for: ${data.vendor_name}`;
            body.innerHTML = `
                <p><strong>Vendor Name:</strong> ${data.vendor_name}</p>
                <p><strong>Contact Person:</strong> ${data.contact_person || 'N/A'}</p>
                <p><strong>Phone:</strong> ${data.phone || 'N/A'}</p>
                <p><strong>Email:</strong> ${data.email || 'N/A'}</p>
                <p><strong>Address:</strong> ${data.address || 'N/A'}</p>
                <p><strong>Created At:</strong> ${new Date(data.created_at).toLocaleString()}</p>`;
            openModal(viewModal);
        } catch (error) {
            showGlobalMessage('Error fetching details: ' + error.message, false);
        }
    }

    // --- Admin-Only Function Definitions ---
    async function openEditModal(id) {
        if (!isAdmin) return;
        try {
            const res = await fetch(`?action=get_vendor_details&id=${id}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            
            const data = result.data;
            document.getElementById('edit-vendor-id').value = id;
            document.getElementById('edit-vendor-name').value = data.vendor_name;
            document.getElementById('edit-contact-person').value = data.contact_person;
            document.getElementById('edit-phone').value = data.phone;
            document.getElementById('edit-email').value = data.email;
            document.getElementById('edit-address').value = data.address;
            
            openModal(editModal);
        } catch (error) {
            showGlobalMessage('Error fetching details: ' + error.message, false);
        }
    }

    // --- Table Click Event Handler (Unified) ---
    vendorTableBody.addEventListener('click', async e => {
        const row = e.target.closest('tr');
        if (!row || !row.dataset.id) return;
        const id = row.dataset.id;
        const actionButton = e.target.closest('.action-btn');
        
        if (actionButton) { // An action button was clicked
            e.stopPropagation(); // Stop row click
            if (!isAdmin) return; // Ignore if not admin
            
            if (actionButton.classList.contains('edit-btn')) {
                openEditModal(id);
            } else if (actionButton.classList.contains('delete-btn')) {
                document.getElementById('delete-vendor-id').value = id;
                openModal(deleteModal);
            }
        } else {
            // No action button, just open view modal
            openViewModal(id);
        }
    });

    // --- Admin-Only Listeners (Must be inside if(isAdmin) check) ---
    if (isAdmin) {
        // Save changes button
        document.getElementById('edit-save-btn').addEventListener('click', async () => {
            const id = document.getElementById('edit-vendor-id').value;
            const payload = {
                vendor_id: id,
                vendor_name: document.getElementById('edit-vendor-name').value,
                contact_person: document.getElementById('edit-contact-person').value,
                phone: document.getElementById('edit-phone').value,
                email: document.getElementById('edit-email').value,
                address: document.getElementById('edit-address').value
            };
            
            if (!payload.vendor_name) {
                alert("Vendor Name is required."); return;
            }
            
            try {
                const res = await fetch(`?action=update_vendor&id=${id}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message);
                
                closeModal(editModal);
                showGlobalMessage('Vendor updated successfully!');
                
                // Update table row
                const row = document.querySelector(`tr[data-id="${id}"]`);
                if (row) {
                    row.querySelector('.vendor-name').textContent = payload.vendor_name;
                    row.querySelector('.contact-person').textContent = payload.contact_person;
                    row.querySelector('.phone').textContent = payload.phone;
                    row.querySelector('.email').textContent = payload.email;
                    row.querySelector('.address').textContent = payload.address;
                }
                filterTable(); // Re-apply search
            } catch (error) {
                alert('Error updating: ' + error.message);
            }
        });

        // Delete confirm button
        document.getElementById('delete-confirm-btn').addEventListener('click', async () => {
            const id = document.getElementById('delete-vendor-id').value;
            
            try {
                const res = await fetch('?action=delete_vendor', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ vendor_id: id }) });
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message);
                
                document.querySelector(`tr[data-id="${id}"]`).remove();
                closeModal(deleteModal);
                showGlobalMessage('Vendor deleted successfully.');
            } catch (error) {
                alert('Error deleting: ' + error.message);
            }
        });
    }

});
</script>

</body>
</html>
