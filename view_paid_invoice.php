<?php

require_once 'session_guard.php';
require_once 'connection.php';

if (!isset($_GET['id'])) exit('Invalid Request');
$invoice_id = (int)$_GET['id'];

$sql = "SELECT i.*, 
        ch.Company_Name, ch.Address as Head_Addr, ch.Contact_Number as Head_Phone,
        cb.Branch_Name, cb.Address as Branch_Addr, cb.Contact_Number1 as Branch_Phone,
        u.user_name as created_by_name
        FROM invoice i
        LEFT JOIN client_head ch ON i.client_head_id_fk = ch.client_head_id
        LEFT JOIN client_branch cb ON i.client_branch_id_fk = cb.client_branch_id
        LEFT JOIN users u ON i.created_by = u.user_id
        WHERE i.invoice_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();

if (!$inv) exit('<div class="p-4 text-red-500">Invoice not found.</div>');

$client_name = !empty($inv['Branch_Name']) ? $inv['Branch_Name'] : $inv['Company_Name'];
$client_addr = !empty($inv['Branch_Addr']) ? $inv['Branch_Addr'] : $inv['Head_Addr'];
$client_phone = !empty($inv['Branch_Phone']) ? $inv['Branch_Phone'] : $inv['Head_Phone'];

$status_badges = [
    0 => '<span class="bg-red-100 text-red-800 text-xs font-semibold px-2.5 py-0.5 rounded">Due</span>',
    1 => '<span class="bg-yellow-100 text-yellow-800 text-xs font-semibold px-2.5 py-0.5 rounded">Partially Paid</span>',
    2 => '<span class="bg-green-100 text-green-800 text-xs font-semibold px-2.5 py-0.5 rounded">Paid</span>'
];
$status_html = $status_badges[$inv['status']] ?? 'Unknown';

$sql_items = "SELECT sp.*, m.model_name, b.brand_name 
              FROM sold_product sp
              LEFT JOIN models m ON sp.model_id_fk = m.model_id
              LEFT JOIN brands b ON m.brand_id = b.brand_id
              WHERE sp.invoice_id_fk = ?";
$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $invoice_id);
$stmt_items->execute();
$res_items = $stmt_items->get_result();

$sql_pay = "SELECT * FROM payments WHERE invoice_id_fk = ? ORDER BY payment_date DESC";
$stmt_pay = $conn->prepare($sql_pay);
$stmt_pay->bind_param("i", $invoice_id);
$stmt_pay->execute();
$res_pay = $stmt_pay->get_result();

$total_paid = 0;
?>

<div class="space-y-6">
    <div class="flex justify-between items-start border-b pb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Invoice #<?php echo htmlspecialchars($inv['Invoice_No']); ?></h1>
            <p class="text-sm text-gray-500">Created: <?php echo date('M d, Y', strtotime($inv['created_at'])); ?></p>
        </div>
        <div class="text-right">
            <?php echo $status_html; ?>
            <p class="text-xs text-gray-400 mt-1">By: <?php echo htmlspecialchars($inv['created_by_name']); ?></p>
        </div>
    </div>

    <div class="bg-gray-50 p-4 rounded-lg text-sm">
        <h3 class="font-bold text-gray-700 uppercase mb-2">Bill To:</h3>
        <p class="font-semibold text-lg"><?php echo htmlspecialchars($client_name); ?></p>
        <p class="text-gray-600"><?php echo htmlspecialchars($client_addr); ?></p>
        <p class="text-gray-600">Phone: <?php echo htmlspecialchars($client_phone); ?></p>
    </div>

    <div>
        <h3 class="font-bold text-gray-700 mb-2">Line Items</h3>
        <table class="w-full text-sm text-left text-gray-500 border rounded-lg overflow-hidden">
            <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                <tr>
                    <th class="px-4 py-2">Product</th>
                    <th class="px-4 py-2 text-right">Qty</th>
                    <th class="px-4 py-2 text-right">Unit Price</th>
                    <th class="px-4 py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php while($item = $res_items->fetch_assoc()): 
                    $line_total = $item['Quantity'] * $item['Sold_Unit_Price'];
                ?>
                <tr class="bg-white border-b">
                    <td class="px-4 py-2 font-medium text-gray-900">
                        <?php echo htmlspecialchars($item['brand_name'] . ' ' . $item['model_name']); ?>
                    </td>
                    <td class="px-4 py-2 text-right"><?php echo $item['Quantity']; ?></td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($item['Sold_Unit_Price'], 2); ?></td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($line_total, 2); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
            <tfoot class="bg-gray-50 font-bold text-gray-900">
                <tr>
                    <td colspan="3" class="px-4 py-2 text-right">Grand Total</td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($inv['IncludingTax_TotalPrice'], 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div>
        <h3 class="font-bold text-gray-700 mb-2">Payment History</h3>
        <?php if ($res_pay->num_rows > 0): ?>
            <table class="w-full text-sm text-left text-gray-500 border rounded-lg">
                <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                    <tr>
                        <th class="px-4 py-2">Date</th>
                        <th class="px-4 py-2">Receipt No</th>
                        <th class="px-4 py-2">Method</th>
                        <th class="px-4 py-2 text-right">Amount</th>
                        <th class="px-4 py-2 text-center">Action</th> </tr>
                </thead>
                <tbody>
                    <?php while($pay = $res_pay->fetch_assoc()): 
                        $total_paid += $pay['amount'];
                    ?>
                    <tr class="bg-white border-b">
                        <td class="px-4 py-2"><?php echo date('M d, Y', strtotime($pay['payment_date'])); ?></td>
                        <td class="px-4 py-2 font-mono text-xs"><?php echo htmlspecialchars($pay['money_receipt_no']); ?></td>
                        <td class="px-4 py-2">
                            <?php echo htmlspecialchars($pay['payment_method']); ?>
                            <?php if($pay['transaction_number']) echo '<br><span class="text-xs text-gray-400">#'.$pay['transaction_number'].'</span>'; ?>
                        </td>
                        <td class="px-4 py-2 text-right text-green-600 font-medium"><?php echo number_format($pay['amount'], 2); ?></td>
                        <td class="px-4 py-2 text-center">
                            <button onclick="printFromUrl('print_money_receipt.php?id=<?php echo $pay['payment_id']; ?>&print=true')" 
                                    class="inline-flex items-center px-2.5 py-1.5 border border-transparent text-xs font-medium rounded text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                                Print
                            </button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-sm text-gray-500 italic">No payments recorded yet.</p>
        <?php endif; ?>
    </div>

    <div class="flex justify-end pt-4 border-t">
        <div class="text-right w-1/2">
            <div class="flex justify-between mb-1">
                <span>Total Payable:</span>
                <span class="font-bold"><?php echo number_format($inv['IncludingTax_TotalPrice'], 2); ?></span>
            </div>
            <div class="flex justify-between mb-1 text-green-600">
                <span>Total Paid:</span>
                <span class="font-bold">-<?php echo number_format($total_paid, 2); ?></span>
            </div>
            <div class="flex justify-between border-t border-gray-300 pt-2 text-lg font-bold text-red-600">
                <span>Balance Due:</span>
                <span><?php echo number_format($inv['IncludingTax_TotalPrice'] - $total_paid, 2); ?></span>
            </div>
        </div>
    </div>
</div>

