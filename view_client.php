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

    // Action: Get details for a single client (Head or Branch)
    if ($_GET['action'] === 'get_client_details' && isset($_GET['id']) && isset($_GET['type'])) {
        $id = intval($_GET['id']);
        $type = $_GET['type'];
        
        if ($type === 'head') {
            $sql = "SELECT *, 'Head Office' as type_name FROM Client_Head WHERE client_head_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
        } else { // 'branch'
            $sql = "SELECT cb.*, ch.Company_Name as parent_company_name, 'Branch Office' as type_name 
                    FROM Client_Branch cb 
                    JOIN Client_Head ch ON cb.client_head_id_fk = ch.client_head_id 
                    WHERE cb.client_branch_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
        }
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($data) $response = ['status' => 'success', 'data' => $data];
        else $response['message'] = 'Client not found.';
    }

    // Action: Update a client (Admin Only)
    if ($_GET['action'] === 'update_client' && $_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 1) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            if ($data['type'] === 'head') {
                $sql = "UPDATE Client_Head SET Company_Name=?, Department=?, Contact_Person=?, Contact_Number=?, Address=?, is_updated=TRUE WHERE client_head_id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssi", $data['Company_Name'], $data['Department'], $data['Contact_Person'], $data['Contact_Number'], $data['Address'], $data['id']);
            } else { // 'branch'
                $sql = "UPDATE Client_Branch SET client_head_id_fk=?, Branch_Name=?, Contact_Person1=?, Contact_Number1=?, Contact_Person2=?, Contact_Number2=?, Zone=?, Address=?, is_updated=TRUE WHERE client_branch_id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssssssi", $data['client_head_id_fk'], $data['Branch_Name'], $data['Contact_Person1'], $data['Contact_Number1'], $data['Contact_Person2'], $data['Contact_Number2'], $data['Zone'], $data['Address'], $data['id']);
            }
            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Client updated successfully.'];
            } else {
                $response['message'] = 'Database error: ' . $stmt->error;
            }
            $stmt->close();
        } catch (Exception $e) {
            $response['message'] = 'Error: ' . $e->getMessage();
        }
    }
    
    // Action: Soft delete a client (Admin Only)
    if ($_GET['action'] === 'delete_client' && $_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 1) {
        $data = json_decode(file_get_contents('php://input'), true);
        $id = intval($data['id']);
        $type = $data['type'];
        
        if ($type === 'head') {
            $sql = "UPDATE Client_Head SET is_deleted = TRUE WHERE client_head_id = ?";
        } else { // 'branch'
            $sql = "UPDATE Client_Branch SET is_deleted = TRUE WHERE client_branch_id = ?";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $response = ['status' => 'success', 'message' => 'Client deleted.'];
        } else {
            $response['message'] = 'Database error: ' . $stmt->error;
        }
        $stmt->close();
    }
    
    // Action: Search Head Office (for Edit Branch modal)
    if ($_GET['action'] === 'search_head_office' && isset($_GET['query'])) {
        $query = trim($_GET['query']) . '%';
        $sql = "SELECT client_head_id, Company_Name, Contact_Person FROM Client_Head WHERE Company_Name LIKE ? AND is_deleted = FALSE LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $query);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $response = ['status' => 'success', 'data' => $data];
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Fetch initial data for page load ---
header('Content-Type: text/html');

// Use UNION ALL to combine Head and Branch offices into one list
$client_list_sql = "
    (SELECT 
        client_head_id as id, 
        Company_Name as client_name, 
        'Head Office' as client_type, 
        NULL as parent_company_name,
        Department as department_or_zone,
        Address,
        'head' as record_type
    FROM Client_Head 
    WHERE is_deleted = FALSE)
    UNION ALL
    (SELECT 
        cb.client_branch_id as id, 
        cb.Branch_Name as client_name, 
        'Branch Office' as client_type, 
        ch.Company_Name as parent_company_name,
        ch.Department as department_or_zone, /* <-- MODIFIED: Show parent's department */
        cb.Address,
        'branch' as record_type
    FROM Client_Branch cb
    JOIN Client_Head ch ON cb.client_head_id_fk = ch.client_head_id
    WHERE cb.is_deleted = FALSE AND ch.is_deleted = FALSE)
    ORDER BY client_name
";
$clients = $conn->query($client_list_sql)->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Clients</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } .modal.is-open { display: flex; }
        #client-table tbody tr { cursor: pointer; }
        #edit-search-results div:hover { background-color: #f3f4f6; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Client List</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>
        
        <!-- Search Bar -->
        <div class="mb-6">
            <input type="text" id="search-box" placeholder="Search clients by name, type, parent, or contact..." class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>

        <!-- Global Messages Area -->
        <div id="global-message" class="mb-6"></div>

        <!-- Client Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table id="client-table" class="min-w-full">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Parent Company</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Department</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                            <?php if ($user_role === 1): // Admin Only Actions ?>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($clients)): ?>
                            <tr><td colspan="<?php echo $user_role === 1 ? '6' : '5'; ?>" class="text-center py-10 text-gray-500">No clients found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): ?>
                            <tr class="hover:bg-gray-50 client-row" data-id="<?php echo $client['id']; ?>" data-type="<?php echo $client['record_type']; ?>">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900 client-name"><?php echo htmlspecialchars($client['client_name']); ?></td>
                                <td class="px-6 py-4 text-sm client-type"><?php echo $client['client_type'] === 'Head Office' ? '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Head Office</span>' : '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Branch</span>'; ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600 parent-name"><?php echo htmlspecialchars($client['parent_company_name'] ?? '---'); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600 department-zone"><?php echo htmlspecialchars($client['department_or_zone'] ?? '---'); ?></td>
                                <td class="px-6 py-4 text-sm text-gray-600 address truncate max-w-sm" title="<?php echo htmlspecialchars($client['Address'] ?? ''); ?>"><?php echo htmlspecialchars($client['Address'] ?? ''); ?></td>
                                <?php if ($user_role === 1): // Admin Only Actions ?>
                                <td class="px-6 py-4 text-center text-sm space-x-2">
                                    <button class="action-btn edit-btn p-2 bg-blue-500 text-white rounded-full hover:bg-blue-600" title="Edit" data-id="<?php echo $client['id']; ?>" data-type="<?php echo $client['record_type']; ?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg></button>
                                    <button class="action-btn delete-btn p-2 bg-red-500 text-white rounded-full hover:bg-red-600" title="Delete" data-id="<?php echo $client['id']; ?>" data-type="<?php echo $client['record_type']; ?>"><svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
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
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col">
            <div class="p-4 border-b flex justify-between items-center"><h2 id="view-title" class="text-xl font-semibold">Client Details</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div>
            <div id="view-modal-body" class="p-6 space-y-4 text-sm overflow-y-auto"></div>
            <div class="p-4 bg-gray-50 border-t text-right"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Close</button></div>
        </div>
    </div>
    
    <!-- Edit Modal (Admin Only) -->
    <?php if ($user_role === 1): ?>
    <div id="edit-client-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl flex flex-col">
            <div class="p-4 border-b flex justify-between items-center"><h2 id="edit-title" class="text-xl font-semibold">Edit Client</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div>
            <input type="hidden" id="edit-client-id">
            <input type="hidden" id="edit-client-type">

            <!-- Edit Head Office Form -->
            <form id="edit-head-form" class="hidden p-6 space-y-4 overflow-y-auto max-h-[70vh]">
                <div><label class="block text-sm">Company Name <span class="text-red-500">*</span></label><input type="text" id="edit-Company_Name" required class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm">Department</label><input type="text" id="edit-Department" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                    <div><label class="block text-sm">Contact Person</label><input type="text" id="edit-Contact_Person" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                </div>
                <div><label class="block text-sm">Contact Number</label><input type="tel" id="edit-Contact_Number" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div><label class="block text-sm">Address</label><textarea id="edit-Address-Head" rows="3" class="mt-1 w-full p-2 border-gray-300 rounded-md"></textarea></div>
            </form>
            
            <!-- Edit Branch Office Form -->
            <form id="edit-branch-form" class="hidden p-6 space-y-4 overflow-y-auto max-h-[70vh]">
                <div>
                    <label class="block text-sm">Head Office <span class="text-red-500">*</span></label>
                    <div class="relative"><input type="text" id="edit-head-office-search" required class="w-full p-2 border-gray-300 rounded-md" autocomplete="off"><button type="button" id="edit-clear-search-btn" class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600 hidden">&times;</button></div>
                    <div id="edit-search-results" class="border border-gray-300 rounded-b-lg -mt-1 bg-white max-h-40 overflow-y-auto hidden"></div>
                    <input type="hidden" id="edit-client_head_id_fk">
                </div>
                <div><label class="block text-sm">Branch Name <span class="text-red-500">*</span></label><input type="text" id="edit-Branch_Name" required class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm">Contact Person 1</label><input type="text" id="edit-Contact_Person1" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                    <div><label class="block text-sm">Contact Number 1</label><input type="tel" id="edit-Contact_Number1" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm">Contact Person 2</label><input type="text" id="edit-Contact_Person2" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                    <div><label class="block text-sm">Contact Number 2</label><input type="tel" id="edit-Contact_Number2" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                </div>
                <div><label class="block text-sm">Zone</label><input type="text" id="edit-Zone" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div><label class="block text-sm">Address</label><textarea id="edit-Address-Branch" rows="3" class="mt-1 w-full p-2 border-gray-300 rounded-md"></textarea></div>
            </form>

            <div class="p-4 bg-gray-50 border-t flex justify-end gap-4"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Cancel</button><button id="edit-save-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg">Save Changes</button></div>
        </div>
    </div>

    <!-- Delete Modal (Admin Only) -->
    <div id="delete-confirm-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm p-6 text-center">
            <h2 class="text-xl font-bold mb-4">Confirm Deletion</h2>
            <p class="mb-6">Are you sure you want to delete this client? This may also hide associated branches.</p>
            <input type="hidden" id="delete-client-id">
            <input type="hidden" id="delete-client-type">
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
    let editModal, deleteModal, editSearchBox, editResultsBox, editHiddenInput, editClearBtn;
    if (isAdmin) {
        editModal = document.getElementById('edit-client-modal');
        deleteModal = document.getElementById('delete-confirm-modal');
        allModals.push(editModal, deleteModal);
        
        editSearchBox = document.getElementById('edit-head-office-search');
        editResultsBox = document.getElementById('edit-search-results');
        editHiddenInput = document.getElementById('edit-client_head_id_fk');
        editClearBtn = document.getElementById('edit-clear-search-btn');
    }
    
    // --- Live Search ---
    const searchBox = document.getElementById('search-box');
    const clientTableBody = document.querySelector('#client-table tbody');
    const filterTable = () => {
        const searchTerm = searchBox.value.toLowerCase();
        clientTableBody.querySelectorAll('tr').forEach(row => {
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
    async function openViewModal(id, type) {
        try {
            const res = await fetch(`?action=get_client_details&id=${id}&type=${type}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            
            const data = result.data;
            const body = document.getElementById('view-modal-body');
            document.getElementById('view-title').textContent = `Details for: ${data.Company_Name || data.Branch_Name}`;
            
            if (type === 'head') {
                body.innerHTML = `
                    <p><strong>Client Type:</strong> <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">${data.type_name}</span></p>
                    <p><strong>Company Name:</strong> ${data.Company_Name}</p>
                    <p><strong>Department:</strong> ${data.Department || 'N/A'}</p>
                    <p><strong>Contact Person:</strong> ${data.Contact_Person || 'N/A'}</p>
                    <p><strong>Contact Number:</strong> ${data.Contact_Number || 'N/A'}</p>
                    <p><strong>Address:</strong> ${data.Address || 'N/A'}</p>`;
            } else { // branch
                body.innerHTML = `
                    <p><strong>Client Type:</strong> <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800">${data.type_name}</span></p>
                    <p><strong>Branch Name:</strong> ${data.Branch_Name}</p>
                    <p><strong>Head Office:</strong> ${data.parent_company_name}</p>
                    <p><strong>Zone:</strong> ${data.Zone || 'N/A'}</p>
                    <p><strong>Contact Person 1:</strong> ${data.Contact_Person1 || 'N/A'}</p>
                    <p><strong>Contact Number 1:</strong> ${data.Contact_Number1 || 'N/A'}</p>
                    <p><strong>Contact Person 2:</strong> ${data.Contact_Person2 || 'N/A'}</p>
                    <p><strong>Contact Number 2:</strong> ${data.Contact_Number2 || 'N/A'}</p>
                    <p><strong>Address:</strong> ${data.Address || 'N/A'}</p>`;
            }
            openModal(viewModal);
        } catch (error) {
            showGlobalMessage('Error fetching details: ' + error.message, false);
        }
    }

    // --- Admin-Only Function Definitions ---
    async function openEditModal(id, type) {
        if (!isAdmin) return;
        try {
            const res = await fetch(`?action=get_client_details&id=${id}&type=${type}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            
            const data = result.data;
            document.getElementById('edit-client-id').value = id;
            document.getElementById('edit-client-type').value = type;
            
            const headForm = document.getElementById('edit-head-form');
            const branchForm = document.getElementById('edit-branch-form');

            if (type === 'head') {
                document.getElementById('edit-title').textContent = "Edit Head Office";
                headForm.classList.remove('hidden');
                branchForm.classList.add('hidden');
                document.getElementById('edit-Company_Name').value = data.Company_Name;
                document.getElementById('edit-Department').value = data.Department;
                document.getElementById('edit-Contact_Person').value = data.Contact_Person;
                document.getElementById('edit-Contact_Number').value = data.Contact_Number;
                document.getElementById('edit-Address-Head').value = data.Address;
            } else { // branch
                document.getElementById('edit-title').textContent = "Edit Branch Office";
                headForm.classList.add('hidden');
                branchForm.classList.remove('hidden');
                
                editHiddenInput.value = data.client_head_id_fk;
                editSearchBox.value = data.parent_company_name;
                editSearchBox.readOnly = true;
                editClearBtn.classList.remove('hidden');
                
                document.getElementById('edit-Branch_Name').value = data.Branch_Name;
                document.getElementById('edit-Contact_Person1').value = data.Contact_Person1;
                document.getElementById('edit-Contact_Number1').value = data.Contact_Number1;
                document.getElementById('edit-Contact_Person2').value = data.Contact_Person2;
                document.getElementById('edit-Contact_Number2').value = data.Contact_Number2;
                document.getElementById('edit-Zone').value = data.Zone;
                document.getElementById('edit-Address-Branch').value = data.Address;
            }
            openModal(editModal);
        } catch (error) {
            showGlobalMessage('Error fetching details: ' + error.message, false);
        }
    }

    // --- Table Click Event Handler (Unified) ---
    clientTableBody.addEventListener('click', async e => {
        const row = e.target.closest('tr');
        if (!row || !row.dataset.id) return;
        const id = row.dataset.id;
        const type = row.dataset.type;

        const actionButton = e.target.closest('.action-btn');
        
        if (actionButton) { // An action button was clicked
            e.stopPropagation(); // Stop row click
            if (!isAdmin) return; // Ignore if not admin
            
            if (actionButton.classList.contains('edit-btn')) {
                openEditModal(id, type);
            } else if (actionButton.classList.contains('delete-btn')) {
                document.getElementById('delete-client-id').value = id;
                document.getElementById('delete-client-type').value = type;
                openModal(deleteModal);
            }
        } else {
            // No action button, just open view modal
            openViewModal(id, type);
        }
    });

    // --- Admin-Only Listeners (Must be inside if(isAdmin) check) ---
    if (isAdmin) {
        // Search logic for edit modal
        let editSearchTimeout;
        editSearchBox.addEventListener('input', () => {
            const query = editSearchBox.value.trim();
            editResultsBox.innerHTML = '';
            if (query.length < 1) { editResultsBox.classList.add('hidden'); return; }

            clearTimeout(editSearchTimeout);
            editSearchTimeout = setTimeout(async () => {
                const res = await fetch(`?action=search_head_office&query=${encodeURIComponent(query)}`);
                const result = await res.json();
                if (result.status === 'success' && result.data.length > 0) {
                    result.data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'p-3 border-t cursor-pointer';
                        div.innerHTML = `<strong class="block">${item.Company_Name}</strong>`;
                        div.addEventListener('click', () => {
                            editHiddenInput.value = item.client_head_id;
                            editSearchBox.value = item.Company_Name;
                            editSearchBox.readOnly = true;
                            editResultsBox.classList.add('hidden');
                            editClearBtn.classList.remove('hidden');
                        });
                        editResultsBox.appendChild(div);
                    });
                    editResultsBox.classList.remove('hidden');
                } else {
                    editResultsBox.innerHTML = `<div class="p-3 text-gray-500">No results found.</div>`;
                    editResultsBox.classList.remove('hidden');
                }
            }, 300);
        });
        
        editClearBtn.addEventListener('click', () => {
            editHiddenInput.value = ''; editSearchBox.value = '';
            editSearchBox.readOnly = false; editClearBtn.classList.add('hidden');
            editResultsBox.classList.add('hidden'); editSearchBox.focus();
        });

        // Save changes button
        document.getElementById('edit-save-btn').addEventListener('click', async () => {
            const id = document.getElementById('edit-client-id').value;
            const type = document.getElementById('edit-client-type').value;
            let payload = { id, type };

            if (type === 'head') {
                payload = { ...payload,
                    Company_Name: document.getElementById('edit-Company_Name').value,
                    Department: document.getElementById('edit-Department').value,
                    Contact_Person: document.getElementById('edit-Contact_Person').value,
                    Contact_Number: document.getElementById('edit-Contact_Number').value,
                    Address: document.getElementById('edit-Address-Head').value
                };
            } else { // branch
                payload = { ...payload,
                    client_head_id_fk: document.getElementById('edit-client_head_id_fk').value,
                    Branch_Name: document.getElementById('edit-Branch_Name').value,
                    Contact_Person1: document.getElementById('edit-Contact_Person1').value,
                    Contact_Number1: document.getElementById('edit-Contact_Number1').value,
                    Contact_Person2: document.getElementById('edit-Contact_Person2').value,
                    Contact_Number2: document.getElementById('edit-Contact_Number2').value,
                    Zone: document.getElementById('edit-Zone').value,
                    Address: document.getElementById('edit-Address-Branch').value
                };
            }
            
            try {
                const res = await fetch(`?action=update_client&type=${type}&id=${id}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message);
                
                closeModal(editModal);
                showGlobalMessage('Client updated successfully!');
                
                // Update table row
                const row = document.querySelector(`tr[data-id="${id}"][data-type="${type}"]`);
                if (row) {
                    if (type === 'head') {
                        row.querySelector('.client-name').textContent = payload.Company_Name;
                        row.querySelector('.department-zone').textContent = payload.Department; 
                        row.querySelector('.address').textContent = payload.Address;
                    } else { // branch
                        row.querySelector('.client-name').textContent = payload.Branch_Name;
                        row.querySelector('.parent-name').textContent = document.getElementById('edit-head-office-search').value;
                        // *** FIX: Update department/zone with the parent's department ***
                        // We need to fetch this or just reload. Reloading is safer.
                        // Let's just update what we know for sure.
                        row.querySelector('.department-zone').textContent = payload.Zone; // This is branch zone, not parent dept.
                        // Let's reload the page to get the correct parent department.
                        window.location.reload(); // Safer way to ensure data consistency
                        row.querySelector('.address').textContent = payload.Address;
                    }
                }
                filterTable(); // Re-apply search
            } catch (error) {
                alert('Error updating: ' + error.message);
            }
        });

        // Delete confirm button
        document.getElementById('delete-confirm-btn').addEventListener('click', async () => {
            const id = document.getElementById('delete-client-id').value;
            const type = document.getElementById('delete-client-type').value;
            
            try {
                const res = await fetch('?action=delete_client', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ id, type }) });
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message);
                
                document.querySelector(`tr[data-id="${id}"][data-type="${type}"]`).remove();
                closeModal(deleteModal);
                showGlobalMessage('Client deleted successfully.');
            } catch (error) {
                alert('Error deleting: ' + error.message);
            }
        });
    }

});
</script>

</body>
</html>

