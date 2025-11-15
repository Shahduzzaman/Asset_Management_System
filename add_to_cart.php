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

$current_user_id = $_SESSION['user_id'];

require_once 'connection.php';
$page_title = "Add Products to Cart";
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

        /* Serial list box */
        #serial-search { width: 100%; padding: .5rem; border: 1px solid #d1d5db; border-radius: .375rem; }
        #serial-list { max-height: 160px; overflow-y: auto; padding: .5rem; border: 1px solid #e5e7eb; border-radius: .375rem; background: #fff; }
        .serial-item { display: flex; align-items: center; gap: .5rem; padding: .25rem .25rem; border-radius: .25rem; }
        .serial-item.hidden { display: none; }
        .serial-item label { cursor: pointer; }

        /* Small helper for price labels */
        .price-small { font-size: .85rem; color: #6b7280; }
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
            <!-- Product Entry -->
            <div class="bg-white p-6 rounded-lg shadow-lg max-w-4xl mx-auto">
                <h2 class="text-xl font-semibold text-gray-800 mb-4 border-b pb-3">Add Product to Cart</h2>
                <div class="space-y-4 p-4 border border-gray-200 rounded-lg bg-gray-50">
                    <!-- Product Selection Row -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="product-category" class="block text-sm font-medium text-gray-700">Category</label>
                            <select id="product-category" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">-- Select Category --</option>
                                <?php
                                $result = $conn->query("SELECT category_id, category_name FROM categories WHERE is_deleted = 0 ORDER BY category_name");
                                while ($row = $result->fetch_assoc()) {
                                    echo '<option value="' . $row['category_id'] . '">' . htmlspecialchars($row['category_name']) . '</option>';
                                }
                                $conn->close(); // Close connection
                                ?>
                            </select>
                        </div>
                        <div>
                            <label for="product-brand" class="block text-sm font-medium text-gray-700">Brand</label>
                            <select id="product-brand" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" disabled>
                                <option value="">-- Select Brand --</option>
                            </select>
                        </div>
                        <div>
                            <label for="product-model" class="block text-sm font-medium text-gray-700">Model</label>
                            <select id="product-model" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" disabled>
                                <option value="">-- Select Model --</option>
                            </select>
                        </div>
                    </div>

                    <!-- Product Info Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                        <!-- Stock/Price/Qty Info -->
                        <div class="space-y-4">
                            <!-- Stock/Price Info -->
                            <div class="grid grid-cols-3 gap-2 bg-white p-3 rounded-lg border items-center">
                                <div class="text-center">
                                    <span class="block text-xs font-medium text-gray-500">Available Stock</span>
                                    <span id="stock-available" class="block text-lg font-bold text-blue-700">0</span>
                                </div>
                                <div class="text-center border-l">
                                    <span class="block text-xs font-medium text-gray-500">Avg. Pur. Price</span>
                                    <span id="price-avg" class="block text-lg font-bold text-gray-700">0.00</span>
                                    <span class="block price-small">Avg</span>
                                </div>
                                <div class="text-center border-l">
                                    <span class="block text-xs font-medium text-gray-500">Max Pur. Price</span>
                                    <span id="price-max" class="block text-lg font-bold text-gray-700">0.00</span>
                                    <span class="block price-small">Max</span>
                                </div>
                            </div>
                            
                            <!-- Quantity -->
                            <div>
                                <label for="product-quantity" class="block text-sm font-medium text-gray-700">Quantity</label>
                                <input type="number" id="product-quantity" value="1" min="1" class="mt-1 block w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>

                            <!-- Unit Price -->
                            <div>
                                <label for="product-unit-price" class="block text-sm font-medium text-gray-700">Cart Unit Price</label>
                                <input type="number" id="product-unit-price" step="0.01" min="0" class="mt-1 block w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="0.00">
                            </div>
                        </div>
                        
                        <!-- Serial Number (searchable) -->
                        <div>
                            <label for="product-serial" class="block text-sm font-medium text-gray-700">Serial Number (Optional)</label>
                            <input id="serial-search" type="text" placeholder="Search serial (type to filter)" class="mt-1 mb-2" />
                            <div id="serial-list" class="mt-0">
                                <!-- Serial checkboxes will be loaded here -->
                                <div class="text-xs text-gray-500">Select serial(s) to add exact items — selecting serials will override quantity.</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Add Button -->
                    <div class="text-right pt-4 border-t mt-4">
                        <button type="button" id="add-to-cart-btn" class="bg-blue-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 transition duration-150 text-lg">
                            <i class="fas fa-cart-plus mr-2"></i>Add to Cart
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toast-notification" class="toast"></div>


<script>
$(document).ready(() => {

    // --- UI Elements ---
    const $catSelect = $('#product-category');
    const $brandSelect = $('#product-brand');
    const $modelSelect = $('#product-model');
    const $serialSearch = $('#serial-search');
    const $serialList = $('#serial-list');
    const $quantityInput = $('#product-quantity');
    const $unitPriceInput = $('#product-unit-price');
    const $stockDisplay = $('#stock-available');
    const $avgPriceDisplay = $('#price-avg');
    const $maxPriceDisplay = $('#price-max');
    const $addToCartBtn = $('#add-to-cart-btn');
    const $toast = $('#toast-notification');

    // Hold current serials (array of {sl_id, product_sl})
    let currentSerials = [];

    // --- Helper Functions ---
    function showToast(message, type = 'error') {
        $toast.text(message).removeClass('success error').addClass(type).addClass('show');
        setTimeout(() => $toast.removeClass('show'), 3000);
    }
    
    function logError(message, xhr, status, error) {
        console.error(message, {
            status: status,
            error: error,
            responseText: xhr.responseText
        });
        let serverMessage = 'Error communicating with server.';
        try {
            const response = JSON.parse(xhr.responseText);
            if (response.message) {
                serverMessage = response.message;
            }
        } catch (e) { /* Not JSON */ }
        showToast(`${message}: ${serverMessage}`, 'error');
    }

    function resetProductForm() {
        $catSelect.val('');
        $brandSelect.empty().append('<option value="">-- Select Brand --</option>').prop('disabled', true);
        $modelSelect.empty().append('<option value="">-- Select Model --</option>').prop('disabled', true);
        $serialSearch.val('');
        $serialList.html('<div class="text-xs text-gray-500">Select serial(s) to add exact items — selecting serials will override quantity.</div>');
        currentSerials = [];
        $stockDisplay.text('0');
        $avgPriceDisplay.text('0.00');
        $maxPriceDisplay.text('0.00');
        $quantityInput.val('1').prop('disabled', false);
        $unitPriceInput.val('');
    }

    $catSelect.on('change', function() {
        const catId = $(this).val();
        // Reset everything below
        $brandSelect.empty().append('<option value="">-- Select Brand --</option>').prop('disabled', true);
        $modelSelect.empty().append('<option value="">-- Select Model --</option>').prop('disabled', true);
        $serialSearch.val('');
        $serialList.html('<div class="text-xs text-gray-500">Select serial(s) to add exact items — selecting serials will override quantity.</div>');
        $stockDisplay.text('0');
        $avgPriceDisplay.text('0.00');
        $maxPriceDisplay.text('0.00');
        
        if (catId) {
            $brandSelect.prop('disabled', true).html('<option value="">Loading...</option>');
            $.get('cart_ajax.php?action=get_brands_by_category', { category_id: catId })
                .done(data => {
                    if (data.status === 'success') {
                        $brandSelect.empty().append('<option value="">-- Select Brand --</option>');
                        data.brands.forEach(brand => {
                            $brandSelect.append(`<option value="${brand.brand_id}">${brand.brand_name}</option>`);
                        });
                        $brandSelect.prop('disabled', false);
                    } else {
                        showToast(data.message || 'Failed to load brands');
                    }
                })
                .fail((xhr, status, error) => logError('Error loading brands', xhr, status, error));
        }
    });

    $brandSelect.on('change', function() {
        const brandId = $(this).val();
        // Reset model and serial
        $modelSelect.empty().append('<option value="">-- Select Model --</option>').prop('disabled', true);
        $serialSearch.val('');
        $serialList.html('<div class="text-xs text-gray-500">Select serial(s) to add exact items — selecting serials will override quantity.</div>');
        $stockDisplay.text('0');
        $avgPriceDisplay.text('0.00');
        $maxPriceDisplay.text('0.00');
        
        if (brandId) {
            $modelSelect.prop('disabled', true).html('<option value="">Loading...</option>');
            $.get('cart_ajax.php?action=get_models_by_brand', { brand_id: brandId })
                .done(data => {
                    if (data.status === 'success') {
                        $modelSelect.empty().append('<option value="">-- Select Model --</option>');
                        data.models.forEach(model => {
                            $modelSelect.append(`<option value="${model.model_id}">${model.model_name}</option>`);
                        });
                        $modelSelect.prop('disabled', false);
                    } else {
                        showToast(data.message || 'Failed to load models');
                    }
                })
                .fail((xhr, status, error) => logError('Error loading models', xhr, status, error));
        }
    });

    $modelSelect.on('change', function() {
        const modelId = $(this).val();
        // Reset serials and info
        $serialSearch.val('');
        $serialList.html('<div class="text-xs text-gray-500">Loading serials...</div>');
        $stockDisplay.text('...');
        $avgPriceDisplay.text('...');
        $maxPriceDisplay.text('...');
        $quantityInput.val(1).prop('disabled', false); // Reset quantity
        
        if (modelId) {
            // Fetch stock, price, and serials in parallel
            const stockRequest = $.get('cart_ajax.php?action=available_quantity', { model_id: modelId });
            const priceRequest = $.get('cart_ajax.php?action=get_avg_max_price', { model_id: modelId });
            const serialRequest = $.get('cart_ajax.php?action=get_serials_for_model', { model_id: modelId });

            $.when(stockRequest, priceRequest, serialRequest)
                .done((stockData, priceData, serialData) => {
                    // stockData, priceData, serialData are arrays [data, status, xhr] — we want data at [0]
                    const stock = stockData[0];
                    const price = priceData[0];
                    const serials = serialData[0];

                    // 1. Stock (non-serialled)
                    if (stock.status === 'success') {
                        $stockDisplay.text(stock.available);
                    } else {
                        $stockDisplay.text('0');
                        showToast(stock.message || 'Stock unavailable');
                    }
                    
                    // 2. Prices (avg & max)
                    if (price.status === 'success') {
                        const avg = parseFloat(price.avg).toFixed(2);
                        const max = parseFloat(price.max).toFixed(2);
                        $avgPriceDisplay.text(avg);
                        $maxPriceDisplay.text(max);
                        if (!$unitPriceInput.val()) {
                            $unitPriceInput.val(avg); // Auto-fill price with average by default
                        }
                    } else {
                        $avgPriceDisplay.text('0.00');
                        $maxPriceDisplay.text('0.00');
                        showToast(price.message || 'Price unavailable');
                    }
                    
                    // 3. Serials (populate searchable checkbox list)
                    if (serials.status === 'success') {
                        currentSerials = serials.serials || [];
                        renderSerialList(currentSerials);
                    } else {
                        currentSerials = [];
                        $serialList.html('<div class="text-xs text-red-500">Error loading serials</div>');
                        showToast(serials.message || 'Error loading serials');
                    }
                })
                .fail((xhr, status, error) => {
                    logError('Failed to load product details', xhr, status, error);
                    $stockDisplay.text('ERR');
                    $avgPriceDisplay.text('ERR');
                    $maxPriceDisplay.text('ERR');
                    $serialList.html('<div class="text-xs text-red-500">Error loading</div>');
                });
        } else {
            // If no model selected, clear serials/prices
            currentSerials = [];
            $serialList.html('<div class="text-xs text-gray-500">Select a model to see serials</div>');
            $stockDisplay.text('0');
            $avgPriceDisplay.text('0.00');
            $maxPriceDisplay.text('0.00');
        }
    });

    // Render serials as checkbox list
    function renderSerialList(serialsArr) {
        if (!serialsArr || serialsArr.length === 0) {
            $serialList.html('<div class="text-xs text-gray-500">No available serials</div>');
            return;
        }
        const html = [];
        serialsArr.forEach(s => {
            // each serial item: checkbox with data-id
            html.push(
                `<div class="serial-item" data-sl="${escapeHtml(s.sl_id)}" data-text="${escapeHtml(s.product_sl)}">` +
                    `<input type="checkbox" class="serial-checkbox" id="sl_${escapeHtml(s.sl_id)}" value="${escapeHtml(s.sl_id)}" />` +
                    `<label for="sl_${escapeHtml(s.sl_id)}" class="text-sm">${escapeHtml(s.product_sl)}</label>` +
                `</div>`
            );
        });
        $serialList.html(html.join(''));

        // bind change handlers for checkboxes
        $serialList.find('.serial-checkbox').on('change', function() {
            const checkedCount = $serialList.find('.serial-checkbox:checked').length;
            if (checkedCount > 0) {
                $quantityInput.val(checkedCount).prop('disabled', true);
            } else {
                $quantityInput.val(1).prop('disabled', false);
            }
        });
    }

    // Simple client-side serial search/filter
    $serialSearch.on('input', function() {
        const term = String($(this).val() || '').trim().toLowerCase();
        if (!term) {
            // show all
            $serialList.find('.serial-item').removeClass('hidden');
            return;
        }
        $serialList.find('.serial-item').each(function() {
            const text = $(this).attr('data-text') || '';
            if (text.toLowerCase().indexOf(term) !== -1) {
                $(this).removeClass('hidden');
            } else {
                $(this).addClass('hidden');
            }
        });
    });

    // --- Add to Cart ---
    $addToCartBtn.on('click', () => {
        const modelId = $modelSelect.val();
        if (!modelId) {
            showToast('Please select a product model.', 'error');
            $modelSelect.focus();
            return;
        }
        
        const quantity = parseInt($quantityInput.val());
        const available = parseInt($stockDisplay.text());
        // collect selected serial ids (array)
        const serialIds = $serialList.find('.serial-checkbox:checked').map(function(){ return $(this).val(); }).get();
        const isSerialSale = (serialIds && serialIds.length > 0);
        
        if (isNaN(quantity) || quantity <= 0) {
            showToast('Quantity must be at least 1.', 'error');
            $quantityInput.focus();
            return;
        }
        
        
        // Stock check for non-serial
        if (!isSerialSale && quantity > available) {
            showToast(`Quantity (${quantity}) exceeds available stock (${available}).`, 'error');
            $quantityInput.focus();
            return;
        }
        
        const unitPrice = parseFloat($unitPriceInput.val());
        if (isNaN(unitPrice) || unitPrice < 0) {
            showToast('Please enter a valid unit price.', 'error');
            $unitPriceInput.focus();
            return;
        }

        // Prepare data
        const postData = {
            model_id_fk: modelId,
            // jQuery will correctly send arrays when field value is an array
            product_sl_id_fk: serialIds,
            Quantity: quantity,
            Sold_Unit_Price: unitPrice
        };

        $addToCartBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding...');
        
        $.post('cart_ajax.php?action=add_to_cart', postData)
            .done(data => {
                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    // Reset form
                    $modelSelect.val('').trigger('change');
                    $brandSelect.val('');
                    $catSelect.val('');
                    $unitPriceInput.val('');
                    $serialSearch.val('');
                } else {
                    showToast(data.message || 'Failed to add item.', 'error');
                    // If add fails, we must refresh serials/stock
                    $modelSelect.trigger('change');
                }
            })
            .fail((xhr, status, error) => {
                logError('Error adding to cart', xhr, status, error);
                // Refresh data in case of failure
                $modelSelect.trigger('change');
            })
            .always(() => {
                $addToCartBtn.prop('disabled', false).html('<i class="fas fa-cart-plus mr-2"></i>Add to Cart');
            });
    });

    // Utility: escape HTML for safe insertion
    function escapeHtml(str) {
        if (str === undefined || str === null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
});
</script>

</body>
</html>
