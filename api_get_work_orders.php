<?php
require_once 'session_guard.php';
require_once 'connection.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_GET['client_identifier'])) {
    echo json_encode([]);
    exit;
}

$raw_id = $_GET['client_identifier'];
$client_head_id = 0;

if (preg_match('/^head_(\d+)$/', $raw_id, $matches)) {
    $client_head_id = (int)$matches[1];
} else {
    $client_branch_id = (int)$raw_id;
    
    $stmt = $conn->prepare("
        SELECT client_head_id_fk 
        FROM client_branch 
        WHERE client_branch_id = ?
    ");
    $stmt->bind_param("i", $client_branch_id);
    $stmt->execute();
    $stmt->bind_result($found_head_id);
    
    if ($stmt->fetch()) {
        $client_head_id = $found_head_id;
    }
    $stmt->close();
}

$work_orders = [];

if ($client_head_id > 0) {
    $sql = "SELECT work_order_id, Order_No, Order_Date 
            FROM work_order 
            WHERE client_head_id_fk = ? 
              AND is_deleted = 0
            ORDER BY work_order_id DESC";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $client_head_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $work_orders[] = $row;
        }
        $stmt->close();
    }
}

echo json_encode($work_orders);
?>