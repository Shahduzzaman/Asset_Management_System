<?php
require_once 'session_guard.php';

require_once 'connection.php'; // Your database connection file

header('Content-Type: application/json; charset=utf-8');


if ((!isset($_GET['client_head_id']) || $_GET['client_head_id'] === '') ) {
    http_response_code(400);
    echo json_encode(['error' => 'client_head_id not provided.']);
    exit();
}

$client_head_id = (int)$_GET['client_head_id'];
$work_orders = [];

$sql = "SELECT work_order_id, Order_No 
        FROM work_order 
        WHERE client_head_id_fk = ? AND is_deleted = 0
        ORDER BY Order_Date DESC, Order_No DESC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $client_head_id);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'Execution failed: ' . $stmt->error]);
        $stmt->close();
        exit();
    }
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $work_orders[] = $row;
    }
    $stmt->close();
    echo json_encode($work_orders, JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database query prepare failed: ' . $conn->error]);
}

$conn->close();
exit();
