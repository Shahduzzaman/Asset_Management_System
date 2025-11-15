<?php
session_start();
require_once 'connection.php'; // Uses $conn (mysqli)

// --- Security & Session Check ---
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$invoice_id = isset($_GET['invoice_id']) ? (int)$_GET['invoice_id'] : 0;
if ($invoice_id === 0) {
    die("Invalid invoice ID.");
}

// Set error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // 1. Get Invoice Details
    $stmt_invoice = $conn->prepare("
        SELECT i.*, ch.Company_Name, cb.Branch_Name, cb.Address AS Branch_Address, wo.Order_No
        FROM invoice i
        JOIN sold_product sp ON i.invoice_id = sp.invoice_id_fk
        JOIN client_head ch ON sp.client_head_id_fk = ch.client_head_id
        JOIN client_branch cb ON sp.client_branch_id_fk = cb.client_branch_id
        LEFT JOIN work_order wo ON sp.work_order_id_fk = wo.work_order_id
        WHERE i.invoice_id = ?
        LIMIT 1
    ");
    $stmt_invoice->bind_param("i", $invoice_id);
    $stmt_invoice->execute();
    $invoice = $stmt_invoice->get_result()->fetch_assoc();
    $stmt_invoice->close();

    if (!$invoice) {
        die("Invoice not found or you do not have permission to view it.");
    }

    // 2. Get Sold Items
    $stmt_items = $conn->prepare("
        SELECT 
            s.Quantity, s.Sold_Unit_Price, s.Remarks,
            m.model_name,
            b.brand_name,
            c.category_name,
            psl.product_sl
        FROM sold_product s
        JOIN models m ON s.model_id_fk = m.model_id
        JOIN brands b ON m.brand_id = b.brand_id
        JOIN categories c ON m.category_id = c.category_id
        LEFT JOIN product_sl psl ON s.product_sl_id_fk = psl.sl_id
        WHERE s.invoice_id_fk = ?
        GROUP BY s.sold_product_id
    ");
    $stmt_items->bind_param("i", $invoice_id);
    $stmt_items->execute();
    $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_items->close();

} catch (Exception $e) {
    die("Database error loading invoice: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo htmlspecialchars($invoice['Invoice_No']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                margin: 0;
                padding: 0;
            }
            .print-container {
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body class="bg-gray-200">

    <div class="print-container w-full max-w-4xl mx-auto bg-white shadow-xl my-0 md:my-10 p-8 md:p-12">
        
        <!-- Header -->
        <div class="flex justify-between items-center pb-8 border-b">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">INVOICE</h1>
                <p class="text-lg font-semibold text-gray-700 mt-1"><?php echo htmlspecialchars($invoice['Invoice_No']); ?></p>
            </div>
            <div class="text-right">
                <h2 class="text-2xl font-semibold text-gray-800">Your Company Name</h2>
                <p class="text-gray-600">Your Address Line 1</p>
                <p class="text-gray-600">Your Phone & Email</p>
            </div>
        </div>

        <!-- Client & Date Info -->
        <div class="grid grid-cols-2 gap-8 mt-8">
            <div>
                <h3 class="text-sm font-semibold text-gray-600 uppercase">SOLD TO:</h3>
                <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($invoice['Company_Name']); ?></p>
                <p class="text-gray-700"><?php echo htmlspecialchars($invoice['Branch_Name']); ?></p>
                <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($invoice['Branch_Address'])); ?></p>
            </div>
            <div class="text-right">
                <div class="mb-4">
                    <h3 class="text-sm font-semibold text-gray-600 uppercase">Invoice Date:</h3>
                    <p class="text-lg font-medium text-gray-900"><?php echo date("F j, Y", strtotime($invoice['created_at'])); ?></p>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-600 uppercase">Work Order:</h3>
                    <p class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($invoice['Order_No'] ?: 'N/A'); ?></p>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div class="mt-10">
            <h3 class="text-sm font-semibold text-gray-600 uppercase border-b pb-2 mb-3">Order Summary</h3>
            <table class="min-w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="text-left py-3 px-4 text-xs font-semibold text-gray-500 uppercase">Product Details</th>
                        <th class="text-left py-3 px-4 text-xs font-semibold text-gray-500 uppercase">Serial No.</th>
                        <th class="text-right py-3 px-4 text-xs font-semibold text-gray-500 uppercase">Qty</th>
                        <th class="text-right py-3 px-4 text-xs font-semibold text-gray-500 uppercase">Unit Price</th>
                        <th class="text-right py-3 px-4 text-xs font-semibold text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($items as $item): 
                        $total = (float)$item['Quantity'] * (float)$item['Sold_Unit_Price'];
                    ?>
                    <tr>
                        <td class="py-3 px-4">
                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars($item['model_name']); ?></div>
                            <div class="text-xs text-gray-600"><?php echo htmlspecialchars($item['category_name']); ?> | <?php echo htmlspecialchars($item['brand_name']); ?></div>
                            <?php if ($item['Remarks']): ?>
                                <div class="text-xs text-gray-500 italic pt-1">Remark: <?php echo htmlspecialchars($item['Remarks']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-sm text-gray-700"><?php echo htmlspecialchars($item['product_sl'] ?: 'N/A'); ?></td>
                        <td class="py-3 px-4 text-right text-gray-700"><?php echo $item['Quantity']; ?></td>
                        <td class="py-3 px-4 text-right text-gray-700"><?php echo number_format($item['Sold_Unit_Price'], 2); ?></td>
                        <td class="py-3 px-4 text-right font-medium text-gray-900"><?php echo number_format($total, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="flex justify-end mt-10">
            <div class="w-full max-w-sm space-y-3">
                <div class="flex justify-between text-lg">
                    <span class="font-medium text-gray-700">Subtotal:</span>
                    <span class="font-medium text-gray-900"><?php echo number_format($invoice['ExcludingTax_TotalPrice'], 2); ?></span>
                </div>
                <div class="flex justify-between text-lg">
                    <span class="font-medium text-gray-700">Tax (<?php echo $invoice['Tax_Percentage']; ?>%):</span>
                    <span class="font-medium text-gray-900"><?php echo number_format($invoice['IncludingTax_TotalPrice'] - $invoice['ExcludingTax_TotalPrice'], 2); ?></span>
                </div>
                <hr class="my-2">
                <div class="flex justify-between text-2xl font-bold">
                    <span class="text-gray-900">Grand Total:</span>
                    <span class="text-gray-900"><?php echo number_format($invoice['IncludingTax_TotalPrice'], 2); ?></span>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-16 pt-8 border-t text-center text-gray-500 text-sm">
            <p>Thank you for your business!</p>
            <p>Invoice generated on <?php echo date("F j, Y, g:i a"); ?></p>
        </div>
    </div>
    
    <!-- Print Button -->
    <div class="no-print w-full max-w-4xl mx-auto my-6 text-right">
        <button onclick="window.print()" class="bg-blue-600 text-white font-bold py-3 px-6 rounded-lg shadow-md hover:bg-blue-700 transition duration-150">
            <i class="fas fa-print mr-2"></i>Print Memo
        </button>
    </div>

</body>
</html>