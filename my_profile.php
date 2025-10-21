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

$profileSuccessMessage = ''; $profileErrorMessage = '';
$passwordSuccessMessage = ''; $passwordErrorMessage = '';

// --- Handle Form Submissions (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // --- ACTION: UPDATE PROFILE INFORMATION ---
    if ($action === 'update_profile') {
        $user_name = trim($_POST['user_name']);
        $phone = trim($_POST['phone']);

        if (empty($user_name)) {
            $profileErrorMessage = "Full Name cannot be empty.";
        } else {
            $sql = "UPDATE users SET user_name = ?, phone = ?, is_updated = TRUE WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $user_name, $phone, $current_user_id);
            if ($stmt->execute()) {
                $profileSuccessMessage = "Profile information updated successfully.";
                $_SESSION['user_name'] = $user_name; // Update session name
            } else {
                $profileErrorMessage = "Error updating profile: " . $stmt->error;
            }
            $stmt->close();
        }
    }

    // --- ACTION: CHANGE PASSWORD ---
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_new_password = $_POST['confirm_new_password'];

        if (empty($current_password) || empty($new_password) || empty($confirm_new_password)) {
            $passwordErrorMessage = "Please fill in all password fields.";
        } elseif ($new_password !== $confirm_new_password) {
            $passwordErrorMessage = "The new passwords do not match.";
        } else {
            // First, get the current password hash from the DB
            $sql_select = "SELECT password_hash FROM users WHERE user_id = ?";
            $stmt_select = $conn->prepare($sql_select);
            $stmt_select->bind_param("i", $current_user_id);
            $stmt_select->execute();
            $result = $stmt_select->get_result();
            $user = $result->fetch_assoc();
            $stmt_select->close();

            // Verify the current password
            if ($user && password_verify($current_password, $user['password_hash'])) {
                // If correct, hash the new password and update the DB
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $sql_update = "UPDATE users SET password_hash = ?, is_updated = TRUE WHERE user_id = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("si", $new_password_hash, $current_user_id);
                if ($stmt_update->execute()) {
                    $passwordSuccessMessage = "Password changed successfully.";
                } else {
                    $passwordErrorMessage = "Error updating password: " . $stmt_update->error;
                }
                $stmt_update->close();
            } else {
                $passwordErrorMessage = "The current password you entered is incorrect.";
            }
        }
    }
}

// --- Fetch current user data for display ---
$sql_user = "SELECT user_name, email, phone FROM users WHERE user_id = ?";
$stmt_user = $conn->prepare($sql_user);
$stmt_user->bind_param("i", $current_user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } .modal { display: none; } .modal.is-open { display: flex; } </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">My Profile</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Profile Information Section -->
            <div class="bg-white p-8 rounded-xl shadow-md">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-4">Profile Information</h2>
                <?php if ($profileSuccessMessage): ?><div class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $profileSuccessMessage; ?></span></div><?php endif; ?>
                <?php if ($profileErrorMessage): ?><div class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $profileErrorMessage; ?></span></div><?php endif; ?>
                
                <form id="profile-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="update_profile">
                    <div>
                        <label for="user_name" class="block text-sm font-medium text-gray-700">Full Name</label>
                        <input type="text" id="user_name" name="user_name" value="<?php echo htmlspecialchars($user_data['user_name']); ?>" data-original="<?php echo htmlspecialchars($user_data['user_name']); ?>" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_data['email']); ?>" readonly disabled class="mt-1 w-full p-3 border border-gray-300 rounded-lg bg-gray-100 cursor-not-allowed">
                    </div>
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($user_data['phone']); ?>" data-original="<?php echo htmlspecialchars($user_data['phone']); ?>" class="mt-1 w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    <div class="text-right pt-2">
                        <button type="button" id="update-profile-btn" class="bg-blue-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-blue-700">Update Profile</button>
                    </div>
                </form>
            </div>

            <!-- Change Password Section -->
            <div class="bg-white p-8 rounded-xl shadow-md">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-4">Change Password</h2>
                <?php if ($passwordSuccessMessage): ?><div class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $passwordSuccessMessage; ?></span></div><?php endif; ?>
                <?php if ($passwordErrorMessage): ?><div class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $passwordErrorMessage; ?></span></div><?php endif; ?>

                <form id="password-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="change_password">
                    <div>
                        <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                        <input type="password" id="current_password" name="current_password" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                        <input type="password" id="new_password" name="new_password" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    <div>
                        <label for="confirm_new_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                        <input type="password" id="confirm_new_password" name="confirm_new_password" required class="mt-1 w-full p-3 border border-gray-300 rounded-lg">
                        <p id="password-match-error" class="text-red-500 text-sm mt-1 h-4"></p>
                    </div>
                    <div class="text-right pt-2">
                        <button type="submit" class="bg-blue-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-blue-700">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="confirm-update-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg flex flex-col">
            <div class="p-4 border-b"><h2 class="text-xl font-semibold">Confirm Changes</h2></div>
            <div id="changes-summary" class="p-6"></div>
            <div class="p-4 bg-gray-50 border-t flex justify-end gap-4">
                <button class="close-modal-btn bg-gray-300 px-6 py-2 rounded-lg">Cancel</button>
                <button id="confirm-update-btn" class="bg-green-600 text-white font-bold px-6 py-2 rounded-lg">Confirm Update</button>
            </div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const confirmModal = document.getElementById('confirm-update-modal');
    const openModal = modalEl => modalEl.classList.add('is-open');
    const closeModal = modalEl => modalEl.classList.remove('is-open');
    confirmModal.querySelectorAll('.close-modal-btn').forEach(btn => btn.addEventListener('click', () => closeModal(confirmModal)));

    // --- Profile Update Confirmation Logic ---
    document.getElementById('update-profile-btn').addEventListener('click', () => {
        const nameInput = document.getElementById('user_name');
        const phoneInput = document.getElementById('phone');
        
        const originalName = nameInput.dataset.original;
        const newName = nameInput.value;
        const originalPhone = phoneInput.dataset.original;
        const newPhone = phoneInput.value;

        let changesHtml = '<div class="space-y-4 text-sm">';
        let hasChanges = false;

        if (originalName !== newName) {
            changesHtml += `<div><strong class="block text-gray-800">Full Name</strong><p class="text-gray-500">Previous: ${originalName}</p><p class="text-green-600">New: ${newName}</p></div>`;
            hasChanges = true;
        }
        if (originalPhone !== newPhone) {
            changesHtml += `<div><strong class="block text-gray-800">Phone Number</strong><p class="text-gray-500">Previous: ${originalPhone || 'Not set'}</p><p class="text-green-600">New: ${newPhone || 'Not set'}</p></div>`;
            hasChanges = true;
        }

        if (!hasChanges) {
            changesHtml += '<p>No changes were made to your profile information.</p>';
            document.getElementById('confirm-update-btn').style.display = 'none';
        } else {
            document.getElementById('confirm-update-btn').style.display = 'inline-block';
        }
        changesHtml += '</div>';

        document.getElementById('changes-summary').innerHTML = changesHtml;
        openModal(confirmModal);
    });

    document.getElementById('confirm-update-btn').addEventListener('click', () => {
        document.getElementById('profile-form').submit();
    });
    
    // --- Password Form Validation ---
    const passwordForm = document.getElementById('password-form');
    const newPassword = document.getElementById('new_password');
    const confirmNewPassword = document.getElementById('confirm_new_password');
    const passwordErrorMsg = document.getElementById('password-match-error');

    passwordForm.addEventListener('submit', (e) => {
        if (newPassword.value !== confirmNewPassword.value) {
            e.preventDefault(); // Stop form submission
            passwordErrorMsg.textContent = 'Passwords do not match.';
        } else {
            passwordErrorMsg.textContent = '';
        }
    });
});
</script>

</body>
</html>
