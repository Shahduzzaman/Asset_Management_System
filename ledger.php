<?php
session_start();

// --- START: SESSION & SECURITY CHECKS ---
$idleTimeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset(); session_destroy(); header("Location: index.php?reason=idle"); exit();
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php"); exit();
}
// Store user name for print footer
$current_user_name = $_SESSION['user_name'] ?? 'User';
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';

// --- Initial Data Fetch for Filters ---
$vendors = $conn->query("SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);

// --- Handle Form Submission to Generate Ledger ---
$transactions = [];
$total_debit = 0;
$total_credit = 0;
$final_balance = 0;
$selected_vendor_details = null;
$start_date_display = '';
$end_date_display = '';
$errorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_ledger'])) {
    $vendor_id = filter_input(INPUT_POST, 'vendor_id', FILTER_VALIDATE_INT);
    $start_date = filter_input(INPUT_POST, 'start_date');
    $end_date = filter_input(INPUT_POST, 'end_date');

    if (!$vendor_id || !$start_date || !$end_date) {
        $errorMessage = "Please select a vendor and valid start/end dates.";
    } elseif ($start_date > $end_date) {
        $errorMessage = "Start date cannot be after the end date.";
    } else {
        $stmt_vn = $conn->prepare("SELECT vendor_name, contact_person, address, phone, email FROM vendors WHERE vendor_id = ?");
        $stmt_vn->bind_param("i", $vendor_id);
        $stmt_vn->execute();
        $selected_vendor_details = $stmt_vn->get_result()->fetch_assoc();
        $stmt_vn->close();

        $start_date_display = $start_date;
        $end_date_display = $end_date;

        $sql_payments = "SELECT payment_date as transaction_date, invoice_number, debit_amount as amount, 'Payment Made' as type, payment_method, payment_info_no FROM payment_table WHERE vendor_id = ? AND payment_date BETWEEN ? AND ? AND is_deleted = FALSE";
        $stmt_pay = $conn->prepare($sql_payments);
        $stmt_pay->bind_param("iss", $vendor_id, $start_date, $end_date);
        $stmt_pay->execute();
        $payments = $stmt_pay->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_pay->close();

        $sql_purchases = "SELECT purchase_date as transaction_date, invoice_number, SUM(quantity * unit_price) as amount, 'Purchase Received' as type FROM purchased_products WHERE vendor_id = ? AND purchase_date BETWEEN ? AND ? AND is_deleted = FALSE GROUP BY purchase_date, invoice_number";
        $stmt_p = $conn->prepare($sql_purchases);
        $stmt_p->bind_param("iss", $vendor_id, $start_date, $end_date);
        $stmt_p->execute();
        $purchases = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_p->close();

        $transactions = array_merge($payments, $purchases);
        usort($transactions, function($a, $b) {
            $dateComparison = strcmp($a['transaction_date'], $b['transaction_date']);
            if ($dateComparison === 0) {
                 if ($a['type'] === 'Purchase Received' && $b['type'] === 'Payment Made') return -1;
                 if ($a['type'] === 'Payment Made' && $b['type'] === 'Purchase Received') return 1;
                 return 0;
            }
            return $dateComparison;
        });

        $current_balance = 0;
        foreach ($transactions as &$txn) {
            if ($txn['type'] === 'Payment Made') {
                $txn['debit'] = $txn['amount']; $txn['credit'] = 0; $current_balance += $txn['amount']; $total_debit += $txn['amount'];
                 $txn['description'] = 'Payment Made';
                 if(!empty($txn['payment_method'])) $txn['description'] .= ' (' . $txn['payment_method'];
                 if(!empty($txn['payment_info_no'])) $txn['description'] .= ' #' . $txn['payment_info_no'];
                 if(!empty($txn['payment_method'])) $txn['description'] .= ')';
            } else {
                $txn['debit'] = 0; $txn['credit'] = $txn['amount']; $current_balance -= $txn['amount']; $total_credit += $txn['amount'];
                $txn['description'] = 'Purchase Received';
            }
            $txn['balance'] = $current_balance;
            $txn['balance_indicator'] = ($current_balance < 0) ? '(D)' : '(C)';
        }
        unset($txn);
        $final_balance = $current_balance;
    }
} else {
     $end_date_default = date('Y-m-d');
     $start_date_default = date('Y-m-d', strtotime('-1 month'));
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Ledger</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Print specific styles */
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body * { visibility: hidden; }
            #print-section, #print-section * { visibility: visible; }
            #print-section {
                position: absolute; left: 0; top: 0;
                width: 100%; height: auto;
                margin: 0; padding: 10mm;
                font-size: 9pt; /* Base print font size (adjust if needed, kept slightly smaller) */
            }
             #print-header {
                display: flex !important;
                align-items: center;
                justify-content: center;
                margin-bottom: 20px;
                border-bottom: 1px solid #ccc;
                padding-bottom: 15px;
             }
             #print-logo {
                 height: 55px;
                 width: auto;
                 margin-right: 15px;
             }
             #print-company-info { text-align: left; }
             #print-company-name { font-size: 20px; font-weight: bold; line-height: 1.2; }
             #print-tagline { font-size: 14px; line-height: 1.2;}
             .no-print { display: none !important; }
             table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 7pt; } /* Reduced table font size */
             th, td { border: 1px solid #ccc; padding: 3px 5px; text-align: left; word-wrap: break-word; }
             th { background-color: #eee !important; font-weight: bold;}
             tbody tr:nth-child(even) { background-color: #f9f9f9 !important; }
             .text-right { text-align: right; }
             .text-red-600 { color: #DC2626 !important; }
             .text-green-600 { color: #16A34A !important; }
             .bg-gray-50 { background-color: #F9FAFB !important; }
             .summary-section {
                 margin-top: 25px;
                 padding: 15px;
                 border: 1px solid #ccc;
                 border-top: 2px solid #333;
                 background-color: #f9f9f9 !important;
                 border-radius: 5px;
                 font-size: 9pt; /* Match base print font size */
                 page-break-inside: avoid;
              }
              .summary-section h3 { font-size: 11pt; margin-bottom: 10px; } /* Slightly smaller summary heading */
             .summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
             .summary-section p { margin: 0; }
             #print-footer {
                  display: block !important;
                  position: fixed;
                  bottom: 10mm;
                  left: 10mm;
                  right: 10mm;
                  width: calc(100% - 20mm);
                  font-size: 8pt;
                  border-top: 1px solid #ccc;
                  padding-top: 5px;
                  text-align: left;
              }
             #print-footer-left { float: left; }
             #print-footer-right { float: right; }

             @page {
                 size: A4;
                 margin: 15mm;
                 /* Reset default headers/footers */
                 @top-left { content: ""; } @top-center { content: ""; } @top-right { content: ""; }
                 @bottom-left { content: ""; } @bottom-center { content: ""; } @bottom-right { content: ""; }
             }
        }
        #print-footer, #print-header { display: none; } /* Hide header/footer by default */
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <div class="flex justify-between items-center mb-6 no-print">
            <h1 class="text-3xl font-bold text-gray-800">Vendor Ledger</h1>
            <a href="dashboard.php" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700">&larr; Back to Dashboard</a>
        </div>

        <!-- Filter Form -->
        <div class="bg-white p-6 rounded-xl shadow-md mb-8 no-print">
            <h2 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Generate Ledger Report</h2>
             <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4"><span><?php echo $errorMessage; ?></span></div><?php endif; ?>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label for="vendor_id" class="block text-sm font-medium text-gray-700">Vendor</label>
                    <select id="vendor_id" name="vendor_id" required class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                        <option value="">-- Select Vendor --</option>
                        <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['vendor_id']; ?>" <?php echo (isset($_POST['vendor_id']) && $_POST['vendor_id'] == $vendor['vendor_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($vendor['vendor_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input type="date" id="start_date" name="start_date" required value="<?php echo htmlspecialchars($_POST['start_date'] ?? $start_date_default); ?>" class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                    <input type="date" id="end_date" name="end_date" required value="<?php echo htmlspecialchars($_POST['end_date'] ?? $end_date_default); ?>" class="mt-1 w-full p-2 border border-gray-300 rounded-md shadow-sm">
                </div>
                <div>
                    <button type="submit" name="generate_ledger" class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition">Generate Ledger</button>
                </div>
            </form>
        </div>

        <!-- Ledger Display Section -->
        <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_ledger']) && empty($errorMessage)): ?>
        <div id="print-section">
            <!-- Print Header -->
            <div id="print-header">
                <img id="print-logo" src="images/logo.png" alt="Company Logo">
                <div id="print-company-info">
                    <div id="print-company-name">Protection One</div>
                    <div id="print-tagline">A Complete Security Solutions</div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6 border-b flex justify-between">
                    <div>
                         <h2 class="text-2xl font-bold text-gray-800 mb-1">Ledger for: <?php echo htmlspecialchars($selected_vendor_details['vendor_name'] ?? 'N/A'); ?></h2>
                         <p class="text-sm text-gray-600 mb-3">Period: <?php echo htmlspecialchars($start_date_display); ?> to <?php echo htmlspecialchars($end_date_display); ?></p>
                         <?php if ($selected_vendor_details): ?>
                         <div class="text-xs text-gray-500 mt-2 leading-relaxed">
                             <?php if($selected_vendor_details['contact_person']) echo '<span class="font-bold">' . htmlspecialchars($selected_vendor_details['contact_person']) . '</span><br>'; ?>
                             <?php if($selected_vendor_details['email']) echo 'Email: ' . htmlspecialchars($selected_vendor_details['email']) . '<br>'; ?>
                             <?php if($selected_vendor_details['phone']) echo 'Phone: ' . htmlspecialchars($selected_vendor_details['phone']) . '<br>'; ?>
                             <?php if($selected_vendor_details['address']) echo 'Address: ' . nl2br(htmlspecialchars($selected_vendor_details['address'])); ?>
                         </div>
                         <?php endif; ?>
                    </div>
                     <button onclick="window.print()" class="bg-gray-500 text-white font-semibold py-2 px-4 rounded-lg hover:bg-gray-600 transition no-print">Print Ledger</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Debit (Payment)</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Credit (Purchase)</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="6" class="text-center py-10 text-gray-500">No transactions found for this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $txn): ?>
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-600 whitespace-nowrap"><?php echo htmlspecialchars($txn['transaction_date']); ?></td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($txn['description']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600"><?php echo htmlspecialchars($txn['invoice_number'] ?? ''); ?></td>
                                    <td class="px-6 py-4 text-sm text-right text-green-600"><?php echo $txn['debit'] > 0 ? number_format($txn['debit'], 2) : ''; ?></td>
                                    <td class="px-6 py-4 text-sm text-right text-red-600"><?php echo $txn['credit'] > 0 ? number_format($txn['credit'], 2) : ''; ?></td>
                                    <td class="px-6 py-4 text-sm text-right font-semibold <?php echo $txn['balance'] < 0 ? 'text-red-700' : 'text-gray-800'; ?>">
                                        <?php echo number_format(abs($txn['balance']), 2) . ' ' . $txn['balance_indicator']; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                 <!-- Summary Section -->
                 <div class="summary-section p-6 bg-gray-50 border-t">
                    <h3 class="text-lg font-semibold mb-3 text-gray-700">Ledger Summary</h3>
                     <div class="summary-grid grid grid-cols-1 md:grid-cols-3 gap-4 text-sm font-medium text-gray-700">
                         <p>Total Payments (Debit): <span class="font-bold text-green-600"><?php echo number_format($total_debit, 2); ?></span></p>
                         <p>Total Purchases (Credit): <span class="font-bold text-red-600"><?php echo number_format($total_credit, 2); ?></span></p>
                         <p>Final Balance:
                            <span class="font-bold <?php echo $final_balance < 0 ? 'text-red-700' : 'text-gray-800'; ?>">
                                <?php echo number_format(abs($final_balance), 2); ?>
                                <?php
                                    // Description without (D)/(C) in summary
                                    if ($final_balance < 0) echo " (Due to Vendor)";
                                    elseif ($final_balance > 0) echo " (Advance Paid / Credit)";
                                    else echo " (Settled)";
                                ?>
                            </span>
                         </p>
                     </div>
                 </div>
            </div>

            <!-- Print Footer -->
            <div id="print-footer">
                 <div id="print-footer-left">
                     Printed by: <?php echo htmlspecialchars($current_user_name); ?> on <span id="print-datetime"></span>
                 </div>
                 <div id="print-footer-right">
                     Page <span class="pageNumber"></span>
                 </div>
             </div>
        </div>
        <?php endif; ?>

    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const alertBox = document.getElementById('alert-box');
    if (alertBox) {
        setTimeout(() => { alertBox.style.transition = 'opacity 0.5s ease'; alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000);
    }
     <?php if ($_SERVER["REQUEST_METHOD"] != "POST"): ?>
        const today = new Date(); const oneMonthAgo = new Date(); oneMonthAgo.setMonth(today.getMonth() - 1);
        document.getElementById('end_date').valueAsDate = today; document.getElementById('start_date').valueAsDate = oneMonthAgo;
     <?php endif; ?>

     const now = new Date();
     const printDateTimeEl = document.getElementById('print-datetime');
     if (printDateTimeEl) {
         printDateTimeEl.textContent = now.toLocaleDateString() + ' ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
     }

     // Simple CSS counter for current page number in print footer
     const style = document.createElement('style');
     style.innerHTML = `
        @media print {
            body { counter-reset: pageNumber; }
            #print-footer-right .pageNumber::before { content: counter(pageNumber); }
             thead { display: table-header-group; } /* Repeat header on each page */
             /* Add page break instructions */
             table { page-break-inside: auto; }
             tr    { page-break-inside: avoid; page-break-after: auto; }
             #print-section { counter-increment: pageNumber; } /* Increment page counter for print section */
        }
     `;
     document.head.appendChild(style);
});
</script>

</body>
</html>

