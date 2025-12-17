<?php
require_once 'session_guard.php';
require_once 'connection.php';

header('Content-Type: application/json; charset=utf-8');

// Check if input is provided
if (empty($_GET['client_identifier'])) {
    echo json_encode([]);
    exit;
}

$raw_id = $_GET['client_identifier'];
$client_head_id = 0;

/* -------------------------------------------------------
   STEP 1: RESOLVE THE HEAD ID
   Whether the user selects "Head" or "Branch", we want 
   the Main Company ID (client_head_id).
------------------------------------------------------- */

if (preg_match('/^head_(\d+)$/', $raw_id, $matches)) {
    // Case A: User selected the Head Office directly (e.g., "head_5")
    $client_head_id = (int)$matches[1];
} else {
    // Case B: User selected a Branch (e.g., "10")
    // We must query the database to find who the Head Client is for this branch.
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

/* -------------------------------------------------------
   STEP 2: FETCH ALL WORK ORDERS FOR THIS HEAD CLIENT
------------------------------------------------------- */

$work_orders = [];

if ($client_head_id > 0) {
    // Fetch ALL active work orders for this company.
    // This ignores specific branch assignments and shows everything 
    // belonging to the Head Client.
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