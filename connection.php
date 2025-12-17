<?php
$servername = "localhost"; 
$username = "root";        
$password = "";            
$dbname = "ams"; 

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->query("SET sql_mode=''");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// --- AUTO-CREATE SUPER ADMIN (ID 1) ---
// 1. Check if User ID 1 exists
$check_admin_sql = "SELECT user_id FROM users WHERE user_id = 1";
$check_admin_result = $conn->query($check_admin_sql);

if ($check_admin_result && $check_admin_result->num_rows == 0) {
    // 2. Define Super Admin Details
    $sa_id = 1;
    $sa_name = 'Super Admin';
    $sa_email = 'info@protectionone.com.bd';       // Default Email
    $sa_password = '12345678';                // Default Password
    $sa_phone = '+8801755-551912';
    $sa_role = 1;                        // 1 = Admin
    $sa_status = 0;                      // 0 = Active
    
    // Hash the password securely
    $sa_hash = password_hash($sa_password, PASSWORD_DEFAULT);

    // 3. Insert the Super Admin explicitly forcing ID 1
    // Note: We use NULL for branch_id_fk
    $insert_admin_sql = "INSERT INTO users (user_id, user_name, email, password_hash, phone, role, status, branch_id_fk) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, NULL)";
    
    $stmt_sa = $conn->prepare($insert_admin_sql);
    
    if ($stmt_sa) {
        $stmt_sa->bind_param("issssii", $sa_id, $sa_name, $sa_email, $sa_hash, $sa_phone, $sa_role, $sa_status);
        if ($stmt_sa->execute()) {
            // Optional: Log success (e.g. to error_log)
            // error_log("Super Admin created successfully.");
        } else {
            // Check for specific error (like duplicate email for ID other than 1)
             error_log("Failed to auto-create Super Admin: " . $stmt_sa->error);
        }
        $stmt_sa->close();
    }
}
?>

