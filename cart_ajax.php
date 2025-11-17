<?php
session_start();
// --- JSON header & error reporting (errors logged, not shown) ---
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'connection.php'; // expects $conn (mysqli)

// Authentication check
if (!isset($_SESSION['user_id'])) {
    send_json(['status' => 'error', 'message' => 'Not authenticated. Please log in.']);
}
$current_user_id = (int)$_SESSION['user_id'];

// Router
$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

// Flag for transaction handling
$inTransaction = false;

try {
    // Make mysqli throw exceptions
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    switch ($action) {

        // --- get brands by category ---
        case 'get_brands_by_category':
            $category_id = (int)($_GET['category_id'] ?? 0);
            $stmt = $conn->prepare("SELECT brand_id, brand_name FROM brands WHERE category_id = ? AND is_deleted = 0 ORDER BY brand_name");
            $stmt->bind_param("i", $category_id);
            $stmt->execute();
            $brands = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'brands' => $brands]);
            break;

        // --- get models by brand ---
        case 'get_models_by_brand':
            $brand_id = (int)($_GET['brand_id'] ?? 0);
            $stmt = $conn->prepare("SELECT model_id, model_name FROM models WHERE brand_id = ? AND is_deleted = 0 ORDER BY model_name");
            $stmt->bind_param("i", $brand_id);
            $stmt->execute();
            $models = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'models' => $models]);
            break;

        // --- available quantity (non-serial) ---
        case 'available_quantity':
            $model_id = (int)($_GET['model_id'] ?? 0);

            // NOTE: purchased_products uses model_id (per your earlier schema dump)
            // sold_product uses model_id_fk, cart uses model_id_fk
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

        // --- avg & max purchase price for model ---
        case 'get_avg_max_price':
            $model_id = (int)($_GET['model_id'] ?? 0);

            // Using purchased_products.model_id per schema
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

        // --- list available serials for a model ---
        case 'get_serials_for_model':
            $model_id = (int)($_GET['model_id'] ?? 0);

            // product_sl uses model_id_fk and has 'status' column (no is_sold/is_deleted in your schema dump)
            // left join with cart to ensure serial isn't already in someone's cart
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

        // --- get cart contents for current user ---
        case 'get_cart_contents':
            $sql = "SELECT 
                        c.cart_id, c.model_id_fk, c.product_sl_id_fk, c.quantity, c.sale_price,
                        m.model_name,
                        b.brand_name,
                        cat.category_name,
                        psl.product_sl
                    FROM cart c
                    JOIN models m ON c.model_id_fk = m.model_id
                    JOIN brands b ON m.brand_id = b.brand_id
                    JOIN categories cat ON m.category_id = cat.category_id
                    LEFT JOIN product_sl psl ON c.product_sl_id_fk = psl.sl_id
                    WHERE c.user_id_fk = ?
                    ORDER BY c.cart_id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $current_user_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'rows' => $rows]);
            break;

        // --- add to cart (serial or bulk) ---
        case 'add_to_cart':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            $data = $_POST;

            $model_id_fk = (int)($data['model_id_fk'] ?? 0);
            $unit_price = isset($data['Sold_Unit_Price']) ? (float)$data['Sold_Unit_Price'] : -1.0;

            if ($model_id_fk <= 0 || $unit_price < 0) {
                throw new Exception("Invalid model or price.");
            }

            // Normalize serial IDs (may be array or single value)
            $product_sl_ids = $data['product_sl_id_fk'] ?? null;
            if (!is_null($product_sl_ids) && !is_array($product_sl_ids)) {
                $product_sl_ids = [$product_sl_ids];
            }

            // Start transaction
            $conn->begin_transaction();
            $inTransaction = true;

            // Prepare statements:
            // 1) Insert serial item (quantity = 1)
            $sql_insert_serial = "INSERT INTO cart (model_id_fk, product_sl_id_fk, quantity, sale_price, user_id_fk)
                                  VALUES (?, ?, 1, ?, ?)";
            $stmt_insert_serial = $conn->prepare($sql_insert_serial);

            // 2) Insert bulk item (product_sl_id_fk = NULL)
            $sql_insert_bulk = "INSERT INTO cart (model_id_fk, product_sl_id_fk, quantity, sale_price, user_id_fk)
                                VALUES (?, NULL, ?, ?, ?)";
            $stmt_insert_bulk = $conn->prepare($sql_insert_bulk);

            // 3) Reserve serial via status (product_sl.status)
            $sql_update_sl_reserve = "UPDATE product_sl SET status = 1 WHERE sl_id = ? AND status = 0";
            $stmt_update_sl_reserve = $conn->prepare($sql_update_sl_reserve);

            $message = '';

            if (is_array($product_sl_ids) && count($product_sl_ids) > 0) {
                // Serial path
                $added = 0;
                foreach ($product_sl_ids as $raw) {
                    $serial_id = (int)$raw;
                    if ($serial_id <= 0) {
                        throw new Exception("Invalid serial id provided.");
                    }

                    // Ensure serial not already present in cart
                    $chk = $conn->prepare("SELECT 1 FROM cart WHERE product_sl_id_fk = ? LIMIT 1");
                    $chk->bind_param("i", $serial_id);
                    $chk->execute();
                    $exists = (bool)$chk->get_result()->fetch_row();
                    $chk->close();
                    if ($exists) {
                        throw new Exception("Serial #{$serial_id} is already reserved in a cart.");
                    }

                    // Reserve the serial (concurrency-safe)
                    $stmt_update_sl_reserve->bind_param("i", $serial_id);
                    $stmt_update_sl_reserve->execute();
                    if ($stmt_update_sl_reserve->affected_rows === 0) {
                        throw new Exception("Serial #{$serial_id} is no longer available.");
                    }

                    // Insert into cart
                    $stmt_insert_serial->bind_param("iidi", $model_id_fk, $serial_id, $unit_price, $current_user_id);
                    $stmt_insert_serial->execute();

                    $added++;
                }
                $message = "Added {$added} serial item(s) to cart.";

                // close statements used
                $stmt_insert_serial->close();
                $stmt_update_sl_reserve->close();
                $stmt_insert_bulk->close();
            } else {
                // Bulk path
                $quantity = isset($data['Quantity']) ? (int)$data['Quantity'] : 0;
                if ($quantity <= 0) {
                    throw new Exception("Quantity must be greater than 0.");
                }

                // Stock check (purchased_products.model_id per schema)
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

                // Insert bulk with NULL product_sl_id_fk
                $stmt_insert_bulk->bind_param("iidi", $model_id_fk, $quantity, $unit_price, $current_user_id);
                $stmt_insert_bulk->execute();
                $message = "Added {$quantity} item(s) to cart.";

                // close statements used
                $stmt_insert_bulk->close();
                $stmt_insert_serial->close();
                $stmt_update_sl_reserve->close();
            }

            // commit
            $conn->commit();
            $inTransaction = false;
            send_json(['status' => 'success', 'message' => $message]);
            break;

        // --- remove from cart ---
        case 'remove_from_cart':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            $cart_id = (int)($_POST['cart_id'] ?? 0);
            if ($cart_id <= 0) throw new Exception("Invalid cart item ID.");

            $conn->begin_transaction();
            $inTransaction = true;

            // Find product_sl_id_fk for this item and verify user
            $stmt_get = $conn->prepare("SELECT product_sl_id_fk FROM cart WHERE cart_id = ? AND user_id_fk = ?");
            $stmt_get->bind_param("ii", $cart_id, $current_user_id);
            $stmt_get->execute();
            $row = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();

            $serial_id = $row['product_sl_id_fk'] ?? null;

            // Delete the cart item
            $stmt_del = $conn->prepare("DELETE FROM cart WHERE cart_id = ? AND user_id_fk = ?");
            $stmt_del->bind_param("ii", $cart_id, $current_user_id);
            $stmt_del->execute();
            $affected = $stmt_del->affected_rows;
            $stmt_del->close();

            if ($affected > 0 && $serial_id) {
                // Unreserve serial (set status back to 0)
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

// close connection
$conn->close();

/**
 * Helper: send JSON and exit
 */
function send_json($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
