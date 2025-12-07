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
$user_role = 0; // Default to non-admin
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
    // Redirect non-admins to the dashboard with an error message
    $_SESSION['error_message'] = "Access Denied: You do not have permission to create users.";
    header("Location: dashboard.php");
    exit();
}
// --- END: ADMIN ROLE CHECK ---


$successMessage = ''; $errorMessage = '';

// --- PHP Form Processing Logic ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $user_name = trim($_POST['user_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = isset($_POST['role']) ? intval($_POST['role']) : 0;     // Default to 0 (User)
    // *** NEW: Get branch_id, allow NULL ***
    $branch_id_fk = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;

    // Basic server-side validation
    if (empty($user_name) || empty($email) || empty($password) || empty($confirm_password)) {
         $errorMessage = "Please fill in all required fields (Full Name, Email, Password, Confirm Password).";
    } elseif ($password !== $confirm_password) {
        $errorMessage = "Passwords do not match!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
         $errorMessage = "Invalid email format.";
    } else {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // *** MODIFIED: Added branch_id_fk to SQL INSERT ***
        $sql = "INSERT INTO users (user_name, email, phone, password_hash, role, branch_id_fk, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            // *** MODIFIED: Bind 7 params (ssssiii) ***
            $stmt->bind_param("ssssiii", $user_name, $email, $phone, $password_hash, $role, $branch_id_fk, $current_user_id);
            
            if ($stmt->execute()) {
                $successMessage = "New user created successfully!";
            } else {
                if ($conn->errno == 1062) { 
                    $errorMessage = "Error: This email address is already registered.";
                } else {
                    $errorMessage = "Error: " . $stmt->error;
                }
            }
            $stmt->close();
        } else {
            $errorMessage = "Error preparing statement: " . $conn->error;
        }
    }
    // *** MODIFICATION: Connection close moved down to allow branch fetching ***
}

// --- Fetch Branch List for Dropdown ---
$branches_result = $conn->query("SELECT branch_id, Name FROM Branch WHERE is_deleted = FALSE ORDER BY Name");
$branches = $branches_result ? $branches_result->fetch_all(MYSQLI_ASSOC) : [];
$conn->close(); // Connection is now closed after all data is fetched
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New User</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">

    <main class="w-full max-w-lg p-4 sm:p-8">
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <div class="flex justify-center mb-6">
                 <img src="images/logo.png" alt="Protection One AMS Logo" class="h-16 w-auto">
            </div>
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Create New User</h1>
                <p class="text-gray-500 mt-2">Fill out the form to add a new user.</p>
            </div>

            <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $successMessage; ?></span></div><?php endif; ?>
            <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $errorMessage; ?></span></div><?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                
                <div>
                    <label for="user_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" id="user_name" name="user_name" placeholder="FirstName LastName" required class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address <span class="text-red-500">*</span></label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="+880 12 3456 7890" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>

                <!-- *** NEW: Branch and Role fields in a grid *** -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <div>
                        <label for="branch_id" class="block text-sm font-medium text-gray-700 mb-1">Assign to Branch</label>
                        <select id="branch_id" name="branch_id" class="w-full p-3 border border-gray-300 rounded-lg">
                            <option value="">-- No Branch Assigned --</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['branch_id']; ?>">
                                    <?php echo htmlspecialchars($branch['Name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div> 
                         <label for="role" class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                         <select id="role" name="role" class="w-full p-3 border border-gray-300 rounded-lg">
                             <option value="0" selected>User</option>
                             <option value="1">Admin</option>
                         </select>
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                    <div class="relative"><input type="password" id="password" name="password" placeholder="Enter Password" required class="w-full p-3 border border-gray-300 rounded-lg pr-10"><button type="button" id="togglePassword" class="absolute inset-y-0 right-0 px-3 text-gray-500"></button></div>
                </div>
                
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                    <div class="relative"><input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required class="w-full p-3 border border-gray-300 rounded-lg pr-10"><button type="button" id="toggleConfirmPassword" class="absolute inset-y-0 right-0 px-3 text-gray-500"></button></div>
                    <p id="password-error" class="text-red-500 text-sm mt-1 h-4"></p>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-blue-700">Create User</button>
                </div>
            </form>
        </div>
    </main>
    
<script>
    // --- Password Visibility Toggle ---
    const eyeIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z" /><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.022 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" /></svg>`;
    const eyeSlashIconSvg = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd" /><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.742L2.303 3.707a1 1 0 011.414-1.414l14 14a1 1 0 01-1.414 1.414l-4.26-4.26z" /></svg>`;

    function setupPasswordToggle(inputId, toggleId) {
        const input = document.getElementById(inputId); const btn = document.getElementById(toggleId);
        btn.innerHTML = eyeIconSvg;
        btn.addEventListener('click', () => { input.type = input.type === 'password' ? 'text' : 'password'; btn.innerHTML = input.type === 'password' ? eyeIconSvg : eyeSlashIconSvg; });
    }
    setupPasswordToggle('password', 'togglePassword');
    setupPasswordToggle('confirm_password', 'toggleConfirmPassword');

    // --- Client-Side Password Match Validation ---
    const form = document.querySelector('form');
    const passwordError = document.getElementById('password-error');
    form.addEventListener('submit', (event) => {
        const p1 = document.getElementById('password').value; const p2 = document.getElementById('confirm_password').value;
        passwordError.textContent = "";
        if (p1 !== p2) { event.preventDefault(); passwordError.textContent = "Passwords do not match!"; }
    });

    // Auto-hide alerts
    const alertBox = document.getElementById('alert-box');
    if (alertBox) { setTimeout(() => { alertBox.style.transition = 'opacity 0.5s ease'; alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000); }
</script>

</body>
</html>