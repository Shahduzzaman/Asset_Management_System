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
$sql_role_check = "SELECT role FROM users WHERE user_id = ?";
$stmt_role_check = $conn->prepare($sql_role_check);
$stmt_role_check->bind_param("i", $current_user_id);
$stmt_role_check->execute();
$result_role_check = $stmt_role_check->get_result();
if($row_role = $result_role_check->fetch_assoc()) {
    $user_role = $row_role['role'];
}
$stmt_role_check->close();
if ($user_role != 1) { // 1 = Admin role
    $_SESSION['error_message'] = "Access Denied: You do not have permission to manage users.";
    header("Location: dashboard.php");
    exit();
}
// --- END: ADMIN ROLE CHECK ---

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get details for a single user
    if ($_GET['action'] === 'get_user_details' && isset($_GET['id'])) {
        $user_id_to_edit = intval($_GET['id']);
        $sql = "SELECT user_id, user_name, email, phone, role, status FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id_to_edit);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        if ($data) {
            $response = ['status' => 'success', 'data' => $data];
        } else {
            $response['message'] = 'User not found.';
        }
    }

    // Action: Update user details
    if ($_GET['action'] === 'update_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $user_id_to_update = intval($data['user_id']);
        $email = trim($data['email']);

        // Basic Server-side email validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'Invalid email format provided.';
        }
        // Reinstate check: Prevent admin from disabling or changing role of themselves
        elseif ($user_id_to_update === $current_user_id && ($data['status'] == 1 || $data['role'] == 0)) {
             $response['message'] = 'Error: An Admin cannot disable their own account or change their own role to User.';
        } else {
            $sql = "UPDATE users SET user_name=?, email=?, phone=?, role=?, status=?, is_updated=TRUE WHERE user_id=?";
            $stmt = $conn->prepare($sql);
            // Added email (s) to bind_param, changed ssiii to sssiii
            $stmt->bind_param("sssiii", $data['user_name'], $email, $data['phone'], $data['role'], $data['status'], $user_id_to_update);
            try {
                if ($stmt->execute()) {
                    $response = ['status' => 'success', 'message' => 'User details updated.'];
                } else {
                    $response['message'] = 'Database update error: ' . $stmt->error;
                }
            } catch (mysqli_sql_exception $e) {
                 // Catch potential duplicate email errors
                if ($e->getCode() == 1062) { // 1062 is MySQL code for duplicate entry
                     $response['message'] = 'Error: This email address is already in use by another account.';
                } else {
                    $response['message'] = 'Database update error: ' . $e->getMessage();
                }
            }
        }
    }

    // Action: Reset user password
    if ($_GET['action'] === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $user_id_to_reset = intval($data['user_id']);
        $new_password = $data['new_password'];
        
        if (empty($new_password)) {
             $response['message'] = 'New password cannot be empty.';
        } else {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET password_hash=?, is_updated=TRUE WHERE user_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $new_password_hash, $user_id_to_reset);
            if ($stmt->execute()) {
                $response = ['status' => 'success', 'message' => 'Password reset successfully.'];
            } else {
                $response['message'] = 'Password reset failed: ' . $stmt->error;
            }
        }
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Fetch initial data for page load ---
header('Content-Type: text/html');
$users_sql = "SELECT user_id, user_name, email, phone, role, status FROM users ORDER BY user_name";
$users = $conn->query($users_sql)->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } .modal { display: none; } .modal.is-open { display: flex; } </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Manage Users</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>

        <!-- Search Bar -->
        <div class="mb-6">
            <input type="text" id="search-box" placeholder="Search users by name, email, or phone..." class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>

        <!-- Global Messages Area -->
        <div id="global-message" class="mb-6"></div>

        <!-- User Table -->
        <div class="bg-white rounded-xl shadow-md overflow-hidden">
            <div class="overflow-x-auto">
                <table id="users-table" class="min-w-full">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($users as $user): ?>
                        <tr class="hover:bg-gray-50 user-row" data-id="<?php echo $user['user_id']; ?>">
                            <td class="px-6 py-4 text-sm font-medium text-gray-900 user-name"><?php echo htmlspecialchars($user['user_name']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600 user-email"><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600 user-phone"><?php echo htmlspecialchars($user['phone']); ?></td>
                            <td class="px-6 py-4 text-sm user-role"><?php echo $user['role'] == 1 ? '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">Admin</span>' : '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">User</span>'; ?></td>
                            <td class="px-6 py-4 text-sm user-status"><?php echo $user['status'] == 0 ? '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>' : '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Disabled</span>'; ?></td>
                            <td class="px-6 py-4 text-center text-sm space-x-2">
                                <button class="action-btn edit-btn p-2 bg-blue-500 text-white rounded-full hover:bg-blue-600" title="Edit User" data-id="<?php echo $user['user_id']; ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.5L16.732 3.732z" /></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="edit-user-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col">
            <div class="p-4 border-b flex justify-between items-center">
                 <h2 class="text-xl font-semibold">Edit User Details</h2>
                 <button class="close-modal-btn text-gray-500 text-2xl font-bold">&times;</button>
            </div>
            <div class="p-6 space-y-4 overflow-y-auto max-h-[70vh]">
                <input type="hidden" id="edit-user-id">
                
                <div><label class="block text-sm font-medium">Email Address</label><input type="email" id="edit-email" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div> 
                <div><label class="block text-sm font-medium">Full Name</label><input type="text" id="edit-user-name" required class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>
                <div><label class="block text-sm font-medium">Phone Number</label><input type="tel" id="edit-phone" class="mt-1 w-full p-2 border border-gray-300 rounded-md"></div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium">Role</label><select id="edit-role" class="mt-1 w-full p-2 border border-gray-300 rounded-md"><option value="0">User</option><option value="1">Admin</option></select></div>
                    <div><label class="block text-sm font-medium">Status</label><select id="edit-status" class="mt-1 w-full p-2 border border-gray-300 rounded-md"><option value="0">Active</option><option value="1">Disabled</option></select></div>
                </div>
                
                <!-- Password Reset Section -->
                <div class="pt-4 border-t mt-4">
                    <h3 class="text-lg font-medium mb-2">Reset Password (Optional)</h3>
                     <div class="relative">
                        <label class="block text-sm font-medium">New Password</label>
                        <input type="password" id="edit-new-password" placeholder="Leave blank to keep current password" class="mt-1 w-full p-2 border border-gray-300 rounded-md pr-10">
                         <button type="button" class="toggle-password absolute inset-y-0 right-0 top-6 px-3 text-gray-500" data-target="edit-new-password">Show</button>
                    </div>
                     <p id="password-reset-message" class="text-sm mt-2 text-green-600 h-4"></p>
                </div>

            </div>
            <div class="p-4 bg-gray-50 border-t flex justify-end gap-4">
                <button class="close-modal-btn bg-gray-300 px-4 py-2 rounded-lg">Cancel</button>
                <button id="edit-save-btn" class="bg-green-600 text-white px-4 py-2 rounded-lg">Save Changes</button>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const editModal = document.getElementById('edit-user-modal');
    const allModals = [editModal];
    const searchBox = document.getElementById('search-box');
    const userTableBody = document.querySelector('#users-table tbody');
    const currentUserId = <?php echo $current_user_id; ?>; // Get current user ID for JS checks

    // --- Live Search Function ---
    const filterTable = () => {
        const searchTerm = searchBox.value.toLowerCase();
        userTableBody.querySelectorAll('tr').forEach(row => {
            const isMatch = row.textContent.toLowerCase().includes(searchTerm);
            row.style.display = isMatch ? '' : 'none';
        });
    };
    searchBox.addEventListener('input', filterTable);

    // --- Modal Controls ---
    const openModal = modalEl => modalEl.classList.add('is-open');
    const closeModal = modalEl => modalEl.classList.remove('is-open');
    allModals.forEach(modal => modal.querySelectorAll('.close-modal-btn').forEach(btn => btn.addEventListener('click', () => closeModal(modal))));

    // --- Show/Hide Password Toggle ---
    editModal.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', () => {
            const targetInput = document.getElementById(button.dataset.target);
            if (targetInput.type === 'password') {
                targetInput.type = 'text'; button.textContent = 'Hide';
            } else {
                targetInput.type = 'password'; button.textContent = 'Show';
            }
        });
    });

    // --- Display Global Messages ---
    function showGlobalMessage(message, isSuccess = true) {
        const messageDiv = document.getElementById('global-message');
        const alertClass = isSuccess ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700';
        messageDiv.innerHTML = `<div class="${alertClass} px-4 py-3 rounded-lg"><span>${message}</span></div>`;
        setTimeout(() => messageDiv.innerHTML = '', 5000);
    }

    // --- Table Event Delegation for Edit Button ---
    userTableBody.addEventListener('click', async e => {
        const button = e.target.closest('button.edit-btn');
        if (!button) return;
        e.stopPropagation(); 
        
        const id = button.dataset.id;
        try {
            const res = await fetch(`?action=get_user_details&id=${id}`);
            const result = await res.json();
            if (result.status === 'success') {
                const data = result.data;
                const userIdBeingEdited = parseInt(data.user_id); // Ensure it's a number
                document.getElementById('edit-user-id').value = userIdBeingEdited;
                document.getElementById('edit-email').value = data.email;
                document.getElementById('edit-user-name').value = data.user_name;
                document.getElementById('edit-phone').value = data.phone;
                document.getElementById('edit-role').value = data.role;
                document.getElementById('edit-status').value = data.status;
                document.getElementById('edit-new-password').value = '';
                document.getElementById('password-reset-message').textContent = '';

                // Disable role/status change for self IF the user being edited IS the current user
                const isAdminEditingSelf = (userIdBeingEdited === currentUserId);
                document.getElementById('edit-role').disabled = isAdminEditingSelf;
                document.getElementById('edit-status').disabled = isAdminEditingSelf;

                openModal(editModal);
            } else {
                showGlobalMessage('Error fetching user details: ' + result.message, false);
            }
        } catch (error) {
            showGlobalMessage('Network or server error fetching details.', false);
            console.error(error);
        }
    });

    // --- Save Changes Button Listener ---
    document.getElementById('edit-save-btn').addEventListener('click', async () => {
        const userId = document.getElementById('edit-user-id').value;
        const newPassword = document.getElementById('edit-new-password').value;
        const passwordResetMsgEl = document.getElementById('password-reset-message');
        passwordResetMsgEl.textContent = '';
        passwordResetMsgEl.classList.remove('text-red-600');
        passwordResetMsgEl.classList.add('text-green-600');

        // 1. Update Profile Details
        const updatedData = {
            user_id: userId,
            user_name: document.getElementById('edit-user-name').value,
            email: document.getElementById('edit-email').value, // Added email
            phone: document.getElementById('edit-phone').value,
            role: document.getElementById('edit-role').value,
            status: document.getElementById('edit-status').value,
        };
        let updateSuccess = false;
        try {
            const resUpdate = await fetch('?action=update_user', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(updatedData) });
            const resultUpdate = await resUpdate.json();
            updateSuccess = resultUpdate.status === 'success';
            if (!updateSuccess) {
                // Show error message within the modal if update fails
                alert('Error updating profile: ' + resultUpdate.message);
            }
        } catch (error) {
             alert('Network or server error updating profile.'); console.error(error);
        }


        // 2. Reset Password (if entered and profile update was successful)
        let passwordSuccess = true;
        if (newPassword && updateSuccess) { // Only proceed if profile update worked
            try {
                const resPass = await fetch('?action=reset_password', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ user_id: userId, new_password: newPassword }) });
                const resultPass = await resPass.json();
                passwordSuccess = resultPass.status === 'success';
                if(passwordSuccess) {
                    passwordResetMsgEl.textContent = 'Password reset successfully!';
                    document.getElementById('edit-new-password').value = ''; 
                } else {
                    passwordResetMsgEl.textContent = 'Password reset failed: ' + resultPass.message;
                    passwordResetMsgEl.classList.remove('text-green-600');
                    passwordResetMsgEl.classList.add('text-red-600');
                }
            } catch(error) {
                 passwordSuccess = false;
                 passwordResetMsgEl.textContent = 'Network or server error resetting password.';
                 passwordResetMsgEl.classList.remove('text-green-600');
                 passwordResetMsgEl.classList.add('text-red-600');
                 console.error(error);
            }
        }

        // 3. Close modal and show message IF BOTH operations were successful
        if(updateSuccess && passwordSuccess) {
            closeModal(editModal);
            showGlobalMessage('User updated successfully!');
            // Update table row dynamically
            const row = document.querySelector(`tr[data-id="${userId}"]`);
            if (row) {
                row.querySelector('.user-name').textContent = updatedData.user_name;
                row.querySelector('.user-email').textContent = updatedData.email; // Update email cell
                row.querySelector('.user-phone').textContent = updatedData.phone;
                row.querySelector('.user-role').innerHTML = updatedData.role == 1 ? '<span class="px-2 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Admin</span>' : '<span class="px-2 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">User</span>';
                row.querySelector('.user-status').innerHTML = updatedData.status == 0 ? '<span class="px-2 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>' : '<span class="px-2 text-xs font-semibold rounded-full bg-red-100 text-red-800">Disabled</span>';
                 // Re-apply filter in case the updated data changes visibility
                filterTable();
            }
        } 
        // If either failed, error messages are shown either via alert or in the modal
    });
});
</script>

</body>
</html>

