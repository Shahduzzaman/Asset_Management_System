<?php
session_start();
// --- FIX: Force JSON content-type and error reporting ---
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
    // --- Force mysqli to throw exceptions ---
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $inTransaction = false;

    switch ($action) {
        // --- GET REQUESTS ---
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
            send_json(['status' => 'success', 'models' => $models]);
            break;

        case 'available_quantity':
            $model_id = (int)$_GET['model_id'];

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
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            send_json(['status' => 'success', 'available' => (int)($result['available'] ?? 0)]);
            break;

        case 'get_avg_max_price':
            $model_id = (int)$_GET['model_id'];
            $sql = "SELECT COALESCE(AVG(unit_price), 0) AS avg_price, COALESCE(MAX(unit_price), 0) AS max_price
                    FROM purchased_products WHERE model_id = ? AND is_deleted = 0";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $model_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            send_json(['status' => 'success', 'avg' => (float)$result['avg_price'], 'max' => (float)$result['max_price']]);
            break;

        case 'get_serials_for_model':
            $model_id = (int)$_GET['model_id'];
            // PRODUCT_SL uses model_id_fk and has 'status' column (no is_sold / no is_deleted)
            // We only check status = 0 and that the serial isn't already in cart (ct.cart_id IS NULL).
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
            $result = $stmt->get_result();
            $serials = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            send_json(['status' => 'success', 'serials' => $serials]);
            break;

        // --- POST REQUESTS ---
        case 'add_to_cart':
            if ($method !== 'POST') send_json(['status' => 'error', 'message' => 'Invalid request method']);
            $data = $_POST;

            // Common data
            $model_id_fk = (int)($data['model_id_fk'] ?? 0);
            $unit_price = isset($data['Sold_Unit_Price']) ? (float)$data['Sold_Unit_Price'] : -1;

            if ($model_id_fk <= 0 || $unit_price < 0) {
                throw new Exception("Invalid model or price.");
            }

            // Normalize product_sl_id_fk (may be array or single value)
            $product_sl_ids = $data['product_sl_id_fk'] ?? null;
            if (!is_null($product_sl_ids) && !is_array($product_sl_ids)) {
                $product_sl_ids = [$product_sl_ids];
            }

            // Begin transaction
            $conn->begin_transaction();
            $inTransaction = true;

            // Prepared statements:
            // Insert for serial items (cart columns: model_id_fk, product_sl_id_fk, quantity, sale_price, user_id_fk)
            $sql_insert_serial = "INSERT INTO cart (model_id_fk, product_sl_id_fk, quantity, sale_price, user_id_fk)
                                  VALUES (?, ?, 1, ?, ?)";
            $stmt_insert_serial = $conn->prepare($sql_insert_serial);

            // Insert for bulk items (explicit NULL for product_sl_id_fk)
            $sql_insert_bulk = "INSERT INTO cart (model_id_fk, product_sl_id_fk, quantity, sale_price, user_id_fk)
                                VALUES (?, NULL, ?, ?, ?)";
            $stmt_insert_bulk = $conn->prepare($sql_insert_bulk);

            // Update product_sl to reserve it — use 'status' only (no is_deleted)
            $sql_update_sl = "UPDATE product_sl
                              SET status = 1
                              WHERE sl_id = ? AND status = 0";
            $stmt_update_sl = $conn->prepare($sql_update_sl);

            $message = "";

            if (is_array($product_sl_ids) && count($product_sl_ids) > 0) {
                // Serial flow
                $added_count = 0;
                foreach ($product_sl_ids as $serial_raw) {
                    $serial_id = (int)$serial_raw;
                    if ($serial_id <= 0) {
                        throw new Exception("Invalid serial id provided.");
                    }

                    // Ensure serial not already reserved in cart
                    $chk = $conn->prepare("SELECT 1 FROM cart WHERE product_sl_id_fk = ? LIMIT 1");
                    $chk->bind_param("i", $serial_id);
                    $chk->execute();
                    $resChk = $chk->get_result();
                    if ($resChk->num_rows > 0) {
                        $chk->close();
                        throw new Exception("Serial #{$serial_id} is already reserved in cart.");
                    }
                    $chk->close();

                    // Reserve the serial (concurrency-safe)
                    $stmt_update_sl->bind_param("i", $serial_id);
                    $stmt_update_sl->execute();
                    if ($stmt_update_sl->affected_rows === 0) {
                        throw new Exception("Serial #{$serial_id} is no longer available.");
                    }

                    // Insert into cart (use sale_price column and user_id_fk)
                    $stmt_insert_serial->bind_param("iidi", $model_id_fk, $serial_id, $unit_price, $current_user_id);
                    $stmt_insert_serial->execute();

                    $added_count++;
                }
                $message = "Added {$added_count} serial item(s) to cart.";

                // Close statements
                $stmt_insert_serial->close();
                $stmt_update_sl->close();
                $stmt_insert_bulk->close();
            } else {
                // Bulk flow (no serial)
                $quantity = isset($data['Quantity']) ? (int)$data['Quantity'] : 0;
                if ($quantity <= 0) {
                    throw new Exception("Quantity must be greater than 0.");
                }

                // Stock check
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
                $available = (int)$stmt_check->get_result()->fetch_assoc()['available'];
                $stmt_check->close();

                if ($quantity > $available) {
                    throw new Exception("Stock check failed: Quantity ({$quantity}) exceeds available stock ({$available}).");
                }

                // Insert bulk with explicit NULL product_sl_id_fk (use sale_price and user_id_fk)
                $stmt_insert_bulk->bind_param("iidi", $model_id_fk, $quantity, $unit_price, $current_user_id);
                $stmt_insert_bulk->execute();
                $message = "Added {$quantity} item(s) to cart.";

                // Close statements
                $stmt_insert_bulk->close();
                $stmt_insert_serial->close();
                $stmt_update_sl->close();
            }

            $conn->commit();
            $inTransaction = false;
            send_json(['status' => 'success', 'message' => $message]);
            break;

        default:
            send_json(['status' => 'error', 'message' => 'Invalid action specified.']);
    }
} catch (mysqli_sql_exception $e) {
    // Database error: rollback if in transaction, log details, but send safe message
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

// Close the main connection
$conn->close();

/**
 * Helper function to send JSON response and exit.
 * @param array $data The data to encode as JSON.
 */
function send_json($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
