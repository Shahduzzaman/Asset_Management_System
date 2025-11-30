<?php
// Start session to get the logged-in user (Assuming session management exists)
session_start();
if (!isset($_SESSION['user_id'])) {
    // For demonstration, setting a default user ID if login system isn't active
    $_SESSION['user_id'] = 1; 
}

require_once 'connection.php';

// Enable error reporting for debugging API responses (disable in production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$message = "";
$messageType = "";

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
        
        $sql = "SELECT invoice_no FROM invoice WHERE invoice_no LIKE ? LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $suggestions = [];
        while($row = $result->fetch_assoc()) {
            $suggestions[] = $row['invoice_no'];
        }
        echo json_encode($suggestions);
        exit;
    }

    // --- 2. HANDLE AJAX REQUESTS (Get Invoice Details) ---
    if (isset($_GET['action']) && $_GET['action'] === 'get_invoice_details') {
        header('Content-Type: application/json');
        
        $invoice_no = $_GET['invoice_no'] ?? '';
        
        // 1. Fetch Invoice & Client Info
        // CHANGED: 'i.date' to 'i.created_at' based on database error
        $sql_invoice = "SELECT 
                            i.invoice_id, 
                            i.invoice_no, 
                            i.created_at as invoice_date,
                            ch.client_name,
                            ch.phone,
                            ch.address
                        FROM invoice i
                        LEFT JOIN client_head ch ON i.client_id_fk = ch.client_head_id
                        WHERE i.invoice_no = ?";
                        
        $stmt = $conn->prepare($sql_invoice);
        $stmt->bind_param("s", $invoice_no);
        $stmt->execute();
        $result = $stmt->get_result();
        $invoiceData = $result->fetch_assoc();
        
        if (!$invoiceData) {
            echo json_encode(['status' => 'error', 'message' => 'Invoice not found.']);
            exit;
        }

        // 2. Fetch Sold Products for this Invoice
        // FIX: Adjusted JOIN to link models via sold_product (sp) instead of product_sl (psl)
        // based on standard schema patterns where sold_product tracks the model.
        $sql_products = "SELECT 
                            sp.product_sl_id_fk,
                            psl.sl_no,
                            m.model_name
                         FROM sold_product sp
                         JOIN product_sl psl ON sp.product_sl_id_fk = psl.sl_id
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

        $sql = "SELECT sl_id, sl_no, model_id_fk FROM product_sl WHERE sl_no = ?";
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
    // Return Database Errors as JSON
    if(isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
        exit;
    } else {
        $message = "Database Error: " . $e->getMessage();
        $messageType = "danger";
    }
} catch (Exception $e) {
    if(isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
        exit;
    }
    $message = "System Error: " . $e->getMessage();
    $messageType = "danger";
}

// --- 4. HANDLE FORM SUBMISSION (Process Return) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_return'])) {
    
    $invoice_id_fk = $_POST['invoice_id'];
    $invoice_number = $_POST['invoice_number'];
    $return_date = $_POST['return_date'];
    $received_product_sl_id_fk = $_POST['returned_product_id'];
    $replace_product_sl_id_fk = !empty($_POST['replacement_product_id']) ? $_POST['replacement_product_id'] : NULL;
    $created_by = $_SESSION['user_id'];

    // Start Transaction
    $conn->begin_transaction();

    try {
        // A. Insert into sales_return
        $stmt = $conn->prepare("INSERT INTO sales_return (invoice_id_fk, invoice_number, return_date, received_product_sl_id_fk, replace_product_sl_id_fk, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issiii", $invoice_id_fk, $invoice_number, $return_date, $received_product_sl_id_fk, $replace_product_sl_id_fk, $created_by);
        
        if (!$stmt->execute()) {
            throw new Exception("Error creating return record: " . $stmt->error);
        }

        // B. (Optional) Update Product Status Logic here...

        $conn->commit();
        $message = "Sales Return Processed Successfully!";
        $messageType = "success";

    } catch (Exception $e) {
        $conn->rollback();
        $message = "Failed: " . $e->getMessage();
        $messageType = "danger";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Return Management</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .card { border: none; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.05); border-radius: 0.5rem; }
        .card-header { background-color: #fff; border-bottom: 1px solid #edf2f9; font-weight: 600; }
        .form-label { font-weight: 500; color: #495057; }
        .product-select-card { cursor: pointer; border: 2px solid transparent; transition: all 0.2s; }
        .product-select-card:hover { background-color: #f8f9fa; }
        .product-select-card.selected { border-color: #0d6efd; background-color: #e7f1ff; }
        .section-title { font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; color: #6c757d; margin-bottom: 1rem; margin-top: 1rem;}
        
        /* Styles for Autocomplete Suggestions */
        #invoiceSuggestions {
            max-height: 200px;
            overflow-y: auto;
            border-top-left-radius: 0;
            border-top-right-radius: 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .suggestion-item { cursor: pointer; }
    </style>
</head>
<body>

<div class="container py-5">
    
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="text-primary"><i class="fas fa-undo-alt me-2"></i>Sales Return</h2>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
    </div>

    <!-- Alert Messages -->
    <?php if($message): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="returnForm">
        <div class="row">
            
            <!-- Left Column: Search & Client Info -->
            <div class="col-lg-4 mb-4">
                
                <!-- 1. Search Invoice -->
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <i class="fas fa-search me-2"></i>Find Invoice
                    </div>
                    <div class="card-body">
                        <div class="input-group mb-0 position-relative">
                            <input type="text" class="form-control" id="searchInvoiceNo" name="invoice_number" placeholder="Type Invoice No..." autocomplete="off" required>
                            <button class="btn btn-primary" type="button" id="btnSearch">Search</button>
                            
                            <!-- Suggestions Container -->
                            <div id="invoiceSuggestions" class="list-group position-absolute w-100" style="top: 100%; z-index: 1000; display: none;">
                                <!-- Suggestions will be populated here -->
                            </div>
                        </div>
                        <small class="text-muted mt-2 d-block">Start typing to see matching invoices.</small>
                    </div>
                </div>

                <!-- 2. Client Information (Read Only) -->
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
                        
                        <!-- Hidden Inputs to store IDs -->
                        <input type="hidden" name="invoice_id" id="invoiceId">
                    </div>
                </div>
            </div>

            <!-- Right Column: Return Logic -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-box-open me-2"></i>Return Processing
                    </div>
                    <div class="card-body">
                        
                        <!-- Return Date -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label">Return Date <span class="text-danger">*</span></label>
                                <input type="datetime-local" class="form-control" name="return_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                            </div>
                        </div>

                        <hr>

                        <!-- 3. Select Product to Return -->
                        <h6 class="section-title">Step 1: Select Product to Return</h6>
                        <div id="productListArea" class="mb-4">
                            <div class="alert alert-light text-center border" role="alert">
                                Please search for an invoice to view products.
                            </div>
                        </div>
                        <input type="hidden" name="returned_product_id" id="returnedProductId" required>

                        <!-- 4. Replacement (Optional) -->
                        <h6 class="section-title">Step 2: Replacement (Optional)</h6>
                        <div class="bg-light p-3 rounded mb-4 border">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="hasReplacement">
                                <label class="form-check-label" for="hasReplacement">Customer wants a replacement product?</label>
                            </div>

                            <div id="replacementSection" style="display: none;">
                                <label class="form-label">Scan/Enter New Product Serial No</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="replacementSerialInput" placeholder="New Serial Number">
                                    <button class="btn btn-outline-secondary" type="button" id="btnCheckReplacement">Verify</button>
                                </div>
                                <div id="replacementFeedback" class="mt-2 small"></div>
                                <input type="hidden" name="replacement_product_id" id="replacementProductId">
                            </div>
                        </div>

                        <!-- Submit -->
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

<!-- jQuery and Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
$(document).ready(function() {

    // --- Dynamic Search Logic ---
    $('#searchInvoiceNo').on('keyup', function() {
        var query = $(this).val();
        if (query.length > 1) { // Start searching after 1 char (or 2)
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
                        $('#invoiceSuggestions').html(html).show();
                    } else {
                        $('#invoiceSuggestions').hide();
                    }
                }
            });
        } else {
            $('#invoiceSuggestions').hide();
        }
    });

    // Handle Click on Suggestion
    $(document).on('click', '.suggestion-item', function(e) {
        e.preventDefault();
        var val = $(this).data('val');
        $('#searchInvoiceNo').val(val);
        $('#invoiceSuggestions').hide();
        $('#btnSearch').click(); // Trigger the main search logic
    });

    // Hide suggestions when clicking outside
    $(document).click(function(e) {
        if (!$(e.target).closest('#searchInvoiceNo, #invoiceSuggestions').length) {
            $('#invoiceSuggestions').hide();
        }
    });

    // --- Existing Search Invoice Logic ---
    $('#btnSearch').click(function() {
        var invoiceNo = $('#searchInvoiceNo').val();
        if(!invoiceNo) { alert("Please enter an Invoice Number"); return; }

        // Reset UI
        $('#productListArea').html('<div class="text-center"><div class="spinner-border text-primary" role="status"></div></div>');
        $('#btnSubmit').prop('disabled', true);
        $('#invoiceSuggestions').hide(); // Ensure suggestions are closed

        $.ajax({
            url: '?action=get_invoice_details',
            type: 'GET',
            data: { invoice_no: invoiceNo },
            dataType: 'json', // Expect JSON response
            success: function(response) {
                if(response.status === 'success') {
                    // Populate Client Info
                    $('#displayClientName').text(response.invoice.client_name || 'N/A');
                    $('#displayPhone').text(response.invoice.phone || 'N/A');
                    $('#displayAddress').text(response.invoice.address || 'N/A');
                    $('#displayDate').text(response.invoice.invoice_date || 'N/A');
                    $('#invoiceId').val(response.invoice.invoice_id);

                    // Populate Products List
                    var productsHtml = '<div class="row g-2">';
                    if(response.products.length > 0) {
                        $.each(response.products, function(i, prod) {
                            productsHtml += `
                                <div class="col-md-6">
                                    <div class="card p-3 product-select-card" onclick="selectProduct(this, ${prod.product_sl_id_fk})">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>${prod.model_name || 'Unknown Model'}</strong><br>
                                                <small class="text-muted">SL: ${prod.sl_no}</small>
                                            </div>
                                            <i class="fas fa-check-circle text-primary d-none check-icon"></i>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                    } else {
                        productsHtml += '<div class="col-12 text-danger">No products found for this invoice.</div>';
                    }
                    productsHtml += '</div>';
                    $('#productListArea').html(productsHtml);

                } else {
                    $('#productListArea').html('<div class="alert alert-warning">' + response.message + '</div>');
                    // Reset client info
                    $('#displayClientName, #displayPhone, #displayAddress, #displayDate').text('-');
                }
            },
            error: function(xhr, status, error) {
                // Improved Error Handling: Show exact error from server if available
                var errMsg = "Error fetching invoice details.";
                if(xhr.responseJSON && xhr.responseJSON.message) {
                    errMsg = xhr.responseJSON.message;
                } else if (xhr.responseText) {
                    // Sometimes PHP errors come as plain text if JSON encoding fails
                    console.log("Server Error:", xhr.responseText);
                    errMsg += " Check console for details.";
                }
                alert(errMsg);
                $('#productListArea').html('<div class="alert alert-danger">'+errMsg+'</div>');
            }
        });
    });

    // 2. Toggle Replacement Section
    $('#hasReplacement').change(function() {
        if($(this).is(':checked')) {
            $('#replacementSection').slideDown();
        } else {
            $('#replacementSection').slideUp();
            $('#replacementProductId').val(''); // Clear ID
            $('#replacementSerialInput').val(''); // Clear Input
            $('#replacementFeedback').html('');
        }
    });

    // 3. Check Replacement Product Logic
    $('#btnCheckReplacement').click(function() {
        var sn = $('#replacementSerialInput').val();
        if(!sn) return;

        $.ajax({
            url: '?action=check_replacement',
            type: 'GET',
            data: { serial_no: sn },
            success: function(response) {
                if(response.status === 'success') {
                    $('#replacementFeedback').html('<span class="text-success"><i class="fas fa-check"></i> Available (ID: '+response.data.sl_id+')</span>');
                    $('#replacementProductId').val(response.data.sl_id);
                } else {
                    $('#replacementFeedback').html('<span class="text-danger"><i class="fas fa-times"></i> Not Found or Unavailable</span>');
                    $('#replacementProductId').val('');
                }
            }
        });
    });

});

// Function to handle product selection visuals and logic
function selectProduct(element, id) {
    // Remove active class from all
    $('.product-select-card').removeClass('selected');
    $('.product-select-card .check-icon').addClass('d-none');
    
    // Add to clicked
    $(element).addClass('selected');
    $(element).find('.check-icon').removeClass('d-none');

    // Set Hidden Input
    $('#returnedProductId').val(id);
    
    // Enable Submit
    $('#btnSubmit').prop('disabled', false);
}
</script>

</body>
</html>