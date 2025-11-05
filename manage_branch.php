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

// --- START: ADMIN ROLE CHECK ---
$user_role = 0;
if(isset($_SESSION['user_role'])) {
    $user_role = (int)$_SESSION['user_role'];
} else {
    // Fallback: Check DB if session variable isn't set
    $sql_role_check = "SELECT role FROM users WHERE user_id = ?";
    $stmt_role_check = $conn->prepare($sql_role_check);
    $stmt_role_check->bind_param("i", $current_user_id);
    $stmt_role_check->execute();
    $result_role_check = $stmt_role_check->get_result();
    if($row_role = $result_role_check->fetch_assoc()) {
        $user_role = $row_role['role'];
        $_SESSION['user_role'] = $user_role; // Set it for next time
    }
    $stmt_role_check->close();
}

if ($user_role != 1) { // 1 = Admin role
    $_SESSION['error_message'] = "Access Denied: You do not have permission to manage branches.";
    header("Location: dashboard.php");
    exit();
}
// --- END: ADMIN ROLE CHECK ---

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get details for a single branch
    if ($_GET['action'] === 'get_branch_details' && isset($_GET['id'])) {
        $branch_id = intval($_GET['id']);
        $data = null;
        
        // Query 1: Get Branch Info
        $sql = "SELECT branch_id, Name, Address, Email, Phone FROM Branch WHERE branch_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $branch_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($data) {
            // Query 2: Get assigned users
            $sql_users = "SELECT user_name, email FROM users WHERE branch_id_fk = ? AND is_deleted = FALSE AND status = 0";
            $stmt_users = $conn->prepare($sql_users);
            $stmt_users->bind_param("i", $branch_id);
            $stmt_users->execute();
            $users_result = $stmt_users->get_result()->fetch_all(MYSQLI_ASSOC);
            $data['assigned_users'] = $users_result;
            $stmt_users->close();
            
            $response = ['status' => 'success', 'data' => $data];
        } else {
            $response['message'] = 'Branch not found.';
        }
    }

    // Action: Update branch details
    if ($_GET['action'] === 'update_branch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sql = "UPDATE Branch SET Name=?, Address=?, Email=?, Phone=?, is_updated=TRUE WHERE branch_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssi", 
            $data['Name'], $data['Address'], $data['Email'], 
            $data['Phone'], $data['branch_id']
        );
        if ($stmt->execute()) {
            $response = ['status' => 'success', 'message' => 'Branch details updated.'];
        } else {
            $response['message'] = 'Database update error: ' . $stmt->error;
        }
        $stmt->close();
    }
    
    // Action: Keep-alive ping
    if ($_GET['action'] === 'keep_alive') {
        $response = ['status' => 'success'];
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Fetch initial data for page load ---
header('Content-Type: text/html');
$branches_sql = "SELECT branch_id, Name, Address, Email, Phone FROM Branch WHERE is_deleted = FALSE ORDER BY Name";
$branches = $conn->query($branches_sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Branches</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } .modal.is-open { display: flex; }
        #branch-table tbody tr { cursor: pointer; } /* Add cursor for row click */
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Manage Branches</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>

        <!-- Search Bar -->
        <div class="mb-6">
            <input type="text" id="search-box" placeholder="Search branches by name, address, email, or phone..." class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>

        <!-- Global Messages Area -->
        <div id="global-message" class="mb-6"></div>

        <!-- Branch Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table id="branch-table" class="min-w-full">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Branch Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($branches as $branch): ?>
                        <tr class="hover:bg-gray-50 branch-row" data-id="<?php echo $branch['branch_id']; ?>">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900 branch-name"><?php echo htmlspecialchars($branch['Name']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600 branch-address truncate max-w-xs" title="<?php echo htmlspecialchars($branch['Address'] ?? ''); ?>"><?php echo htmlspecialchars($branch['Address'] ?? ''); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600 branch-email"><?php echo htmlspecialchars($branch['Email'] ?? ''); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600 branch-phone"><?php echo htmlspecialchars($branch['Phone'] ?? ''); ?></td>
                            <td class="px-6 py-4 text-center text-sm space-x-2">
                                <button class="action-btn edit-btn p-2 bg-blue-500 text-white rounded-full hover:bg-blue-600" title="Edit Branch" data-id="<?php echo $branch['branch_id']; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($branches)): ?>
                            <tr><td colspan="5" class="text-center py-10 text-gray-500">No branches found. <a href="add_branch.php" class="text-blue-500 hover:underline">Add one now</a>.</td></tr>
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
            <div class="p-4 border-b flex justify-between items-center"><h2 id="view-title" class="text-xl font-semibold">Branch Details</h2><button class="close-modal-btn text-2xl font-bold">&times;</button></div>
            <div id="view-modal-body" class="p-6 space-y-3 text-sm overflow-y-auto"></div>
            <div class="p-4 bg-gray-50 border-t text-right"><button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Close</button></div>
        </div>
    </div>
    
    <div id="edit-branch-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-xl flex flex-col">
            <div class="p-4 border-b flex justify-between items-center">
                 <h2 class="text-xl font-semibold">Edit Branch Details</h2>
                 <button class="close-modal-btn text-gray-500 text-2xl font-bold">&times;</button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[70vh]">
                <input type="hidden" id="edit-branch-id">
                <div><label class="block text-sm font-medium">Branch Name <span class="text-red-500">*</span></label><input type="text" id="edit-Name" required class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium">Phone</label><input type="tel" id="edit-Phone" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                    <div><label class="block text-sm font-medium">Email</label><input type="email" id="edit-Email" class="mt-1 w-full p-2 border-gray-300 rounded-md"></div>
                </div>
                <div><label class="block text-sm font-medium">Address</label><textarea id="edit-Address" rows="3" class="mt-1 w-full p-2 border-gray-300 rounded-md"></textarea></div>
            </div>
            <div class="p-4 bg-gray-50 border-t flex justify-end gap-4">
                <button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Cancel</button>
                <button id="edit-save-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg">Save Changes</button>
            </div>
        </div>
    </div>

<!-- Session Timeout Modal (standard) -->
<div id="session-timeout-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl p-6 md:p-8 w-11/12 max-w-md text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4"><svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg></div>
        <h3 class="text-2xl font-bold text-gray-800">Session Expiring Soon</h3>
        <p class="text-gray-600 mt-2">You will be logged out due to inactivity.</p>
        <p class="text-gray-500 text-sm mt-4">Redirecting in <span id="redirect-countdown" class="font-semibold">10</span> seconds...</p>
        <div class="mt-6"><button id="stay-logged-in-btn" class="w-full bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition">Stay Logged In</button></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const editModal = document.getElementById('edit-branch-modal');
    const viewModal = document.getElementById('view-details-modal'); // Added View Modal
    const allModals = [editModal, viewModal]; // Added View Modal
    
    // --- Live Search ---
    const searchBox = document.getElementById('search-box');
    const branchTableBody = document.querySelector('#branch-table tbody');
    const filterTable = () => {
        const searchTerm = searchBox.value.toLowerCase(); // <-- FIX: Get value from searchBox
        branchTableBody.querySelectorAll('tr').forEach(row => {
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
    
    // --- Function to open View Modal ---
    async function openViewModal(id) {
        try {
            const res = await fetch(`?action=get_branch_details&id=${id}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            
            const data = result.data;
            const body = document.getElementById('view-modal-body');
            document.getElementById('view-title').textContent = `Details for: ${data.Name}`;
            
            let usersHtml = '<p><strong>Assigned Users:</strong></p>';
            if (data.assigned_users && data.assigned_users.length > 0) {
                usersHtml += '<ul class="list-disc list-inside pl-4">';
                data.assigned_users.forEach(user => {
                    usersHtml += `<li>${user.user_name} (${user.email})</li>`;
                });
                usersHtml += '</ul>';
            } else {
                usersHtml += '<p class="text-gray-500 italic">No active users assigned to this branch.</p>';
            }

            body.innerHTML = `
                <p><strong>Branch Name:</strong> ${data.Name}</p>
                <p><strong>Phone:</strong> ${data.Phone || 'N/A'}</p>
                <p><strong>Email:</strong> ${data.Email || 'N/A'}</p>
                <p><strong>Address:</strong> ${data.Address || 'N/A'}</p>
                <hr class="my-3">
                ${usersHtml}
            `;
            openModal(viewModal);
        } catch (error) {
            showGlobalMessage('Error fetching details: ' + error.message, false);
        }
    }

    // --- Function to open Edit Modal ---
    async function openEditModal(id) {
        try {
            const res = await fetch(`?action=get_branch_details&id=${id}`);
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            
            const data = result.data;
            document.getElementById('edit-branch-id').value = data.branch_id;
            document.getElementById('edit-Name').value = data.Name;
            document.getElementById('edit-Address').value = data.Address;
            document.getElementById('edit-Email').value = data.Email;
            document.getElementById('edit-Phone').value = data.Phone;
            openModal(editModal);
        } catch (error) {
            showGlobalMessage('Error fetching branch details: ' + error.message, false);
        }
    }

    // --- Table Click Event Handler (Unified) ---
    branchTableBody.addEventListener('click', async e => {
        const row = e.target.closest('tr');
        if (!row || !row.dataset.id) return;
        const id = row.dataset.id;
        const actionButton = e.target.closest('.action-btn');
        
        if (actionButton) { // An action button was clicked
            e.stopPropagation(); // Stop row click
            if (actionButton.classList.contains('edit-btn')) {
                openEditModal(id);
            }
        } else {
            // No action button, just open view modal
            openViewModal(id);
        }
    });

    // --- Save Changes Button Listener ---
    document.getElementById('edit-save-btn').addEventListener('click', async () => {
        const id = document.getElementById('edit-branch-id').value;
        const payload = {
            branch_id: id,
            Name: document.getElementById('edit-Name').value,
            Address: document.getElementById('edit-Address').value,
            Email: document.getElementById('edit-Email').value,
            Phone: document.getElementById('edit-Phone').value
        };

        if (!payload.Name) {
            alert("Branch Name is required."); return;
        }

        try {
            const res = await fetch(`?action=update_branch&id=${id}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
            const result = await res.json();
            if (result.status !== 'success') throw new Error(result.message);
            
            closeModal(editModal);
            showGlobalMessage('Branch updated successfully!');
            
            // Update table row dynamically
            const row = document.querySelector(`tr[data-id="${payload.branch_id}"]`); // <-- FIX: Use payload.branch_id
            if (row) {
                row.querySelector('.branch-name').textContent = payload.Name;
                row.querySelector('.branch-address').textContent = payload.Address;
                row.querySelector('.branch-email').textContent = payload.Email;
                row.querySelector('.branch-phone').textContent = payload.Phone;
            }
            filterTable(); // Re-apply search
        } catch (error) {
            alert('Error updating: ' + error.message);
        }
    });

    // --- Session Timeout Logic ---
    (function() {
        const sessionModal = document.getElementById('session-timeout-modal');
        if (!sessionModal) return; 

        const stayLoggedInBtn = document.getElementById('stay-logged-in-btn');
        const countdownElement = document.getElementById('redirect-countdown');
        const idleTimeout = <?php echo $idleTimeout; ?> * 1000;
        const redirectDelay = 10000;
        let timeoutId, countdownInterval;

        function showTimeoutModal() {
            sessionModal.classList.add('is-open');
            let countdown = redirectDelay / 1000;
            countdownElement.textContent = countdown;
            countdownInterval = setInterval(() => {
                countdown--;
                countdownElement.textContent = countdown;
                if (countdown <= 0) { clearInterval(countdownInterval); window.location.href = 'logout.php?reason=idle'; }
            }, 1000);
        }
        function startTimer() { clearTimeout(timeoutId); timeoutId = setTimeout(showTimeoutModal, idleTimeout - redirectDelay); }
        
        stayLoggedInBtn.addEventListener('click', async () => {
            clearInterval(countdownInterval);
            sessionModal.classList.remove('is-open');
            try {
                // Ping the server to keep session alive
                await fetch('manage_branch.php?action=keep_alive');
                startTimer();
            } catch (error) { window.location.href = 'logout.php?reason=idle'; }
        });
        startTimer();
    })();
});
</script>

</body>
</html>