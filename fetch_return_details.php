<?php
session_start();
require_once 'connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$return_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$return_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Return ID']);
    exit();
}

try {
    // FIXED: changed u.name → u.user_name
    $sql = "SELECT
                sr.sales_return_id,
                sr.invoice_number,
                sr.return_date,
                u.user_name AS created_by_name,
                rp.product_sl AS returned_serial,
                rp.status AS returned_status,
                m1.model_name AS returned_model,
                replp.product_sl AS replacement_serial,
                replp.status AS replacement_status,
                m2.model_name AS replacement_model
            FROM sales_return sr
            LEFT JOIN users u ON sr.created_by = u.user_id
            LEFT JOIN product_sl rp ON sr.received_product_sl_id_fk = rp.sl_id
            LEFT JOIN models m1 ON rp.model_id_fk = m1.model_id
            LEFT JOIN product_sl replp ON sr.replace_product_sl_id_fk = replp.sl_id
            LEFT JOIN models m2 ON replp.model_id_fk = m2.model_id
            WHERE sr.sales_return_id = ? LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $return_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Return not found']);
        exit();
    }

    $data = [
        'invoice_number' => $row['invoice_number'],
        'return_date' => $row['return_date'],
        'created_by_name' => $row['created_by_name'],
        'returned_product' => [
            'serial' => $row['returned_serial'],
            'status' => $row['returned_status'],
            'model' => $row['returned_model'],
        ],
        'replacement_product' => $row['replacement_serial'] ? [
            'serial' => $row['replacement_serial'],
            'status' => $row['replacement_status'],
            'model' => $row['replacement_model'],
        ] : null,
    ];

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
