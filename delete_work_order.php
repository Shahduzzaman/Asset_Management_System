<?php
// delete_work_order.php
require_once 'session_guard.php';
require_once 'connection.php';

header('Content-Type: application/json');

// 1. Security Check: Only Admins can delete
if (!isset($_SESSION['user_role']) || (int)$_SESSION['user_role'] !== 1) {
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $work_order_id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($work_order_id > 0) {
        // Soft delete the work order
        $sql = "UPDATE work_order SET is_deleted = 1 WHERE work_order_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $work_order_id);

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    }
}
$conn->close();
?>