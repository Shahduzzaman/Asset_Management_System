<?php
session_start();
require_once 'connection.php';

// --- Session & Security Checks (match your style) ---
$idleTimeout = 1800;
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

// --- Helper utilities -----------------------------------------------------
function to_int($v) { return (int)$v; }

/**
 * Run a query and return first row assoc or null on failure/empty.
 * This prevents fatal errors when a query fails.
 */
function fetch_row_assoc($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $row = $res->fetch_assoc()) return $row;
    return null;
}

/**
 * Run a query that returns a single scalar value (like SUM or COUNT) and return default on failure.
 */
function fetch_scalar($conn, $sql, $key = null, $default = 0) {
    $row = fetch_row_assoc($conn, $sql);
    if (!$row) return $default;
    if ($key === null) {
        // take first value
        $vals = array_values($row);
        return isset($vals[0]) ? $vals[0] : $default;
    }
    return isset($row[$key]) ? $row[$key] : $default;
}

// If called as AJAX details endpoint, return modal content and exit.
if (isset($_GET['mode']) && $_GET['mode'] === 'details' && isset($_GET['model_id'])) {
    $model_id = (int) $_GET['model_id'];

    // 1) If model has serials, list serials with status & purchase invoice + branch
    $sqlSerialCheck = "SELECT COUNT(*) AS serial_total FROM product_sl WHERE model_id_fk = {$model_id}";
    $serialTotal = to_int(fetch_scalar($conn, $sqlSerialCheck, 'serial_total', 0));

    ob_start();
    ?>
    <div class="space-y-4">
        <?php if ($serialTotal > 0): ?>
            <h4 class="text-lg font-semibold">Serials for Model ID: <?= $model_id ?></h4>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-gray-600">
                        <tr>
                            <th class="px-3 py-2">Serial No.</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2">Purchase Invoice</th>
                            <th class="px-3 py-2">Branch</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $sql = "SELECT ps.sl_id, ps.product_sl, ps.status, pp.purchase_id, pp.invoice_number, pp.branch_id_fk, b.Name AS branch_name
                            FROM product_sl ps
                            LEFT JOIN purchased_products pp ON ps.purchase_id_fk = pp.purchase_id
                            LEFT JOIN branch b ON pp.branch_id_fk = b.branch_id
                            WHERE ps.model_id_fk = {$model_id}
                            ORDER BY ps.sl_id DESC
                            LIMIT 500";
                    if ($rs = $conn->query($sql)) {
                        while ($row = $rs->fetch_assoc()):
                            $statusLabel = 'Unknown';
                            switch ((int)$row['status']) {
                                case 0: $statusLabel = 'In stock'; break;
                                case 1: $statusLabel = 'Sold'; break;
                                case 2: $statusLabel = 'Returned'; break;
                                case 3: $statusLabel = 'Damaged'; break;
                                default: $statusLabel = 'Other';
                            }
                    ?>
                        <tr class="border-t">
                            <td class="px-3 py-2"><?= htmlspecialchars($row['product_sl'] ?: '-') ?></td>
                            <td class="px-3 py-2"><?= $statusLabel ?></td>
                            <td class="px-3 py-2"><?= htmlspecialchars($row['invoice_number'] ?: '-') ?></td>
                            <td class="px-3 py-2"><?= htmlspecialchars($row['branch_name'] ?: 'N/A') ?></td>
                        </tr>
                    <?php
                        endwhile;
                    } else {
                        echo '<tr><td colspan="4" class="px-3 py-4 text-red-500">Failed to fetch serials.</td></tr>';
                    }
                    ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <h4 class="text-lg font-semibold">Quantity-based stock (non-serialized)</h4>
            <?php
            // Show purchased / sold summary and recent purchases
            $sqlQty = "SELECT 
                        IFNULL((SELECT SUM(quantity) FROM purchased_products WHERE model_id = {$model_id} AND is_deleted = 0),0) AS total_purchased,
                        IFNULL((SELECT SUM(Quantity) FROM sold_product WHERE model_id_fk = {$model_id} AND is_deleted = 0),0) AS total_sold";
            $s = fetch_row_assoc($conn, $sqlQty);
            $total_purchased = to_int($s['total_purchased'] ?? 0);
            $total_sold = to_int($s['total_sold'] ?? 0);
            $available = $total_purchased - $total_sold;
            if ($available < 0) $available = 0;
            ?>
            <div class="grid grid-cols-3 gap-4">
                <div class="p-3 bg-gray-50 rounded">
                    <div class="text-sm text-gray-500">Purchased</div>
                    <div class="text-2xl font-semibold"><?= number_format($total_purchased) ?></div>
                </div>
                <div class="p-3 bg-gray-50 rounded">
                    <div class="text-sm text-gray-500">Sold</div>
                    <div class="text-2xl font-semibold"><?= number_format($total_sold) ?></div>
                </div>
                <div class="p-3 bg-gray-50 rounded">
                    <div class="text-sm text-gray-500">Available</div>
                    <div class="text-2xl font-semibold"><?= number_format($available) ?></div>
                </div>
            </div>

            <div class="mt-4">
                <h5 class="font-medium mb-2">Recent Purchases (showing invoice & qty)</h5>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-gray-600">
                            <tr>
                                <th class="px-3 py-2">Purchase ID</th>
                                <th class="px-3 py-2">Invoice #</th>
                                <th class="px-3 py-2">Quantity</th>
                                <th class="px-3 py-2">Branch</th>
                                <th class="px-3 py-2">Purchase Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $sqlRecent = "SELECT pp.purchase_id, pp.invoice_number, pp.quantity, pp.branch_id_fk, b.Name AS branch_name, pp.purchase_date
                                      FROM purchased_products pp
                                      LEFT JOIN branch b ON pp.branch_id_fk = b.branch_id
                                      WHERE pp.model_id = {$model_id}
                                      ORDER BY pp.purchase_id DESC
                                      LIMIT 20";
                        if ($rs2 = $conn->query($sqlRecent)) {
                            while ($r2 = $rs2->fetch_assoc()):
                        ?>
                            <tr class="border-t">
                                <td class="px-3 py-2"><?= htmlspecialchars($r2['purchase_id']) ?></td>
                                <td class="px-3 py-2"><?= htmlspecialchars($r2['invoice_number'] ?: '-') ?></td>
                                <td class="px-3 py-2"><?= htmlspecialchars($r2['quantity']) ?></td>
                                <td class="px-3 py-2"><?= htmlspecialchars($r2['branch_name'] ?: 'N/A') ?></td>
                                <td class="px-3 py-2"><?= htmlspecialchars($r2['purchase_date'] ?: '-') ?></td>
                            </tr>
                        <?php
                            endwhile;
                        } else {
                            echo '<tr><td colspan="5" class="px-3 py-4 text-red-500">Failed to fetch purchases.</td></tr>';
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php

    $html = ob_get_clean();
    echo $html;
    exit();
}

// --- Page: Stock Monitor ---
// Read filters
$selected_category = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0; // 0 => all
$selected_branch = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;     // 0 => all

// Fetch categories for dropdown
$cats = [];
$catSql = "SELECT category_id, category_name FROM categories WHERE is_deleted = 0 ORDER BY category_name";
if ($res = $conn->query($catSql)) {
    while ($row = $res->fetch_assoc()) $cats[] = $row;
}

// Fetch branches
$branches = [];
$brSql = "SELECT branch_id, Name FROM branch ORDER BY Name";
if ($res = $conn->query($brSql)) {
    while ($row = $res->fetch_assoc()) $branches[] = $row;
}

// Fetch models (optionally filtered by category)
$models = [];
$modelSql = "SELECT m.model_id, m.model_name, b.brand_name, m.category_id
             FROM models m
             LEFT JOIN brands b ON m.brand_id = b.brand_id
             WHERE m.is_deleted = 0";
if ($selected_category > 0) $modelSql .= " AND m.category_id = {$selected_category}";
$modelSql .= " ORDER BY b.brand_name, m.model_name";
if ($res = $conn->query($modelSql)) {
    while ($m = $res->fetch_assoc()) $models[] = $m;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Stock Monitor - AMS</title>
<meta name="viewport" content="width=device-width,initial-scale=1">

<!-- Tailwind (same as your file) -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"/>
<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

</head>
<body class="bg-gray-100 font-sans text-gray-800">

    <!-- Top Bar -->
    <div class="bg-white shadow p-4 flex items-center justify-between mb-6 sticky top-0 z-10">
        <h1 class="text-2xl font-bold text-gray-800">Stock Monitor</h1>
    </div>

    <div class="container mx-auto px-4 pb-8">
        <div class="bg-white shadow-lg rounded-lg overflow-hidden mb-4">
            <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <form id="filtersForm" class="col-span-3 flex gap-3 w-full" method="get" action="stock_monitor.php">
                    <div>
                        <label class="block text-xs text-gray-600">Category</label>
                        <select name="category_id" class="mt-1 block w-64 rounded border-gray-200" onchange="this.form.submit()">
                            <option value="0" <?= $selected_category === 0 ? 'selected' : '' ?>>All categories</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?= $c['category_id'] ?>" <?= $selected_category === (int)$c['category_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs text-gray-600">Branch (optional)</label>
                        <select name="branch_id" class="mt-1 block w-64 rounded border-gray-200" onchange="this.form.submit()">
                            <option value="0" <?= $selected_branch === 0 ? 'selected' : '' ?>>All branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['branch_id'] ?>" <?= $selected_branch === (int)$b['branch_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['Name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ml-auto flex items-end gap-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                            <i class="fas fa-filter mr-2"></i>Filter
                        </button>
                        <a href="stock_monitor.php" class="bg-gray-200 px-4 py-2 rounded hover:bg-gray-300">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Brand</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Model</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Purchased</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Sold</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Available</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        if (count($models) === 0) {
                            echo '<tr><td colspan="7" class="px-6 py-10 text-center text-gray-500">No models found for the selected filters.</td></tr>';
                        } else {
                            $i = 0;
                            foreach ($models as $m) {
                                $i++;
                                $model_id = (int)$m['model_id'];

                                // total purchased
                                $sqlPurchased = "SELECT IFNULL(SUM(quantity),0) AS total_purchased FROM purchased_products WHERE model_id = {$model_id} AND is_deleted = 0";
                                if ($selected_branch > 0) $sqlPurchased .= " AND branch_id_fk = {$selected_branch}";
                                $total_purchased = to_int(fetch_scalar($conn, $sqlPurchased, 'total_purchased', 0));

                                // total sold
                                // IMPORTANT: only count sales that are recorded against the selected client_branch (if branch filter applied).
                                $sqlSold = "SELECT IFNULL(SUM(Quantity),0) AS total_sold FROM sold_product WHERE model_id_fk = {$model_id} AND is_deleted = 0";
                                if ($selected_branch > 0) {
                                    // Count only those sold to that client branch (client_branch_id_fk = selected_branch).
                                    $sqlSold .= " AND client_branch_id_fk = {$selected_branch}";
                                }
                                $total_sold = to_int(fetch_scalar($conn, $sqlSold, 'total_sold', 0));

                                // serial totals & in-stock (we still compute serials to decide method)
                                $sqlSerialTotal = "SELECT COUNT(*) AS serial_total FROM product_sl WHERE model_id_fk = {$model_id}";
                                $serial_total = to_int(fetch_scalar($conn, $sqlSerialTotal, 'serial_total', 0));

                                $sqlSerialInStock = "
                                    SELECT COUNT(ps.sl_id) AS serial_in_stock
                                    FROM product_sl ps
                                    LEFT JOIN purchased_products pp ON ps.purchase_id_fk = pp.purchase_id
                                    WHERE ps.model_id_fk = {$model_id}
                                      AND ps.status = 0
                                      AND (pp.is_deleted = 0 OR pp.is_deleted IS NULL)
                                ";
                                if ($selected_branch > 0) $sqlSerialInStock .= " AND pp.branch_id_fk = {$selected_branch}";
                                $serial_in_stock = to_int(fetch_scalar($conn, $sqlSerialInStock, 'serial_in_stock', 0));

                                if ($serial_total > 0) {
                                    $available = $serial_in_stock;
                                    $method = 'serialized';
                                } else {
                                    $available = $total_purchased - $total_sold;
                                    $method = 'qty';
                                    if ($available < 0) $available = 0;
                                }

                                $lowClass = $available <= 5 ? 'text-red-600 font-semibold' : 'text-gray-900';
                        ?>
                        <tr class="hover:bg-blue-50 transition duration-150">
                            <td class="px-6 py-4 whitespace-nowrap"><?= $i ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($m['brand_name'] ?? '-') ?></td>
                            <td class="px-6 py-4 whitespace-nowrap"><?= htmlspecialchars($m['model_name']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right"><?= number_format($total_purchased) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right"><?= number_format($total_sold) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right <?= $lowClass ?>"><?= number_format($available) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <button onclick="openModelDetails(<?= $model_id ?>)" title="View" class="text-blue-600 hover:text-blue-900 mr-3">
                                    <i class="fas fa-eye fa-lg"></i>
                                </button>
                            </td>
                        </tr>
                        <?php
                            } // end foreach models
                        } // end else models
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-3 text-muted text-sm text-gray-500">
            Note: If a model has serials recorded in <code>product_sl</code>, the monitor uses those serials (status = 0) as authoritative in-stock count.
            For non-serialized items, available = purchased - sold.
        </p>
    </div>

    <!-- Modal -->
    <div id="modelModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-0 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center bg-gray-100 px-4 py-2 border-b rounded-t-md">
                <h3 class="text-lg font-medium text-gray-900">Model Details</h3>
                <button onclick="closeModelModal()" class="text-gray-600 hover:text-red-600">
                    <i class="fas fa-times fa-lg"></i>
                </button>
            </div>
            <div id="modelModalContent" class="p-6 max-h-[80vh] overflow-y-auto">
                <div class="flex justify-center items-center py-10">
                    <i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i>
                </div>
            </div>
            <div class="flex justify-end px-4 py-3 bg-gray-50 border-t rounded-b-md">
                <button onclick="closeModelModal()" class="bg-gray-300 text-gray-700 px-4 py-2 rounded hover:bg-gray-400">Close</button>
            </div>
        </div>
    </div>

<script>
function openModelDetails(modelId) {
    const modal = $('#modelModal');
    const content = $('#modelModalContent');

    modal.fadeIn(200);
    content.html('<div class="flex justify-center items-center py-10"><i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i></div>');
    $.ajax({
        url: 'stock_monitor.php',
        method: 'GET',
        data: { mode: 'details', model_id: modelId },
        success: function(resp) {
            content.html(resp);
        },
        error: function() {
            content.html('<div class="text-center text-red-500">Failed to load details.</div>');
        }
    });
}

function closeModelModal() {
    $('#modelModal').fadeOut(200, function() {
        $('#modelModalContent').html('<div class="flex justify-center items-center py-10"><i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i></div>');
    });
}

$(window).click(function(event) {
    if (event.target.id === 'modelModal') {
        closeModelModal();
    }
});
</script>

</body>
</html>
