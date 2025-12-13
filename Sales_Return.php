<?php
session_start();
require_once 'connection.php'; // expects $conn (mysqli)

// Initialize variables to avoid undefined variable warnings
$message = '';
$messageType = '';
$error_message = '';
$success_message = '';

// Session idle timeout handling
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

function log_error_msg($msg) {
    error_log($msg);
}

try {
    // --- 1. HANDLE AJAX REQUESTS (Search Invoices Autocomplete) ---
    if (isset($_GET['action']) && $_GET['action'] === 'search_invoices') {
        header('Content-Type: application/json');
        $term = $_GET['term'] ?? '';

        if (strlen($term) < 1) {
            echo json_encode([]);
            exit;
        }

        $searchTerm = "%" . $term . "%";
        $sql = "SELECT Invoice_No AS invoice_no FROM invoice WHERE Invoice_No LIKE ? LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();

        $suggestions = [];
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = $row['invoice_no'];
        }
        echo json_encode($suggestions);
        exit;
    }

    // --- 2. HANDLE AJAX REQUESTS (Get Invoice Details) ---
    if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_details') {
        header('Content-Type: application/json');

        $invoice_no = $_GET['invoice_no'] ?? '';

        $sql_invoice = "SELECT 
                            i.invoice_id, 
                            i.Invoice_No AS invoice_no, 
                            i.created_at AS invoice_date,
                            COALESCE(ch.Company_Name, cb.Branch_Name) AS client_name,
                            COALESCE(ch.Contact_Number, cb.Contact_Number1) AS phone,
                            COALESCE(ch.Address, cb.Address) AS address
                        FROM invoice i
                        LEFT JOIN sold_product sp ON sp.invoice_id_fk = i.invoice_id
                        LEFT JOIN client_branch cb ON sp.client_branch_id_fk = cb.client_branch_id
                        LEFT JOIN client_head ch ON ch.client_head_id = COALESCE(sp.client_head_id_fk, cb.client_head_id_fk)
                        WHERE i.Invoice_No = ?
                        LIMIT 1";
        $stmt = $conn->prepare($sql_invoice);
        $stmt->bind_param("s", $invoice_no);
        $stmt->execute();
        $result = $stmt->get_result();
        $invoiceData = $result->fetch_assoc();

        if (!$invoiceData) {
            echo json_encode(['status' => 'error', 'message' => 'Invoice not found.']);
            exit;
        }

        // Fetch sold products
        $sql_products = "SELECT 
                            sp.product_sl_id_fk,
                            psl.product_sl AS sl_no,
                            m.model_name
                         FROM sold_product sp
                         LEFT JOIN product_sl psl ON sp.product_sl_id_fk = psl.sl_id
                         LEFT JOIN models m ON sp.model_id_fk = m.model_id
                         WHERE sp.invoice_id_fk = ?";
        $stmt_prod = $conn->prepare($sql_products);
        $stmt_prod->bind_param("i", $invoiceData['invoice_id']);
        $stmt_prod->execute();
        $result_prod = $stmt_prod->get_result();

        $products = [];
        while ($row = $result_prod->fetch_assoc()) {
            $products[] = $row;
        }

        echo json_encode([
            'status' => 'success',
            'invoice' => $invoiceData,
            'products' => $products
        ]);
        exit;
    }

    // --- 3. HANDLE AJAX REQUESTS (Check Replacement Product Availability) ---
    if (isset($_GET['action']) && $_GET['action'] === 'check_replacement') {
        header('Content-Type: application/json');
        $serial_no = $_GET['serial_no'] ?? '';

        $sql = "SELECT sl_id, product_sl AS sl_no, model_id_fk FROM product_sl WHERE product_sl = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $serial_no);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            echo json_encode(['status' => 'success', 'data' => $row]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Product not found.']);
        }
        exit;
    }

} catch (mysqli_sql_exception $e) {
    // AJAX requests get JSON; normal page load shows message
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
        exit;
    } else {
        $message = "Database Error: " . $e->getMessage();
        $messageType = "danger";
    }
} catch (Exception $e) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
        exit;
    } else {
        $message = "System Error: " . $e->getMessage();
        $messageType = "danger";
    }
}

// --- 4. HANDLE FORM SUBMISSION (Process Return) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_return'])) {

    $invoice_id_fk = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : null;
    $invoice_number = $_POST['invoice_number'] ?? '';
    $return_date_raw = $_POST['return_date'] ?? null;
    $received_product_sl_id_fk = isset($_POST['returned_product_id']) ? (int) $_POST['returned_product_id'] : null;
    $replace_product_sl_id_fk = !empty($_POST['replacement_product_id']) ? (int) $_POST['replacement_product_id'] : null;
    $created_by = (int) $_SESSION['user_id'];

    // Convert datetime-local to MySQL DATETIME "Y-m-d H:i:s"
    $return_date = null;
    if ($return_date_raw) {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $return_date_raw);
        if (!$dt) {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $return_date_raw);
        }
        if (!$dt) {
            try {
                $dt = new DateTime($return_date_raw);
            } catch (Exception $ex) {
                $dt = false;
            }
        }
        if ($dt && $dt instanceof DateTime) {
            $return_date = $dt->format('Y-m-d H:i:s');
        } else {
            $return_date = null;
        }
    }

    if (!$invoice_id_fk || !$received_product_sl_id_fk || !$return_date) {
        $message = "Please select an invoice, a returned product, and set a valid return date.";
        $messageType = "danger";
    } else {
        $conn->begin_transaction();
        try {
            // 1) Insert into sales_return
            $stmt = $conn->prepare("
                INSERT INTO sales_return 
                    (invoice_id_fk, invoice_number, return_date, received_product_sl_id_fk, replace_product_sl_id_fk, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "issiii",
                $invoice_id_fk,
                $invoice_number,
                $return_date,
                $received_product_sl_id_fk,
                $replace_product_sl_id_fk,
                $created_by
            );

            if (!$stmt->execute()) {
                throw new Exception("Error creating return record: " . $stmt->error);
            }

            // 2) Update the returned product status => 3 (Damage)
            $updateReturned = $conn->prepare("UPDATE product_sl SET status = ? WHERE sl_id = ?");
            $statusDamage = 2; // 2 = Return
            $updateReturned->bind_param("ii", $statusDamage, $received_product_sl_id_fk);
            if (!$updateReturned->execute()) {
                throw new Exception("Failed to update returned product status: " . $updateReturned->error);
            }

            // 3) If replacement selected, update its status => 4 (Replaced)
            if ($replace_product_sl_id_fk) {
                // Optional: ensure replacement product isn't the same as received product
                if ($replace_product_sl_id_fk == $received_product_sl_id_fk) {
                    throw new Exception("Replacement product cannot be the same as the returned (damaged) product.");
                }

                $updateReplacement = $conn->prepare("UPDATE product_sl SET status = ? WHERE sl_id = ?");
                $statusReplaced = 4; // 4 = Replaced
                $updateReplacement->bind_param("ii", $statusReplaced, $replace_product_sl_id_fk);
                if (!$updateReplacement->execute()) {
                    throw new Exception("Failed to update replacement product status: " . $updateReplacement->error);
                }
            }

            $conn->commit();
            $message = "Sales Return Processed Successfully! Product statuses updated.";
            $messageType = "success";

        } catch (Exception $e) {
            $conn->rollback();
            $message = "Failed: " . $e->getMessage();
            $messageType = "danger";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sales Return Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        body { background-color: #f4f6f9; }
        .card { border: none; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05); border-radius: 0.5rem; }
        .card-header { background-color: #fff; border-bottom: 1px solid #edf2f9; font-weight: 600; }
        .form-label { font-weight: 500; color: #495057; }
        .product-select-card { cursor: pointer; border: 2px solid transparent; transition: all 0.2s; }
        .product-select-card:hover { background-color: #f8f9fa; }
        .product-select-card.selected { border-color: #0d6efd; background-color: #e7f1ff; }
        .section-title { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; color: #6c757d; margin-bottom: 1rem; margin-top: 1rem;}
        #invoiceSuggestions { max-height: 200px; overflow-y: auto; border-top-left-radius: 0; border-top-right-radius: 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .suggestion-item { cursor: pointer; }
    </style>
</head>
<body>

<div class="container py-5">

    <?php if($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="returnForm">
        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-search me-2"></i>Find Invoice
                    </div>
                    <div class="card-body">
                        <div class="input-group mb-0 position-relative">
                            <input type="text" class="form-control" id="searchInvoiceNo" name="invoice_number" placeholder="Type Invoice No..." autocomplete="off" required />
                            <button class="btn btn-primary" type="button" id="btnSearch">Search</button>
                            <div id="invoiceSuggestions" class="list-group position-absolute w-100" style="top: 100%; z-index: 1000; display: none;"></div>
                        </div>
                        <small class="text-muted mt-2 d-block">Start typing to see matching invoices.</small>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-user me-2"></i>Client Details
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label text-muted small">Client Name</label>
                            <div id="displayClientName" class="fw-bold">-</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label text-muted small">Phone</label>
                            <div id="displayPhone">-</div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label text-muted small">Address</label>
                            <div id="displayAddress" class="text-break">-</div>
                        </div>
                        <div class="mb-0">
                            <label class="form-label text-muted small">Invoice Date</label>
                            <div id="displayDate">-</div>
                        </div>

                        <input type="hidden" name="invoice_id" id="invoiceId" />
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-box-open me-2"></i>Return Processing
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Return Date <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" name="return_date" id="returnDateInput" required />
                            </div>
                        </div>

                        <hr />

                        <h6 class="section-title">Step 1: Select Product to Return</h6>
                        <div id="productListArea" class="mb-4">
                            <div class="alert alert-light text-center border" role="alert">
                                Please search for an invoice to view products.
                            </div>
                        </div>
                        <input type="hidden" name="returned_product_id" id="returnedProductId" required />

                        <h6 class="section-title">Step 2: Replacement (Optional)</h6>
                        <div class="bg-light p-3 rounded mb-4 border">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="hasReplacement" />
                                <label class="form-check-label" for="hasReplacement">Customer wants a replacement product?</label>
                            </div>

                            <div id="replacementSection" style="display: none;">
                                <label class="form-label">Scan/Enter New Product Serial No</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="replacementSerialInput" placeholder="New Serial Number" />
                                    <button class="btn btn-outline-secondary" type="button" id="btnCheckReplacement">Verify</button>
                                </div>
                                <div id="replacementFeedback" class="mt-2 small"></div>
                                <input type="hidden" name="replacement_product_id" id="replacementProductId" />
                            </div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="submit_return" class="btn btn-success btn-lg" id="btnSubmit" disabled>
                                <i class="fas fa-check-circle me-2"></i>Confirm Sales Return
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setCurrentDateTimeLocal(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;

    const now = new Date();

    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');

    input.value = `${year}-${month}-${day}T${hours}:${minutes}`;
}

$(document).ready(function() {
    setCurrentDateTimeLocal('returnDateInput');

    $('#searchInvoiceNo').on('keyup', function() {
        var query = $(this).val();
        if (query.length > 1) {
            $.ajax({
                url: '?action=search_invoices',
                type: 'GET',
                data: { term: query },
                success: function(data) {
                    var html = '';
                    if(data.length > 0) {
                        $.each(data, function(index, invoiceNo) {
                            html += '<a href="#" class="list-group-item list-group-item-action suggestion-item" data-val="'+invoiceNo+'">'+invoiceNo+'</a>';
                        });
                    } else {
                        html = '<div class="list-group-item">No invoices found</div>';
                    }
                    $('#invoiceSuggestions').html(html).show();
                }
            });
        } else {
            $('#invoiceSuggestions').hide();
        }
    });

    $(document).on('click', '.suggestion-item', function(e) {
        e.preventDefault();
        var selectedInvoice = $(this).data('val');
        $('#searchInvoiceNo').val(selectedInvoice);
        $('#invoiceSuggestions').hide();

        // Fetch invoice details
        $.ajax({
            url: '?action=get_invoice_details',
            type: 'GET',
            data: { invoice_no: selectedInvoice },
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    $('#invoiceId').val(res.invoice.invoice_id);
                    $('#displayClientName').text(res.invoice.client_name || '-');
                    $('#displayPhone').text(res.invoice.phone || '-');
                    $('#displayAddress').text(res.invoice.address || '-');
                    $('#displayDate').text(res.invoice.invoice_date || '-');

                    // Show product list for return selection
                    var productsHtml = '';
                    if(res.products.length > 0) {
                        $.each(res.products, function(i, product) {
                            productsHtml +=
                            `<div class="card product-select-card mb-2" data-sl-id="${product.product_sl_id_fk}">
                                <div class="card-body d-flex justify-content-between align-items-center">
                                    <div><strong>Serial:</strong> ${product.sl_no}</div>
                                    <div><strong>Model:</strong> ${product.model_name}</div>
                                </div>
                            </div>`;
                        });
                    } else {
                        productsHtml = `<div class="alert alert-warning">No products found for this invoice.</div>`;
                    }
                    $('#productListArea').html(productsHtml);
                    $('#returnedProductId').val('');
                    $('#btnSubmit').prop('disabled', true);
                    $('#hasReplacement').prop('checked', false);
                    $('#replacementSection').hide();
                    $('#replacementSerialInput').val('');
                    $('#replacementProductId').val('');
                    $('#replacementFeedback').text('');
                } else {
                    alert(res.message);
                }
            }
        });
    });

    // Select returned product on click
    $(document).on('click', '.product-select-card', function() {
        $('.product-select-card').removeClass('selected');
        $(this).addClass('selected');
        var selectedId = $(this).data('sl-id');
        $('#returnedProductId').val(selectedId);
        $('#btnSubmit').prop('disabled', false);
    });

    // Toggle replacement section visibility
    $('#hasReplacement').change(function() {
        if($(this).is(':checked')) {
            $('#replacementSection').slideDown();
        } else {
            $('#replacementSection').slideUp();
            $('#replacementSerialInput').val('');
            $('#replacementFeedback').text('');
            $('#replacementProductId').val('');
        }
    });

    // Check replacement product availability
    $('#btnCheckReplacement').click(function() {
        var serialNo = $('#replacementSerialInput').val().trim();
        var returnedId = $('#returnedProductId').val();

        if(!serialNo) {
            $('#replacementFeedback').text('Please enter a serial number to check.');
            return;
        }
        if(!returnedId) {
            $('#replacementFeedback').text('Please select a product to return first.');
            return;
        }

        $('#replacementFeedback').text('Checking availability...');
        $.ajax({
            url: '?action=check_replacement',
            type: 'GET',
            data: { serial_no: serialNo },
            dataType: 'json',
            success: function(res) {
                if(res.status === 'success') {
                    if (res.data.sl_id == returnedId) {
                        $('#replacementFeedback').text("Replacement product cannot be the same as the returned product.").css('color', 'red');
                        $('#replacementProductId').val('');
                        return;
                    }
                    // Check product status here if needed via extra AJAX or extend the response to include status
                    $('#replacementProductId').val(res.data.sl_id);
                    $('#replacementFeedback').text(`Available: Model ID ${res.data.model_id_fk}, Serial: ${serialNo}`).css('color', 'green');
                } else {
                    $('#replacementFeedback').text(res.message).css('color', 'red');
                    $('#replacementProductId').val('');
                }
            },
            error: function() {
                $('#replacementFeedback').text('Error checking product.').css('color', 'red');
                $('#replacementProductId').val('');
            }
        });
    });

    // Disable submit if no product selected
    $('#returnedProductId').on('change', function() {
        $('#btnSubmit').prop('disabled', !$(this).val());
    });
});
</script>

</body>
</html>
