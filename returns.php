<?php
session_start();
require_once 'connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

$sql = "SELECT 
            sr.sales_return_id,
            sr.invoice_number,
            sr.return_date,
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

function statusText($code) {
    switch ($code) {
        case 0: return "In Stock";
        case 1: return "Sold";
        case 2: return "Returned";
        case 3: return "Damaged";
        case 4: return "Replaced";
        default: return "Unknown";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Sales Returns List</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
</style>
</head>
<body>

<div class="container py-4">
<h2>Sales Returns List</h2>

<table class="table table-bordered">
<thead class="table-primary">
<tr>
    <th>ID</th>
    <th>Invoice</th>
    <th>Return Date</th>
    <th>Returned</th>
    <th>Replacement</th>
    <th>Action</th>
</tr>
</thead>
<tbody>

<?php while ($row = $result->fetch_assoc()): ?>
<tr>
    <td><?= $row['sales_return_id'] ?></td>
    <td><?= $row['invoice_number'] ?></td>
    <td><?= date("Y-m-d H:i", strtotime($row['return_date'])) ?></td>

    <td>
        <b>Serial:</b> <?= $row['returned_serial'] ?><br>
        <b>Model:</b> <?= $row['returned_model'] ?><br>
        <b>Status:</b> <?= statusText($row['returned_status']) ?>
    </td>

    <td>
        <?php if ($row['replacement_serial']): ?>
            <b>Serial:</b> <?= $row['replacement_serial'] ?><br>
            <b>Model:</b> <?= $row['replacement_model'] ?><br>
            <b>Status:</b> <?= statusText($row['replacement_status']) ?>
        <?php else: ?>
            <i>No Replacement</i>
        <?php endif; ?>
    </td>

    <td>
        <button class="btn btn-info btn-sm btn-view-details" data-id="<?= $row['sales_return_id'] ?>">View Details</button>
    </td>
</tr>
<?php endwhile; ?>

</tbody>
</table>
</div>


<!-- MODAL -->
<div class="modal fade" id="returnDetailsModal">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Return Details</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <div class="modal-side-by-side">

            <div class="product-card">
                <h5>Returned Product</h5>
                <p><b>Serial:</b> <span id="retSerial">-</span></p>
                <p><b>Model:</b> <span id="retModel">-</span></p>
                <p><b>Status:</b> <span id="retStatus">-</span></p>
            </div>

            <div class="product-card">
                <h5>Replacement Product</h5>
                <p><b>Serial:</b> <span id="repSerial">-</span></p>
                <p><b>Model:</b> <span id="repModel">-</span></p>
                <p><b>Status:</b> <span id="repStatus">-</span></p>
            </div>

        </div>

        <hr>

        <h6><b>Client Information</b></h6>
        <p><b>Name:</b> <span id="clientName">-</span></p>
        <p><b>Department:</b> <span id="clientDept">-</span></p>
        <p><b>Contact Person:</b> <span id="clientCP">-</span></p>
        <p><b>Contact Number:</b> <span id="clientCN">-</span></p>
        <p><b>Address:</b> <span id="clientAddress">-</span></p>

        <hr>

        <h6><b>Other Return Info</b></h6>
        <p><b>Invoice Number:</b> <span id="infoInvoice">-</span></p>
        <p><b>Return Date:</b> <span id="infoReturnDate">-</span></p>
        <p><b>Created By:</b> <span id="infoCreatedBy">-</span></p>

      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$(".btn-view-details").click(function () {

    var id = $(this).data("id");

    $("#retSerial,#retModel,#retStatus,#repSerial,#repModel,#repStatus,#clientName,#clientDept,#clientCP,#clientCN,#clientAddress,#infoInvoice,#infoReturnDate,#infoCreatedBy")
        .text("Loading...");

    var modal = new bootstrap.Modal(document.getElementById("returnDetailsModal"));
    modal.show();

    $.get("fetch_return_details.php", { id: id }, function (res) {

        if (res.status !== "success") {
            alert("Error: " + res.message);
            modal.hide();
            return;
        }

        $("#retSerial").text(res.data.returned_product.serial);
        $("#retModel").text(res.data.returned_product.model);
        $("#retStatus").text(statusText(res.data.returned_product.status));

        if (res.data.replacement_product) {
            $("#repSerial").text(res.data.replacement_product.serial);
            $("#repModel").text(res.data.replacement_product.model);
            $("#repStatus").text(statusText(res.data.replacement_product.status));
        } else {
            $("#repSerial").text("-");
            $("#repModel").text("-");
            $("#repStatus").text("No Replacement");
        }

        $("#clientName").text(res.data.client.name);
        $("#clientDept").text(res.data.client.department);
        $("#clientCP").text(res.data.client.contact_person);
        $("#clientCN").text(res.data.client.contact_number);
        $("#clientAddress").text(res.data.client.address);

        $("#infoInvoice").text(res.data.invoice_number);
        $("#infoReturnDate").text(res.data.return_date);
        $("#infoCreatedBy").text(res.data.created_by_name);

    }, "json");
});
</script>

</body>
</html>
