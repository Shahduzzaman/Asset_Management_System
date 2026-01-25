<?php
require_once 'session_guard.php';
require_once 'connection.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo "<div class='text-red-500 p-4'>Invalid Work Order ID.</div>";
    exit;
}

$work_order_id = (int)$_GET['id'];

$sql_head = "SELECT 
                w.Order_No, w.Order_Date, w.created_at,
                u.user_name as created_by_name,
                ch.Company_Name, ch.Address as Head_Address, ch.Contact_Person as Head_Contact, ch.Contact_Number as Head_Phone,
                cb.Branch_Name, cb.Address as Branch_Address, cb.Contact_Person1 as Branch_Contact, cb.Contact_Number1 as Branch_Phone
             FROM work_order w
             LEFT JOIN client_head ch ON w.client_head_id_fk = ch.client_head_id
             LEFT JOIN client_branch cb ON w.client_branch_id_fk = cb.client_branch_id
             LEFT JOIN users u ON w.created_by = u.user_id
             WHERE w.work_order_id = ? AND w.is_deleted = 0";

$stmt = $conn->prepare($sql_head);
$stmt->bind_param("i", $work_order_id);
$stmt->execute();
$result_head = $stmt->get_result();
$order = $result_head->fetch_assoc();

if (!$order) {
    echo "<div class='text-red-500 p-4'>Work Order not found.</div>";
    exit;
}
$client_name = !empty($order['Branch_Name']) ? $order['Branch_Name'] : $order['Company_Name'];
$client_address = !empty($order['Branch_Address']) ? $order['Branch_Address'] : $order['Head_Address'];
$client_contact = !empty($order['Branch_Contact']) ? $order['Branch_Contact'] : $order['Head_Contact'];
$client_phone = !empty($order['Branch_Phone']) ? $order['Branch_Phone'] : $order['Head_Phone'];

$sql_items = "SELECT 
                sp.Quantity, sp.Sold_Unit_Price, sp.Remarks,
                m.model_name,
                b.brand_name,
                c.category_name,
                psl.product_sl as serial_no
              FROM sold_product sp
              LEFT JOIN models m ON sp.model_id_fk = m.model_id
              LEFT JOIN brands b ON m.brand_id = b.brand_id
              LEFT JOIN categories c ON m.category_id = c.category_id
              LEFT JOIN product_sl psl ON sp.product_sl_id_fk = psl.sl_id
              WHERE sp.work_order_id_fk = ? AND sp.is_deleted = 0";

$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $work_order_id);
$stmt_items->execute();
$result_items = $stmt_items->get_result();

?>

<div class="space-y-6">
    
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b pb-4">
        <div class="max-w-full sm:max-w-[70%]">
            <p class="text-xl font-bold text-gray-800 break-all">Order #<?php echo htmlspecialchars($order['Order_No']); ?></p>
            <p class="text-sm text-gray-500">Date: <?php echo date('M d, Y', strtotime($order['Order_Date'])); ?></p>
        </div>
        <div class="mt-2 sm:mt-0 text-right shrink-0">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                Created by: <?php echo htmlspecialchars($order['created_by_name']); ?>
            </span>
        </div>
    </div>

    <div class="bg-gray-50 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">Client Information</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-gray-500">Client Name</p>
                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($client_name ?? 'N/A'); ?></p>
            </div>
            <div>
                <p class="text-gray-500">Contact Person</p>
                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($client_contact ?? 'N/A'); ?></p>
            </div>
            <div>
                <p class="text-gray-500">Phone</p>
                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($client_phone ?? 'N/A'); ?></p>
            </div>
            <div class="sm:col-span-2">
                <p class="text-gray-500">Address</p>
                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($client_address ?? 'N/A'); ?></p>
            </div>
        </div>
    </div>

    <div>
        <h4 class="text-sm font-semibold text-gray-900 uppercase tracking-wider mb-3">Ordered Items</h4>
        <div class="border rounded-lg overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Serial</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php 
                    $grand_total = 0;
                    if ($result_items->num_rows > 0): 
                        while($item = $result_items->fetch_assoc()): 
                            $line_total = $item['Quantity'] * $item['Sold_Unit_Price'];
                            $grand_total += $line_total;
                            $product_display = htmlspecialchars($item['brand_name'] . ' ' . $item['model_name']);
                            if(!empty($item['category_name'])) $product_display .= " <span class='text-gray-400 text-xs'>(".$item['category_name'].")</span>";
                    ?>
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-900">
                                <?php echo $product_display; ?>
                                <?php if(!empty($item['Remarks'])): ?>
                                    <p class="text-xs text-gray-400 mt-1">Note: <?php echo htmlspecialchars($item['Remarks']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600"><?php echo $item['serial_no'] ? htmlspecialchars($item['serial_no']) : '-'; ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right"><?php echo $item['Quantity']; ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right"><?php echo number_format($item['Sold_Unit_Price'], 2); ?></td>
                            <td class="px-4 py-3 text-sm font-medium text-gray-900 text-right"><?php echo number_format($line_total, 2); ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="5" class="px-4 py-3 text-center text-gray-500">No items found for this order.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-right font-bold text-gray-900">Grand Total</td>
                        <td class="px-4 py-3 text-right font-bold text-blue-600"><?php echo number_format($grand_total, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php 
$stmt->close();
$stmt_items->close();
$conn->close(); 
?>