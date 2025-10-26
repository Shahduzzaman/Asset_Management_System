<?php
// Start the session to manage user login state
session_start();

// If the user is already logged in, redirect them to the dashboard
if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit();
}

$errorMessage = ''; // Initialize error message
// Check for a logout reason in the URL query string
$reason = $_GET['reason'] ?? '';
if ($reason === 'idle') {
    $errorMessage = "Your session has expired due to inactivity. Please log in again.";
}


// Check if the form has been submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Include the database connection file
    require_once 'connection.php';

    // Get email and password from the form
    $email = $_POST['username']; 
    $password = $_POST['password'];

    // Prepare SQL statement to prevent SQL injection
    // *** Added 'role' to the SELECT statement ***
    $sql = "SELECT user_id, user_name, password_hash, role FROM users WHERE email = ? AND is_deleted = FALSE AND status = FALSE"; // status = FALSE (0) means Active
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        // Bind the email parameter
        $stmt->bind_param("s", $email);
        
        // Execute the statement
        $stmt->execute();
        
        // Store the result
        $stmt->store_result();
        
        // Check if a user with that email exists and is active
        if ($stmt->num_rows == 1) {
            // Bind the result variables *** Added $user_role ***
            $stmt->bind_result($user_id, $user_name, $password_hash, $user_role);
            
            // Fetch the result
            if ($stmt->fetch()) {
                // Verify the password against the stored hash
                if (password_verify($password, $password_hash)) {
                    // Password is correct, start a new session
                    session_regenerate_id(); // Prevents session fixation attacks
                    $_SESSION["user_id"] = $user_id;
                    $_SESSION["user_name"] = $user_name;
                    $_SESSION['last_activity'] = time(); // Start the session timer
                    $_SESSION['user_role'] = $user_role; // *** STORE USER ROLE IN SESSION ***
                    
                    // Redirect to a protected dashboard page
                    header("Location: dashboard.php");
                    exit();
                } else {
                    // Password is not valid
                    $errorMessage = "Invalid email or password.";
                }
            }
        } else {
            // No user found or user is disabled
            $errorMessage = "Invalid email or password, or account disabled.";
        }
        
        // Close the statement
        $stmt->close();
    } else {
        // If an error message isn't already set, provide a generic one
        if (empty($errorMessage)) {
             $errorMessage = "An error occurred. Please try again later.";
        }
    }
    
    // Close the connection
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Protection One AMS - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-sm mx-auto bg-white p-8 rounded-xl shadow-lg">
        <div class="flex flex-col items-center">
            <!-- Company Logo -->
            <div class="mb-4">
                <img src="images/logo.png" alt="Protection One AMS Logo" class="h-16 w-auto">
            </div>

            <!-- Heading -->
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Protection One AMS</h1>
        </div>

        <!-- Display Error Message -->
        <?php if (!empty($errorMessage)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6 text-center" role="alert">
                <span class="block sm:inline"><?php echo htmlspecialchars($errorMessage); ?></span>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form id="loginForm" class="space-y-6" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <!-- Username Input (used for Email) -->
            <div>
                <label for="username" class="sr-only">Email Address</label>
                <input type="email" id="username" name="username" placeholder="Email Address" required
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 transition duration-200">
            </div>

            <!-- Password Input with Show/Hide Toggle -->
            <div class="relative">
                <label for="password" class="sr-only">Password</label>
                <input type="password" id="password" name="password" placeholder="Password" required
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 transition duration-200">
                <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 px-4 flex items-center text-gray-500 hover:text-gray-700">
                    <!-- Eye Icon -->
                    <svg id="eyeIcon" class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.432 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                    <!-- Eye-Off Icon (Hidden by default) -->
                     <svg id="eyeOffIcon" class="w-6 h-6 hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.243 4.243L6.228 6.228" />
                    </svg>
                </button>
            </div>
            
            <!-- Login Button -->
            <div>
                <button type="submit"
                        class="w-full bg-indigo-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-300 ease-in-out">
                    Login
                </button>
            </div>
        </form>

        <!-- Forgot Password Link -->
        <div class="text-center mt-6">
            <a href="#" class="text-sm font-medium text-indigo-600 hover:text-indigo-500 hover:underline">
                Forgot Password?
            </a>
        </div>
    </div>

    <script>
        // --- Password Visibility Toggle ---
        const passwordInput = document.getElementById('password');
        const togglePasswordButton = document.getElementById('togglePassword');
        const eyeIcon = document.getElementById('eyeIcon');
        const eyeOffIcon = document.getElementById('eyeOffIcon');

        togglePasswordButton.addEventListener('click', function () {
            // Toggle the type attribute
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);

            // Toggle the icon visibility
            eyeIcon.classList.toggle('hidden');
            eyeOffIcon.classList.toggle('hidden');
        });

        // --- Remove Error Message on Reload ---
        document.addEventListener('DOMContentLoaded', function() {
            // Check if the 'reason' parameter exists in the URL
            if (window.location.search.includes('reason=')) {
                // If it exists, replace the current URL with a clean one without the parameter
                const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                window.history.replaceState({path: cleanUrl}, '', cleanUrl);
            }
        });
    </script>

</body>
</html>

