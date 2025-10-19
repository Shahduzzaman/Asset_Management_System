<?php
// Start the session
session_start();

// Check if the user is logged in, otherwise redirect to the login page
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

// --- PHP Form Processing Logic ---

// Check if the form has been submitted using the POST method
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Include the database connection file
    require_once 'connection.php'; // This connects to the database

    // 2. Retrieve form data
    $user_name = $_POST['user_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $created_by = $_SESSION['user_id']; // Get the logged-in user's ID

    // Basic server-side validation
    if ($password !== $confirm_password) {
        $errorMessage = "Passwords do not match!";
    } else {
        // 3. Hash the password for secure storage
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // 4. Prepare the SQL INSERT statement to prevent SQL injection
        $sql = "INSERT INTO users (user_name, email, phone, password_hash, created_by) VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            // 5. Bind parameters to the prepared statement
            // 'ssssi' means the params are string, string, string, string, integer
            $stmt->bind_param("ssssi", $user_name, $email, $phone, $password_hash, $created_by);
            
            // 6. Execute the statement and check for success
            if ($stmt->execute()) {
                $successMessage = "New user created successfully!";
            } else {
                // Check if it's a duplicate email error
                if ($conn->errno == 1062) { // 1062 is the MySQL error code for duplicate entry
                    $errorMessage = "Error: This email address is already registered.";
                } else {
                    $errorMessage = "Error: " . $stmt->error;
                }
            }
            
            // Close the statement
            $stmt->close();
        } else {
            $errorMessage = "Error preparing statement: " . $conn->error;
        }
        
        // Close the database connection
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New User</title>
    <!-- Tailwind CSS for styling -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Use the Inter font family */
        body {
            font-family: 'Inter', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen py-12">

    <!-- Form Container -->
    <main class="w-full max-w-lg p-4 sm:p-8">
        <div class="bg-white rounded-2xl shadow-xl p-8">
            
            <!-- Back to Dashboard Link -->
            <div class="text-left mb-6">
                <a href="dashboard.php" class="text-indigo-600 hover:text-indigo-800 font-medium transition duration-200">
                    &larr; Back to Dashboard
                </a>
            </div>

            <!-- Header -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-800">Create New User</h1>
                <p class="text-gray-500 mt-2">Please fill out the form to add a new user.</p>
            </div>

            <!-- Display Success or Error Messages -->
            <?php if (isset($successMessage)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <strong class="font-bold">Success!</strong>
                    <span class="block sm:inline"><?php echo $successMessage; ?></span>
                </div>
            <?php endif; ?>
            <?php if (isset($errorMessage)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-6" role="alert">
                    <strong class="font-bold">Error!</strong>
                    <span class="block sm:inline"><?php echo $errorMessage; ?></span>
                </div>
            <?php endif; ?>

            <!-- User Form -->
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                
                <!-- User Name Input -->
                <div>
                    <label for="user_name" class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                    <input type="text" id="user_name" name="user_name"
                           placeholder="FirstName LastName" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                </div>
                
                <!-- Email Input -->
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                    <input type="email" id="email" name="email"
                           placeholder="you@example.com" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                </div>
                
                <!-- Phone Number Input -->
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                    <input type="tel" id="phone" name="phone"
                           placeholder="+880 12 3456 7890"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200">
                </div>
                
                <!-- Password Input -->
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <div class="relative">
                        <input type="password" id="password" name="password"
                               placeholder="Enter Password" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200 pr-10">
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-700">
                            <!-- Eye icon will be inserted here by JavaScript -->
                        </button>
                    </div>
                </div>
                
                 <!-- Confirm Password Input -->
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                    <div class="relative">
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Confirm Password" required
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-200 pr-10">
                        <button type="button" id="toggleConfirmPassword" class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-500 hover:text-gray-700">
                           <!-- Eye icon will be inserted here by JavaScript -->
                        </button>
                    </div>
                    <p id="password-error" class="text-red-500 text-sm mt-1 h-4"></p>
                </div>

                <!-- Submit Button -->
                <div>
                    <button type="submit"
                            class="w-full bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-transform transform hover:scale-105 duration-300">
                        Create User
                    </button>
                </div>
            </form>
        </div>
    </main>
    
    <script>
        // --- Password Visibility Toggle ---
        const eyeIconSvg = `
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
              <path d="M10 12a2 2 0 100-4 2 2 0 000 4z" />
              <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.022 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd" />
            </svg>`;
        
        const eyeSlashIconSvg = `
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd" />
              <path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.742L2.303 3.707a1 1 0 011.414-1.414l14 14a1 1 0 01-1.414 1.414l-4.26-4.26z" />
            </svg>`;

        function setupPasswordToggle(inputId, toggleId) {
            const passwordInput = document.getElementById(inputId);
            const toggleButton = document.getElementById(toggleId);
            
            if (toggleButton) {
                // Set initial icon
                toggleButton.innerHTML = eyeIconSvg;

                toggleButton.addEventListener('click', () => {
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        toggleButton.innerHTML = eyeSlashIconSvg;
                    } else {
                        passwordInput.type = 'password';
                        toggleButton.innerHTML = eyeIconSvg;
                    }
                });
            }
        }

        setupPasswordToggle('password', 'togglePassword');
        setupPasswordToggle('confirm_password', 'toggleConfirmPassword');

        // --- Client-Side Form Submission Validation ---
        const form = document.querySelector('form');
        const passwordError = document.getElementById('password-error');

        form.addEventListener('submit', (event) => {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            // Clear previous error message
            passwordError.textContent = "";

            if (password !== confirmPassword) {
                event.preventDefault(); // Prevent form submission
                passwordError.textContent = "Passwords do not match!";
            }
        });
    </script>
</body>
</html>

