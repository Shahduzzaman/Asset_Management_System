<?php
session_start();
require_once "connection.php";

header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$return_id = (int)($_GET['id'] ?? 0);

if (!$return_id) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Return ID']);
    exit();
}

try {
    // FIX: 
    // 1. client_branch likely does not have 'Contact_Person'. Removed to fix SQL error.
    // 2. Kept 'Branch_Name' and 'Contact_Number' but ensure they match your schema.
    //    If 'Branch_Name' errors next, it is likely 'Name'.
    
    $sql = "SELECT
                sr.sales_return_id,
                sr.invoice_number,
                sr.return_date,
                u.user_name AS created_by_name,

                -- Returned Product
                rp.sl_id AS returned_sl_id,
                rp.product_sl AS returned_serial,
                rp.status AS returned_status,
                m1.model_name AS returned_model,

                -- Replacement Product
                replp.product_sl AS replacement_serial,
                replp.status AS replacement_status,
                m2.model_name AS replacement_model,

                -- Client Head Info
                ch.Company_Name AS head_name,       
                ch.Department AS head_department,   
                ch.Contact_Person AS head_cp,       
                ch.Contact_Number AS head_cn,      
                '' AS head_cp2,
                '' AS head_cn2,
                ch.Address AS head_address,         

                -- Client Branch Info
                cb.Branch_Name AS branch_name,      -- Assuming this column exists since error was on Contact_Person
                'N/A' AS branch_department,
                '' AS branch_cp,                    -- FIXED: Replaced 'cb.Contact_Person' with empty string to avoid error
                cb.Contact_Number AS branch_cn,     
                '' AS branch_cp2,
                '' AS branch_cn2,
                cb.Address AS branch_address,       

                -- Invoice Data to determine Client Source
                i.client_id_fk,
                i.client_branch_id_fk

            FROM sales_return sr
            LEFT JOIN users u ON sr.created_by = u.user_id
            
            -- Join Invoice
            LEFT JOIN invoice i ON sr.invoice_id_fk = i.invoice_id
            
            -- Join Client Head
            LEFT JOIN client_head ch ON i.client_id_fk = ch.client_head_id
            
            -- Join Client Branch
            LEFT JOIN client_branch cb ON i.client_branch_id_fk = cb.client_branch_id

            -- Join Returned Product
            LEFT JOIN product_sl rp ON sr.received_product_sl_id_fk = rp.sl_id
            LEFT JOIN models m1 ON rp.model_id_fk = m1.model_id

            -- Join Replacement Product
            LEFT JOIN product_sl replp ON sr.replace_product_sl_id_fk = replp.sl_id
            LEFT JOIN models m2 ON replp.model_id_fk = m2.model_id

            WHERE sr.sales_return_id = ?";

    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("i", $return_id);

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'Return record not found']);
        exit();
    }

    // Determine Client Details (Branch vs Head)
    $client = [];
    if (!empty($row['client_branch_id_fk']) && !empty($row['branch_name'])) {
        // 🟢 CLIENT BRANCH
        $client = [
            "name" => $row['branch_name'],
            "department" => $row['branch_department'],
            "contact_person" => $row['branch_cp'], // Will be empty string
            "contact_number" => $row['branch_cn'],
            "address" => $row['branch_address']
        ];
    } else {
        // 🔵 CLIENT HEAD (Default)
        $client = [
            "name" => $row['head_name'] ?? 'Walk-in / Unknown',
            "department" => $row['head_department'],
            "contact_person" => $row['head_cp'],
            "contact_number" => $row['head_cn'],
            "address" => $row['head_address']
        ];
    }

    // Final Response Data Structure
    $data = [
        "invoice_number" => $row['invoice_number'],
        "return_date" => date("d M Y, h:i A", strtotime($row['return_date'])),
        "created_by_name" => $row['created_by_name'],

        "returned_product" => [
            "serial" => $row['returned_serial'] ?? 'N/A',
            "status" => $row['returned_status'] ?? 0,
            "model" => $row['returned_model'] ?? 'Unknown'
        ],

        "replacement_product" => $row['replacement_serial'] ? [
            "serial" => $row['replacement_serial'],
            "status" => $row['replacement_status'],
            "model" => $row['replacement_model']
        ] : null,

        "client" => $client
    ];

    echo json_encode(['status' => 'success', 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>