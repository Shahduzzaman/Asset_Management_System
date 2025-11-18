<?php
// This file is intended to be included in add_to_cart.php,
// so it assumes $conn and $current_user_id are available.

// Fetch combined clients (Head and Branch)
$client_list = [];
$sql_clients = "
    (SELECT 
        client_head_id AS id, 
        client_head_name AS name, 
        'head' AS type,
        email,
        phone
     FROM client_head 
     WHERE is_deleted = 0)
    UNION
    (SELECT 
        client_branch_id AS id, 
        branch_name AS name, 
        'branch' AS type,
        email,
        phone
     FROM client_branch 
     WHERE is_deleted = 0)
    ORDER BY name ASC
";

$result_clients = $conn->query($sql_clients);
if ($result_clients) {
    while ($row = $result_clients->fetch_assoc()) {
        $client_list[] = $row;
    }
}
?>

<!-- Section 3: Checkout -->
<div class="bg-white p-6 rounded-lg shadow-md mt-6">
    <h2 class="text-2xl font-semibold text-gray-800 mb-6 border-b pb-3">3. Process Sale</h2>

    <form id="checkout-form">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Client Selection -->
            <div>
                <label for="client-select" class="block text-sm font-medium text-gray-700 mb-1">Client<span class="text-red-500">*</span></label>
                <select id="client-select" name="client_id" class="w-full">
                    <option value="">Select a client</option>
                    <?php foreach ($client_list as $client): ?>
                        <option value="<?php echo htmlspecialchars($client['type'] . '_' . $client['id']); ?>">
                            <?php echo htmlspecialchars($client['name']); ?>
                            (<?php echo htmlspecialchars(ucfirst($client['type'])); ?>)
                             - <?php echo htmlspecialchars($client['phone'] ?? $client['email'] ?? 'No contact'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small id="client-info" class="text-gray-500 mt-1"></small>
            </div>

            <!-- Work Order Selection -->
            <div>
                <label for="work-order-select" class="block text-sm font-medium text-gray-700 mb-1">Work Order</label>
                <select id="work-order-select" name="work_order_id" class="w-full" disabled>
                    <option value="">Select client first</option>
                </select>
            </div>

            <!-- Sold Date -->
            <div>
                <label for="sold-date" class="block text-sm font-medium text-gray-700 mb-1">Sold Date<span class="text-red-500">*</span></label>
                <input type="date" id="sold-date" name="sold_date"
                       class="w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                       value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>

        <!-- Totals Section -->
        <div class="mt-8 border-t pt-6 max-w-sm ml-auto">
            <div class="space-y-4">
                <div class="flex justify-between items-center">
                    <span class="text-lg font-medium text-gray-700">Subtotal:</span>
                    <span id="checkout-subtotal" class="text-lg font-semibold text-gray-900">৳0.00</span>
                </div>
                <div class="flex justify-between items-center">
                    <label for="tax-percentage" class="text-lg font-medium text-gray-700">Tax (%):</label>
                    <input type="number" id="tax-percentage" name="tax_percentage"
                           class="w-32 border-gray-300 rounded-md shadow-sm text-right focus:border-blue-500 focus:ring-blue-500"
                           value="0" min="0" step="0.01">
                </div>
                <div class="flex justify-between items-center border-t pt-4">
                    <span class="text-xl font-bold text-gray-900">Total (Inc. Tax):</span>
                    <span id="checkout-total" class="text-xl font-bold text-blue-600">৳0.00</span>
                </div>
            </div>
        </div>

        <!-- Sell Button -->
        <div class="mt-8 text-right">
            <button type="button" id="sell-button"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-8 rounded-lg shadow-md transition duration-300 ease-in-out disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fas fa-dollar-sign mr-2"></i>Process Sale
            </button>
        </div>
    </form>
</div>

<!-- Confirmation Modal -->
<div id="confirmation-modal" class="fixed inset-0 bg-gray-600 bg-opacity-75 hidden overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-8 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <h3 class="text-2xl font-bold text-gray-900 mb-6">Confirm Sale Details</h3>
        <div id="confirmation-content" class="space-y-3 text-gray-700">
            <!-- Content will be injected by JS -->
        </div>
        <div class="mt-8 flex justify-end gap-4">
            <button id="cancel-sale-btn" type="button"
                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-6 rounded-lg transition duration-300">
                Cancel
            </button>
            <button id="confirm-sale-btn" type="button"
                    class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg shadow-md transition duration-300">
                <i class="fas fa-check mr-2"></i>Confirm & Generate Invoice
            </button>
        </div>
    </div>
</div>