<?php
require_once 'session_guard.php';
require_once 'connection.php';

$user_id = (int)$_SESSION['user_id'];
$error_message = '';
$success_message = '';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* -----------------------------------------
   HELPERS
----------------------------------------- */

function log_error_msg($msg) {
    error_log($msg);
}

function generate_invoice_no(mysqli $conn, $prefix = 'P1EQINV') {
    $like = $prefix . '%';
    $stmt = $conn->prepare("
        SELECT Invoice_No
        FROM invoice
        WHERE Invoice_No LIKE ?
        ORDER BY invoice_id DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();

    $next = 1;
    if ($row = $res->fetch_assoc()) {
        $num = (int)str_replace($prefix, '', $row['Invoice_No']);
        $next = $num + 1;
    }
    $stmt->close();

    return $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);
}

function ensure_manual_work_order(mysqli $conn, int $client_head_id, int $user_id): int {
    $like = "MANUAL-SALE-{$client_head_id}-%";

    $stmt = $conn->prepare("
        SELECT work_order_id
        FROM work_order
        WHERE Order_No LIKE ?
          AND client_head_id_fk = ?
        ORDER BY work_order_id DESC
        LIMIT 1
    ");
    $stmt->bind_param("si", $like, $client_head_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $stmt->close();
        return (int)$row['work_order_id'];
    }
    $stmt->close();

    $order_no = "MANUAL-SALE-{$client_head_id}-" . time();
    $order_date = date('Y-m-d');

    $stmt = $conn->prepare("
        INSERT INTO work_order
        (Order_No, Order_Date, client_head_id_fk, created_by, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("ssii", $order_no, $order_date, $client_head_id, $user_id);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();

    return $id;
}

/* -----------------------------------------
   POST: COMPLETE SALE
----------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sell_product'])) {

    $conn->begin_transaction();

    try {
        /* -------- CLIENT RESOLUTION (CRITICAL FIX) -------- */

        $client_head_id = null;
        $client_branch_id = null;

        if (empty($_POST['client_branch_id'])) {
            throw new Exception("Client selection is required.");
        }

        $raw = $_POST['client_branch_id'];

        if (preg_match('/^head_(\d+)$/', $raw, $m)) {
            // Head office selected
            $client_head_id = (int)$m[1];
        } else {
            // Branch selected → derive head from DB
            $client_branch_id = (int)$raw;

            $stmt = $conn->prepare("
                SELECT client_head_id_fk
                FROM client_branch
                WHERE client_branch_id = ? AND is_deleted = 0
            ");
            $stmt->bind_param("i", $client_branch_id);
            $stmt->execute();
            $stmt->bind_result($client_head_id);
            $stmt->fetch();
            $stmt->close();

            if (!$client_head_id) {
                throw new Exception("Invalid client branch selected.");
            }
        }

        /* -------- WORK ORDER -------- */

        $work_order_id = !empty($_POST['work_order_id'])
            ? (int)$_POST['work_order_id']
            : ensure_manual_work_order($conn, $client_head_id, $user_id);

        $sale_date = !empty($_POST['sale_date']) ? $_POST['sale_date'] : date('Y-m-d');

        /* -------- CART LOCK -------- */

        $stmt = $conn->prepare("
            SELECT *
            FROM cart
            WHERE user_id_fk = ?
            FOR UPDATE
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $cart = $stmt->get_result();

        if ($cart->num_rows === 0) {
            throw new Exception("Cart is empty.");
        }

        /* -------- FINANCIALS -------- */

        $sub_total = (float)($_POST['sub_total'] ?? 0);
        $discount = (float)($_POST['discount'] ?? 0);
        $tax_percent = (float)($_POST['tax_percent'] ?? 0);

        $excl = max(0, $sub_total - $discount);
        $tax_amount = $excl * ($tax_percent / 100);
        $incl = $excl + $tax_amount;

        /* -------- INVOICE INSERT (FIXED) -------- */

        $invoice_no = generate_invoice_no($conn);

        $stmt = $conn->prepare("
            INSERT INTO invoice
            (Invoice_No, client_head_id_fk, client_branch_id_fk,
             IncludingTax_TotalPrice, ExcludingTax_TotalPrice,
             Tax_Percentage, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            "siidddi",
            $invoice_no,
            $client_head_id,
            $client_branch_id,
            $incl,
            $excl,
            $tax_percent,
            $user_id
        );
        $stmt->execute();
        $invoice_id = (int)$conn->insert_id;
        $stmt->close();

        /* -------- PREPARED STATEMENTS -------- */
/* -------- PREPARED STATEMENTS -------- */

        // Note: We updated the bind types to be correct (i=int, d=double, s=string)
        // Order: head(i), branch(i), date(s), wo(i), sl(i), model(i), qty(i), price(d), avg(d), rem(s), inv(i), user(i)
        $stmt_sold = $conn->prepare("
            INSERT INTO sold_product (
                client_head_id_fk,
                client_branch_id_fk,
                sold_date,
                work_order_id_fk,
                product_sl_id_fk,
                model_id_fk,
                Quantity,
                Sold_Unit_Price,
                Avg_Max_Price,
                Remarks,
                invoice_id_fk,
                created_by,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt_avg = $conn->prepare("
            SELECT COALESCE(AVG(unit_price),0) 
            FROM purchased_products 
            WHERE model_id = ? AND is_deleted = 0
        ");

        $stmt_sl = $conn->prepare("
            UPDATE product_sl 
            SET status = 1 
            WHERE sl_id = ? AND status = 0
        ");

        /* -------- LOOP CART -------- */

        while ($row = $cart->fetch_assoc()) {

            $model_id = (int)$row['model_id_fk'];
            // Ensure strictly NULL if empty
            $sl_id = !empty($row['product_sl_id_fk']) ? (int)$row['product_sl_id_fk'] : null;
            $qty = (int)$row['quantity'];
            $price = (float)$row['sale_price'];
            $remarks = $row['remarks'] ?? '';

            // Calculate Average Price
            $stmt_avg->bind_param("i", $model_id);
            $stmt_avg->execute();
            $stmt_avg->store_result();
            $stmt_avg->bind_result($avg_price);
            $stmt_avg->fetch();
            $stmt_avg->free_result();

            // --- FIX FOR CONSTRAINT 'chk_client_link' ---
            // Logic: If we have a Branch, we do NOT set the Head ID in this specific table
            // (because the branch already links to the head). 
            // If we have no Branch (Head Office sale), we set the Head ID.
            
            $sold_branch_id = !empty($client_branch_id) ? (int)$client_branch_id : null;
            $sold_head_id   = !empty($sold_branch_id) ? null : (int)$client_head_id;

            $stmt_sold->bind_param(
                "iisiiiiddsii",  // Corrected Types: i=int, d=double, s=string
                $sold_head_id,   // i: NULL if branch is set
                $sold_branch_id, // i: NULL if head is set
                $sale_date,      // s
                $work_order_id,  // i
                $sl_id,          // i
                $model_id,       // i (Was d in your code, should be i)
                $qty,            // i (Was d in your code, should be i)
                $price,          // d
                $avg_price,      // d (Average price is numeric)
                $remarks,        // s
                $invoice_id,     // i
                $user_id         // i
            );
            
            if (!$stmt_sold->execute()) {
                throw new Exception("Error inserting sold product: " . $stmt_sold->error);
            }

            if ($sl_id) {
                $stmt_sl->bind_param("i", $sl_id);
                $stmt_sl->execute();
            }
        }

        /* -------- CLEANUP -------- */

        $conn->query("DELETE FROM cart WHERE user_id_fk = {$user_id}");
        $conn->commit();

        $success_message = "Sale completed successfully. Invoice: {$invoice_no}";

    } catch (Exception $e) {
        if ($conn->errno === 0) {
            $conn->rollback();
        }
        $error_message = "Transaction failed: " . $e->getMessage();
        log_error_msg($e->getMessage());
    }
}

/* -----------------------------------------
   UI DATA
----------------------------------------- */

$clients_struct = [];
$res = $conn->query("
    SELECT ch.client_head_id, ch.Company_Name,
           cb.client_branch_id, cb.Branch_Name, cb.is_deleted AS bdel
    FROM client_head ch
    LEFT JOIN client_branch cb ON cb.client_head_id_fk = ch.client_head_id
    WHERE ch.is_deleted = 0
    ORDER BY ch.Company_Name, cb.Branch_Name
");

while ($r = $res->fetch_assoc()) {
    $hid = $r['client_head_id'];
    if (!isset($clients_struct[$hid])) {
        $clients_struct[$hid] = ['name' => $r['Company_Name'], 'branches' => []];
    }
    if ($r['client_branch_id'] && !$r['bdel']) {
        $clients_struct[$hid]['branches'][] = [
            'id' => $r['client_branch_id'],
            'name' => $r['Branch_Name']
        ];
    }
}
?>
<!-- UI PART REMAINS UNCHANGED -->


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sell Product / Checkout</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
    </style>
</head>
<body class="bg-gray-100 min-h-screen font-sans text-gray-900">

<div class="container mx-auto px-4 py-8 max-w-7xl">
    
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Checkout / Sell Products</h1>
        <div class="flex gap-2">
            <a href="add_to_cart.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded shadow transition">
                <i class="fas fa-arrow-left mr-2"></i> Back to Cart
            </a>
            <a href="dashboard.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded shadow transition">
                <i class="fas fa-home mr-2"></i> Dashboard
            </a>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow">
            <p class="font-bold">Error</p>
            <p><?php echo htmlspecialchars($error_message); ?></p>
        </div>
    <?php endif; ?>
    <?php if ($success_message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow">
            <p class="font-bold">Success</p>
            <p><?php echo htmlspecialchars($success_message); ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow-lg overflow-hidden">
        <form action="" method="POST" class="p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <div class="lg:col-span-1 space-y-6">
                    <div>
                        <label for="client_branch_id" class="block text-sm font-medium text-gray-700 mb-1">Select Client</label>
                        <select name="client_branch_id" id="client_branch_id" class="w-full" required>
                            <option value="">Search Client...</option>
                            <?php foreach ($clients_struct as $hid => $cdata): ?>
                                <option value="head_<?php echo $hid; ?>" class="font-bold text-blue-800">
                                    <?php echo htmlspecialchars($cdata['name']); ?> (Head Office)
                                </option>
                                <?php foreach ($cdata['branches'] as $br): ?>
                                    <option value="<?php echo $br['id']; ?>">
                                        &nbsp;&nbsp;&nbsp; - <?php echo htmlspecialchars($br['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="client_head_id" id="client_head_id_hidden" value="">
                    </div>

                    <div>
                        <label for="work_order_id" class="block text-sm font-medium text-gray-700 mb-1">Work Order (Optional)</label>
                        <select name="work_order_id" id="work_order_id" class="w-full" disabled>
                            <option value="">Select client first</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Leave empty to use manual sale (auto-generated).</p>
                    </div>

                    <div>
                        <label for="sale_date" class="block text-sm font-medium text-gray-700 mb-1">Sale Date</label>
                        <input type="date" name="sale_date" id="sale_date" value="<?php echo date('Y-m-d'); ?>" class="w-full border-gray-300 rounded-md shadow-sm h-10 px-3">
                    </div>
                </div>

                <div class="lg:col-span-2 space-y-6">
                    <?php
                    // Get cart items for display and calc
                    $cart_items = [];
                    $sub_total_calc = 0;
                    $cstmt = $conn->prepare("
                        SELECT c.*, m.model_name, b.brand_name, cat.category_name
                        FROM cart c
                        JOIN models m ON c.model_id_fk = m.model_id
                        LEFT JOIN brands b ON m.brand_id = b.brand_id
                        LEFT JOIN categories cat ON m.category_id = cat.category_id
                        WHERE c.user_id_fk = ?
                    ");
                    $cstmt->bind_param("i", $user_id);
                    $cstmt->execute();
                    $cres = $cstmt->get_result();
                    while ($crow = $cres->fetch_assoc()) {
                        $cart_items[] = $crow;
                        $sub_total_calc += ($crow['sale_price'] * $crow['quantity']);
                    }
                    $cstmt->close();
                    ?>

                    <div class="border rounded-md overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-4 py-2 text-left">Product</th>
                                    <th class="px-4 py-2 text-right">Qty</th>
                                    <th class="px-4 py-2 text-right">Unit Price</th>
                                    <th class="px-4 py-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($cart_items as $item): 
                                    $row_total = $item['sale_price'] * $item['quantity'];
                                ?>
                                <tr>
                                    <td class="px-4 py-2 font-medium">
                                        <?php echo htmlspecialchars($item['brand_name'] . ' ' . $item['model_name']); ?>
                                    </td>
                                    <td class="px-4 py-2 text-right"><?php echo $item['quantity']; ?></td>
                                    <td class="px-4 py-2 text-right"><?php echo number_format($item['sale_price'], 2); ?></td>
                                    <td class="px-4 py-2 text-right"><?php echo number_format($row_total, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-gray-50 p-4 rounded-md border border-gray-200">
                        <div class="flex flex-col gap-3 max-w-sm ml-auto">
                            <div class="flex justify-between items-center">
                                <label class="text-sm font-medium text-gray-700">Sub Total</label>
                                <div class="text-lg font-bold text-gray-800"><?php echo number_format($sub_total_calc, 2); ?></div>
                                <input type="hidden" name="sub_total" id="sub_total" value="<?php echo $sub_total_calc; ?>">
                            </div>

                            <div class="flex justify-between items-center">
                                <label class="text-sm font-medium text-gray-700">Discount Amount</label>
                                <input type="number" name="discount" id="discount" step="0.01" min="0" value="0" class="w-32 text-right border-gray-300 rounded h-8 px-2">
                            </div>

                            <div class="flex justify-between items-center">
                                <label class="text-sm font-medium text-gray-700">Tax / VAT (%)</label>
                                <input type="number" name="tax_percent" id="tax_percent" step="0.01" min="0" value="0" class="w-32 text-right border-gray-300 rounded h-8 px-2">
                            </div>

                            <hr class="border-gray-300 my-1">

                            <div class="flex justify-between items-center text-xl text-blue-700 font-bold">
                                <span>Grand Total</span>
                                <span id="grand_total_display"><?php echo number_format($sub_total_calc, 2); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-4">
                        <button type="submit" name="sell_product" class="bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-8 rounded shadow flex items-center">
                            <i class="fas fa-check-circle mr-2"></i> Confirm Sale
                        </button>
                    </div>

                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('#client_branch_id').select2({ placeholder: "Search Client...", allowClear: true });
    $('#work_order_id').select2({ placeholder: 'Select client first', width: '100%', allowClear: true });

    // Listen for Client Change
    $('#client_branch_id').on('change', function() {
        var val = $(this).val(); // This will be "head_5" or "10"
        var $workOrderSelect = $('#work_order_id');

        // Reset Work Order Dropdown
        $workOrderSelect.empty().prop('disabled', true);

        if (val) {
            $workOrderSelect.append('<option>Loading...</option>');
            
            $.ajax({
                url: 'api_get_work_orders.php', // Points to the file fixed above
                type: 'GET',
                data: { client_identifier: val }, // Send the raw value
                dataType: 'json',
                success: function(data) {
                    $workOrderSelect.empty();
                    $workOrderSelect.append('<option value="">-- Select Work Order (Optional) --</option>');

                    if (Array.isArray(data) && data.length > 0) {
                        // Populate options
                        data.forEach(function(o) {
                            $workOrderSelect.append(new Option(o.Order_No + ' (' + o.Order_Date + ')', o.work_order_id));
                        });
                    } else {
                        // Inform user no orders found
                        $workOrderSelect.append('<option value="" disabled>No active work orders found</option>');
                    }
                    // Re-enable the dropdown
                    $workOrderSelect.prop('disabled', false);
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", error);
                    $workOrderSelect.empty().append('<option value="">Error loading orders</option>');
                    $workOrderSelect.prop('disabled', false);
                }
            });
        } else {
            // If client selection is cleared
            $workOrderSelect.append('<option value="">Select client first</option>');
        }
    });

    // --- Existing Calculation Logic ---
    function calculateTotals() {
        var subTotal = parseFloat($('#sub_total').val()) || 0;
        var taxPercent = parseFloat($('#tax_percent').val()) || 0;
        var discount = parseFloat($('#discount').val()) || 0;

        var taxable = Math.max(0, subTotal - discount);
        var taxAmount = taxable * (taxPercent / 100);
        var grandTotal = taxable + taxAmount;

        $('#grand_total_display').text(grandTotal.toFixed(2));
    }

    $('#discount, #tax_percent').on('input change', calculateTotals);
});
</script>
</body>
</html>