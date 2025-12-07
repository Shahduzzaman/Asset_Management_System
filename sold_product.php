<?php
session_start();
require_once 'connection.php'; // expects $conn (mysqli)

// Session idle timeout handling
$idleTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset();
    session_destroy();
    header("Location: index.php?reason=idle");
    exit();
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$error_message = '';
$success_message = '';

function log_error_msg($msg) {
    error_log($msg);
}

/**
 * ---------- SIMPLE HELPERS ----------
 */

// Generate sequential invoice number with prefix P1EQINV000001 style
function generate_invoice_no(mysqli $conn, $prefix = 'P1EQINV') {
    $like = $prefix . '%';
    $stmt = $conn->prepare("SELECT Invoice_No FROM invoice WHERE Invoice_No LIKE ? ORDER BY invoice_id DESC LIMIT 1");
    if (!$stmt) {
        throw new Exception("Prepare failed (last invoice): " . $conn->error);
    }
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $r = $stmt->get_result();
    $next = 1;
    if ($row = $r->fetch_assoc()) {
        $last = $row['Invoice_No'];
        $n = (int)str_replace($prefix, '', $last);
        $next = $n + 1;
    }
    $stmt->close();
    return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
}

/**
 * If no work_order chosen, ensure a fallback work_order exists for this client_head_id.
 * Returns a valid work_order_id (existing or newly created).
 */
function ensure_manual_work_order(mysqli $conn, ?int $client_head_id, int $created_by) : int {
    // We require a client_head_id to attach. If client_head_id is null, fallback to first work_order in DB.
    if (!$client_head_id) {
        // try to find any work_order
        $stmt = $conn->prepare("SELECT work_order_id FROM work_order ORDER BY work_order_id LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $id = (int)$row['work_order_id'];
                $stmt->close();
                return $id;
            }
            $stmt->close();
        }
        // if none, create a generic entry with client_head_id NULL
        $orderNo = 'MANUAL-SALE-' . time();
        $orderDate = date('Y-m-d');
        $stmt = $conn->prepare("INSERT INTO work_order (Order_No, Order_Date, client_head_id_fk, client_branch_id_fk, created_by, created_at) VALUES (?, ?, NULL, NULL, ?, NOW())");
        if (!$stmt) throw new Exception("Prepare create manual work_order failed: " . $conn->error);
        $stmt->bind_param("ssi", $orderNo, $orderDate, $created_by);
        if (!$stmt->execute()) throw new Exception("Execute create manual work_order failed: " . $stmt->error);
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id;
    }

    // Look for an existing manual work order for this client head
    $like = 'MANUAL-SALE-%';
    $stmt = $conn->prepare("SELECT work_order_id FROM work_order WHERE client_head_id_fk = ? AND Order_No LIKE ? LIMIT 1");
    if (!$stmt) throw new Exception("Prepare check manual_work_order failed: " . $conn->error);
    $stmt->bind_param("is", $client_head_id, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $id = (int)$row['work_order_id'];
        $stmt->close();
        return $id;
    }
    $stmt->close();

    // else create one
    $orderNo = 'MANUAL-SALE-' . $client_head_id . '-' . time();
    $orderDate = date('Y-m-d');
    $stmt = $conn->prepare("INSERT INTO work_order (Order_No, Order_Date, client_head_id_fk, client_branch_id_fk, created_by, created_at) VALUES (?, ?, ?, NULL, ?, NOW())");
    if (!$stmt) throw new Exception("Prepare create manual work_order failed: " . $conn->error);
    $stmt->bind_param("siii", $orderNo, $orderDate, $client_head_id, $created_by);
    if (!$stmt->execute()) throw new Exception("Execute create manual work_order failed: " . $stmt->error);
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * ---------- POST: Complete sale ----------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sell_product'])) {
    // Inputs from form
    $client_branch_raw = $_POST['client_branch_id'] ?? '';
    $client_head_id = isset($_POST['client_head_id']) && $_POST['client_head_id'] !== '' ? (int)$_POST['client_head_id'] : null;
    $client_branch_id = null;
    if ($client_branch_raw !== '') {
        if (preg_match('/^head_(\d+)$/', $client_branch_raw, $m)) {
            $client_branch_id = null;
            if (!$client_head_id) $client_head_id = (int)$m[1];
        } else {
            $client_branch_id = (int)$client_branch_raw;
        }
    }

    $work_order_id = isset($_POST['work_order_id']) && $_POST['work_order_id'] !== '' ? (int)$_POST['work_order_id'] : null;
    $sale_date = !empty($_POST['sale_date']) ? $_POST['sale_date'] : date('Y-m-d');

    // Financials
    $sub_total = isset($_POST['sub_total']) ? (float)$_POST['sub_total'] : 0.0;
    $tax_percent = isset($_POST['tax_percent']) ? (float)$_POST['tax_percent'] : 0.0;
    $discount = isset($_POST['discount']) ? (float)$_POST['discount'] : 0.0;

    // Apply discount to sub_total to get ExcludingTax_TotalPrice
    $excl = $sub_total - $discount;
    if ($excl < 0) $excl = 0;
    $tax_amount = ($excl * ($tax_percent / 100.0));
    $incl = $excl + $tax_amount;

    // Start transaction
    $conn->begin_transaction();
    try {
        // 1) Lock & read cart for user (non-aggregated fetch for selling)
        $stmt = $conn->prepare("SELECT * FROM cart WHERE user_id_fk = ? FOR UPDATE");
        if (!$stmt) throw new Exception("Prepare failed (select cart): " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $cart_res = $stmt->get_result();
        $stmt->close();

        if ($cart_res->num_rows == 0) {
            throw new Exception("Cart is empty. Nothing to sell.");
        }

        // 2) Generate invoice and insert
        $invoice_no = generate_invoice_no($conn);

        $stmtIns = $conn->prepare("INSERT INTO invoice (Invoice_No, IncludingTax_TotalPrice, ExcludingTax_TotalPrice, Tax_Percentage, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        if (!$stmtIns) throw new Exception("Prepare invoice insert failed: " . $conn->error);
        $stmtIns->bind_param("sdddi", $invoice_no, $incl, $excl, $tax_percent, $user_id);
        if (!$stmtIns->execute()) throw new Exception("Execute invoice insert failed: " . $stmtIns->error);
        $new_invoice_id = (int)$conn->insert_id;
        $stmtIns->close();

        // 3) Prepare sold_product insert and product_sl update statements
        $stmt_ins_sold = $conn->prepare("
            INSERT INTO sold_product
            (invoice_id_fk, product_sl_id_fk, model_id_fk, Quantity,
            Sold_Unit_Price, Avg_Max_Price, Remarks, created_by,
            sold_date, work_order_id_fk, client_branch_id_fk, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if (!$stmt_ins_sold) {
            throw new Exception("Prepare sold_product insert failed: " . $conn->error);
        }
        $product_sl_status_col = 'status';
        $product_sl_table_id = 'sl_id';
        $stmt_upd_sl = $conn->prepare("UPDATE product_sl SET {$product_sl_status_col} = 2 WHERE {$product_sl_table_id} = ? AND {$product_sl_status_col} IN (0,1)");
        if (!$stmt_upd_sl) throw new Exception("Prepare product_sl update failed: " . $conn->error);

        // average/max price stmt (based on column model_id in purchased_products)
        $stmt_avg = $conn->prepare("SELECT COALESCE(AVG(unit_price),0) AS avg_price, COALESCE(MAX(unit_price),0) AS max_price FROM purchased_products WHERE model_id = ? AND is_deleted = 0");
        if (!$stmt_avg) throw new Exception("Prepare avg price failed: " . $conn->error);

        // If work_order not provided, ensure a manual one exists (to satisfy FK)
        if (empty($work_order_id)) {
            $work_order_id = ensure_manual_work_order($conn, $client_head_id, $user_id);
        }

        // 4) Loop cart rows and insert sold_product rows
        while ($cart_row = $cart_res->fetch_assoc()) {
            $cart_model_id = isset($cart_row['model_id_fk']) ? (int)$cart_row['model_id_fk'] : (isset($cart_row['model_id']) ? (int)$cart_row['model_id'] : null);
            $cart_sl_id = isset($cart_row['product_sl_id_fk']) ? ($cart_row['product_sl_id_fk'] !== '' ? (int)$cart_row['product_sl_id_fk'] : null) : null;
            $cart_qty = isset($cart_row['quantity']) ? (int)$cart_row['quantity'] : 1;
            $unitPrice = isset($cart_row['sale_price']) ? (float)$cart_row['sale_price'] : (isset($cart_row['unit_price']) ? (float)$cart_row['unit_price'] : 0.0);

            // compute avg|max
            $avg_max_text = '';
            if ($cart_model_id) {
                $stmt_avg->bind_param("i", $cart_model_id);
                $stmt_avg->execute();
                $resAvg = $stmt_avg->get_result()->fetch_assoc();
                $avgP = number_format((float)$resAvg['avg_price'], 2, '.', '');
                $maxP = number_format((float)$resAvg['max_price'], 2, '.', '');
                $avg_max_text = "{$avgP}|{$maxP}";
            }

            // If serial exists, check its status FOR UPDATE
            if ($cart_sl_id) {
                $stmt_check_sl = $conn->prepare("SELECT status FROM product_sl WHERE sl_id = ? FOR UPDATE");
                if (!$stmt_check_sl) throw new Exception("Prepare product_sl check failed: " . $conn->error);
                $stmt_check_sl->bind_param("i", $cart_sl_id);
                $stmt_check_sl->execute();
                $rsl = $stmt_check_sl->get_result();
                if ($rsl->num_rows == 0) {
                    throw new Exception("Serial not found (sl_id={$cart_sl_id}).");
                }
                $srow = $rsl->fetch_assoc();
                $sstatus = (int)$srow['status'];
                if (!in_array($sstatus, [0,1], true)) {
                    throw new Exception("Serial sl_id={$cart_sl_id} has invalid status ({$sstatus}).");
                }
                $stmt_check_sl->close();
            }

            $remarks = '';

            // Bind and execute sold_product insert
            $stmt_ins_sold->bind_param(
                "iiiidssiisi",
                $new_invoice_id,
                $cart_sl_id,
                $cart_model_id,
                $cart_qty,
                $unitPrice,
                $avg_max_text,
                $remarks,
                $user_id,
                $sale_date,
                $work_order_id,
                $client_branch_id
            );

            if (!$stmt_ins_sold->execute()) {
                throw new Exception("Insert into sold_product failed: " . $stmt_ins_sold->error);
            }

            // Mark product_sl as sold when serial exists
            if ($cart_sl_id) {
                $stmt_upd_sl->bind_param("i", $cart_sl_id);
                $stmt_upd_sl->execute();
                if ($stmt_upd_sl->affected_rows === 0) {
                    throw new Exception("Failed to mark serial sold (sl_id={$cart_sl_id}). Possible concurrency issue.");
                }
            }
        } // end while cart

        // close prepared statements
        $stmt_ins_sold->close();
        $stmt_upd_sl->close();
        $stmt_avg->close();

        // 5) Clear cart
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id_fk = ?");
        if (!$stmt) throw new Exception("Prepare clear cart failed: " . $conn->error);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $success_message = "Sale successful! Invoice: {$invoice_no} (ID: {$new_invoice_id}).";

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Transaction failed: " . $e->getMessage();
        log_error_msg("sold_product.php transaction error: " . $e->getMessage());
    }
}

/**
 * ---------- GET: build data for UI ----------
 */

// Build client list: company + branches (company-first layout)
$clients_struct = [];
$sql_heads = "SELECT ch.client_head_id, ch.Company_Name, cb.client_branch_id, cb.Branch_Name, cb.is_deleted AS branch_is_deleted
              FROM client_head ch
              LEFT JOIN client_branch cb ON cb.client_head_id_fk = ch.client_head_id
              WHERE ch.is_deleted = 0
              ORDER BY ch.Company_Name, cb.Branch_Name";
$res_heads = $conn->query($sql_heads);
if ($res_heads) {
    while ($r = $res_heads->fetch_assoc()) {
        $hid = (int)$r['client_head_id'];
        if (!isset($clients_struct[$hid])) {
            $clients_struct[$hid] = [
                'company_name' => $r['Company_Name'],
                'branches' => []
            ];
        }
        if (!is_null($r['client_branch_id']) && (int)$r['branch_is_deleted'] === 0) {
            $clients_struct[$hid]['branches'][] = [
                'client_branch_id' => (int)$r['client_branch_id'],
                'branch_name' => $r['Branch_Name']
            ];
        }
    }
    $res_heads->close();
}

// Cart items: group by model and aggregate serials, warranties
$cart_items = [];
$cart_sub_total = 0.0;
$sql_cart_view = "
    SELECT 
        m.model_id,
        cat.category_name,
        b.brand_name,
        m.model_name,
        COALESCE(GROUP_CONCAT(DISTINCT product_sl.product_sl ORDER BY c.cart_id SEPARATOR ', '), '') AS serials,
        COALESCE(GROUP_CONCAT(DISTINCT pp.warranty_period SEPARATOR ', '), '') AS warranty_concat,
        SUM(c.quantity) AS quantity,
        COALESCE(SUM(c.quantity * COALESCE(c.sale_price,0)),0) AS total_price,
        -- unit price display: if mixed prices exist we show average; this is only for display
        COALESCE(AVG(COALESCE(c.sale_price,0)),0) AS unit_price
    FROM cart c
    JOIN models m ON c.model_id_fk = m.model_id
    JOIN brands b ON m.brand_id = b.brand_id
    JOIN categories cat ON m.category_id = cat.category_id
    LEFT JOIN product_sl ON c.product_sl_id_fk = product_sl.sl_id
    LEFT JOIN purchased_products pp ON product_sl.purchase_id_fk = pp.purchase_id
    WHERE c.user_id_fk = ?
    GROUP BY m.model_id, cat.category_name, b.brand_name, m.model_name
    ORDER BY MIN(c.cart_id) ASC
";
if ($stmt_cart = $conn->prepare($sql_cart_view)) {
    $stmt_cart->bind_param('i', $user_id);
    $stmt_cart->execute();
    $cart_view_result = $stmt_cart->get_result();
    while ($row = $cart_view_result->fetch_assoc()) {
        // Normalize empty strings to nulls for display
        if ($row['serials'] === '') $row['serials'] = null;
        if ($row['warranty_concat'] === '') $row['warranty_concat'] = null;

        $cart_items[] = $row;
        $cart_sub_total += (float)$row['total_price'];
    }
    $stmt_cart->close();
} else {
    log_error_msg("sold_product.php: failed to prepare cart view query: " . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sell Products</title>

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        .select2-container .select2-selection--single {
            height: 2.5rem;
            border-radius: 0.375rem;
            border: 1px solid #D1D5DB;
            padding-top: 0.375rem;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 2.375rem;
        }
        .product-details-main { font-weight: 600; }
        .product-details-sub { font-size: .9rem; color: #4b5563; margin-top: .25rem; }
        .warranty-cell { font-size: .95rem; color: #111827; }

        /* header buttons spacing */
        .top-actions { margin-bottom: 1rem; display:flex; justify-content:space-between; gap:1rem; align-items:center; }
        @media (max-width:640px) {
            .top-actions { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">
<div class="container mx-auto p-8">

    <!-- Top action buttons: Back to Cart (left) and Back to Dashboard (right) -->
    <div class="top-actions">
        <div class="flex items-center">
            <a href="add_to_cart.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">
                <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i>
                <span>Back to Cart</span>
            </a>
        </div>
    </div>

    <div class="bg-white shadow-lg rounded-lg p-6 max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-6 text-gray-800 border-b pb-2">Complete Sale</h1>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">
                <strong class="font-bold">Success!</strong>
                <span class="block sm:inline"><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo htmlspecialchars($error_message); ?></span>
            </div>
        <?php endif; ?>

        <form id="sale-form" action="sold_product.php" method="POST">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <label for="client_branch_id" class="block text-sm font-medium text-gray-700 mb-1">Client (Company + Branch)</label>
                    <select id="client_branch_id" name="client_branch_id" class="w-full" required>
                        <option value="">Select a client</option>
                        <?php foreach ($clients_struct as $hid => $data): ?>
                            <?php $companyName = $data['company_name']; ?>
                            <option value="<?php echo "head_{$hid}"; ?>" data-client-head="<?php echo $hid; ?>"><?php echo htmlspecialchars($companyName . ' - Head Office'); ?></option>
                            <?php foreach ($data['branches'] as $b): ?>
                                <option value="<?php echo htmlspecialchars($b['client_branch_id']); ?>" data-client-head="<?php echo $hid; ?>"><?php echo htmlspecialchars($companyName . ' - ' . $b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" id="client_head_id" name="client_head_id" value="">
                </div>

                <div>
                    <label for="sale_date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" id="sale_date" name="sale_date" value="<?php echo date('Y-m-d'); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md" required>
                </div>
            </div>

            <div class="mb-6">
                <label for="work_order_id" class="block text-sm font-medium text-gray-700 mb-1">Work Order (Optional)</label>
                <select id="work_order_id" name="work_order_id" class="w-full" disabled>
                    <option value="">Select client first</option>
                </select>
            </div>

            <!-- Cart table (updated columns) -->
            <h2 class="text-xl font-semibold mb-4 text-gray-700">Items in Cart</h2>
            <div class="overflow-x-auto border rounded-lg mb-6">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Details</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Warranty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Qty</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($cart_items)): ?>
                            <tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">Cart is empty</td></tr>
                        <?php else: foreach ($cart_items as $item): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div class="product-details-main">
                                        <?php
                                            $cat = htmlspecialchars($item['category_name']);
                                            $brand = htmlspecialchars($item['brand_name']);
                                            $model = htmlspecialchars($item['model_name']);
                                            echo "{$cat} | {$brand} | {$model}";
                                        ?>
                                    </div>
                                    <?php if (!empty($item['serials'])): ?>
                                        <div class="product-details-sub">Serials: <?php echo htmlspecialchars($item['serials']); ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-sm warranty-cell">
                                    <?php
                                        // If multiple warranties exist (comma separated), show them deduped
                                        if (!empty($item['warranty_concat'])) {
                                            // clean up duplicates and empty strings
                                            $wparts = array_filter(array_map('trim', explode(',', $item['warranty_concat'])));
                                            $wparts = array_values(array_unique($wparts));
                                            echo htmlspecialchars(implode(', ', $wparts));
                                        } else {
                                            echo '<span class="text-gray-400">N/A</span>';
                                        }
                                    ?>
                                </td>

                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right"><?php echo htmlspecialchars($item['quantity']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-right"><?php echo number_format($item['total_price'], 2); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Financials -->
            <div class="max-w-sm ml-auto space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm font-medium text-gray-700">Sub Total:</span>
                    <span id="sub_total_display" class="text-sm font-semibold text-gray-900"><?php echo number_format($cart_sub_total, 2); ?></span>
                    <input type="hidden" id="sub_total" name="sub_total" value="<?php echo $cart_sub_total; ?>">
                </div>

                <div class="flex justify-between items-center">
                    <label for="tax_percent" class="text-sm font-medium text-gray-700">Tax (%):</label>
                    <input type="number" id="tax_percent" name="tax_percent" value="0" min="0" step="0.01"
                           class="w-24 px-2 py-1 border border-gray-300 rounded-md text-right">
                </div>

                <div class="flex justify-between items-center">
                    <label for="discount" class="text-sm font-medium text-gray-700">Discount:</label>
                    <input type="number" id="discount" name="discount" value="0" min="0" step="0.01"
                           class="w-24 px-2 py-1 border border-gray-300 rounded-md text-right">
                </div>

                <hr class="my-2">
                <div class="flex justify-between items-center">
                    <span class="text-xl font-bold text-gray-900">Grand Total:</span>
                    <span id="grand_total_display" class="text-xl font-bold text-gray-900"><?php echo number_format($cart_sub_total, 2); ?></span>
                    <input type="hidden" id="grand_total" name="grand_total" value="<?php echo $cart_sub_total; ?>">
                </div>
            </div>

            <div class="mt-8 text-right">
                <button type="submit" name="sell_product"
                        class="px-6 py-3 bg-green-600 text-white font-semibold rounded-lg"
                        <?php echo empty($cart_items) ? 'disabled' : ''; ?>>
                    <?php echo empty($cart_items) ? 'Cart is Empty' : 'Complete Sale'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#client_branch_id').select2({ placeholder: 'Select a client', width: '100%' })
        .on('select2:select', function () { $(this).trigger('change'); });

    $('#work_order_id').select2({ placeholder: 'Select a work order (optional)', width: '100%', allowClear: true });

    $('#client_branch_id').on('change', function() {
        var $opt = $(this).find('option:selected');
        var clientHead = $opt.data('client-head') || '';
        $('#client_head_id').val(clientHead);

        var $workOrderSelect = $('#work_order_id');
        $workOrderSelect.empty().append('<option value="">Loading...</option>').prop('disabled', true);

        if (clientHead) {
            $.ajax({
                url: 'api_get_work_orders.php',
                type: 'GET',
                data: { client_head_id: clientHead },
                dataType: 'json',
                success: function(data) {
                    $workOrderSelect.empty().append('<option value="">Select a work order (optional)</option>');
                    if (Array.isArray(data) && data.length > 0) {
                        $.each(data, function(i, wo) {
                            $workOrderSelect.append($('<option></option>').val(wo.work_order_id).text(wo.Order_No));
                        });
                    } else {
                        $workOrderSelect.empty().append('<option value="">No work orders found</option>');
                    }
                    $workOrderSelect.prop('disabled', false).trigger('change');
                },
                error: function(xhr) {
                    var msg = 'Error loading work orders';
                    try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                    $workOrderSelect.empty().append('<option value="">' + msg + '</option>').prop('disabled', true);
                }
            });
        } else {
            $workOrderSelect.empty().append('<option value="">Select client first</option>').prop('disabled', true);
        }
    });

    function calculateTotals() {
        var subTotal = parseFloat($('#sub_total').val()) || 0;
        var taxPercent = parseFloat($('#tax_percent').val()) || 0;
        var discount = parseFloat($('#discount').val()) || 0;

        // 1) calculate tax on the full subtotal
        var taxAmount = subTotal * (taxPercent / 100);

        // 2) total after tax (before discount)
        var totalAfterTax = subTotal + taxAmount;

        // 3) subtract discount AFTER tax
        var grandTotal = Math.max(0, totalAfterTax - discount);

        // update displays
        $('#grand_total_display').text(grandTotal.toFixed(2));
        $('#total_after_tax_display')?.text(totalAfterTax.toFixed(2)); // if exists
        $('#sub_total_display').text(subTotal.toFixed(2));
        $('#tax_amount_display')?.text(taxAmount.toFixed(2)); // if exists

        // keep hidden input in sync
        $('#grand_total').val(grandTotal.toFixed(2));
    }

    $('#tax_percent, #discount').on('keyup change', calculateTotals);
    calculateTotals();
});
</script>
</body>
</html>
