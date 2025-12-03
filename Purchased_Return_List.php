<?php
session_start();
require_once 'connection.php'; // expects $conn (mysqli)

// --- helper ---
function bail($msg) {
    echo "<div class='alert alert-danger'>{$msg}</div>";
    exit();
}

function is_admin() {
    return isset($_SESSION['role']) && (int)$_SESSION['role'] === 1;
}

// --- session / idle handling ---
$idleTimeout = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset();
    session_destroy();
    header("Location: index.php?reason=idle");
    exit();
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$user_role = isset($_SESSION['role']) ? (int)$_SESSION['role'] : 0;

// --- Handle delete POST (secure-ish) ---
$deleteMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_return_id'])) {
    if (!is_admin()) {
        $deleteMessage = "You are not authorized to delete records.";
    } else {
        $del_id = (int)$_POST['delete_return_id'];
        $stmt = $conn->prepare("DELETE FROM purchase_return WHERE purchase_return_id = ? LIMIT 1");
        $stmt->bind_param("i", $del_id);
        if ($stmt->execute()) {
            $deleteMessage = "Record #{$del_id} deleted successfully.";
        } else {
            $deleteMessage = "Failed to delete record: " . $stmt->error;
        }
        $stmt->close();
    }
}

// --- Fetch all purchase_return rows to display (joins)
// Note: we join purchased_products (pp) using product_sl.purchase_id_fk to retrieve unit_price as purchased_price
$sql = "SELECT pr.purchase_return_id, pr.vendor_id_fk, v.vendor_name,
               pr.returned_product_sl_id_fk, rp.product_sl AS returned_serial,
               rp.model_id_fk AS returned_model_id,
               m_return.model_name AS returned_model_name,
               pr.replacement_product_sl_id_fk, repl.product_sl AS replacement_serial,
               pr.price AS returned_price, pr.reason, pr.return_date
        FROM purchase_return pr
        LEFT JOIN vendors v ON v.vendor_id = pr.vendor_id_fk
        LEFT JOIN product_sl rp ON rp.sl_id = pr.returned_product_sl_id_fk
        LEFT JOIN product_sl repl ON repl.sl_id = pr.replacement_product_sl_id_fk
        LEFT JOIN models m_return ON m_return.model_id = rp.model_id_fk
        -- join purchased_products to get purchased price (may return multiple rows; typically one)
        LEFT JOIN purchased_products pp ON pp.purchase_id = rp.purchase_id_fk
        ORDER BY pr.return_date DESC";

$result = $conn->query($sql);
if ($result === false) {
    bail("Database error: " . $conn->error);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Purchase Return List</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- DataTables CSS & JS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css"/>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <style>
        .card-header { background-color: #343a40; color: #fff; }
        .table-wrap { overflow-x:auto; }
        .btn-back { min-width:160px; }
        .small-text { font-size:0.9em; color:#666; }
    </style>
</head>
<body class="bg-light">
<div class="container mt-4">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h3 class="mb-0">Purchase Return List</h3>
            <div class="small-text">All records from <code>purchase_return</code></div>
        </div>

        <div class="d-flex gap-2">
            <a href="dashboard.php" class="btn btn-outline-secondary btn-back">← Back to Dashboard</a>
        </div>
    </div>

    <?php if ($deleteMessage): ?>
        <div class="alert alert-info"><?php echo htmlspecialchars($deleteMessage); ?></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header">
            <strong>Returned Products</strong>
        </div>
        <div class="card-body">
            <div class="table-wrap">
                <table id="returnsTable" class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Vendor</th>
                            <th>Returned Serial</th>
                            <th>Model</th>
                            <th>Replacement Serial</th>
                            <th>Returned Price</th>
                            <th>Reason</th>
                            <th>Return Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $i=1; while ($row = $result->fetch_assoc()): 
                        // Attempt to fetch purchased_price for this product_sl (from purchased_products)
                        $purchased_price = null;
                        if (!empty($row['returned_product_sl_id_fk'])) {
                            // Query purchased_products.unit_price using product_sl.purchase_id_fk
                            $sqlpp = "SELECT pp.unit_price 
                                      FROM product_sl psl
                                      LEFT JOIN purchased_products pp ON pp.purchase_id = psl.purchase_id_fk
                                      WHERE psl.sl_id = ? 
                                      LIMIT 1";
                            $stmtpp = $conn->prepare($sqlpp);
                            $stmtpp->bind_param("i", $row['returned_product_sl_id_fk']);
                            $stmtpp->execute();
                            $respp = $stmtpp->get_result();
                            if ($rpRow = $respp->fetch_assoc()) {
                                $purchased_price = $rpRow['unit_price'];
                            }
                            $stmtpp->close();
                        }
                    ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($row['vendor_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['returned_serial'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['returned_model_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['replacement_serial'] ?? '—'); ?></td>
                            <td><?php echo number_format((float)$row['returned_price'],2,'.',''); ?></td>
                            <td style="max-width:200px; white-space:normal;"><?php echo nl2br(htmlspecialchars($row['reason'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($row['return_date']); ?></td>
                            <td>
                                <button
                                    class="btn btn-sm btn-primary viewBtn"
                                    data-id="<?php echo (int)$row['purchase_return_id']; ?>"
                                    data-vendor="<?php echo htmlspecialchars($row['vendor_name'] ?? ''); ?>"
                                    data-returned="<?php echo htmlspecialchars($row['returned_serial'] ?? ''); ?>"
                                    data-model="<?php echo htmlspecialchars($row['returned_model_name'] ?? ''); ?>"
                                    data-replacement="<?php echo htmlspecialchars($row['replacement_serial'] ?? ''); ?>"
                                    data-returned-price="<?php echo number_format((float)$row['returned_price'],2,'.',''); ?>"
                                    data-purchased-price="<?php echo $purchased_price !== null ? number_format((float)$purchased_price,2,'.','') : ''; ?>"
                                    data-reason="<?php echo htmlspecialchars($row['reason'] ?? ''); ?>"
                                    data-return_date="<?php echo htmlspecialchars($row['return_date']); ?>"
                                >View</button>

                                <?php if ($user_role === 1): // admin can delete ?>
                                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete this return record?');">
                                        <input type="hidden" name="delete_return_id" value="<?php echo (int)$row['purchase_return_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="returnModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Return Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <dl class="row">
              <dt class="col-sm-4">Vendor</dt>
              <dd class="col-sm-8" id="mdVendor"></dd>

              <dt class="col-sm-4">Returned Serial</dt>
              <dd class="col-sm-8" id="mdReturned"></dd>

              <dt class="col-sm-4">Model</dt>
              <dd class="col-sm-8" id="mdModel"></dd>

              <dt class="col-sm-4">Replacement Serial</dt>
              <dd class="col-sm-8" id="mdReplacement"></dd>

              <dt class="col-sm-4">Purchased Price</dt>
              <dd class="col-sm-8" id="mdPurchasedPrice"></dd>

              <dt class="col-sm-4">Returned Price</dt>
              <dd class="col-sm-8" id="mdReturnedPrice"></dd>

              <dt class="col-sm-4">Reason</dt>
              <dd class="col-sm-8" id="mdReason"></dd>

              <dt class="col-sm-4">Return Date</dt>
              <dd class="col-sm-8" id="mdReturnDate"></dd>
          </dl>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap JS (requires Popper) -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>

<script>
$(document).ready(function(){
    // Initialize DataTable
    $('#returnsTable').DataTable({
        "pageLength": 25,
        "lengthMenu": [10,25,50,100],
        "order": [[7, "desc"]], // order by return_date column (0-based index; return_date is column 7)
        columnDefs: [
            { orderable: false, targets: [8] } // actions column not orderable (index changed after removing Created By)
        ]
    });

    // View button handler
    var returnModal = new bootstrap.Modal(document.getElementById('returnModal'));
    $(document).on('click', '.viewBtn', function(){
        $('#mdVendor').text($(this).data('vendor') || '—');
        $('#mdReturned').text($(this).data('returned') || '—');
        $('#mdModel').text($(this).data('model') || '—');
        $('#mdReplacement').text($(this).data('replacement') || '—');

        var purchased = $(this).data('purchased-price');
        $('#mdPurchasedPrice').text( purchased !== '' ? purchased : 'N/A' );

        $('#mdReturnedPrice').text($(this).data('returned-price') || '0.00');
        $('#mdReason').html( ('' + $(this).data('reason')).replace(/\n/g, "<br>") || '—' );
        $('#mdReturnDate').text($(this).data('return_date') || '—');
        returnModal.show();
    });
});
</script>
</body>
</html>
