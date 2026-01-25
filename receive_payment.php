<?php
require_once 'session_guard.php';
$current_user_id = $_SESSION['user_id'];
require_once 'connection.php';

// --- START: AJAX HANDLER (Fetch Invoice Details) ---
if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_details') {
    header('Content-Type: application/json');
    $invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
    
    if ($invoice_id > 0) {
        $sql_inv = "SELECT IncludingTax_TotalPrice as Total_Amount, Invoice_No FROM invoice WHERE invoice_id = ? AND is_deleted = 0";
        $stmt = $conn->prepare($sql_inv);
        $stmt->bind_param("i", $invoice_id);
        $stmt->execute();
        $res_inv = $stmt->get_result()->fetch_assoc();
        
        if ($res_inv) {
            $total_amount = (float)$res_inv['Total_Amount'];
            
            $sql_paid = "SELECT SUM(amount) as total_paid FROM payments WHERE invoice_id_fk = ? AND is_deleted = 0";
            $stmt_paid = $conn->prepare($sql_paid);
            $stmt_paid->bind_param("i", $invoice_id);
            $stmt_paid->execute();
            $res_paid = $stmt_paid->get_result()->fetch_assoc();
            
            $paid_so_far = $res_paid['total_paid'] ? (float)$res_paid['total_paid'] : 0.00;
            $due_amount = $total_amount - $paid_so_far;
            
            echo json_encode([
                'status' => 'success',
                'invoice_no' => $res_inv['Invoice_No'],
                'total_amount' => $total_amount,
                'paid_so_far' => $paid_so_far,
                'due_amount' => max(0, $due_amount)
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invoice not found']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    }
    exit();
}

// --- START: FORM SUBMISSION HANDLER ---
$successMessage = '';
$errorMessage = '';
$auto_print_id = null; // Variable to trigger auto-print

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $invoice_id = (int)$_POST['invoice_id'];
    $payment_date = $_POST['payment_date'];
    $payment_method = $_POST['payment_method'];
    $transaction_number = ($payment_method === 'Cash') ? null : trim($_POST['transaction_number']);
    $received_amount = (float)$_POST['received_amount'];

    if ($invoice_id && $received_amount > 0) {
        if ($payment_method !== 'Cash' && empty($transaction_number)) {
            $errorMessage = "Transaction Number is required for " . $payment_method;
        } else {
            $sql_check = "SELECT IncludingTax_TotalPrice as Total_Amount, 
                          (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id_fk = invoice.invoice_id AND is_deleted = 0) as paid_so_far 
                          FROM invoice WHERE invoice_id = ?";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param("i", $invoice_id);
            $stmt_check->execute();
            $check_data = $stmt_check->get_result()->fetch_assoc();
            
            $real_due = $check_data['Total_Amount'] - $check_data['paid_so_far'];

            if ($received_amount > ($real_due + 0.10)) { 
                $errorMessage = "Error: Amount cannot be greater than the payable due amount.";
            } else {
                $conn->begin_transaction();
                try {
                    // 1. GENERATE MONEY RECEIPT NO
                    $sql_mr = "SELECT money_receipt_no FROM payments ORDER BY payment_id DESC LIMIT 1 FOR UPDATE";
                    $res_mr = $conn->query($sql_mr);
                    $row_mr = $res_mr->fetch_assoc();
                    
                    $next_num = 1;
                    if ($row_mr && !empty($row_mr['money_receipt_no'])) {
                        $parts = explode('-', $row_mr['money_receipt_no']);
                        if (isset($parts[1])) {
                            $next_num = intval($parts[1]) + 1;
                        }
                    }
                    $new_mr_no = 'P1MR-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);

                    // 2. INSERT PAYMENT
                    $sql_insert = "INSERT INTO payments (invoice_id_fk, payment_date, payment_method, transaction_number, amount, money_receipt_no, created_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt_ins = $conn->prepare($sql_insert);
                    $stmt_ins->bind_param("isssdsi", $invoice_id, $payment_date, $payment_method, $transaction_number, $received_amount, $new_mr_no, $current_user_id);
                    $stmt_ins->execute();
                    $new_payment_id = $conn->insert_id;

                    // 3. UPDATE STATUS
                    $new_total_paid = $check_data['paid_so_far'] + $received_amount;
                    $new_status = 0; 
                    if (abs($new_total_paid - $check_data['Total_Amount']) < 0.10) {
                        $new_status = 2; // Paid
                    } elseif ($new_total_paid > 0) {
                        $new_status = 1; // Partial
                    }

                    $sql_update = "UPDATE invoice SET status = ? WHERE invoice_id = ?";
                    $stmt_upd = $conn->prepare($sql_update);
                    $stmt_upd->bind_param("ii", $new_status, $invoice_id);
                    $stmt_upd->execute();

                    $conn->commit();
                    
                    // --- SUCCESS ---
                    $successMessage = "Payment Received successfully! <br>Receipt No: <strong>" . $new_mr_no . "</strong>";
                    
                    // Set this ID to trigger the hidden iframe below
                    $auto_print_id = $new_payment_id;
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $errorMessage = "Transaction failed: " . $e->getMessage();
                }
            }
        }
    } else {
        $errorMessage = "Please select an invoice and enter a valid amount.";
    }
}

// --- FETCH INVOICES ---
$sql_list = "SELECT i.invoice_id, i.Invoice_No, i.IncludingTax_TotalPrice, 
             ch.Company_Name, cb.Branch_Name
             FROM invoice i
             LEFT JOIN client_head ch ON i.client_head_id_fk = ch.client_head_id
             LEFT JOIN client_branch cb ON i.client_branch_id_fk = cb.client_branch_id
             WHERE i.status != 2 AND i.is_deleted = 0
             ORDER BY i.created_at DESC";
$invoices = $conn->query($sql_list);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receive Payment</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .select2-container .select2-selection--single { height: 46px; border-color: #d1d5db; border-radius: 0.5rem; display: flex; align-items: center; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { top: 10px; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen py-8">

    <div class="container mx-auto px-4 max-w-2xl">
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-4">Receive Payment</h2>

            <?php if ($successMessage): ?><div class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?php echo $successMessage; ?></div><?php endif; ?>
            <?php if ($errorMessage): ?><div class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?php echo $errorMessage; ?></div><?php endif; ?>

            <form id="paymentForm" action="" method="POST" class="space-y-6">
                <div>
                    <label for="invoice_id" class="block text-sm font-medium text-gray-700 mb-1">Select Invoice</label>
                    <select id="invoice_id" name="invoice_id" class="w-full" required>
                        <option value="">Search Invoice No or Client...</option>
                        <?php while($inv = $invoices->fetch_assoc()): 
                            $client = !empty($inv['Branch_Name']) ? $inv['Branch_Name'] : $inv['Company_Name'];
                            $display = $inv['Invoice_No'] . " - " . $client . " (Total: " . $inv['IncludingTax_TotalPrice'] . ")";
                        ?>
                            <option value="<?php echo $inv['invoice_id']; ?>"><?php echo htmlspecialchars($display); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div id="invoice-details" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-4 grid grid-cols-3 gap-4 text-center">
                    <div><p class="text-xs text-gray-500 uppercase">Total Amount</p><p class="font-bold text-gray-800" id="disp_total">0.00</p></div>
                    <div><p class="text-xs text-gray-500 uppercase">Paid So Far</p><p class="font-bold text-blue-600" id="disp_paid">0.00</p></div>
                    <div><p class="text-xs text-gray-500 uppercase">Payable Due</p><p class="font-bold text-red-600 text-lg" id="disp_due">0.00</p></div>
                </div>

                <div>
                    <label for="payment_date" class="block text-sm font-medium text-gray-700 mb-1">Payment Date</label>
                    <input type="date" id="payment_date" name="payment_date" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                        <select id="payment_method" name="payment_method" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500">
                            <option value="Cash">Cash</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Bank Deposit">Bank Deposit</option>
                        </select>
                    </div>
                    <div>
                        <label for="transaction_number" class="block text-sm font-medium text-gray-700 mb-1">Transaction Number</label>
                        <input type="text" id="transaction_number" name="transaction_number" disabled placeholder="N/A for Cash" class="w-full p-3 border border-gray-300 rounded-lg bg-gray-100 focus:ring-blue-500 transition">
                    </div>
                </div>

                <div>
                    <label for="received_amount" class="block text-sm font-medium text-gray-700 mb-1">Received Amount</label>
                    <div class="relative rounded-md shadow-sm">
                        <input type="number" step="0.01" min="0.01" id="received_amount" name="received_amount" required disabled class="block w-full rounded-md border-gray-300 pl-7 p-3 focus:border-blue-500 bg-gray-100">
                    </div>
                    <p id="amount-hint" class="mt-1 text-xs text-red-500 hidden">Amount cannot exceed Payable Due.</p>
                </div>

                <div class="pt-4">
                    <button type="button" id="btn-pre-submit" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed" disabled>Receive Payment</button>
                </div>
            </form>
        </div>
    </div>

    <div id="confirmModal" class="fixed inset-0 z-50 hidden bg-gray-900 bg-opacity-75 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Confirm Payment</h3>
            <p class="text-gray-500 text-sm mb-4">Please verify the details below.</p>
            <div class="bg-gray-50 p-4 rounded-lg space-y-2 mb-6 text-sm">
                <div class="flex justify-between"><span class="text-gray-600">Invoice No:</span> <span class="font-medium text-gray-900" id="conf_invoice"></span></div>
                <div class="flex justify-between"><span class="text-gray-600">Date:</span> <span class="font-medium text-gray-900" id="conf_date"></span></div>
                <div class="flex justify-between"><span class="text-gray-600">Method:</span> <span class="font-medium text-gray-900" id="conf_method"></span></div>
                <div class="flex justify-between hidden" id="conf_trans_row"><span class="text-gray-600">Trans No:</span> <span class="font-medium text-gray-900" id="conf_trans"></span></div>
                <div class="flex justify-between border-t pt-2 mt-2"><span class="text-gray-800 font-bold">Amount:</span> <span class="font-bold text-green-600 text-lg" id="conf_amount"></span></div>
            </div>
            <div class="flex space-x-3">
                <button id="btn-cancel" class="flex-1 bg-white border border-gray-300 text-gray-700 font-semibold py-2 px-4 rounded-lg hover:bg-gray-50">Cancel</button>
                <button id="btn-confirm" class="flex-1 bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700">Confirm & Save</button>
            </div>
        </div>
    </div>

    <?php if ($auto_print_id): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var iframe = document.createElement('iframe');
            iframe.src = 'print_money_receipt.php?id=<?php echo $auto_print_id; ?>&print=true';
            
            iframe.style.position = 'fixed';
            iframe.style.left = '-9999px';
            iframe.style.top = '0';
            iframe.style.width = '1px';
            iframe.style.height = '1px';
            iframe.style.border = 'none';
            iframe.id = 'auto_print_frame';
            
            document.body.appendChild(iframe);
            // Removed iframe.onload -> print() logic because print_money_receipt.php handles it.
        });
    </script>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#invoice_id').select2({ placeholder: "Search for an invoice...", allowClear: true, width: '100%' });
            document.getElementById('payment_date').valueAsDate = new Date();
            let currentDue = 0, currentInvoiceNo = '';

            $('#payment_method').on('change', function() {
                const method = $(this).val();
                const transInput = $('#transaction_number');
                if (method === 'Cash') {
                    transInput.val('').prop('disabled', true).addClass('bg-gray-100').attr('placeholder', 'N/A for Cash');
                } else {
                    let ph = 'Enter Transaction No';
                    if (method === 'Cheque') ph = 'Enter Cheque No';
                    else if (method === 'Bank Transfer') ph = 'Enter Transfer Ref No';
                    else if (method === 'Bank Deposit') ph = 'Enter Deposit Slip No';
                    transInput.prop('disabled', false).removeClass('bg-gray-100').attr('placeholder', ph);
                }
            });

            $('#invoice_id').on('change', function() {
                const invoiceId = $(this).val();
                if (invoiceId) {
                    $.ajax({
                        url: 'receive_payment.php', type: 'GET', data: { action: 'get_invoice_details', invoice_id: invoiceId },
                        success: function(response) {
                            if (response.status === 'success') {
                                currentDue = parseFloat(response.due_amount);
                                currentInvoiceNo = response.invoice_no;
                                $('#disp_total').text(parseFloat(response.total_amount).toFixed(2));
                                $('#disp_paid').text(parseFloat(response.paid_so_far).toFixed(2));
                                $('#disp_due').text(currentDue.toFixed(2));
                                $('#invoice-details').removeClass('hidden');
                                $('#received_amount').prop('disabled', false).removeClass('bg-gray-100').val('').focus().attr('max', currentDue);
                                $('#btn-pre-submit').prop('disabled', true);
                            }
                        }
                    });
                } else {
                    $('#invoice-details').addClass('hidden');
                    $('#received_amount').prop('disabled', true);
                    $('#btn-pre-submit').prop('disabled', true);
                }
            });

            $('#received_amount').on('input', function() {
                const val = parseFloat($(this).val());
                if (isNaN(val) || val <= 0 || val > (currentDue + 0.1)) {
                    $('#btn-pre-submit').prop('disabled', true);
                    if (val > (currentDue + 0.1)) $('#amount-hint').removeClass('hidden');
                } else {
                    $('#btn-pre-submit').prop('disabled', false);
                    $('#amount-hint').addClass('hidden');
                }
            });

            $('#btn-pre-submit').click(function() {
                const method = $('#payment_method').val();
                const trans = $('#transaction_number').val();
                if(method !== 'Cash' && !trans.trim()) { alert('Please enter a Transaction Number for ' + method); return; }
                $('#conf_invoice').text(currentInvoiceNo);
                $('#conf_date').text($('#payment_date').val());
                $('#conf_amount').text(parseFloat($('#received_amount').val()).toFixed(2));
                $('#conf_method').text(method);
                if (method !== 'Cash') { $('#conf_trans_row').removeClass('hidden'); $('#conf_trans').text(trans); } else { $('#conf_trans_row').addClass('hidden'); }
                $('#confirmModal').removeClass('hidden');
            });

            $('#btn-cancel').click(function() { $('#confirmModal').addClass('hidden'); });
            $('#btn-confirm').click(function() { $('#paymentForm').submit(); });
        });
    </script>
</body>
</html>