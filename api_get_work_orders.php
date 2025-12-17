<?php
require_once 'session_guard.php';
require_once 'connection.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Check if the input exists (we expect 'client_mix' from the JS)
if (empty($_GET['client_identifier'])) {
    echo json_encode([]); 
    exit;
}

$raw_id = $_GET['client_identifier'];
$work_orders = [];

// 2. Determine if Head Office or Branch
$client_head_id = 0;
$client_branch_id = 0;

if (preg_match('/^head_(\d+)$/', $raw_id, $matches)) {
    // It is a Head Office
    $client_head_id = (int)$matches[1];
    $sql = "SELECT work_order_id, Order_No, Order_Date 
            FROM work_order 
            WHERE client_head_id_fk = ? 
              AND (client_branch_id_fk IS NULL OR client_branch_id_fk = 0)
              AND is_deleted = 0
            ORDER BY work_order_id DESC";
    $param = $client_head_id;
} else {
    // It is a Branch
    $client_branch_id = (int)$raw_id;
    $sql = "SELECT work_order_id, Order_No, Order_Date 
            FROM work_order 
            WHERE client_branch_id_fk = ? 
              AND is_deleted = 0
            ORDER BY work_order_id DESC";
    $param = $client_branch_id;
}

// 3. Execute Query
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $param);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $work_orders[] = $row;
    }
    $stmt->close();
}

// 4. Return pure JSON array (simplest for JS to handle)
echo json_encode($work_orders);
exit();
?>