<?php
session_start();
require_once 'connection.php'; // Expects $conn (mysqli)

// --- Helper: Log Errors ---
function log_error_msg($msg) {
    error_log("[PurchaseReturn] " . $msg);
}

// --- 1. Session & Auth Checks ---
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
$user_id = (int)$_SESSION['user_id'];

// Initialize messages
$message = '';
$messageType = '';

// --- 2. FETCH DROPDOWN DATA ---

// Fetch All Vendors
$vendorsList = [];
$sqlVendors = "SELECT vendor_id, vendor_name FROM vendors ORDER BY vendor_name ASC";
$resVendors = $conn->query($sqlVendors);
if ($resVendors) {
    while ($row = $resVendors->fetch_assoc()) {
        $vendorsList[] = $row;
    }
}

// Fetch All Potential Return Items (exclude status = 1 or 4)
$productsList = [];
$sqlProducts = "SELECT p.sl_id, p.product_sl, p.model_id_fk, (SELECT model_name FROM models m WHERE m.model_id = p.model_id_fk LIMIT 1) AS model_name
                FROM product_sl p
                WHERE p.status NOT IN (1,4)
                ORDER BY p.product_sl ASC";
$resProducts = $conn->query($sqlProducts);
if ($resProducts) {
    while ($row = $resProducts->fetch_assoc()) {
        $productsList[] = $row;
    }
}

// --- 3. HANDLE AJAX REQUESTS (Only for Replacement Check) ---
if (isset($_GET['action']) && $_GET['action'] === 'check_serial') {
    header('Content-Type: application/json');
    $serial = $_GET['serial'] ?? '';
    
    // Query product_sl table
    $sql = "SELECT sl_id, product_sl, status, model_id_fk FROM product_sl WHERE product_sl = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $serial);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        echo json_encode(['status' => 'success', 'data' => $row]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Serial number not found in system.']);
    }
    exit;
}

// --- 4. HANDLE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_return'])) {
    
    $vendor_id = (int)$_POST['vendor_id'];
    $returned_sl_id = (int)$_POST['returned_sl_id'];
    $replacement_sl_id = !empty($_POST['replacement_sl_id']) ? (int)$_POST['replacement_sl_id'] : NULL;
    $reason = trim($_POST['reason']);
    // Use the submitted date (no PO fields anywhere)
    $return_date = $_POST['return_date'] . ' ' . date('H:i:s'); // Append current time to selected date

    if (!$vendor_id || !$returned_sl_id) {
        $message = "Error: Vendor and Returned Product are required.";
        $messageType = "danger";
    } else {
        // Start Transaction
        $conn->begin_transaction();
        try {
            // 1. Insert into purchase_return (PO removed)
            $sqlInsert = "INSERT INTO purchase_return 
                          (vendor_id_fk, returned_product_sl_id_fk, replacement_product_sl_id_fk, reason, return_date, created_by) 
                          VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sqlInsert);
            $stmt->bind_param("iiissi", $vendor_id, $returned_sl_id, $replacement_sl_id, $reason, $return_date, $user_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save return record: " . $stmt->error);
            }

            // 2. Update Status of Returned Item -> set to 5
            $sqlUpdateReturn = "UPDATE product_sl SET status = 5 WHERE sl_id = ?";
            $stmtRet = $conn->prepare($sqlUpdateReturn);
            $stmtRet->bind_param("i", $returned_sl_id);
            $stmtRet->execute();

            // 3. Update Status of Replacement Item (if provided) -> set to 0 (in stock)
            if ($replacement_sl_id) {
                $sqlUpdateRep = "UPDATE product_sl SET status = 0 WHERE sl_id = ?";
                $stmtRep = $conn->prepare($sqlUpdateRep);
                $stmtRep->bind_param("i", $replacement_sl_id);
                $stmtRep->execute();
            }

            $conn->commit();
            $message = "Purchase return processed successfully!";
            $messageType = "success";
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error: " . $e->getMessage();
            $messageType = "danger";
            log_error_msg($e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Purchase Return</title>
    <!-- Basic Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Select2 (searchable dropdowns) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <!-- flatpickr datepicker -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <style>
        .feedback { font-size: 0.9em; margin-top: 5px; font-weight: bold; }
        .card-header { background-color: #343a40; color: white; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-5">
    
    <!-- Alert Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow">
        <div class="card-header">
            <h4>Purchase Return System</h4>
        </div>
        <div class="card-body">
            <form method="POST" action="" id="returnForm">
                
                <div class="row">
                    <!-- Vendor Selection (Searchable Dropdown via Select2) -->
                    <div class="col-md-6 mb-4">
                        <label for="vendor_id" class="form-label">Search & Select Vendor</label>
                        <select name="vendor_id" id="vendor_id" class="form-select" required>
                            <option value="">-- Choose Vendor --</option>
                            <?php foreach ($vendorsList as $vendor): ?>
                                <option value="<?php echo $vendor['vendor_id']; ?>">
                                    <?php echo htmlspecialchars($vendor['vendor_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Return Date (flatpickr datepicker, default to today) -->
                    <div class="col-md-6 mb-4">
                        <label for="return_date" class="form-label">Return Date</label>
                        <input type="text" name="return_date" id="return_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="row">
                    <!-- Returned Item Section (Searchable Dropdown) -->
                    <div class="col-md-6 mb-3">
                        <div class="border p-3 rounded bg-white h-100">
                            <h5 class="text-danger">Item to Return</h5>
                            <div class="mb-3">
                                <label class="form-label">Product Serial Number (Search)</label>
                                <select name="returned_sl_id" id="returned_sl_id" class="form-select" required>
                                    <option value="">-- Select Product to Return --</option>
                                    <?php foreach ($productsList as $prod): ?>
                                        <option value="<?php echo $prod['sl_id']; ?>">
                                            <?php echo htmlspecialchars($prod['product_sl'] . ' (' . $prod['model_name'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Replacement Item Section (Text Search) -->
                    <div class="col-md-6 mb-3">
                        <div class="border p-3 rounded bg-white h-100">
                            <h5 class="text-success">Replacement Item (Optional)</h5>
                            <div class="mb-3">
                                <label class="form-label">New Serial Number</label>
                                <input type="text" class="form-control" id="replacementSerial" placeholder="Scan or type serial">
                                <input type="hidden" name="replacement_sl_id" id="replacementSlId">
                                <div id="replacementFeedback" class="feedback"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="mb-3">
                    <label class="form-label">Reason for Return</label>
                    <textarea name="reason" class="form-control" rows="2" placeholder="Why is it being returned?"></textarea>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" name="submit_return" id="btnSubmit" class="btn btn-dark btn-lg">Process Return</button>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2 for searchable dropdowns
    $('#vendor_id').select2({
        placeholder: 'Search vendor',
        width: '100%',
        allowClear: true
    });

    $('#returned_sl_id').select2({
        placeholder: 'Search product serial',
        width: '100%',
        allowClear: true
    });

    // Initialize flatpickr for date picking (default today)
    flatpickr('#return_date', {
        dateFormat: 'Y-m-d',
        defaultDate: '<?php echo date('Y-m-d'); ?>',
        allowInput: true
    });

    // --- Check Replacement Product Logic ---
    $('#replacementSerial').on('blur', function() {
        let serial = $(this).val().trim();
        if (!serial) {
            $('#replacementSlId').val('');
            $('#replacementFeedback').text('');
            return;
        }

        $.ajax({
            url: '?action=check_serial',
            data: { serial: serial },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    // Check if it matches the selected returned item ID
                    if (res.data.sl_id == $('#returned_sl_id').val()) {
                         $('#replacementSlId').val('');
                         $('#replacementFeedback').html('<span class="text-danger">Replacement cannot be the same as Returned item.</span>');
                         return;
                    }

                    $('#replacementSlId').val(res.data.sl_id);
                    $('#replacementFeedback').html(`<span class="text-success">Available: Model ID ${res.data.model_id_fk}</span>`);
                } else {
                    $('#replacementSlId').val('');
                    $('#replacementFeedback').html(`<span class="text-danger">${res.message}</span>`);
                }
            }
        });
    });

    // Simple validation helper
    $('form').on('submit', function(e) {
        if (!$('#vendor_id').val() || !$('#returned_sl_id').val()) {
            e.preventDefault();
            alert('Please select a Vendor and a Product to return.');
        }
    });
});
</script>

</body>
</html>