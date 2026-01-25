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

$check_admin_sql = "SELECT user_id FROM users WHERE user_id = 1";
$check_admin_result = $conn->query($check_admin_sql);

if ($check_admin_result && $check_admin_result->num_rows == 0) {
    $sa_id = 1;
    $sa_name = 'Super Admin';
    $sa_email = 'info@protectionone.com.bd';      
    $sa_password = '12345678';               
    $sa_phone = '+8801755-551912';
    $sa_role = 1;                       
    $sa_status = 0;                      
    
    $sa_hash = password_hash($sa_password, PASSWORD_DEFAULT);

    $insert_admin_sql = "INSERT INTO users (user_id, user_name, email, password_hash, phone, role, status, branch_id_fk) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, NULL)";
    
    $stmt_sa = $conn->prepare($insert_admin_sql);
    
    if ($stmt_sa) {
        $stmt_sa->bind_param("issssii", $sa_id, $sa_name, $sa_email, $sa_hash, $sa_phone, $sa_role, $sa_status);
        if ($stmt_sa->execute()) {

        } else {
             error_log("Failed to auto-create Super Admin: " . $stmt_sa->error);
        }
        $stmt_sa->close();
    }
}
?>

