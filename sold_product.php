<?php
session_start();

// --- START: SESSION & SECURITY CHEKS ---
$idleTimeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset();
    session_destroy();
    header("Location: index.php?reason=idle");
    exit();
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

// Assuming user info is in session
$current_user_id = $_SESSION['user_id'];
// $current_user_name = $_SESSION['user_name'];

require_once 'connection.php';
$page_title = "Create New Sale";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - AMS</title>
    <!-- Font Awesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Tailwind CSS (via CDN) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- jQuery CDN -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Custom scrollbar for WebKit browsers */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
        
        /* Toast Notification */
        .toast {
            visibility: hidden; min-width: 250px; margin-left: -125px;
            background-color: #333; color: #fff; text-align: center;
            border-radius: 8px; padding: 16px; position: fixed;
            z-index: 100; left: 50%; bottom: 30px; font-size: 17px;
            opacity: 0; transition: opacity 0.3s, bottom 0.3s, visibility 0.3s;
        }
        .toast.show { visibility: visible; opacity: 1; bottom: 50px; }
        .toast.error { background-color: #D9534F; }
        .toast.success { background-color: #5CB85C; }
        
        /* Modal */
        .modal { display: none; }
        .modal.is-open { display: flex; }
        
        /* Search result highlighting */
        .search-highlight {
            background-color: #fde047; /* yellow-200 */
            font-weight: bold;
        }
        
        /* Tiny spinner for loading states */
        .spinner-tiny {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3498db;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Style for the multi-select box */
        select[multiple] {
            height: 100px; /* Show more items */
        }
    </style>
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">

    <!-- Main Content -->
    <div class="flex-1 min-h-screen bg-gray-100">

        <!-- Top Bar -->
        <div class="bg-white shadow-md p-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($page_title); ?></h1>
            <a href="dashboard.php" class="bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>

        <!-- Page Content -->
        <div class="p-4 md:p-8">
            <form id="sale-form" class="space-y-6">
                
                <!-- Section 1: Client and Order Info -->
                <div class="bg-white p-6 rounded-lg shadow-lg">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-3">1. Client Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        
                        <!-- Client Search (Combined) -->
                        <div class="relative md:col-span-2">
                            <label for="client-search" class="block text-sm font-medium text-gray-700 mb-1">Search Client (Head or Branch)</label>
                            <div class="relative">
                                <input type="text" id="client-search" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Type client or branch name...">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <div id="client-search-spinner" class="spinner-tiny absolute right-3 top-3 hidden"></div>
                            </div>
                            <ul id="client-search-results" class="absolute z-20 w-full bg-white border border-gray-200 rounded-lg shadow-xl mt-1 max-h-60 overflow-y-auto hidden">
                                <!-- Results injected by JS -->
                            </ul>
                            <input type="hidden" id="selected-client-head-id">
                            <input type="hidden" id="selected-client-branch-id">
                        </div>

                        <!-- Sold Date -->
                        <div>
                            <label for="sold-date" class="block text-sm font-medium text-gray-700 mb-1">Sold Date</label>
                            <input type="date" id="sold-date" class="w-full px-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <!-- Work Order Search -->
                        <div class="relative md:col-span-2">
                            <label for="work-order-search" class="block text-sm font-medium text-gray-700 mb-1">Work Order (Optional)</label>
                            <div class="relative">
                                <input type="text" id="work-order-search" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Type work order number...">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <div id="work-order-spinner" class="spinner-tiny absolute right-3 top-3 hidden"></div>
                            </div>
                            <ul id="work-order-results" class="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-xl mt-1 max-h-60 overflow-y-auto hidden">
                                <!-- Results injected by JS -->
                            </ul>
                            <input type="hidden" id="selected-work-order-id">
                        </div>
                    </div>
                </div>

                <!-- Section 2: Product Entry -->
                <div class="bg-white p-6 rounded-lg shadow-lg">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-3">2. Add Products to Cart</h2>
                    <div class="space-y-4 p-4 border border-gray-200 rounded-lg bg-gray-50">
                        <!-- Product Selection Row -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="product-category" class="block text-xs font-medium text-gray-700">Category</label>
                                <select id="product-category" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">-- Select Category --</option>
                                    <?php
                                    $result = $conn->query("SELECT category_id, category_name FROM categories WHERE is_deleted = 0 ORDER BY category_name");
                                    while ($row = $result->fetch_assoc()) {
                                        echo '<option value="' . $row['category_id'] . '">' . htmlspecialchars($row['category_name']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="product-brand" class="block text-xs font-medium text-gray-700">Brand</label>
                                <select id="product-brand" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" disabled>
                                    <option value="">-- Select Brand --</option>
                                </select>
                            </div>
                            <div>
                                <label for="product-model" class="block text-xs font-medium text-gray-700">Model</label>
                                <select id="product-model" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" disabled>
                                    <option value="">-- Select Model --</option>
                                </select>
                            </div>
                        </div>

                        <!-- Product Info Row -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                            <!-- Stock/Price Info -->
                            <div class="md:col-span-2 grid grid-cols-3 gap-2 bg-white p-3 rounded-lg border">
                                <div class="text-center">
                                    <span class="block text-xs font-medium text-gray-500">Available Stock</span>
                                    <span id="stock-available" class="block text-lg font-bold text-blue-700">0</span>
                                </div>
                                <div class="text-center border-l border-r">
                                    <span class="block text-xs font-medium text-gray-500">Avg. Pur. Price</span>
                                    <span id="price-avg" class="block text-lg font-bold text-gray-700">0.00</span>
                                </div>
                                <div class="text-center">
                                    <span class="block text-xs font-medium text-gray-500">Max. Pur. Price</span>
                                    <span id="price-max" class="block text-lg font-bold text-gray-700">0.00</span>
                                </div>
                                <input type="hidden" id="avg-max-price-hidden">
                            </div>
                            
                            <!-- Serial Number -->
                            <div>
                                <label for="product-serial" class="block text-xs font-medium text-gray-700">Serial Number (Optional)</label>
                                <!-- *** MODIFICATION HERE *** -->
                                <!-- Added 'multiple' and 'size="5"' -->
                                <select id="product-serial" multiple size="5" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" disabled>
                                    <!-- Options will be loaded by JS -->
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple serials.</p>
                            </div>

                            <!-- Quantity -->
                            <div>
                                <label for="product-quantity" class="block text-xs font-medium text-gray-700">Quantity</label>
                                <!-- 'py-2' to match height of select -->
                                <input type="number" id="product-quantity" value="1" min="1" class="mt-1 block w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>

                        <!-- Price/Remarks Row -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                            <div>
                                <label for="product-unit-price" class="block text-xs font-medium text-gray-700">Sold Unit Price</label>
                                <input type="number" id="product-unit-price" step="0.01" min="0" class="mt-1 block w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="0.00">
                            </div>
                            <div class="md:col-span-2">
                                <label for="product-remarks" class="block text-xs font-medium text-gray-700">Remarks (Optional)</label>
                                <input type="text" id="product-remarks" class="mt-1 block w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="e.g., warranty info">
                            </div>
                        </div>
                        
                        <!-- Add Button -->
                        <div class="text-right pt-2">
                            <button type="button" id="add-to-cart-btn" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150">
                                <i class="fas fa-cart-plus mr-2"></i>Add to Cart
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Cart and Totals -->
                <div class="bg-white p-6 rounded-lg shadow-lg">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-3">3. Cart & Final Confirmation</h2>
                    
                    <!-- Cart Table -->
                    <div id="cart-container" class="overflow-x-auto mb-6">
                        <table class="min-w-full bg-white border">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="text-left py-3 px-4 font-semibold text-sm text-gray-600 uppercase">Product Details</th>
                                    <th class="text-left py-3 px-4 font-semibold text-sm text-gray-600 uppercase">Serial No.</th>
                                    <th class="text-right py-3 px-4 font-semibold text-sm text-gray-600 uppercase">Qty</th>
                                    <th class="text-right py-3 px-4 font-semibold text-sm text-gray-600 uppercase">Unit Price</th>
                                    <th class="text-right py-3 px-4 font-semibold text-sm text-gray-600 uppercase">Total</th>
                                    <th class="text-center py-3 px-4 font-semibold text-sm text-gray-600 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody id="cart-table-body" class="divide-y divide-gray-200">
                                <!-- Cart rows injected by JS -->
                                <tr id="cart-empty-row">
                                    <td colspan="6" class="text-center py-6 text-gray-500">
                                        <i class="fas fa-shopping-cart fa-2x mb-2"></i>
                                        <p>Your cart is empty.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals Section -->
                    <div class="flex flex-col md:flex-row justify-between items-start gap-6">
                        <!-- Notes/Spacer -->
                        <div class="w-full md:w-1/2">
                            <!-- Can add notes here later -->
                        </div>

                        <!-- Financials -->
                        <div class="w-full md:w-1/2 lg:w-1/3 space-y-3">
                            <div class="flex justify-between items-center text-lg">
                                <span class="font-medium text-gray-700">Subtotal (Excl. Tax):</span>
                                <span id="subtotal-display" class="font-bold text-gray-900">0.00</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <label for="tax-percentage" class="font-medium text-gray-700">Tax (%):</label>
                                <input type="number" id="tax-percentage" value="0" min="0" step="0.01" class="w-24 py-1 px-2 border border-gray-300 rounded-md shadow-sm text-right focus:outline-none focus:ring-1 focus:ring-blue-500">
                            </div>
                            <div class="flex justify-between items-center text-lg">
                                <span class="font-medium text-gray-700">Tax Amount:</span>
                                <span id="tax-amount-display" class="font-bold text-gray-900">0.00</span>
                            </div>
                            <hr class="my-2">
                            <div class="flex justify-between items-center text-2xl">
                                <span class="font-semibold text-gray-900">Grand Total:</span>
                                <span id="grand-total-display" class="font-extrabold text-blue-700">0.00</span>
                            </div>

                            <!-- Hidden inputs for form submission -->
                            <input type="hidden" id="excluding-tax-hidden">
                            <input type="hidden" id="including-tax-hidden">
                            
                            <!-- Confirm Button -->
                            <div class="pt-4 text-right">
                                <button type="button" id="confirm-sale-btn" class="w-full bg-green-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 transition duration-150 text-lg">
                                    <i class="fas fa-check-circle mr-2"></i>Confirm Sale
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toast-notification" class="toast"></div>

    <!-- Confirmation Modal -->
    <div id="confirmation-modal" class="modal fixed inset-0 z-50 items-center justify-center bg-black bg-opacity-50 p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center p-4 border-b">
                <h2 class="text-2xl font-semibold text-gray-800">Confirm Sale Details</h2>
                <button id="modal-close-btn" class="text-gray-500 hover:text-gray-800 text-2xl">&times;</button>
            </div>
            <div id="printable-invoice-content" class="p-6 overflow-y-auto">
                <!-- Invoice preview injected by JS -->
            </div>
            <div class="p-4 bg-gray-50 border-t flex justify-end gap-3">
                <button id="modal-cancel-btn" class="bg-gray-200 text-gray-800 font-bold py-2 px-6 rounded-lg hover:bg-gray-300 transition duration-150">
                    Cancel
                </button>
                <button id="modal-confirm-btn" class="bg-green-600 text-white font-bold py-2 px-6 rounded-lg shadow-md hover:bg-green-700 transition duration-150">
                    <i class="fas fa-check mr-2"></i>Confirm & Save
                </button>
            </div>
        </div>
    </div>


<script>
$(document).ready(() => {

    // --- Global State ---
    let cart = []; // Holds cart data locally for calculation
    let currentSearchTerm = ''; // For highlighting search results

    // --- UI Elements ---
    const $clientSearch = $('#client-search');
    const $clientSpinner = $('#client-search-spinner');
    const $clientResults = $('#client-search-results');
    const $workOrderSearch = $('#work-order-search');
    const $workOrderSpinner = $('#work-order-spinner');
    const $workOrderResults = $('#work-order-results');
    const $soldDate = $('#sold-date');
    const $catSelect = $('#product-category');
    const $brandSelect = $('#product-brand');
    const $modelSelect = $('#product-model');
    const $serialSelect = $('#product-serial');
    const $quantityInput = $('#product-quantity');
    const $unitPriceInput = $('#product-unit-price');
    const $remarksInput = $('#product-remarks');
    const $stockDisplay = $('#stock-available');
    const $avgPriceDisplay = $('#price-avg');
    const $maxPriceDisplay = $('#price-max');
    const $avgMaxHidden = $('#avg-max-price-hidden');
    const $addToCartBtn = $('#add-to-cart-btn');
    const $cartTableBody = $('#cart-table-body');
    const $cartEmptyRow = $('#cart-empty-row');
    const $subtotalDisplay = $('#subtotal-display');
    const $taxInput = $('#tax-percentage');
    const $taxAmountDisplay = $('#tax-amount-display');
    const $grandTotalDisplay = $('#grand-total-display');
    const $excludingTaxHidden = $('#excluding-tax-hidden');
    const $includingTaxHidden = $('#including-tax-hidden');
    const $confirmSaleBtn = $('#confirm-sale-btn');
    const $modal = $('#confirmation-modal');
    const $modalCloseBtn = $('#modal-close-btn');
    const $modalCancelBtn = $('#modal-cancel-btn');
    const $modalConfirmBtn = $('#modal-confirm-btn');
    const $toast = $('#toast-notification');

    // --- Helper Functions ---

    /**
     * Shows a toast notification.
     * @param {string} message - The message to display.
     * @param {string} type - 'success' or 'error'.
     */
    function showToast(message, type = 'error') {
        $toast.text(message).removeClass('success error').addClass(type).addClass('show');
        setTimeout(() => $toast.removeClass('show'), 3000);
    }

    /**
     * Formats a number as a currency string.
     * @param {number|string} num - The number to format.
     * @returns {string} - e.g., "1,200.50"
     */
    function formatCurrency(num) {
        return parseFloat(num).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /**
     * Debounce function to limit API calls
     * @param {function} func - The function to call.
     * @param {number} delay - The delay in milliseconds.
     * @returns {function} - The debounced function.
     */
    const debounce = (func, delay) => {
        let timeout;
        return function(...args) {
            const context = this;
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(context, args), delay);
        };
    };

    /**
     * Highlights search term in results.
     * @param {string} text - The text to search within.
     * @param {string} term - The search term.
     * @returns {string} - HTML string with highlighting.
     */
    function highlightSearch(text, term) {
        if (!term) return text;
        const regex = new RegExp(`(${term.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&')})`, 'gi');
        return text.replace(regex, '<span class="search-highlight">$1</span>');
    }

    // --- 1. Client and Work Order Search ---

    // Combined Client Search
    $clientSearch.on('input', debounce(function() {
        const query = $clientSearch.val().trim();
        currentSearchTerm = query; // Store for highlighting
        if (query.length < 2) {
            $clientResults.addClass('hidden').empty();
            return;
        }
        $clientSpinner.show();
        $.get('sold_ajax.php?action=search_client_branch', { q: query })
            .done(data => {
                if (data.status === 'success') {
                    $clientResults.empty().removeClass('hidden');
                    if (data.clients.length > 0) {
                        data.clients.forEach(client => {
                            const highlightedName = highlightSearch(client.display_name, query);
                            $(`<li class="px-4 py-3 border-b border-gray-100 hover:bg-blue-50 cursor-pointer" 
                                 data-head-id="${client.client_head_id_fk}" 
                                 data-branch-id="${client.client_branch_id}"
                                 data-name="${client.display_name}">
                                ${highlightedName}
                             </li>`).appendTo($clientResults);
                        });
                    } else {
                        $clientResults.html('<li class="px-4 py-3 text-gray-500">No clients found.</li>');
                    }
                } else {
                    showToast(data.message || 'Failed to search clients.');
                }
            })
            .fail((xhr, status, error) => {
                showToast('Error searching clients. Check console.');
                console.error("Client search error:", status, error, xhr.responseText);
            })
            .always(() => $clientSpinner.hide());
    }, 300));

    // Client Selection
    $clientResults.on('click', 'li', function() {
        const $li = $(this);
        const headId = $li.data('head-id');
        const branchId = $li.data('branch-id');
        const name = $li.data('name');
        
        $clientSearch.val(name);
        $('#selected-client-head-id').val(headId);
        $('#selected-client-branch-id').val(branchId);
        $clientResults.addClass('hidden').empty();
        
        // Auto-focus work order search if client is selected
        $workOrderSearch.focus();
    });

    // Work Order Search
    $workOrderSearch.on('input', debounce(function() {
        const query = $workOrderSearch.val().trim();
        currentSearchTerm = query; // Store for highlighting
        const clientHeadId = $('#selected-client-head-id').val();
        
        if (query.length < 2) {
            $workOrderResults.addClass('hidden').empty();
            return;
        }
        $workOrderSpinner.show();
        $.get('sold_ajax.php?action=search_work_order', { q: query, client_head_id: clientHeadId })
            .done(data => {
                if (data.status === 'success') {
                    $workOrderResults.empty().removeClass('hidden');
                    if (data.orders.length > 0) {
                        data.orders.forEach(order => {
                            const highlightedName = highlightSearch(order.Order_No, query);
                            $(`<li class="px-4 py-3 border-b border-gray-100 hover:bg-blue-50 cursor-pointer" 
                                 data-order-id="${order.work_order_id}"
                                 data-name="${order.Order_No}">
                                ${highlightedName}
                             </li>`).appendTo($workOrderResults);
                        });
                    } else {
                        $workOrderResults.html('<li class="px-4 py-3 text-gray-500">No work orders found.</li>');
                    }
                } else {
                    showToast(data.message || 'Failed to search work orders.');
                }
            })
            .fail((xhr, status, error) => {
                showToast('Error searching work orders. Check console.');
                console.error("Work order search error:", status, error, xhr.responseText);
            })
            .always(() => $workOrderSpinner.hide());
    }, 300));

    // Work Order Selection
    $workOrderResults.on('click', 'li', function() {
        const $li = $(this);
        const orderId = $li.data('order-id');
        const name = $li.data('name');
        
        $workOrderSearch.val(name);
        $('#selected-work-order-id').val(orderId);
        $workOrderResults.addClass('hidden').empty();
    });

    // Hide results when clicking outside
    $(document).on('click', (e) => {
        if (!$(e.target).closest('#client-search-results, #client-search').length) {
            $clientResults.addClass('hidden');
        }
        if (!$(e.target).closest('#work-order-results, #work-order-search').length) {
            $workOrderResults.addClass('hidden');
        }
    });

    // --- 2. Product Selection ---

    function resetProductForm() {
        $catSelect.val('');
        $brandSelect.empty().append('<option value="">-- Select Brand --</option>').prop('disabled', true);
        $modelSelect.empty().append('<option value="">-- Select Model --</option>').prop('disabled', true);
        $serialSelect.empty().append('<option value="">-- Select Serial (if applicable) --</option>').prop('disabled', true);
        $stockDisplay.text('0');
        $avgPriceDisplay.text('0.00');
        $maxPriceDisplay.text('0.00');
        $avgMaxHidden.val('');
        $quantityInput.val('1').prop('disabled', false);
        $unitPriceInput.val('');
        $remarksInput.val('');
    }

    $catSelect.on('change', function() {
        const catId = $(this).val();
        resetProductForm();
        $catSelect.val(catId); // Re-set the category
        
        if (catId) {
            $brandSelect.prop('disabled', true).html('<option value="">Loading...</option>');
            $.get('sold_ajax.php?action=get_brands_by_category', { category_id: catId })
                .done(data => {
                    if (data.status === 'success') {
                        $brandSelect.empty().append('<option value="">-- Select Brand --</option>');
                        data.brands.forEach(brand => {
                            $brandSelect.append(`<option value="${brand.brand_id}">${brand.brand_name}</option>`);
                        });
                        $brandSelect.prop('disabled', false);
                    } else {
                        showToast(data.message);
                    }
                })
                .fail((xhr, status, error) => {
                    showToast('Error loading brands. Check console.');
                    console.error("Brand load error:", status, error, xhr.responseText);
                });
        }
    });

    $brandSelect.on('change', function() {
        const brandId = $(this).val();
        $modelSelect.empty().append('<option value="">-- Select Model --</option>').prop('disabled', true);
        $serialSelect.empty().append('<option value="">-- Select Serial --</option>').prop('disabled', true);
        // Reset prices/stock
        $stockDisplay.text('0');
        $avgPriceDisplay.text('0.00');
        $maxPriceDisplay.text('0.00');
        $avgMaxHidden.val('');
        
        if (brandId) {
            $modelSelect.prop('disabled', true).html('<option value="">Loading...</option>');
            $.get('sold_ajax.php?action=get_models_by_brand', { brand_id: brandId })
                .done(data => {
                    if (data.status === 'success') {
                        $modelSelect.empty().append('<option value="">-- Select Model --</option>');
                        data.models.forEach(model => {
                            $modelSelect.append(`<option value="${model.model_id}">${model.model_name}</option>`);
                        });
                        $modelSelect.prop('disabled', false);
                    } else {
                        showToast(data.message);
                    }
                })
                .fail((xhr, status, error) => {
                    showToast('Error loading models. Check console.');
                    console.error("Model load error:", status, error, xhr.responseText);
                });
        }
    });

    $modelSelect.on('change', function() {
        const modelId = $(this).val();
        $serialSelect.empty().append('<option value="">Loading Serials...</option>').prop('disabled', true);
        $stockDisplay.text('...');
        $avgPriceDisplay.text('...');
        $maxPriceDisplay.text('...');
        $avgMaxHidden.val('');
        
        if (modelId) {
            // Fetch all 3 pieces of data in parallel
            const stockRequest = $.get('sold_ajax.php?action=available_quantity', { model_id: modelId });
            const priceRequest = $.get('sold_ajax.php?action=get_avg_max_price', { model_id: modelId });
            const serialRequest = $.get('sold_ajax.php?action=get_serials_for_model', { model_id: modelId });

            $.when(stockRequest, priceRequest, serialRequest)
                .done((stockData, priceData, serialData) => {
                    // 1. Stock
                    if (stockData[0].status === 'success') {
                        $stockDisplay.text(stockData[0].available);
                    } else {
                        $stockDisplay.text('0');
                        showToast(stockData[0].message);
                    }
                    
                    // 2. Prices
                    if (priceData[0].status === 'success') {
                        const avg = parseFloat(priceData[0].avg).toFixed(2);
                        const max = parseFloat(priceData[0].max).toFixed(2);
                        $avgPriceDisplay.text(avg);
                        $maxPriceDisplay.text(max);
                        $avgMaxHidden.val(`${avg},${max}`); // Store for submission
                    } else {
                        $avgPriceDisplay.text('0.00');
                        $maxPriceDisplay.text('0.00');
                        showToast(priceData[0].message);
                    }
                    
                    // 3. Serials
                    if (serialData[0].status === 'success') {
                        $serialSelect.empty().append('<option value="">-- Select Serial (if applicable) --</option>');
                        if (serialData[0].serials.length > 0) {
                            serialData[0].serials.forEach(serial => {
                                $serialSelect.append(`<option value="${serial.sl_id}">${serial.product_sl}</option>`);
                            });
                        } else {
                            $serialSelect.append('<option value="" disabled>No available serials</option>');
                        }
                        $serialSelect.prop('disabled', false);
                    } else {
                        showToast(serialData[0].message);
                        $serialSelect.empty().append('<option value="">Error loading</option>');
                    }
                })
                .fail((xhr, status, error) => {
                    showToast('Failed to load product details. Check console.');
                    console.error("Model detail load error:", status, error, xhr.responseText);
                    $stockDisplay.text('ERR');
                    $avgPriceDisplay.text('ERR');
                    $maxPriceDisplay.text('ERR');
                    $serialSelect.empty().append('<option value="">Error</option>');
                });
        }
    });

    // *** MODIFICATION HERE ***
    // When serial is selected, lock quantity to 1
    $serialSelect.on('change', function() {
        const selectedSerials = $(this).val(); // This is now an array
        if (selectedSerials && selectedSerials.length > 0) {
            $quantityInput.val(selectedSerials.length).prop('disabled', true);
        } else {
            $quantityInput.val(1).prop('disabled', false);
        }
    });


    // --- 3. Add to Cart ---

    $addToCartBtn.on('click', () => {
        // 1. Validation
        const clientHeadId = $('#selected-client-head-id').val();
        const clientBranchId = $('#selected-client-branch-id').val();
        if (!clientHeadId || !clientBranchId) {
            showToast('Please select a client first.');
            $clientSearch.focus();
            return;
        }
        
        const modelId = $modelSelect.val();
        if (!modelId) {
            showToast('Please select a product model.');
            $modelSelect.focus();
            return;
        }
        
        const quantity = parseInt($quantityInput.val());
        const available = parseInt($stockDisplay.text());
        
        // *** MODIFICATION HERE ***
        const serialIds = $serialSelect.val(); // This is an array or null
        const isSerialSale = (serialIds && serialIds.length > 0);
        
        if (isNaN(quantity) || quantity <= 0) {
            showToast('Quantity must be at least 1.');
            $quantityInput.focus();
            return;
        }
        
        // Stock check
        if (!isSerialSale && quantity > available) {
            showToast(`Quantity (${quantity}) exceeds available stock (${available}).`);
            $quantityInput.focus();
            return;
        }
        
        // Quantity check for serials (already handled by the 'change' event)
        if (isSerialSale && quantity !== serialIds.length) {
            showToast('Serial count and quantity do not match. Please re-select serials.', 'error');
            return;
        }
        
        const unitPrice = parseFloat($unitPriceInput.val());
        if (isNaN(unitPrice) || unitPrice < 0) {
            showToast('Please enter a valid sold unit price.');
            $unitPriceInput.focus();
            return;
        }

        // 2. Prepare data
        const postData = {
            client_head_id_fk: clientHeadId,
            client_branch_id_fk: clientBranchId,
            sold_date: $soldDate.val(),
            work_order_id_fk: $('#selected-work-order-id').val() || null,
            model_id_fk: modelId,
            // *** MODIFICATION HERE ***
            // Send serialIds (which can be an array) or null
            product_sl_id_fk: serialIds,
            Quantity: quantity,
            Sold_Unit_Price: unitPrice,
            Avg_Max_Price: $avgMaxHidden.val() || '0.00,0.00',
            Remarks: $remarksInput.val().trim()
        };

        // 3. AJAX Post
        $addToCartBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding...');
        
        $.post('sold_ajax.php?action=add_temp_row', postData)
            .done(data => {
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    resetProductForm();
                    loadCart(); // Refresh the cart
                } else {
                    showToast(data.message || 'Failed to add item.');
                }
            })
            .fail((xhr, status, error) => {
                showToast('Error adding to cart. Check console.');
                console.error("Add to cart error:", status, error, xhr.responseText);
            })
            .always(() => {
                $addToCartBtn.prop('disabled', false).html('<i class="fas fa-cart-plus mr-2"></i>Add to Cart');
            });
    });


    // --- 4. Cart Management ---

    function loadCart() {
        $.get('sold_ajax.php?action=get_temp_rows')
            .done(data => {
                if (data.status === 'success') {
                    cart = data.rows; // Store cart data
                    renderCart();
                    updateTotals();
                } else {
                    showToast(data.message || 'Failed to load cart.');
                }
            })
            .fail((xhr, status, error) => {
                showToast('Error loading cart. Check console.');
                console.error("Cart load error:", status, error, xhr.responseText);
            });
    }

    function renderCart() {
        $cartTableBody.empty();
        if (cart.length === 0) {
            $cartTableBody.append($cartEmptyRow);
            return;
        }

        cart.forEach(item => {
            const total = parseFloat(item.Quantity) * parseFloat(item.Sold_Unit_Price);
            const productDesc = `
                <div class="font-medium text-gray-900">${item.model_name}</div>
                <div class="text-xs text-gray-500">${item.category_name} | ${item.brand_name}</div>
            `;
            const serialDesc = item.product_sl ? `<span>${item.product_sl}</span>` : '<span class="text-gray-400">N/A</span>';
            
            $cartTableBody.append(`
                <tr data-temp-id="${item.temp_sold_id}">
                    <td class="py-3 px-4">${productDesc}</td>
                    <td class="py-3 px-4">${serialDesc}</td>
                    <td class="py-3 px-4 text-right">${item.Quantity}</td>
                    <td class="py-3 px-4 text-right">${formatCurrency(item.Sold_Unit_Price)}</td>
                    <td class="py-3 px-4 text-right font-medium">${formatCurrency(total)}</td>
                    <td class="py-3 px-4 text-center">
                        <button type="button" class="remove-cart-item text-red-500 hover:text-red-700" title="Remove">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    $cartTableBody.on('click', '.remove-cart-item', function() {
        const $row = $(this).closest('tr');
        const tempId = $row.data('temp-id');
        
        if (confirm('Are you sure you want to remove this item from the cart?')) {
            $row.css('opacity', '0.5');
            $.post('sold_ajax.php?action=remove_temp_row', { temp_sold_id: tempId })
                .done(data => {
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        loadCart(); // Reload the whole cart
                    } else {
                        showToast(data.message);
                        $row.css('opacity', '1');
                    }
                })
                .fail((xhr, status, error) => {
                    showToast('Error removing item. Check console.');
                    console.error("Remove item error:", status, error, xhr.responseText);
                    $row.css('opacity', '1');
                });
        }
    });

    // --- 5. Financials and Confirmation ---

    function updateTotals() {
        let subtotal = 0;
        cart.forEach(item => {
            subtotal += parseFloat(item.Quantity) * parseFloat(item.Sold_Unit_Price);
        });
        
        const taxRate = parseFloat($taxInput.val()) || 0;
        const taxAmount = subtotal * (taxRate / 100);
        const grandTotal = subtotal + taxAmount;

        $subtotalDisplay.text(formatCurrency(subtotal));
        $taxAmountDisplay.text(formatCurrency(taxAmount));
        $grandTotalDisplay.text(formatCurrency(grandTotal));
        
        $excludingTaxHidden.val(subtotal.toFixed(2));
        $includingTaxHidden.val(grandTotal.toFixed(2));
    }

    $taxInput.on('input', updateTotals);

    // --- 6. Final Sale Confirmation ---

    $confirmSaleBtn.on('click', () => {
        if (cart.length === 0) {
            showToast('Your cart is empty. Please add products first.');
            return;
        }

        // Build invoice preview
        const clientName = $clientSearch.val();
        const soldDate = $soldDate.val();
        const workOrder = $workOrderSearch.val() || 'N/A';
        
        let itemsHtml = '';
        cart.forEach(item => {
            itemsHtml += `
                <tr class="border-b">
                    <td class="py-2 pr-2">
                        <div class="font-medium">${item.model_name}</div>
                        <div class="text-xs text-gray-600">${item.category_name} | ${item.brand_name}</div>
                    </td>
                    <td class="py-2 px-2 text-sm">${item.product_sl || 'N/A'}</td>
                    <td class="py-2 px-2 text-right">${item.Quantity}</td>
                    <td class="py-2 px-2 text-right">${formatCurrency(item.Sold_Unit_Price)}</td>
                    <td class="py-2 pl-2 text-right font-medium">${formatCurrency(item.Quantity * item.Sold_Unit_Price)}</td>
                </tr>
            `;
        });
        
        const subtotal = $excludingTaxHidden.val();
        const taxAmount = $taxAmountDisplay.text();
        const taxPercent = $taxInput.val();
        const grandTotal = $grandTotalDisplay.text();

        $('#printable-invoice-content').html(`
            <div class="space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-600 uppercase">Sold To:</h3>
                        <p class="text-lg font-medium text-gray-900">${clientName}</p>
                    </div>
                    <div class="text-right">
                        <h3 class="text-sm font-semibold text-gray-600 uppercase">Sale Date:</h3>
                        <p class="text-lg font-medium text-gray-900">${soldDate}</p>
                        <h3 class="text-sm font-semibold text-gray-600 uppercase mt-2">Work Order:</h3>
                        <p class="text-lg font-medium text-gray-900">${workOrder}</p>
                    </div>
                </div>
                
                <h3 class="text-sm font-semibold text-gray-600 uppercase border-b pb-2">Order Summary</h3>
                <table class="min-w-full">
                    <thead class="border-b">
                        <tr>
                            <th class="text-left py-2 pr-2 text-xs font-semibold text-gray-500 uppercase">Product</th>
                            <th class="text-left py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Serial No.</th>
                            <th class="text-right py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Qty</th>
                            <th class="text-right py-2 px-2 text-xs font-semibold text-gray-500 uppercase">Unit Price</th>
                            <th class="text-right py-2 pl-2 text-xs font-semibold text-gray-500 uppercase">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>
                
                <div class="flex justify-end">
                    <div class="w-full max-w-xs space-y-2">
                        <div class="flex justify-between">
                            <span class="font-medium text-gray-700">Subtotal:</span>
                            <span class="font-medium text-gray-900">${formatCurrency(subtotal)}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="font-medium text-gray-700">Tax (${taxPercent}%):</span>
                            <span class="font-medium text-gray-900">${taxAmount}</span>
                        </div>
                        <hr>
                        <div class="flex justify-between text-xl">
                            <span class="font-bold text-gray-900">Grand Total:</span>
                            <span class="font-bold text-gray-900">${grandTotal}</span>
                        </div>
                    </div>
                </div>
            </div>
        `);
        
        $modal.addClass('is-open');
    });

    // Modal close buttons
    $modalCloseBtn.on('click', () => $modal.removeClass('is-open'));
    $modalCancelBtn.on('click', () => $modal.removeClass('is-open'));

    // FINAL SUBMIT
    $modalConfirmBtn.on('click', () => {
        $modalConfirmBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...');
        
        const finalData = {
            tax_percentage: $taxInput.val(),
            excluding_tax: $excludingTaxHidden.val(),
            including_tax: $includingTaxHidden.val()
        };

        $.post('sold_ajax.php?action=confirm_sale', finalData)
            .done(data => {
                if (data.status === 'success') {
                    showToast('Sale confirmed successfully!', 'success');
                    $modal.removeClass('is-open');
                    cart = []; // Clear local cart
                    renderCart();
                    updateTotals();
                    // Open print window
                    window.open(`invoice_print.php?invoice_id=${data.invoice_id}`, '_blank');
                } else {
                    showToast(data.message || 'Final sale failed.');
                }
            })
            .fail((xhr, status, error) => {
                showToast('Final sale failed. Check console.');
                console.error("Confirm sale error:", status, error, xhr.responseText);
            })
            .always(() => {
                $modalConfirmBtn.prop('disabled', false).html('<i class="fas fa-check mr-2"></i>Confirm & Save');
            });
    });

    /**
     * Function to print the invoice content.
     */
    function printInvoice() {
        const printContent = document.getElementById('printable-invoice-content').innerHTML;
        const printWindow = window.open('', '_blank', 'height=700,width=900');
        printWindow.document.write('<html><head><title>Print Invoice</title>');
        // --- *** This URL is now correct *** ---
        printWindow.document.write('<script src="https://cdn.tailwindcss.com"><\/script>');
        printWindow.document.write('<style>@media print { .no-print { display: none; } body { -webkit-print-color-adjust: exact; } }</style>');
        printWindow.document.write('</head><body class="p-8">');
        printWindow.document.write(printContent);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        
        // Wait for content to load before printing
        $(printWindow).on('load', function() {
            printWindow.print();
            printWindow.close();
        });
    }

    // --- Initial Load ---
    loadCart(); // Load any items left in cart from a previous session

});
</script>

</body>
</html>