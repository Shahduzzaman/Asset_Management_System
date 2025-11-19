<?php
session_start();
require_once 'connection.php';

// Security Check
if (!isset($_SESSION["user_id"])) {
    die("Access Denied");
}

if (!isset($_GET['id'])) {
    die("Invalid Invoice ID");
}

$invoice_id = (int)$_GET['id'];
$is_print = isset($_GET['print']) && $_GET['print'] == 'true';

// --- 1. Fetch Invoice Master Data ---
$sql_master = "SELECT 
    inv.*, 
    ch.Company_Name, 
    ch.Address AS Head_Address,
    ch.Contact_Number,
    ch.Email,
    cb.Branch_Name,
    cb.Address AS Branch_Address,
    wo.Order_No AS WO_No,
    wo.Order_Date AS WO_Date,
    u.user_name AS Created_By_User
FROM invoice inv
LEFT JOIN sold_product sp ON inv.invoice_id = sp.invoice_id_fk
LEFT JOIN client_branch cb ON sp.client_branch_id_fk = cb.client_branch_id
LEFT JOIN client_head ch ON cb.client_head_id_fk = ch.client_head_id
LEFT JOIN work_order wo ON sp.work_order_id_fk = wo.work_order_id
LEFT JOIN users u ON inv.created_by = u.user_id
WHERE inv.invoice_id = ?
";

$stmt = $conn->prepare($sql_master);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    die("Invoice not found.");
}

// --- 2. Fetch Sold Items ---
$sql_items = "SELECT 
                sp.*, 
                m.model_name, 
                sl.product_sl as serial_no 
              FROM sold_product sp
              JOIN models m ON sp.model_id_fk = m.model_id
              LEFT JOIN product_sl sl ON sp.product_sl_id_fk = sl.sl_id
              WHERE sp.invoice_id_fk = ?";

$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $invoice_id);
$stmt_items->execute();
$result_items = $stmt_items->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo $invoice['Invoice_No']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { background-color: white; -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
            .shadow-lg { box-shadow: none; }
            .border { border: 1px solid #ddd; }
        }
        .invoice-container { max-width: 800px; margin: 0 auto; background: white; }
    </style>
</head>
<body class="<?php echo $is_print ? 'bg-white' : 'bg-gray-100'; ?> text-gray-800 p-4">

    <!-- Invoice Box -->
    <div class="invoice-container <?php echo $is_print ? '' : 'shadow-lg border rounded-lg p-8 mt-4'; ?>">
        
        <!-- Header -->
        <div class="flex justify-between items-start border-b pb-6 mb-6">
            <div>
                <!-- You can add an <img> tag here for a logo -->
                <h1 class="text-3xl font-bold text-gray-800 uppercase tracking-wide">INVOICE</h1>
                <p class="text-sm text-gray-500 mt-1">Original Copy</p>
            </div>
            <div class="text-right">
                <h2 class="text-xl font-bold text-gray-700">Company Name (AMS)</h2>
                <p class="text-sm text-gray-600">123 Business Street</p>
                <p class="text-sm text-gray-600">Dhaka, Bangladesh</p>
                <p class="text-sm text-gray-600">Phone: +880 1234 567890</p>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="flex justify-between mb-8 gap-8">
            
            <!-- Client Info -->
            <div class="w-1/2">
                <h3 class="text-xs font-bold text-gray-500 uppercase mb-2">Bill To:</h3>
                <div class="text-gray-800 font-bold text-lg"><?php echo htmlspecialchars($invoice['Company_Name']); ?></div>
                <div class="text-gray-600"><?php echo htmlspecialchars($invoice['branch_name']); ?></div>
                <div class="text-sm text-gray-500 mt-1 whitespace-pre-line"><?php echo htmlspecialchars($invoice['Branch_Address'] ?? $invoice['Head_Address']); ?></div>
                <?php if(!empty($invoice['Contact_Number'])): ?>
                    <div class="text-sm text-gray-500 mt-1">Tel: <?php echo htmlspecialchars($invoice['Contact_Number']); ?></div>
                <?php endif; ?>
            </div>

            <!-- Invoice Meta -->
            <div class="w-1/2 text-right">
                <table class="w-full">
                    <tr>
                        <td class="text-gray-600 text-sm font-semibold py-1">Invoice No:</td>
                        <td class="text-gray-800 font-bold py-1"><?php echo $invoice['Invoice_No']; ?></td>
                    </tr>
                    <tr>
                        <td class="text-gray-600 text-sm font-semibold py-1">Date:</td>
                        <td class="text-gray-800 py-1"><?php echo date('F d, Y', strtotime($invoice['invoice_date'])); ?></td>
                    </tr>
                    <?php if(!empty($invoice['WO_No'])): ?>
                    <tr>
                        <td class="text-gray-600 text-sm font-semibold py-1">Work Order:</td>
                        <td class="text-gray-800 py-1"><?php echo htmlspecialchars($invoice['WO_No']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-gray-600 text-sm font-semibold py-1">Created By:</td>
                        <td class="text-gray-800 py-1"><?php echo htmlspecialchars($invoice['Created_By_User']); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Items Table -->
        <div class="overflow-hidden border rounded-lg mb-8">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    $counter = 1;
                    while($item = $result_items->fetch_assoc()): 
                    ?>
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-500"><?php echo $counter++; ?></td>
                        <td class="px-4 py-3 text-sm text-gray-800">
                            <div class="font-medium"><?php echo htmlspecialchars($item['model_name']); ?></div>
                            <?php if(!empty($item['serial_no'])): ?>
                                <div class="text-xs text-gray-500">SN: <?php echo htmlspecialchars($item['serial_no']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800"><?php echo $item['quantity']; ?></td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800"><?php echo number_format($item['total_price'], 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="flex justify-end mb-8">
            <div class="w-1/2 md:w-1/3">
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Sub Total</span>
                    <span class="text-gray-800 font-semibold"><?php echo number_format($invoice['sub_total'], 2); ?></span>
                </div>
                
                <?php if($invoice['tax_amount'] > 0): ?>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Tax (<?php echo $invoice['tax_percent']; ?>%)</span>
                    <span class="text-gray-800 font-semibold"><?php echo number_format($invoice['tax_amount'], 2); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if($invoice['discount'] > 0): ?>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Discount</span>
                    <span class="text-red-600 font-semibold">- <?php echo number_format($invoice['discount'], 2); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="flex justify-between py-3 border-t-2 border-gray-800 mt-2">
                    <span class="text-gray-800 font-bold text-lg">Grand Total</span>
                    <span class="text-gray-800 font-bold text-lg"><?php echo number_format($invoice['grand_total'], 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Footer / Signatures -->
        <div class="mt-12 pt-8 border-t grid grid-cols-2 gap-8">
            <div class="text-center mt-8">
                <div class="border-t border-gray-400 w-3/4 mx-auto"></div>
                <p class="text-xs text-gray-500 mt-2">Customer Signature</p>
            </div>
            <div class="text-center mt-8">
                <div class="border-t border-gray-400 w-3/4 mx-auto"></div>
                <p class="text-xs text-gray-500 mt-2">Authorized Signature</p>
            </div>
        </div>
        
        <div class="text-center text-xs text-gray-400 mt-8">
            Thank you for your business!
        </div>

    </div>

    <?php if($is_print): ?>
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
    <?php endif; ?>

</body>
</html>