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
                inv.Invoice_No,
                inv.created_at as invoice_date,
                inv.ExcludingTax_TotalPrice as sub_total,
                inv.IncludingTax_TotalPrice as grand_total,
                inv.Tax_Percentage,
                u.user_name as Created_By_User,
                -- Client & Branch & Work Order Info (Linked via sold_product)
                ch.Company_Name,
                ch.Address as Head_Address,
                ch.Contact_Number,
                cb.Branch_Name,
                cb.Address as Branch_Address,
                wo.Order_No as WO_No,
                wo.Order_Date as WO_Date
              FROM invoice inv
              LEFT JOIN users u ON inv.created_by = u.user_id
              -- Join to sold_product to bridge to client info
              LEFT JOIN sold_product sp ON inv.invoice_id = sp.invoice_id_fk
              LEFT JOIN client_branch cb ON sp.client_branch_id_fk = cb.client_branch_id
              LEFT JOIN client_head ch ON sp.client_head_id_fk = ch.client_head_id
              LEFT JOIN work_order wo ON sp.work_order_id_fk = wo.work_order_id
              WHERE inv.invoice_id = ?
              LIMIT 1"; 

$stmt = $conn->prepare($sql_master);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    die("Invoice not found.");
}

// --- 2. Fetch Sold Items ---
$sql_items = "SELECT 
                sp.Quantity,
                sp.Sold_Unit_Price,
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

// --- 3. Calculate Tax Amount ---
$tax_amount = $invoice['grand_total'] - $invoice['sub_total'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo htmlspecialchars($invoice['Invoice_No']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @media print {
            body { background-color: white; -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
            .shadow-lg { box-shadow: none; }
            .border { border: 1px solid #ddd; }
            /* Ensure logo prints clearly */
            img { -webkit-print-color-adjust: exact; }
        }
        .invoice-container { max-width: 800px; margin: 0 auto; background: white; }
    </style>
</head>
<body class="<?php echo $is_print ? 'bg-white' : 'bg-gray-100'; ?> text-gray-800 p-4">

    <!-- Invoice Box -->
    <div class="invoice-container <?php echo $is_print ? '' : 'shadow-lg border rounded-lg p-8 mt-4'; ?>">
        
        <!-- HEADER SECTION -->
        <div class="flex justify-between items-center border-b-2 border-gray-200 pb-6 mb-6">
            
            <!-- Left: Logo & Branding -->
            <div class="flex items-center">
                <!-- Logo -->
                <div class="mr-5">
                    <img src="images/logo.png" alt="Protection One" style="max-height: 90px; width: auto;" onerror="this.style.display='none'; document.getElementById('alt-logo').style.display='block';">
                    <!-- Fallback text if image is missing -->
                    <h2 id="alt-logo" style="display:none;" class="text-2xl font-bold text-blue-800">Protection One</h2>
                </div>
                
                <!-- Company Name & Tagline -->
                <div>
                    <h1 class="text-4xl font-extrabold text-gray-900 leading-none uppercase tracking-tight" style="font-family: sans-serif;">Protection One</h1>
                    <p class="text-base font-semibold text-blue-600 italic mt-1">A Complete Security Solution</p>
                </div>
            </div>

            <!-- Right: Contact Info (Title removed from here) -->
            <div class="text-right">
                <div class="text-sm text-gray-600 space-y-1">
                    <p class="mt-2"><strong>Head Office:</strong> House 48, Road 02, Block L,</p>
                    <p>Banani, Dhaka- 1213, Bangladesh</p>
                    <p><i class="fas fa-phone-alt mr-1"></i> +880 1755-551912</p>
                    <p><i class="fas fa-envelope mr-1"></i> info@protectionone.com.bd</p>
                </div>
            </div>
        </div>

        <!-- INVOICE TITLE SECTION (Centered) -->
        <div class="text-center mb-8">
            <h2 class="text-4xl font-bold text-gray-800 tracking-widest uppercase inline-block pb-1">INVOICE</h2>
            <p class="text-sm text-gray-500 mt-1 font-medium uppercase tracking-wide">Original Copy</p>
        </div>

        <!-- INFO GRID (Bill To & Meta Data) -->
        <div class="flex justify-between mb-8 gap-8">
            
            <!-- Client Info -->
            <div class="w-1/2">
                <h3 class="text-xs font-bold text-gray-500 uppercase mb-2 tracking-wider">Bill To:</h3>
                <div class="bg-gray-50 p-4 rounded border border-gray-100">
                    <div class="text-gray-900 font-bold text-lg"><?php echo htmlspecialchars($invoice['Company_Name'] ?? 'Walk-in Client'); ?></div>
                    <div class="text-gray-700 font-medium"><?php echo htmlspecialchars($invoice['Branch_Name'] ?? ''); ?></div>
                    <div class="text-sm text-gray-600 mt-2 whitespace-pre-line leading-relaxed"><?php echo htmlspecialchars($invoice['Branch_Address'] ?? $invoice['Head_Address']); ?></div>
                    <?php if(!empty($invoice['Contact_Number'])): ?>
                        <div class="text-sm text-gray-600 mt-2"><span class="font-semibold">Contact:</span> <?php echo htmlspecialchars($invoice['Contact_Number']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Invoice Meta -->
            <div class="w-1/2 text-right flex flex-col justify-end">
                <table class="w-full text-sm">
                    <tr>
                        <td class="text-gray-500 font-medium py-1">Invoice No:</td>
                        <td class="text-gray-900 font-bold py-1"><?php echo htmlspecialchars($invoice['Invoice_No']); ?></td>
                    </tr>
                    <tr>
                        <td class="text-gray-500 font-medium py-1">Date:</td>
                        <td class="text-gray-900 font-semibold py-1"><?php echo date('F d, Y', strtotime($invoice['invoice_date'])); ?></td>
                    </tr>
                    <?php if(!empty($invoice['WO_No'])): ?>
                    <tr>
                        <td class="text-gray-500 font-medium py-1">Work Order:</td>
                        <td class="text-gray-900 font-semibold py-1"><?php echo htmlspecialchars($invoice['WO_No']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-gray-500 font-medium py-1">Sales Person:</td>
                        <td class="text-gray-900 py-1"><?php echo htmlspecialchars($invoice['Created_By_User'] ?? 'N/A'); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- ITEMS TABLE -->
        <div class="overflow-hidden border rounded-lg mb-8">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-800 text-white">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">#</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider">Unit Price</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider">Total</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    $counter = 1;
                    while($item = $result_items->fetch_assoc()): 
                        $line_total = $item['Quantity'] * $item['Sold_Unit_Price'];
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-500"><?php echo $counter++; ?></td>
                        <td class="px-4 py-3 text-sm text-gray-800">
                            <div class="font-bold text-gray-900"><?php echo htmlspecialchars($item['model_name']); ?></div>
                            <?php if(!empty($item['serial_no'])): ?>
                                <div class="text-xs text-gray-500 italic mt-1">SN: <?php echo htmlspecialchars($item['serial_no']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800"><?php echo $item['Quantity']; ?></td>
                        <td class="px-4 py-3 text-sm text-right text-gray-800"><?php echo number_format($item['Sold_Unit_Price'], 2); ?></td>
                        <td class="px-4 py-3 text-sm text-right font-medium text-gray-900"><?php echo number_format($line_total, 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- TOTALS SECTION -->
        <div class="flex justify-end mb-12">
            <div class="w-1/2 md:w-5/12">
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-600 text-sm font-medium">Sub Total</span>
                    <span class="text-gray-900 font-bold"><?php echo number_format($invoice['sub_total'], 2); ?></span>
                </div>
                
                <?php if($tax_amount > 0): ?>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Tax (<?php echo number_format($invoice['Tax_Percentage'], 2); ?>%)</span>
                    <span class="text-gray-900 font-semibold"><?php echo number_format($tax_amount, 2); ?></span>
                </div>
                <?php endif; ?>
                
                <!-- Calculate discount if implied (Sub+Tax - Grand) -->
                <?php 
                    $calculated_grand = $invoice['sub_total'] + $tax_amount;
                    $discount_val = $calculated_grand - $invoice['grand_total'];
                    if($discount_val > 0.01): 
                ?>
                <div class="flex justify-between py-2 border-b border-gray-100">
                    <span class="text-gray-600 text-sm">Discount</span>
                    <span class="text-red-600 font-semibold">- <?php echo number_format($discount_val, 2); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="flex justify-between py-4 border-t-2 border-gray-800 mt-2 items-center">
                    <span class="text-gray-800 font-bold text-xl">Total</span>
                    <span class="text-gray-900 font-bold text-2xl"><?php echo number_format($invoice['grand_total'], 2); ?></span>
                </div>
            </div>
        </div>

        <!-- FOOTER / SIGNATURES -->
        <div class="mt-12 pt-4 grid grid-cols-2 gap-12">
            <div class="text-center mt-12">
                <div class="border-t border-gray-400 w-3/4 mx-auto"></div>
                <p class="text-xs text-gray-500 mt-2 uppercase tracking-wide">Customer Signature</p>
            </div>
            <div class="text-center mt-12">
                <div class="border-t border-gray-400 w-3/4 mx-auto"></div>
                <p class="text-xs text-gray-500 mt-2 uppercase tracking-wide">Authorized Signature</p>
            </div>
        </div>
        
        <div class="text-center text-xs text-gray-400 mt-12 border-t pt-4">
            <p>Thank you for choosing Protection One!</p>
            <p class="mt-1">For support, please contact: +880 1755-551912 or info@protectionone.com.bd</p>
        </div>

    </div>

    <?php if($is_print): ?>
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500); // Small delay to ensure logo loads
        }
    </script>
    <?php endif; ?>

</body>
</html>