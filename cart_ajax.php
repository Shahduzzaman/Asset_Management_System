<?php
require_once 'session_guard.php';

$current_user_id = $_SESSION['user_id'];

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'connection.php';

$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

$inTransaction = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    switch ($action) {

        case 'get_brands_by_category':
            $category_id = (int)($_GET['category_id'] ?? 0);
            $stmt = $conn->prepare("SELECT brand_id, brand_name FROM brands WHERE category_id = ? AND is_deleted = 0 ORDER BY brand_name");
            $stmt->bind_param("i", $category_id);
            $stmt->execute();
            $brands = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'brands' => $brands]);
            break;

        case 'get_models_by_brand':
            $brand_id = (int)($_GET['brand_id'] ?? 0);
            $stmt = $conn->prepare("SELECT model_id, model_name FROM models WHERE brand_id = ? AND is_deleted = 0 ORDER BY model_name");
            $stmt->bind_param("i", $brand_id);
            $stmt->execute();
            $models = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'models' => $models]);
            break;

        case 'available_quantity':
            $model_id = (int)($_GET['model_id'] ?? 0);

            $sql = "SELECT
                        COALESCE((SELECT SUM(quantity) FROM purchased_products WHERE model_id = ? AND is_deleted = 0), 0)
                        -
                        COALESCE((SELECT SUM(Quantity) FROM sold_product WHERE model_id_fk = ? AND is_deleted = 0 AND product_sl_id_fk IS NULL), 0)
                        -
                        COALESCE((SELECT SUM(quantity) FROM cart WHERE model_id_fk = ? AND product_sl_id_fk IS NULL), 0)
                    AS available";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iii", $model_id, $model_id, $model_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $available = (int)($row['available'] ?? 0);
            send_json(['status' => 'success', 'available' => $available]);
            break;

        case 'get_avg_max_price':
            $model_id = (int)($_GET['model_id'] ?? 0);

            $sql = "SELECT COALESCE(AVG(unit_price), 0) AS avg_price, COALESCE(MAX(unit_price), 0) AS max_price
                    FROM purchased_products WHERE model_id = ? AND is_deleted = 0";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $model_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            send_json([
                'status' => 'success',
                'avg' => (float)($row['avg_price'] ?? 0.00),
                'max' => (float)($row['max_price'] ?? 0.00)
            ]);
            break;

        case 'get_serials_for_model':
            $model_id = (int)($_GET['model_id'] ?? 0);
            $sql = "SELECT sl.sl_id, sl.product_sl
                    FROM product_sl sl
                    LEFT JOIN cart ct ON sl.sl_id = ct.product_sl_id_fk
                    WHERE sl.model_id_fk = ?
                      AND sl.status = 0
                      AND ct.cart_id IS NULL
                    ORDER BY sl.product_sl";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $model_id);
            $stmt->execute();
            $serials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'serials' => $serials]);
            break;

        case 'get_cart_contents':
            $sql = "SELECT 
                        c.cart_id,
                        c.model_id_fk,
                        c.product_sl_id_fk,
                        c.quantity,
                        c.sale_price,
                        m.model_name,
                        b.brand_name,
                        cat.category_name,
                        
                        (SELECT p.product_sl FROM product_sl p WHERE p.sl_id = c.product_sl_id_fk LIMIT 1) AS product_sl,
                        (CASE
                            WHEN c.product_sl_id_fk IS NOT NULL THEN (
                                SELECT pp.warranty_period
                                FROM purchased_products pp
                                WHERE pp.purchase_id = (
                                    SELECT ps.purchase_id_fk FROM product_sl ps WHERE ps.sl_id = c.product_sl_id_fk LIMIT 1
                                )
                                LIMIT 1
                            )
                            ELSE COALESCE('', '')
                        END) AS warranty_period
                    FROM cart c
                    JOIN models m ON c.model_id_fk = m.model_id
                    JOIN brands b ON m.brand_id = b.brand_id
                    JOIN categories cat ON m.category_id = cat.category_id
                    WHERE c.user_id_fk = ?
                    ORDER BY c.cart_id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $current_user_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'rows' => $rows]);
            break;

        case 'get_client_work_orders':
            $client_mix = $_GET['client_mix'] ?? '';
            $orders = [];

            if ($client_mix) {
                $head_id = 0;
                $branch_id = 0;

                if (strpos($client_mix, 'head_') === 0) {
                    $head_id = (int)str_replace('head_', '', $client_mix);
                    
                    $sql = "SELECT work_order_id, Order_No, Order_Date 
                            FROM work_order 
                            WHERE client_head_id_fk = ? 
                              AND is_deleted = 0 
                            ORDER BY work_order_id DESC";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $head_id);

                } else {
                    $branch_id = (int)$client_mix;
                    
                    $stmt_h = $conn->prepare("SELECT client_head_id_fk FROM client_branch WHERE client_branch_id = ?");
                    $stmt_h->bind_param("i", $branch_id);
                    $stmt_h->execute();
                    $res_h = $stmt_h->get_result();
                    if ($r_h = $res_h->fetch_assoc()) {
                        $head_id = (int)$r_h['client_head_id_fk'];
                    }
                    $stmt_h->close();

                    $sql = "SELECT work_order_id, Order_No, Order_Date 
                            FROM work_order 
                            WHERE (client_branch_id_fk = ? OR client_head_id_fk = ?) 
                              AND is_deleted = 0 
                            ORDER BY work_order_id DESC";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ii", $branch_id, $head_id);
                }

                if (isset($stmt) && $stmt) {
                    $stmt->execute();
                    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                }
            }
            send_json(['status' => 'success', 'orders' => $orders]);
            break;


        case 'add_to_cart':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            $data = $_POST;

            $model_id_fk = (int)($data['model_id_fk'] ?? 0);
            $unit_price = isset($data['Sold_Unit_Price']) ? (float)$data['Sold_Unit_Price'] : -1.0;

            if ($model_id_fk <= 0 || $unit_price < 0) {
                throw new Exception("Invalid model or price.");
            }

            $product_sl_ids = $data['product_sl_id_fk'] ?? null;
            if (!is_null($product_sl_ids) && !is_array($product_sl_ids)) {
                $product_sl_ids = [$product_sl_ids];
            }

            $conn->begin_transaction();
            $inTransaction = true;

            $sql_insert_serial = "INSERT INTO cart (model_id_fk, product_sl_id_fk, quantity, sale_price, user_id_fk)
                                  VALUES (?, ?, 1, ?, ?)";
            $stmt_insert_serial = $conn->prepare($sql_insert_serial);

            $sql_insert_bulk = "INSERT INTO cart (model_id_fk, product_sl_id_fk, quantity, sale_price, user_id_fk)
                                VALUES (?, NULL, ?, ?, ?)";
            $stmt_insert_bulk = $conn->prepare($sql_insert_bulk);

            $sql_update_sl_reserve = "UPDATE product_sl SET status = 1 WHERE sl_id = ? AND status = 0";
            $stmt_update_sl_reserve = $conn->prepare($sql_update_sl_reserve);

            $message = '';

            if (is_array($product_sl_ids) && count($product_sl_ids) > 0) {
                $added = 0;
                foreach ($product_sl_ids as $raw) {
                    $serial_id = (int)$raw;
                    if ($serial_id <= 0) {
                        throw new Exception("Invalid serial id provided.");
                    }

                    $chk = $conn->prepare("SELECT 1 FROM cart WHERE product_sl_id_fk = ? LIMIT 1");
                    $chk->bind_param("i", $serial_id);
                    $chk->execute();
                    $exists = (bool)$chk->get_result()->fetch_row();
                    $chk->close();
                    if ($exists) {
                        throw new Exception("Serial #{$serial_id} is already reserved in a cart.");
                    }

                    $stmt_update_sl_reserve->bind_param("i", $serial_id);
                    $stmt_update_sl_reserve->execute();
                    if ($stmt_update_sl_reserve->affected_rows === 0) {
                        throw new Exception("Serial #{$serial_id} is no longer available.");
                    }

                    $stmt_insert_serial->bind_param("iidi", $model_id_fk, $serial_id, $unit_price, $current_user_id);
                    $stmt_insert_serial->execute();

                    $added++;
                }
                $message = "Added {$added} serial item(s) to cart.";

                $stmt_insert_serial->close();
                $stmt_update_sl_reserve->close();
                $stmt_insert_bulk->close();
            } else {
                $quantity = isset($data['Quantity']) ? (int)$data['Quantity'] : 0;
                if ($quantity <= 0) {
                    throw new Exception("Quantity must be greater than 0.");
                }

                $sql_check = "SELECT
                                COALESCE((SELECT SUM(quantity) FROM purchased_products WHERE model_id = ? AND is_deleted = 0), 0)
                                -
                                COALESCE((SELECT SUM(Quantity) FROM sold_product WHERE model_id_fk = ? AND is_deleted = 0 AND product_sl_id_fk IS NULL), 0)
                                -
                                COALESCE((SELECT SUM(quantity) FROM cart WHERE model_id_fk = ? AND product_sl_id_fk IS NULL), 0)
                              AS available";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->bind_param("iii", $model_id_fk, $model_id_fk, $model_id_fk);
                $stmt_check->execute();
                $row = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();
                $available = (int)($row['available'] ?? 0);

                if ($quantity > $available) {
                    throw new Exception("Stock check failed: Quantity ({$quantity}) exceeds available stock ({$available}).");
                }

                $stmt_insert_bulk->bind_param("iidi", $model_id_fk, $quantity, $unit_price, $current_user_id);
                $stmt_insert_bulk->execute();
                $message = "Added {$quantity} item(s) to cart.";

                $stmt_insert_bulk->close();
                $stmt_insert_serial->close();
                $stmt_update_sl_reserve->close();
            }

            $conn->commit();
            $inTransaction = false;
            send_json(['status' => 'success', 'message' => $message]);
            break;

        case 'remove_from_cart':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            $cart_id = (int)($_POST['cart_id'] ?? 0);
            if ($cart_id <= 0) throw new Exception("Invalid cart item ID.");

            $conn->begin_transaction();
            $inTransaction = true;

            $stmt_get = $conn->prepare("SELECT product_sl_id_fk FROM cart WHERE cart_id = ? AND user_id_fk = ?");
            $stmt_get->bind_param("ii", $cart_id, $current_user_id);
            $stmt_get->execute();
            $row = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();

            $serial_id = $row['product_sl_id_fk'] ?? null;

            $stmt_del = $conn->prepare("DELETE FROM cart WHERE cart_id = ? AND user_id_fk = ?");
            $stmt_del->bind_param("ii", $cart_id, $current_user_id);
            $stmt_del->execute();
            $affected = $stmt_del->affected_rows;
            $stmt_del->close();

            if ($affected > 0 && $serial_id) {
                $stmt_unreserve = $conn->prepare("UPDATE product_sl SET status = 0 WHERE sl_id = ?");
                $stmt_unreserve->bind_param("i", $serial_id);
                $stmt_unreserve->execute();
                $stmt_unreserve->close();
            }

            $conn->commit();
            $inTransaction = false;
            send_json(['status' => 'success', 'message' => 'Item removed from cart.']);
            break;

        default:
            send_json(['status' => 'error', 'message' => 'Invalid action specified.']);
    }

} catch (mysqli_sql_exception $e) {
    if ($inTransaction) {
        $conn->rollback();
    }
    error_log("mysqli_sql_exception in cart_ajax.php: " . $e->getMessage() . " (line " . $e->getLine() . ")");
    send_json(['status' => 'error', 'message' => 'A database error occurred. Please try again. (Code: ' . $e->getCode() . ')']);
} catch (Exception $e) {
    if ($inTransaction) {
        $conn->rollback();
    }
    error_log("Exception in cart_ajax.php: " . $e->getMessage() . " (line " . $e->getLine() . ")");
    send_json(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();

function send_json($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}