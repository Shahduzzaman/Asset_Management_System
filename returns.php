<?php
session_start();
require_once 'connection.php';

// --- Session & Security Checks ---
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

// Fetch all sales returns with joined info
$sql = "SELECT 
            sr.sales_return_id,
            sr.invoice_number,
            sr.return_date,
            sr.created_by,
            rp.product_sl AS returned_serial,
            rp.status AS returned_status,
            m1.model_name AS returned_model,
            replp.product_sl AS replacement_serial,
            replp.status AS replacement_status,
            m2.model_name AS replacement_model
        FROM sales_return sr
        LEFT JOIN product_sl rp ON sr.received_product_sl_id_fk = rp.sl_id
        LEFT JOIN models m1 ON rp.model_id_fk = m1.model_id
        LEFT JOIN product_sl replp ON sr.replace_product_sl_id_fk = replp.sl_id
        LEFT JOIN models m2 ON replp.model_id_fk = m2.model_id
        ORDER BY sr.return_date DESC";

$result = $conn->query($sql);

// Helper function to convert status code to text
function statusText($code) {
    switch ((int)$code) {
        case 0: return 'In Stock';
        case 1: return 'Sold';
        case 2: return 'Returned';
        case 3: return 'Damaged';
        case 4: return 'Replaced';
        default: return 'Unknown';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Sales Returns Listing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <style>
        .modal-side-by-side {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .product-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 1rem;
            width: 45%;
            min-width: 280px;
            box-shadow: 0 0 5px rgba(0,0,0,0.1);
        }
        .product-card h5 {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 fw-bold text-dark">Sales Return List</h1>
        <a href="dashboard.php" class="btn btn-primary fw-semibold px-4 py-2 rounded-3">
            &larr; Back to Dashboard
        </a>
    </div>
    <table class="table table-striped table-bordered align-middle">
        <thead class="table-primary">
            <tr>
                <th>ID</th>
                <th>Invoice Number</th>
                <th>Return Date</th>
                <th>Returned Product</th>
                <th>Replacement Product</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['sales_return_id']) ?></td>
                    <td><?= htmlspecialchars($row['invoice_number']) ?></td>
                    <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($row['return_date']))) ?></td>
                    <td>
                        <strong>Serial:</strong> <?= htmlspecialchars($row['returned_serial'] ?? '-') ?><br/>
                        <strong>Model:</strong> <?= htmlspecialchars($row['returned_model'] ?? '-') ?><br/>
                        <strong>Status:</strong> <?= htmlspecialchars(statusText($row['returned_status'])) ?>
                    </td>
                    <td>
                        <?php if ($row['replacement_serial']): ?>
                            <strong>Serial:</strong> <?= htmlspecialchars($row['replacement_serial']) ?><br/>
                            <strong>Model:</strong> <?= htmlspecialchars($row['replacement_model']) ?><br/>
                            <strong>Status:</strong> <?= htmlspecialchars(statusText($row['replacement_status'])) ?>
                        <?php else: ?>
                            <em>No Replacement</em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-info btn-view-details" data-id="<?= $row['sales_return_id'] ?>">
                            View Details
                        </button>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="6" class="text-center">No returns found.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal -->
<div class="modal fade" id="returnDetailsModal" tabindex="-1" aria-labelledby="returnDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="returnDetailsModalLabel">Return Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Content filled by AJAX -->
                <div id="modalContent" class="modal-side-by-side">
                    <div class="product-card" id="returnedProductCard">
                        <h5>Returned Product</h5>
                        <div><strong>Serial:</strong> <span id="retSerial">-</span></div>
                        <div><strong>Model:</strong> <span id="retModel">-</span></div>
                        <div><strong>Status:</strong> <span id="retStatus">-</span></div>
                    </div>
                    <div class="product-card" id="replacementProductCard">
                        <h5>Replacement Product</h5>
                        <div><strong>Serial:</strong> <span id="repSerial">-</span></div>
                        <div><strong>Model:</strong> <span id="repModel">-</span></div>
                        <div><strong>Status:</strong> <span id="repStatus">-</span></div>
                    </div>
                </div>
                <hr />
                <div>
                    <h6>Other Return Info</h6>
                    <p><strong>Invoice Number:</strong> <span id="infoInvoice">-</span></p>
                    <p><strong>Return Date:</strong> <span id="infoReturnDate">-</span></p>
                    <p><strong>Created By:</strong> <span id="infoCreatedBy">-</span></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    function statusText(statusCode) {
        switch (parseInt(statusCode)) {
            case 0: return 'In Stock';
            case 1: return 'Sold';
            case 2: return 'Returned';
            case 3: return 'Damaged';
            case 4: return 'Replaced';
            default: return 'Unknown';
        }
    }

    $(document).ready(function() {
        $('.btn-view-details').on('click', function() {
            var returnId = $(this).data('id');

            // Clear modal content while loading
            $('#retSerial, #retModel, #retStatus, #repSerial, #repModel, #repStatus, #infoInvoice, #infoReturnDate, #infoCreatedBy').text('Loading...');

            // Show modal
            var modal = new bootstrap.Modal(document.getElementById('returnDetailsModal'));
            modal.show();

            // Fetch details via AJAX
            $.ajax({
                url: 'fetch_return_details.php',
                method: 'GET',
                data: { id: returnId },
                dataType: 'json',
                success: function(res) {
                    if (res.status === 'success') {
                        $('#retSerial').text(res.data.returned_product.serial);
                        $('#retModel').text(res.data.returned_product.model);
                        $('#retStatus').text(statusText(res.data.returned_product.status));

                        if (res.data.replacement_product) {
                            $('#repSerial').text(res.data.replacement_product.serial);
                            $('#repModel').text(res.data.replacement_product.model);
                            $('#repStatus').text(statusText(res.data.replacement_product.status));
                        } else {
                            $('#repSerial').text('-');
                            $('#repModel').text('-');
                            $('#repStatus').text('No Replacement');
                        }

                        $('#infoInvoice').text(res.data.invoice_number);
                        $('#infoReturnDate').text(res.data.return_date);
                        $('#infoCreatedBy').text(res.data.created_by_name);
                    } else {
                        alert('Error fetching details: ' + res.message);
                        modal.hide();
                    }
                },
                error: function() {
                    alert('AJAX error loading return details.');
                    modal.hide();
                }
            });
        });
    });
</script>
</body>
</html>
