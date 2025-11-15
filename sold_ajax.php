<?php
session_start();
// --- FIX: Force JSON content-type and error reporting ---
// This prevents stray PHP warnings from breaking JSON.
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't show errors to user, but we'll log them
// --- END FIX ---

require_once 'connection.php'; // Uses $conn (mysqli)

// --- Security & Session Check ---
if (!isset($_SESSION["user_id"])) {
    send_json(['status' => 'error', 'message' => 'Not authenticated. Please log in.']);
}
$current_user_id = $_SESSION['user_id'];

// --- Main Action Router ---
$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

try {
    // --- FIX: Force mysqli to throw exceptions ---
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    switch ($action) {
        // --- GET REQUESTS ---
        
        // MODIFICATION: New combined client search
        case 'search_client_branch':
            $query = $conn->real_escape_string(trim($_GET['q'] ?? ''));
            if (empty($query)) {
                send_json(['status' => 'success', 'clients' => []]);
            }
            $sql = "SELECT 
                        cb.client_branch_id, 
                        cb.client_head_id_fk, 
                        cb.Branch_Name, 
                        ch.Company_Name,
                        CONCAT(ch.Company_Name, ' - ', cb.Branch_Name) AS display_name
                    FROM client_branch AS cb
                    JOIN client_head AS ch ON cb.client_head_id_fk = ch.client_head_id
                    WHERE (cb.Branch_Name LIKE ? OR ch.Company_Name LIKE ?)
                    AND cb.is_deleted = 0 AND ch.is_deleted = 0
                    LIMIT 20";
            $stmt = $conn->prepare($sql);
            $like_query = "%{$query}%";
            $stmt->bind_param("ss", $like_query, $like_query);
            $stmt->execute();
            $result = $stmt->get_result();
            $clients = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'clients' => $clients]);
            break; // Added break for safety

        case 'search_work_order':
            $query = $conn->real_escape_string(trim($_GET['q'] ?? ''));
            $client_head_id = (int)($_GET['client_head_id'] ?? 0);
            
            $sql = "SELECT work_order_id, Order_No FROM work_order 
                    WHERE Order_No LIKE ? AND is_deleted = 0";
            $params = ["%{$query}%"];
            $types = "s";
            
            if ($client_head_id > 0) {
                $sql .= " AND client_head_id_fk = ?";
                $params[] = $client_head_id;
                $types .= "i";
            }
            $sql .= " LIMIT 10";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $orders = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'orders' => $orders]);
            break;

        case 'get_brands_by_category':
            $category_id = (int)$_GET['category_id'];
            $stmt = $conn->prepare("SELECT brand_id, brand_name FROM brands WHERE category_id = ? AND is_deleted = 0 ORDER BY brand_name");
            $stmt->bind_param("i", $category_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $brands = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'brands' => $brands]);
            break;

        case 'get_models_by_brand':
            $brand_id = (int)$_GET['brand_id'];
            $stmt = $conn->prepare("SELECT model_id, model_name FROM models WHERE brand_id = ? AND is_deleted = 0 ORDER BY model_name");
            $stmt->bind_param("i", $brand_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $models = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            // --- *** THE FIX FOR BUG A (JSON ERROR) IS HERE *** ---
            // The typo `status's` has been corrected to `status`.
            send_json(['status' => 'success', 'models' => $models]);
            break;

        case 'available_quantity':
            $model_id = (int)$_GET['model_id'];
            
            // --- *** THE FIX FOR BUG B (Code: 1054) IS HERE *** ---
            // `purchased_products` uses `model_id` (as per ams (7).sql)
            // `sold_product` uses `model_id_fk` (as per ams (7).sql)
            
            $sql = "SELECT
                        COALESCE((SELECT SUM(quantity) FROM purchased_products WHERE model_id = ? AND is_deleted = 0), 0)
                        -
                        COALESCE((SELECT SUM(Quantity) FROM sold_product WHERE model_id_fk = ? AND is_deleted = 0), 0)
                    AS available";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $model_id, $model_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            send_json(['status' => 'success', 'available' => $result['available'] ?? 0]);
            break;

        case 'get_avg_max_price':
            $model_id = (int)$_GET['model_id'];

            // --- *** THE FIX FOR BUG B (Code: 1054) IS HERE *** ---
            // `purchased_products` uses `model_id` (as per ams (7).sql)
            
            $sql = "SELECT COALESCE(AVG(unit_price), 0) AS avg_price, COALESCE(MAX(unit_price), 0) AS max_price
                    FROM purchased_products WHERE model_id = ? AND is_deleted = 0";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $model_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            send_json(['status' => 'success', 'avg' => $result['avg_price'], 'max' => $result['max_price']]);
            break;

        case 'get_serials_for_model':
            $model_id = (int)$_GET['model_id'];
            // `product_sl` uses `model_id_fk` (This was already correct).
            $sql = "SELECT sl_id, product_sl FROM product_sl 
                    WHERE model_id_fk = ? AND status = 0 AND is_deleted = 0 
                    ORDER BY product_sl";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $model_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $serials = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'serials' => $serials]);
            break; 

        // --- POST REQUESTS ---
        case 'add_temp_row':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            
            $data = $_POST; // Data from jQuery
            
            // 1. Server-side validation
            $client_head_id = (int)$data['client_head_id_fk'];
            $model_id_fk = (int)$data['model_id_fk']; // This is for `temp_sold_product`
            $quantity = (int)$data['Quantity'];
            $unit_price = (float)$data['Sold_Unit_Price'];

            if ($client_head_id <= 0 || $model_id_fk <= 0 || $quantity <= 0 || $unit_price < 0) {
                throw new Exception("Invalid input data.");
            }
            
            // --- *** MODIFICATION FOR MULTI-SERIAL *** ---
            $product_sl_ids = $data['product_sl_id_fk'] ?? null;
            
            $conn->begin_transaction();

            // Prepare the insert statement ONCE
            $sql = "INSERT INTO temp_sold_product (
                        created_by, client_head_id_fk, client_branch_id_fk, sold_date, 
                        work_order_id_fk, model_id_fk, product_sl_id_fk, Remarks, 
                        Avg_Max_Price, Quantity, Sold_Unit_Price
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $types = "iiisiisisid"; // i(user), i(head), i(branch), s(date), i(work_order), i(model), i(sl), s(remarks), s(avg_max), i(qty), d(price)
            $stmt->bind_param($types,
                $current_user_id,
                $data['client_head_id_fk'],
                $data['client_branch_id_fk'],
                $data['sold_date'],
                $data['work_order_id_fk'] ?: null,
                $data['model_id_fk'],
                $current_serial_id, // Placeholder, will be set in loop
                $data['Remarks'],
                $data['Avg_Max_Price'],
                $current_quantity, // Placeholder
                $data['Sold_Unit_Price']
            );

            if (is_array($product_sl_ids) && !empty($product_sl_ids)) {
                // --- A) User selected multiple serials ---
                $added_count = 0;
                foreach ($product_sl_ids as $serial_id) {
                    $current_serial_id = (int)$serial_id;
                    $current_quantity = 1; // Always 1 for a serial
                    
                    // Check this specific serial
                    $stmt_check_serial = $conn->prepare("SELECT sl_id FROM product_sl WHERE sl_id = ? AND status = 0 AND model_id_fk = ?");
                    $stmt_check_serial->bind_param("ii", $current_serial_id, $model_id_fk);
                    $stmt_check_serial->execute();
                    $result_serial = $stmt_check_serial->get_result();
                    if ($result_serial->num_rows === 0) {
                        throw new Exception("Serial ID $current_serial_id is not available or does not match the model.");
                    }
                    $stmt_check_serial->close();

                    $stmt->execute();
                    $added_count++;
                }
                $message = "Added $added_count serial items to cart.";

            } else {
                // --- B) User is adding a bulk quantity (no serial) ---
                $current_serial_id = null; // No serial
                $current_quantity = $quantity; // Use the quantity from the form
                
                // --- *** THE FIX FOR BUG B (Code: 1054) IS HERE *** ---
                // `purchased_products` uses `model_id` (as per ams (7).sql)
                $stmt_check = $conn->prepare("SELECT COALESCE((SELECT SUM(quantity) FROM purchased_products WHERE model_id = ?), 0) - COALESCE((SELECT SUM(Quantity) FROM sold_product WHERE model_id_fk = ?), 0) AS available");
                $stmt_check->bind_param("ii", $model_id_fk, $model_id_fk);
                $stmt_check->execute();
                $available = (int)$stmt_check->get_result()->fetch_assoc()['available'];
                $stmt_check->close();
                if ($quantity > $available) {
                    throw new Exception("Stock check failed: Quantity ($quantity) exceeds available stock ($available).");
                }
                
                $stmt->execute();
                $message = "Added $quantity items to cart.";
            }

            $stmt->close();
            $conn->commit();
            send_json(['status' => 'success', 'message' => $message]);
            break;

        case 'get_temp_rows':
            $sql = "SELECT ts.*, m.model_name, b.brand_name, c.category_name, psl.product_sl
                    FROM temp_sold_product ts
                    JOIN models m ON ts.model_id_fk = m.model_id
                    JOIN brands b ON m.brand_id = b.brand_id
                    JOIN categories c ON m.category_id = c.category_id
                    LEFT JOIN product_sl psl ON ts.product_sl_id_fk = psl.sl_id
                    WHERE ts.created_by = ?
                    ORDER BY ts.temp_sold_id";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $current_user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'rows' => $rows]);
            break;

        case 'remove_temp_row':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            
            $temp_sold_id = (int)$_POST['temp_sold_id'];
            $stmt = $conn->prepare("DELETE FROM temp_sold_product WHERE temp_sold_id = ? AND created_by = ?");
            $stmt->bind_param("ii", $temp_sold_id, $current_user_id);
            $stmt->execute();
            $stmt->close();
            send_json(['status' => 'success', 'message' => 'Item removed.']);
            break;

        case 'confirm_sale':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            
            $data = $_POST;
            $tax_percentage = (float)$data['tax_percentage'];
            $excluding_tax = (float)$data['excluding_tax'];
            $including_tax = (float)$data['including_tax'];

            $conn->begin_transaction();
            
            // 1. Generate Invoice Number
            $stmt_inv = $conn->prepare("SELECT Invoice_No FROM invoice ORDER BY invoice_id DESC LIMIT 1");
            $stmt_inv->execute();
            $result_inv = $stmt_inv->get_result();
            $last_invoice = $result_inv->fetch_assoc();
            $stmt_inv->close();

            $next_num = 1;
            if ($last_invoice && !empty($last_invoice['Invoice_No'])) {
                $last_num = (int)substr($last_invoice['Invoice_No'], -6);
                $next_num = $last_num + 1;
            }
            $new_invoice_no = 'P1WQINV' . sprintf('%06d', $next_num);

            // 2. Insert Invoice
            $sql_invoice = "INSERT INTO invoice (Invoice_No, IncludingTax_TotalPrice, ExcludingTax_TotalPrice, Tax_Percentage, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt_invoice = $conn->prepare($sql_invoice);
            $stmt_invoice->bind_param("sddi",
                $new_invoice_no,
                $including_tax,
                $excluding_tax,
                $tax_percentage,
                $current_user_id
            );
            $stmt_invoice->execute();
            $new_invoice_id = $conn->insert_id; // Get the new invoice ID
            $stmt_invoice->close();

            // 3. Get all temp items for user
            $stmt_get_temp = $conn->prepare("SELECT * FROM temp_sold_product WHERE created_by = ?");
            $stmt_get_temp->bind_param("i", $current_user_id);
            $stmt_get_temp->execute();
            $temp_items = $stmt_get_temp->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_get_temp->close();

            if (empty($temp_items)) {
                throw new Exception("Cart is empty. Nothing to confirm.");
            }

            // 4. Prepare statements to move items and update stock
            $sql_move_item = "INSERT INTO sold_product (
                                client_head_id_fk, client_branch_id_fk, sold_date, work_order_id_fk, 
                                product_sl_id_fk, model_id_fk, Remarks, Avg_Max_Price, 
                                Quantity, Sold_Unit_Price, invoice_id_fk, created_by, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt_move_item = $conn->prepare($sql_move_item);

            $sql_update_sl = "UPDATE product_sl SET status = 1 WHERE sl_id = ?"; // 1 = Sold
            $stmt_update_sl = $conn->prepare($sql_update_sl);

            // 5. Loop, Move, and Update
            foreach ($temp_items as $item) {
                // 5a. Move to sold_product
                
                $types_move = "iisiiisisiidi"; 
                
                $stmt_move_item->bind_param($types_move,
                    $item['client_head_id_fk'],
                    $item['client_branch_id_fk'],
                    $item['sold_date'],
                    $item['work_order_id_fk'] ?: null,
                    $item['product_sl_id_fk'] ?: null,
                    $item['model_id_fk'],
                    $item['Remarks'],
                    $item['Avg_Max_Price'],
                    $item['Quantity'],
                    $item['Sold_Unit_Price'],
                    $new_invoice_id,
                    $current_user_id
                );
                $stmt_move_item->execute();

                // 5b. Update product_sl if serial was used
                if (!empty($item['product_sl_id_fk'])) {
                    $stmt_update_sl->bind_param("i", $item['product_sl_id_fk']);
                    $stmt_update_sl->execute();
                }
            }
            $stmt_move_item->close();
            $stmt_update_sl->close();

            // 6. Delete temp items
            $stmt_delete_temp = $conn->prepare("DELETE FROM temp_sold_product WHERE created_by = ?");
            $stmt_delete_temp->bind_param("i", $current_user_id);
            $stmt_delete_temp->execute();
            $stmt_delete_temp->close();

        // 7. Commit transaction
            $conn->commit();

            send_json(['status' => 'success', 'invoice_id' => $new_invoice_id, 'invoice_no' => $new_invoice_no]);
            break;

        default:
            send_json(['status' => 'error', 'message' => 'Invalid action specified.']);
    }
} catch (mysqli_sql_exception $e) {
    // Catch database-specific errors
    if ($conn->in_transaction) {
        $conn->rollback();
    }
    // Log the detailed error to the server's error log
    error_log("mysqli_sql_exception: " . $e->getMessage() . " in sold_ajax.php on line " . $e->getLine());
    send_json(['status' => 'error', 'message' => 'A database error occurred. Please try again. (Code: ' . $e->getCode() . ')']);
} catch (Exception $e) {
    // Catch general errors (e.g., our "Cart is empty" throw)
    if ($conn->in_transaction) {
        $conn->rollback();
    }
    error_log("Exception: " . $e->getMessage() . " in sold_ajax.php on line " . $e->getLine());
    
    // --- *** THE FIX FOR BUG A (JSON ERROR) IS HERE *** ---
    // The typo `status's` has been corrected to `status`.
    send_json(['status' => 'error', 'message' => $e->getMessage()]);
}

// Close the main connection
$conn->close();

/**
 * Helper function to send JSON response and exit.
 * @param array $data The data to encode as JSON.
 */
function send_json($data) {
    echo json_encode($data);
    exit;
}
?>