<?php
session_start();

// --- START: SESSION & SECURITY CHECKS ---
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
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title><?php echo htmlspecialchars($page_title); ?> - AMS</title>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- Tailwind (cdn) -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        /* Custom scrollbar for WebKit browsers */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }

        /* Toast */
        .toast {
            visibility: hidden; min-width: 250px; margin-left: -125px;
            background-color: #333; color: #fff; text-align: center;
            border-radius: 8px; padding: 12px 18px; position: fixed;
            z-index: 100; left: 50%; bottom: 30px; font-size: 15px;
            opacity: 0; transition: opacity .25s, bottom .25s, visibility .25s;
        }
        .toast.show { visibility: visible; opacity: 1; bottom: 50px; }
        .toast.error { background-color: #D9534F; }
        .toast.success { background-color: #16A34A; }

        /* Serial UI */
        #serial-search { width: 100%; padding: .5rem; border: 1px solid #e5e7eb; border-radius: .375rem; }
        #serial-list { max-height: 180px; overflow-y: auto; padding: .375rem; border: 1px solid #e5e7eb; border-radius: .375rem; background: #fff; }
        .serial-item { display:flex; align-items:center; gap:.6rem; padding:.25rem .35rem; border-radius:.375rem; }
        .serial-item.hidden { display: none; }
        .checkbox-custom { width:18px; height:18px; border-radius:4px; border:2px solid #cbd5e1; display:inline-flex; align-items:center; justify-content:center; background:white; cursor:pointer; }
        .checkbox-custom.checked { background:#2563eb; border-color:#2563eb; }
        .checkbox-custom svg { width:11px; height:11px; color:white; display:none; }
        .checkbox-custom.checked svg { display:block; }
        .serial-meta { font-size:.95rem; color:#374151; }
        .select-info { font-size:.85rem; color:#6b7280; }

        /* small responsive tweaks */
        @media (max-width:640px) {
            #serial-list { max-height: 220px; }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans leading-normal tracking-normal">

    <!-- Top -->
    <div class="bg-white shadow p-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($page_title); ?></h1>
        <a href="dashboard.php" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700"><i class="fas fa-arrow-left mr-2"></i>Back to Dashboard</a>
    </div>

    <div class="p-6 md:p-10 max-w-5xl mx-auto">
        <!-- Add to Cart Card -->
        <div class="bg-white rounded-lg shadow p-6 mb-8">
            <h2 class="text-lg font-semibold mb-4">Add Product to Cart</h2>

            <!-- selection row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Category</label>
                    <select id="product-category" class="mt-1 block w-full border rounded-md p-2">
                        <option value="">-- Select Category --</option>
                        <?php
                        $res = $conn->query("SELECT category_id, category_name FROM categories WHERE is_deleted = 0 ORDER BY category_name");
                        while ($r = $res->fetch_assoc()) {
                            echo '<option value="'.$r['category_id'].'">'.htmlspecialchars($r['category_name']).'</option>';
                        }
                        ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Brand</label>
                    <select id="product-brand" class="mt-1 block w-full border rounded-md p-2" disabled>
                        <option value="">-- Select Brand --</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Model</label>
                    <select id="product-model" class="mt-1 block w-full border rounded-md p-2" disabled>
                        <option value="">-- Select Model --</option>
                    </select>
                </div>
            </div>

            <!-- info row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-4">
                    <div class="grid grid-cols-3 gap-2 bg-gray-50 p-3 rounded-md items-center">
                        <div class="text-center">
                            <div class="text-xs text-gray-500">Available Stock</div>
                            <div id="stock-available" class="text-xl font-bold text-blue-700">0</div>
                        </div>
                        <div class="text-center border-l">
                            <div class="text-xs text-gray-500">Avg. Pur. Price</div>
                            <div id="price-avg" class="text-lg font-bold">0.00</div>
                        </div>
                        <div class="text-center border-l">
                            <div class="text-xs text-gray-500">Max Pur. Price</div>
                            <div id="price-max" class="text-lg font-bold">0.00</div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Quantity</label>
                        <input id="product-quantity" type="number" min="1" value="1" class="mt-1 w-full p-2 border rounded-md" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Cart Unit Price</label>
                        <input id="product-unit-price" type="number" step="0.01" min="0" placeholder="0.00" class="mt-1 w-full p-2 border rounded-md" />
                    </div>
                </div>

                <!-- serial UI -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Serial Number (Optional)</label>

                    <input id="serial-search" type="text" placeholder="Search serial (filter visible)" class="w-full p-2 border rounded-md mb-2" />

                    <div class="flex items-center justify-between mb-2">
                        <label class="inline-flex items-center cursor-pointer">
                            <input id="select-all-visible" type="checkbox" class="hidden" />
                            <span id="select-all-box" class="checkbox-custom" role="checkbox" aria-checked="false" tabindex="0">
                                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-4.121-4.121a1 1 0 011.414-1.414L8.414 12.586l7.879-7.879a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            </span>
                            <span class="ml-2 select-info text-sm text-gray-600">Select all visible</span>
                        </label>
                        <div id="selected-count" class="text-sm text-gray-600">Selected: 0</div>
                    </div>

                    <div id="serial-list">
                        <div class="text-xs text-gray-500 p-2">Select serial(s) — selecting serials will override quantity.</div>
                    </div>
                </div>
            </div>

            <div class="text-right mt-4 border-t pt-4">
                <button id="add-to-cart-btn" class="bg-blue-600 text-white px-6 py-2 rounded shadow hover:bg-blue-700">
                    <i class="fas fa-cart-plus mr-2"></i>Add to Cart
                </button>
            </div>
        </div>

        <!-- Cart Card -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold mb-4">Current Cart</h2>

            <div class="overflow-x-auto">
                <table class="min-w-full bg-white">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left p-3 text-xs text-gray-600">Product</th>
                            <th class="text-left p-3 text-xs text-gray-600">Serial</th>
                            <th class="text-right p-3 text-xs text-gray-600">Qty</th>
                            <th class="text-right p-3 text-xs text-gray-600">Unit</th>
                            <th class="text-right p-3 text-xs text-gray-600">Total</th>
                            <th class="text-center p-3 text-xs text-gray-600">Action</th>
                        </tr>
                    </thead>
                    <tbody id="cart-table-body" class="divide-y divide-gray-100">
                        <tr id="cart-empty-row">
                            <td colspan="6" class="text-center p-8 text-gray-500">
                                <i class="fas fa-spinner fa-spin fa-2x"></i>
                                <div class="mt-2">Loading Cart...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <div id="toast-notification" class="toast"></div>

<script>
$(function(){

    // Element refs
    const $cat = $('#product-category');
    const $brand = $('#product-brand');
    const $model = $('#product-model');
    const $qty = $('#product-quantity');
    const $unit = $('#product-unit-price');
    const $stock = $('#stock-available');
    const $avg = $('#price-avg');
    const $max = $('#price-max');
    const $serialSearch = $('#serial-search');
    const $serialList = $('#serial-list');
    const $selectAllBox = $('#select-all-box');
    const $selectAllNative = $('#select-all-visible');
    const $selectedCount = $('#selected-count');
    const $addBtn = $('#add-to-cart-btn');
    const $toast = $('#toast-notification');
    const $cartBody = $('#cart-table-body');

    let currentSerials = []; // [{sl_id, product_sl}]
    // Helper: show toast
    function showToast(msg, type='error') {
        $toast.text(msg).removeClass('success error').addClass(type).addClass('show');
        setTimeout(()=> $toast.removeClass('show'), 3000);
    }
    function logError(msg, xhr) {
        console.error(msg, xhr);
        let server = 'Server error';
        try { const d = JSON.parse(xhr.responseText); if (d.message) server = d.message; } catch(e) {}
        showToast(msg + ': ' + server, 'error');
    }
    function formatCurrency(n) {
        n = parseFloat(n) || 0;
        return n.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    // Reset helpers
    function resetFormKeepCategory() {
        $brand.prop('disabled', true).html('<option>-- Select Brand --</option>');
        $model.prop('disabled', true).html('<option>-- Select Model --</option>');
        $serialList.html('<div class="text-xs text-gray-500 p-2">Select serial(s) — selecting serials will override quantity.</div>');
        $stock.text('0'); $avg.text('0.00'); $max.text('0.00');
        $qty.val(1).prop('disabled', false);
        $unit.val('');
        currentSerials = [];
        updateSelectedCount();
        updateSelectAllVisual(false);
    }

    // Load brands when category changes
    $cat.on('change', function(){
        const catId = $(this).val();
        resetFormKeepCategory();
        if (!catId) return;
        $brand.prop('disabled', true).html('<option>Loading...</option>');
        $.get('cart_ajax.php?action=get_brands_by_category', {category_id: catId})
            .done(d => {
                if (d.status === 'success') {
                    $brand.empty().append('<option value="">-- Select Brand --</option>');
                    d.brands.forEach(b => $brand.append(`<option value="${b.brand_id}">${b.brand_name}</option>`));
                    $brand.prop('disabled', false);
                } else showToast(d.message || 'Failed to load brands');
            }).fail(xhr => logError('Error loading brands', xhr));
    });

    // Load models
    $brand.on('change', function(){
        const brandId = $(this).val();
        $model.prop('disabled', true).html('<option>Loading...</option>');
        $serialList.html('<div class="text-xs text-gray-500 p-2">Select serial(s) — selecting serials will override quantity.</div>');
        $stock.text('0'); $avg.text('0.00'); $max.text('0.00');
        if (!brandId) {
            $model.prop('disabled', true).html('<option>-- Select Model --</option>');
            return;
        }
        $.get('cart_ajax.php?action=get_models_by_brand', {brand_id: brandId})
            .done(d => {
                if (d.status === 'success') {
                    $model.empty().append('<option value="">-- Select Model --</option>');
                    d.models.forEach(m => $model.append(`<option value="${m.model_id}">${m.model_name}</option>`));
                    $model.prop('disabled', false);
                } else showToast(d.message || 'Failed to load models');
            }).fail(xhr => logError('Error loading models', xhr));
    });

    // Model change: load stock, price, serials
    $model.on('change', function(){
        const modelId = $(this).val();
        $serialList.html('<div class="text-xs text-gray-500 p-2">Loading serials...</div>');
        $stock.text('...'); $avg.text('...'); $max.text('...');
        $qty.val(1).prop('disabled', false);
        currentSerials = [];
        updateSelectedCount();
        updateSelectAllVisual(false);

        if (!modelId) {
            $stock.text('0'); $avg.text('0.00'); $max.text('0.00');
            return;
        }

        const stockReq = $.get('cart_ajax.php?action=available_quantity', {model_id: modelId});
        const priceReq = $.get('cart_ajax.php?action=get_avg_max_price', {model_id: modelId});
        const serialReq = $.get('cart_ajax.php?action=get_serials_for_model', {model_id: modelId});

        $.when(stockReq, priceReq, serialReq)
            .done((stockData, priceData, serialData) => {
                const s = stockData[0], p = priceData[0], sl = serialData[0];

                if (s.status === 'success') $stock.text(s.available);
                else { $stock.text('0'); showToast(s.message || 'Stock error'); }

                if (p.status === 'success') {
                    $avg.text(parseFloat(p.avg).toFixed(2));
                    $max.text(parseFloat(p.max).toFixed(2));
                    if (!$unit.val()) $unit.val(parseFloat(p.avg).toFixed(2));
                } else { $avg.text('0.00'); $max.text('0.00'); showToast(p.message || 'Price error'); }

                if (sl.status === 'success') {
                    currentSerials = sl.serials || [];
                    renderSerialList(currentSerials);
                } else {
                    currentSerials = [];
                    $serialList.html('<div class="text-xs text-red-500 p-2">Error loading serials</div>');
                    showToast(sl.message || 'Serial load error');
                }
            })
            .fail(xhr => {
                logError('Failed to load product details', xhr);
                $stock.text('ERR'); $avg.text('ERR'); $max.text('ERR');
                $serialList.html('<div class="text-xs text-red-500 p-2">Error loading</div>');
            });
    });

    // Render serials (custom checkbox + label)
    function renderSerialList(arr) {
        if (!arr || arr.length === 0) {
            $serialList.html('<div class="text-xs text-gray-500 p-2">No available serials</div>');
            updateSelectedCount(); updateSelectAllVisual(false);
            return;
        }
        const html = arr.map(s => {
            return `<div class="serial-item" data-sl="${escapeHtml(s.sl_id)}" data-text="${escapeHtml(s.product_sl)}">
                        <span class="checkbox-custom" data-slid="${escapeHtml(s.sl_id)}" role="button" tabindex="0" aria-pressed="false">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414L8.414 15l-4.121-4.121a1 1 0 011.414-1.414L8.414 12.586l7.879-7.879a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        </span>
                        <span class="serial-meta ml-2" data-slid="${escapeHtml(s.sl_id)}">${escapeHtml(s.product_sl)}</span>
                    </div>`;
        }).join('');
        $serialList.html(html);

        // bind handlers
        $serialList.find('.checkbox-custom').on('click', function(){
            const slid = $(this).attr('data-slid');
            toggleSerialById(slid);
        }).on('keydown', function(e){
            if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); $(this).trigger('click'); }
        });
        $serialList.find('.serial-meta').on('click', function(){ toggleSerialById($(this).attr('data-slid')); });

        updateSelectedCount(); updateSelectAllVisual(false);
    }

    function toggleSerialById(slid) {
        const $item = $serialList.find(`.serial-item[data-sl='${CSSescape(slid)}']`);
        if ($item.length === 0) return;
        const $box = $item.find('.checkbox-custom');
        const checked = $box.hasClass('checked');
        if (checked) {
            $box.removeClass('checked').attr('aria-pressed','false');
            $item.find('input.hidden-serial-input').remove();
        } else {
            $box.addClass('checked').attr('aria-pressed','true');
            if ($item.find('input.hidden-serial-input').length === 0) {
                $item.append(`<input type="hidden" class="hidden-serial-input" name="product_sl_id_fk[]" value="${escapeHtml(slid)}" />`);
            }
        }
        const cnt = $serialList.find('.checkbox-custom.checked').length;
        if (cnt > 0) { $qty.val(cnt).prop('disabled', true); }
        else { $qty.val(1).prop('disabled', false); }
        updateSelectedCount(); updateSelectAllVisual();
    }

    function updateSelectedCount() {
        const cnt = $serialList.find('.checkbox-custom.checked').length;
        $selectedCount.text('Selected: ' + cnt);
    }

    function updateSelectAllVisual(forceState) {
        let allChecked;
        if (typeof forceState === 'boolean') {
            allChecked = forceState;
        } else {
            const $visible = $serialList.find('.serial-item').not('.hidden');
            if ($visible.length === 0) allChecked = false;
            else {
                const visChecked = $visible.find('.checkbox-custom.checked').length;
                allChecked = (visChecked === $visible.length);
            }
        }
        if (allChecked) { $selectAllBox.addClass('checked').attr('aria-checked','true'); $selectAllNative.prop('checked', true); }
        else { $selectAllBox.removeClass('checked').attr('aria-checked','false'); $selectAllNative.prop('checked', false); }
    }

    // Select all visible toggle
    $selectAllBox.on('click', function(){
        const isChecked = $(this).hasClass('checked');
        if (isChecked) {
            // uncheck all visible
            $serialList.find('.serial-item').not('.hidden').each(function(){
                $(this).find('.checkbox-custom.checked').removeClass('checked').attr('aria-pressed','false');
                $(this).find('input.hidden-serial-input').remove();
            });
            updateSelectAllVisual(false);
        } else {
            // check all visible
            $serialList.find('.serial-item').not('.hidden').each(function(){
                const $box = $(this).find('.checkbox-custom');
                const slid = $(this).attr('data-sl');
                if (!$box.hasClass('checked')) {
                    $box.addClass('checked').attr('aria-pressed','true');
                    if ($(this).find('input.hidden-serial-input').length === 0) {
                        $(this).append(`<input type="hidden" class="hidden-serial-input" name="product_sl_id_fk[]" value="${escapeHtml(slid)}" />`);
                    }
                }
            });
            updateSelectAllVisual(true);
        }
        const cnt = $serialList.find('.checkbox-custom.checked').length;
        if (cnt > 0) $qty.val(cnt).prop('disabled', true); else $qty.val(1).prop('disabled', false);
        updateSelectedCount();
    });
    $selectAllBox.attr('tabindex', 0).on('keydown', function(e){ if (e.key===' '||e.key==='Enter'){ e.preventDefault(); $(this).trigger('click'); } });

    // Search filter
    $serialSearch.on('input', function(){
        const term = String($(this).val()||'').trim().toLowerCase();
        if (!term) $serialList.find('.serial-item').removeClass('hidden');
        else {
            $serialList.find('.serial-item').each(function(){
                const t = ($(this).attr('data-text')||'').toLowerCase();
                if (t.indexOf(term) !== -1) $(this).removeClass('hidden'); else $(this).addClass('hidden');
            });
        }
        updateSelectAllVisual();
    });

    // Add to cart click
    $addBtn.on('click', function(){
        const modelId = $model.val();
        if (!modelId) { showToast('Please select a product model.', 'error'); $model.focus(); return; }

        const quantity = parseInt($qty.val()) || 0;
        const available = parseInt($stock.text()) || 0;
        const serialIds = $serialList.find('input.hidden-serial-input').map(function(){ return $(this).val(); }).get();
        const isSerialSale = (serialIds && serialIds.length>0);

        if (quantity <= 0) { showToast('Quantity must be at least 1.', 'error'); $qty.focus(); return; }
        if (!isSerialSale && quantity > available) { showToast(`Quantity (${quantity}) exceeds available (${available}).`, 'error'); $qty.focus(); return; }
        const unitPrice = parseFloat($unit.val());
        if (isNaN(unitPrice) || unitPrice < 0) { showToast('Please enter a valid unit price.', 'error'); $unit.focus(); return; }

        const postData = {
            model_id_fk: modelId,
            product_sl_id_fk: serialIds,
            Quantity: quantity,
            Sold_Unit_Price: unitPrice
        };

        $addBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding...');
        $.post('cart_ajax.php?action=add_to_cart', postData)
            .done(d => {
                if (d.status === 'success') {
                    showToast(d.message || 'Added', 'success');
                    // reset
                    $model.val('').trigger('change');
                    $brand.val('');
                    $cat.val('');
                    $unit.val('');
                    // reload cart
                    loadCart();
                } else {
                    showToast(d.message || 'Failed to add item', 'error');
                    $model.trigger('change');
                }
            })
            .fail(xhr => logError('Error adding to cart', xhr))
            .always(()=> { $addBtn.prop('disabled', false).html('<i class="fas fa-cart-plus mr-2"></i>Add to Cart'); });
    });

    // Load cart
    function loadCart() {
        $cartBody.html('<tr><td colspan="6" class="text-center p-6 text-gray-500"><i class="fas fa-spinner fa-spin"></i></td></tr>');
        $.get('cart_ajax.php?action=get_cart_contents')
            .done(d => {
                if (d.status === 'success') renderCart(d.rows);
                else { showToast(d.message||'Failed to load cart'); $cartBody.html('<tr><td colspan="6" class="text-center p-6 text-red-500">Error loading cart</td></tr>'); }
            })
            .fail(xhr => { logError('Error loading cart', xhr); $cartBody.html('<tr><td colspan="6" class="text-center p-6 text-red-500">Error loading cart</td></tr>'); });
    }

    function renderCart(rows) {
        $cartBody.empty();
        if (!rows || rows.length===0) {
            $cartBody.append(`<tr id="cart-empty-row"><td colspan="6" class="text-center p-8 text-gray-500"><i class="fas fa-shopping-cart fa-2x mb-2"></i><div>Your cart is empty.</div></td></tr>`);
            return;
        }
        rows.forEach(item => {
            const total = (parseFloat(item.sale_price)||0) * (parseInt(item.quantity)||0);
            const productDesc = `<div class="font-medium">${escapeHtml(item.model_name)}</div><div class="text-xs text-gray-500">${escapeHtml(item.category_name)} | ${escapeHtml(item.brand_name)}</div>`;
            const serialDesc = item.product_sl ? escapeHtml(item.product_sl) : '<span class="text-gray-400">N/A</span>';
            $cartBody.append(`
                <tr data-cart-id="${item.cart_id}">
                    <td class="p-3">${productDesc}</td>
                    <td class="p-3">${serialDesc}</td>
                    <td class="p-3 text-right">${item.quantity}</td>
                    <td class="p-3 text-right">${formatCurrency(item.sale_price)}</td>
                    <td class="p-3 text-right font-medium">${formatCurrency(total)}</td>
                    <td class="p-3 text-center">
                        <button class="remove-cart-item text-red-500 hover:text-red-700" data-id="${item.cart_id}" title="Remove"><i class="fas fa-trash-alt"></i></button>
                    </td>
                </tr>
            `);
        });
    }

    // Remove handler (delegated)
    $cartBody.on('click', '.remove-cart-item', function(){
        const id = $(this).data('id');
        if (!id) return;
        if (!confirm('Remove this item from cart?')) return;
        const $btn = $(this);
        $btn.prop('disabled', true);
        $.post('cart_ajax.php?action=remove_from_cart', {cart_id: id})
            .done(d => {
                if (d.status === 'success') {
                    showToast(d.message || 'Removed', 'success');
                    loadCart();
                    $model.trigger('change'); // refresh serial availability
                } else {
                    showToast(d.message || 'Failed to remove', 'error');
                    $btn.prop('disabled', false);
                }
            })
            .fail(xhr => { logError('Error removing item', xhr); $btn.prop('disabled', false); });
    });

    // Utilities
    function escapeHtml(s){ if (s===undefined||s===null) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
    function CSSescape(s){ return String(s).replace(/([ #;?%&,.+*~\':"!^$[\]()=>|\/@])/g,'\\$1'); }

    // Initialize
    loadCart();
    updateSelectedCount();
    updateSelectAllVisual(false);

});
</script>
</body>
</html>
