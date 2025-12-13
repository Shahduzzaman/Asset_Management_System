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
$current_user_name = $_SESSION['user_name'] ?? 'User';
// --- END: SESSION & SECURITY CHECKS ---

require_once 'connection.php';

// --- Initial Data Fetch for Filters ---
$vendors = $conn->query("SELECT vendor_id, vendor_name FROM vendors WHERE is_deleted = FALSE ORDER BY vendor_name")->fetch_all(MYSQLI_ASSOC);

// --- Ledger Variables ---
$transactions = [];
$total_debit = 0;
$total_credit = 0;
$final_balance = 0;
$opening_balance = 0;
$selected_vendor_details = null;
$start_date_display = '';
$end_date_display = '';
$errorMessage = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_ledger'])) {

    $vendor_id = filter_input(INPUT_POST, 'vendor_id', FILTER_VALIDATE_INT);
    $start_date = filter_input(INPUT_POST, 'start_date');
    $end_date   = filter_input(INPUT_POST, 'end_date');

    if (!$vendor_id || !$start_date || !$end_date) {
        $errorMessage = "Please select a vendor and valid start/end dates.";
    } elseif ($start_date > $end_date) {
        $errorMessage = "Start date cannot be after the end date.";
    } else {

        // --- Vendor Info ---
        $stmt_vn = $conn->prepare("SELECT vendor_name, contact_person, address, phone, email FROM vendors WHERE vendor_id = ?");
        $stmt_vn->bind_param("i", $vendor_id);
        $stmt_vn->execute();
        $selected_vendor_details = $stmt_vn->get_result()->fetch_assoc();
        $stmt_vn->close();

        $start_date_display = $start_date;
        $end_date_display   = $end_date;

        // =====================================================
        // OPENING BALANCE CALCULATION (Before Start Date)
        // =====================================================

        // Payments before start date
        $stmt_op = $conn->prepare("
            SELECT COALESCE(SUM(debit_amount), 0) AS total_payment
            FROM payment_table
            WHERE vendor_id = ?
              AND payment_date < ?
              AND is_deleted = FALSE
        ");
        $stmt_op->bind_param("is", $vendor_id, $start_date);
        $stmt_op->execute();
        $opening_payment = $stmt_op->get_result()->fetch_assoc()['total_payment'];
        $stmt_op->close();

        // Purchases before start date
        $stmt_oc = $conn->prepare("
            SELECT COALESCE(SUM(quantity * unit_price), 0) AS total_purchase
            FROM purchased_products
            WHERE vendor_id = ?
              AND purchase_date < ?
              AND is_deleted = FALSE
        ");
        $stmt_oc->bind_param("is", $vendor_id, $start_date);
        $stmt_oc->execute();
        $opening_purchase = $stmt_oc->get_result()->fetch_assoc()['total_purchase'];
        $stmt_oc->close();

        // Opening balance = Payments - Purchases
        $opening_balance = $opening_payment - $opening_purchase;

        // =====================================================
        // TRANSACTIONS WITHIN DATE RANGE
        // =====================================================

        $stmt_pay = $conn->prepare("
            SELECT 
                payment_date AS transaction_date,
                invoice_number,
                debit_amount AS amount,
                'Payment Made' AS type,
                payment_method,
                payment_info_no
            FROM payment_table
            WHERE vendor_id = ?
              AND payment_date BETWEEN ? AND ?
              AND is_deleted = FALSE
        ");
        $stmt_pay->bind_param("iss", $vendor_id, $start_date, $end_date);
        $stmt_pay->execute();
        $payments = $stmt_pay->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_pay->close();

        $stmt_p = $conn->prepare("
            SELECT 
                purchase_date AS transaction_date,
                invoice_number,
                SUM(quantity * unit_price) AS amount,
                'Purchase Received' AS type
            FROM purchased_products
            WHERE vendor_id = ?
              AND purchase_date BETWEEN ? AND ?
              AND is_deleted = FALSE
            GROUP BY purchase_date, invoice_number
        ");
        $stmt_p->bind_param("iss", $vendor_id, $start_date, $end_date);
        $stmt_p->execute();
        $purchases = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_p->close();

        $transactions = array_merge($payments, $purchases);

        usort($transactions, function($a, $b) {
            $cmp = strcmp($a['transaction_date'], $b['transaction_date']);
            if ($cmp === 0) {
                if ($a['type'] === 'Purchase Received' && $b['type'] === 'Payment Made') return -1;
                if ($a['type'] === 'Payment Made' && $b['type'] === 'Purchase Received') return 1;
                return 0;
            }
            return $cmp;
        });

        // =====================================================
        // OPENING BALANCE ROW (PREPENDED)
        // =====================================================

        array_unshift($transactions, [
            'transaction_date'  => date('d-m-Y', strtotime($start_date . ' -1 day')),
            'description'       => 'Opening Balance',
            'invoice_number'    => '',
            'debit'             => '',
            'credit'            => '',
            'balance'           => $opening_balance,
            'balance_indicator' => ($opening_balance < 0) ? '(D)' : '(C)'
        ]);

        // =====================================================
        // RUNNING BALANCE LOGIC
        // =====================================================

        $current_balance = $opening_balance;

        foreach ($transactions as &$txn) {

            if (($txn['description'] ?? '') === 'Opening Balance') {
                continue;
            }

            if ($txn['type'] === 'Payment Made') {
                $txn['debit'] = $txn['amount'];
                $txn['credit'] = 0;
                $current_balance += $txn['amount'];
                $total_debit += $txn['amount'];

                $txn['description'] = 'Payment Made';
                if (!empty($txn['payment_method'])) $txn['description'] .= ' (' . $txn['payment_method'];
                if (!empty($txn['payment_info_no'])) $txn['description'] .= ' #' . $txn['payment_info_no'];
                if (!empty($txn['payment_method'])) $txn['description'] .= ')';

            } else {
                $txn['debit'] = 0;
                $txn['credit'] = $txn['amount'];
                $current_balance -= $txn['amount'];
                $total_credit += $txn['amount'];
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

        /* ---------------------------
           SCREEN ONLY (Hide in Print)
           --------------------------- */
        @media screen {
            .print-only { display: none !important; }
        }

        /* ---------------------------
           PRINT ONLY STYLES
           --------------------------- */
        @media print {
            /* 1. Reset Page & Margins */
            @page {
                size: A4;
                margin: 5mm 5mm 5mm 5mm; /* Very minimal margins */
            }

            body {
                margin: 0;
                padding: 0;
                background-color: #fff !important;
                font-family: 'Helvetica', 'Arial', sans-serif; /* Clean font for print */
                font-size: 10pt; /* Compact font size */
                color: #000;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                counter-reset: page; /* Initialize page counter */
            }

            /* 2. Hide Screen Elements */
            .no-print, .no-print * {
                display: none !important;
                height: 0;
                width: 0;
            }

            /* 3. Main Container Reset */
            .container, .max-w-7xl, .mx-auto, .p-4, .p-6, .p-8 {
                width: 100% !important;
                max-width: 100% !important;
                min-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
            }

            /* 4. Show Print Section */
            #print-section {
                display: block !important;
                width: 100%;
                visibility: visible;
                position: relative;
            }
            
            #print-section * {
                visibility: visible;
            }

            /* 5. Header Section - ADDED !important */
            #print-header {
                display: flex !important;
                align-items: center;
                border-bottom: 2px solid #000;
                padding-bottom: 5px;
                margin-bottom: 10px;
            }
            #print-logo {
                height: 50px; /* Adjusted size */
                width: auto;
                margin-right: 15px;
                display: block !important;
            }
            .header-info h1 { font-size: 16pt; font-weight: bold; margin: 0; text-transform: uppercase; }
            .header-info p { font-size: 9pt; margin: 0; }

            /* 6. Vendor Info Block - ADDED !important */
            .vendor-info-box {
                border: 1px solid #000;
                padding: 5px;
                margin-bottom: 10px;
                font-size: 9pt;
                display: flex !important; /* Forces display even if 'hidden' class is present */
                justify-content: space-between;
            }
            .vendor-info-col { width: 48%; }

            /* 7. TABLE STYLING - THE CORE FIX */
            table {
                width: 100% !important;
                border-collapse: collapse !important;
                table-layout: fixed; /* Fix column widths */
            }

            th, td {
                border: 1px solid #000 !important; /* Sharp black borders */
                padding: 3px 4px !important; /* COMPACT PADDING - No extra gap */
                line-height: 1.1 !important; /* Tighter line height */
                vertical-align: top;
                color: #000 !important; /* Force black text */
                font-size: 8pt !important; /* STRICTLY ENFORCE 8PT FONT */
            }

            th {
                background-color: #f0f0f0 !important; /* Light gray header background */
                font-weight: bold;
                text-transform: uppercase;
                text-align: center;
                font-size: 8pt !important;
            }

            /* Specific Column Alignment */
            .col-date { width: 10%; text-align: center; white-space: nowrap; }
            .col-desc { width: 42%; text-align: left; }
            .col-inv  { width: 12%; text-align: center; white-space: nowrap; }
            .col-amt  { width: 12%; text-align: right; font-family: 'Courier New', monospace; }

            /* 8. Summary Box - ADDED !important */
            .summary-box {
                margin-top: 10px;
                border: 1px solid #000;
                padding: 5px;
                page-break-inside: avoid;
                display: block !important; /* CRITICAL: Forces this block to show in print */
            }
            .summary-row {
                display: flex;
                justify-content: space-between;
                font-weight: bold;
                font-size: 10pt;
                margin-bottom: 2px;
            }

            /* 9. Footer - UPDATED for Center Date & Right Page Number */
            #print-footer {
                position: fixed;
                bottom: 0;
                left: 0;
                width: 100%;
                border-top: 1px solid #000;
                font-size: 7pt;
                padding-top: 2px;
                display: flex !important;
                justify-content: space-between;
                align-items: center;
            }

            /* Flex columns for equal spacing to ensure center is truly center */
            .pf-left {
                text-align: left;
                flex: 1;
            }
            .pf-center {
                text-align: center;
                flex: 1;
            }
            .pf-right {
                text-align: right;
                flex: 1;
            }

            /* CSS Page Counter logic */
            .page-number:after {
                counter-increment: page;
                /* content combines current page counter and Total pages variable (calculated via JS) */
                content: "Page " counter(page) " of " var(--total-pages, "..");
            }
            
            /* Hide Screen-Only Classes in Print */
            .shadow-md, .bg-white, .rounded-xl, .bg-gray-50, .bg-gray-100 {
                background: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            .text-gray-500, .text-gray-600, .text-gray-700, .text-gray-800, .text-gray-900 {
                color: #000 !important;
            }
            .text-green-600, .text-red-600, .text-red-700 {
                color: #000 !important; /* Remove colors for pure B&W print, or keep if color printer available */
            }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">

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

        <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['generate_ledger']) && empty($errorMessage)): ?>
        
        <div id="print-section">
            
            <div id="print-header" class="hidden print-only"> <img id="print-logo" src="images/logo.png" alt="Logo" style="display:block;">
                <div class="header-info">
                    <h1>Protection One (Pvt.) Ltd.</h1>
                    <p>A Complete Security Solutions</p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-md overflow-hidden mb-4 no-print">
                 <div class="p-6 border-b">
                     <h2 class="text-2xl font-bold text-gray-800 mb-1"><?php echo htmlspecialchars($selected_vendor_details['vendor_name'] ?? 'N/A'); ?></h2>
                     <p class="text-sm text-gray-600">Period: <?php echo htmlspecialchars($start_date_display); ?> to <?php echo htmlspecialchars($end_date_display); ?></p>
                 </div>
            </div>

            <div class="vendor-info-box hidden print-only"> <div class="vendor-info-col">
                    <strong>Vendor:</strong> <?php echo htmlspecialchars($selected_vendor_details['vendor_name'] ?? 'N/A'); ?><br>
                    <strong>Contact:</strong> <?php echo htmlspecialchars($selected_vendor_details['contact_person'] ?? ''); ?><br>
                    <strong>Phone:</strong> <?php echo htmlspecialchars($selected_vendor_details['phone'] ?? ''); ?>
                </div>
                <div class="vendor-info-col" style="text-align: right;">
                    <strong>Period:</strong> <?php echo htmlspecialchars($start_date_display); ?> to <?php echo htmlspecialchars($end_date_display); ?><br>
                    <strong>Email:</strong> <?php echo htmlspecialchars($selected_vendor_details['email'] ?? ''); ?><br>
                    <strong>Address:</strong> <?php echo htmlspecialchars($selected_vendor_details['address'] ?? ''); ?>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-md overflow-hidden print:shadow-none print:rounded-none">
                <div class="overflow-x-auto print:overflow-visible">
                    <table class="min-w-full print:w-full">
                        <thead class="bg-gray-50 border-b print:bg-gray-200">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase col-date">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase col-desc">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase col-inv">Invoice #</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase col-amt">Debit<br><span style="font-size:0.8em; font-weight:normal;">(Payment)</span></th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase col-amt">Credit<br><span style="font-size:0.8em; font-weight:normal;">(Purchase)</span></th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase col-amt">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 print:divide-y-0">
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="6" class="text-center py-10 text-gray-500">No transactions found for this period.</td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $txn): ?>
                                <tr>
                                    <td class="px-6 py-4 text-sm text-gray-600 col-date"><?php echo htmlspecialchars($txn['transaction_date']); ?></td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900 col-desc"><?php echo htmlspecialchars($txn['description']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-600 col-inv"><?php echo htmlspecialchars($txn['invoice_number'] ?? ''); ?></td>
                                    <td class="px-6 py-4 text-sm text-right text-green-600 col-amt">
                                        <?php echo $txn['debit'] > 0 ? number_format($txn['debit'], 2) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-right text-red-600 col-amt">
                                        <?php echo $txn['credit'] > 0 ? number_format($txn['credit'], 2) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-right font-semibold <?php echo $txn['balance'] < 0 ? 'text-red-700' : 'text-gray-800'; ?> col-amt">
                                        <?php echo number_format(abs($txn['balance']), 2) . ' ' . $txn['balance_indicator']; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="summary-section p-6 bg-gray-50 border-t print:bg-white print:border-none print:p-0 no-print">
                 <h3 class="text-lg font-semibold mb-3 text-gray-700">Ledger Summary</h3>
                 <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm font-medium text-gray-700">
                     <p>Total Payments: <span class="font-bold text-green-600"><?php echo number_format($total_debit, 2); ?></span></p>
                     <p>Total Purchases: <span class="font-bold text-red-600"><?php echo number_format($total_credit, 2); ?></span></p>
                     <p>Final Balance: <span class="font-bold <?php echo $final_balance < 0 ? 'text-red-700' : 'text-gray-800'; ?>"><?php echo number_format(abs($final_balance), 2) . ($final_balance < 0 ? " (Due)" : " (Adv)"); ?></span></p>
                 </div>
            </div>

            <div class="summary-box hidden print-only">
                <div class="summary-row">
                    <span>Total Payments (Debit):</span>
                    <span><?php echo number_format($total_debit, 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>Total Purchases (Credit):</span>
                    <span><?php echo number_format($total_credit, 2); ?></span>
                </div>
                <div class="summary-row" style="border-top: 1px dashed #000; padding-top: 2px; margin-top: 2px;">
                    <span>Final Balance:</span>
                    <span>
                        <?php echo number_format(abs($final_balance), 2); ?>
                        <?php 
                            if ($final_balance < 0) echo " (Due to Vendor)";
                            elseif ($final_balance > 0) echo " (Advance Paid)";
                            else echo " (Settled)";
                        ?>
                    </span>
                </div>
            </div>

            <div id="print-footer" class="hidden print-only">
                 <div class="pf-left">
                     Printed by: <?php echo htmlspecialchars($current_user_name); ?>
                 </div>
                 <div class="pf-center">
                     <span id="print-datetime-footer"></span>
                 </div>
                 <div class="pf-right">
                     <span class="page-number"></span>
                 </div>
            </div>

            <div class="mt-6 text-right no-print">
                <button onclick="window.print()" class="bg-gray-500 text-white font-semibold py-2 px-4 rounded-lg hover:bg-gray-600 transition text-sm">Print Ledger</button>
            </div>

        </div> <?php endif; ?>

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
     const dateStr = now.toLocaleDateString() + ' ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
     const footerTime = document.getElementById('print-datetime-footer');
     if(footerTime) footerTime.textContent = dateStr;

     // --- CALCULATE TOTAL PAGES ESTIMATION FOR PRINT ---
     // Standard browsers do not support "Total Pages" in CSS. This JS estimates it based on A4 height.
     // A4 Height (297mm) at 96PPI is approx 1123px. Subtracting margins (~40px) = 1083px.
     // We use a safe division to estimate the page count.
     if (document.getElementById('print-section')) {
         const contentHeight = document.body.scrollHeight;
         const a4HeightPx = 1123; 
         const estimatedPages = Math.ceil(contentHeight / a4HeightPx);
         document.documentElement.style.setProperty('--total-pages', '"' + estimatedPages + '"');
     }
});
</script>

</body>
</html>