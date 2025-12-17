<?php
require_once 'session_guard.php';
require_once 'connection.php';

$current_user_id = $_SESSION['user_id'];

if (!isset($_GET['id'])) {
    die("Invalid Invoice ID");
}

$invoice_id = (int)$_GET['id'];
$is_print = isset($_GET['print']) && $_GET['print'] == 'true';

/* ---------------------------------------
   1. Fetch Invoice/Challan Master Data
----------------------------------------- */
$sql_master = "SELECT 
                inv.Invoice_No,
                inv.created_at as invoice_date,
                u.user_name as Created_By_User,
                ch.Company_Name,
                ch.Address as Head_Address,
                ch.Contact_Number as Head_Phone,
                cb.Branch_Name,
                cb.Address as Branch_Address,
                cb.Contact_Number1 as Branch_Phone,
                wo.Order_No as WO_No,
                wo.Order_Date as WO_Date
              FROM invoice inv
              LEFT JOIN users u ON inv.created_by = u.user_id
              LEFT JOIN sold_product sp ON inv.invoice_id = sp.invoice_id_fk
              LEFT JOIN client_branch cb ON sp.client_branch_id_fk = cb.client_branch_id
              LEFT JOIN client_head ch ON sp.client_head_id_fk = ch.client_head_id
              LEFT JOIN work_order wo ON sp.work_order_id_fk = wo.work_order_id
              WHERE inv.invoice_id = ?
              LIMIT 1";

$stmt = $conn->prepare($sql_master);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();

if (!$invoice) {
    die("Invoice not found.");
}

/* ---------------------------------------
   2. Fetch Items
----------------------------------------- */
$sql_items = "SELECT 
                sp.Quantity,
                sp.Remarks as item_remarks,
                m.model_name,
                b.brand_name,
                c.category_name, 
                sl.product_sl as serial_no,
                pp.warranty_period
              FROM sold_product sp
              JOIN models m ON sp.model_id_fk = m.model_id
              LEFT JOIN brands b ON m.brand_id = b.brand_id
              LEFT JOIN categories c ON m.category_id = c.category_id
              LEFT JOIN product_sl sl ON sp.product_sl_id_fk = sl.sl_id
              LEFT JOIN purchased_products pp ON sl.purchase_id_fk = pp.purchase_id
              WHERE sp.invoice_id_fk = ?
              ORDER BY c.category_name, b.brand_name, m.model_name";

$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $invoice_id);
$stmt_items->execute();
$result_items = $stmt_items->get_result();

/* ---------------------------------------
   Group Items Logic
----------------------------------------- */
$grouped_items = [];
while ($row = $result_items->fetch_assoc()) {
    $key = $row['model_name'];

    if (!isset($grouped_items[$key])) {
        $grouped_items[$key] = [
            'description' => $row['brand_name'] . ' ' . $row['model_name'],
            'quantity'    => 0,
            'warranty'    => $row['warranty_period'],
            'serials'     => [],
            'remarks'     => $row['item_remarks']
        ];
    }

    $grouped_items[$key]['quantity'] += $row['Quantity'];
    
    if (!empty($row['serial_no'])) {
        $grouped_items[$key]['serials'][] = $row['serial_no'];
    }
    if (empty($grouped_items[$key]['warranty']) && !empty($row['warranty_period'])) {
        $grouped_items[$key]['warranty'] = $row['warranty_period'];
    }
}

/* ---------------------------------------
   Client Info Logic
----------------------------------------- */
$client_name = $invoice['Company_Name'] ?? '';
$branch_name = $invoice['Branch_Name'] ?? '';
$display_name = 'Walk-in Client';

if (!empty($client_name)) {
    $display_name = $client_name;
    if (!empty($branch_name) && $branch_name != $client_name) {
        $display_name .= " - " . $branch_name;
    }
} elseif (!empty($branch_name)) {
    $display_name = $branch_name;
}

$display_addr = !empty($invoice['Branch_Address']) ? $invoice['Branch_Address'] : ($invoice['Head_Address'] ?? '');
$display_phone = !empty($invoice['Branch_Phone']) ? $invoice['Branch_Phone'] : ($invoice['Head_Phone'] ?? '');

/* ---------------------------------------
   Footer Data
----------------------------------------- */
date_default_timezone_set('Asia/Dhaka');
$print_datetime = date("d-M-Y h:i A");
$printed_by = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'System Admin'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DC-<?php echo htmlspecialchars($invoice['Invoice_No']); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<style>
/* Base Reset */
*{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    background-color:#555;
    color:#111;
    font-size: 12px;
}

/* A4 Container */
.page-container{
    width:210mm;
    min-height:297mm;
    background:white;
    margin:20px auto;
    padding:25px; 
    position:relative;
    box-shadow:0 0 10px rgba(0,0,0,0.3);
    display: flex;
    flex-direction: column;
}

/* HEADER */
.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:2px solid #333;
    padding-bottom:10px; 
    margin-bottom:15px; 
}
.company-identity{display:flex; align-items:center; gap:15px;}
.logo-img{max-height:60px;width:auto;}
.company-text h1{font-size:22px;font-weight:800;text-transform:uppercase;color:#111; margin-bottom: 2px;}
.company-text p{font-size:12px;color:#2563eb;font-style:italic;}
.company-address{text-align:right;font-size:11px;color:#444; line-height: 1.4;}

/* TITLE */
.invoice-title{
    text-align:center;
    margin: 0 auto 20px auto; 
    border: 2px solid #333; 
    width: 250px; 
    padding: 6px; 
    border-radius: 5px;
}
.invoice-title h2{font-size:18px;font-weight:800;letter-spacing:2px; text-transform: uppercase; margin: 0;}

/* INFO BLOCKS */
.info-grid{display:flex;justify-content:space-between;margin-bottom:20px; gap:30px;}
.bill-to{flex:1;}
.bill-to h4{font-size:12px; font-weight:700; text-transform:uppercase; color:#555; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 8px;}
.client-name{font-weight:700;font-size:14px;color:#000;}
.client-addr{font-size:12px;color:#444;margin-top:4px;white-space:pre-line;}

.meta-info{flex:0 0 40%;}
.meta-table{width:100%;font-size:12px;border-collapse:collapse;}
.meta-table td { padding: 4px 0; }
.meta-key{font-weight:600;color:#555;text-align:left;}
.meta-val{font-weight:700;color:#000;text-align:right;}

/* INTRO PARAGRAPH */
.challan-intro {
    font-size: 12px;
    margin-bottom: 25px;
    text-align: left; /* Changed to left align */
    line-height: 1.6;
    color: #333;
    /* Removed heavy styling for cleaner look */
    padding: 0;
    border: none;
    background: transparent;
}

/* TABLE */
.items-table{
    width:100%;
    border-collapse:collapse;
    margin-bottom:30px;
    font-size:12px;
}
.items-table th{
    border:1px solid #999;
    background: #e5e7eb;
    color: #000;
    padding:8px 8px;
    text-align:center;
    font-weight:700;
    text-transform:uppercase;
}
.items-table td{
    border:1px solid #999;
    padding:8px;
    vertical-align:middle;
    color:#333;
}
.col-center{text-align:center;}

/* SIGNATURE SECTION */
.signature-section {
    margin-top: auto; 
    padding-top: 20px;
    page-break-inside: avoid;
}
.signature-declaration {
    font-size: 12px;
    margin-bottom: 80px; 
    font-weight: 600;
}
.signatures-grid {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 40px;
}
.sig-block { width: 45%; }
.sig-line-area {
    border-top: 1px dashed #000;
    margin-bottom: 10px;
    width: 100%;
}
.sig-title {
    font-weight: bold;
    font-size: 12px;
    text-transform: uppercase;
    text-align: center;
    margin-bottom: 15px;
}
.sig-fields { font-size: 11px; }
.sig-field-row {
    display: flex;
    margin-bottom: 6px;
    align-items: baseline; /* Default alignment */
}
/* Specific style for seal row to align bottom */
.sig-field-row.seal-row {
    align-items: flex-end;
}
.sig-field-label { width: 85px; font-weight: 600; }
.sig-field-input {
    flex: 1;
    border-bottom: 1px dotted #999;
    height: 16px;
}
.sig-field-input.seal-space {
    height: 60px; /* Big space for seal */
}

/* FOOTER META */
.footer-meta-row {
    display: flex; 
    justify-content: space-between; 
    align-items: center;
    width: 100%;
    margin-top: 20px;
    font-size: 10px;
    color: #888;
    border-top: 1px solid #eee;
    padding-top: 5px;
}

/* PRINT MODE */
@media print {
    @page { margin: 0; size: auto; }
    body{background:white;margin:0;}
    .page-container{
        width:100%; margin:0; box-shadow:none; border:none; padding: 10mm; min-height: 100vh;
    }
    .no-print{display:none !important;}
    .items-table th { -webkit-print-color-adjust: exact; background: #e5e7eb !important; }
}

/* Buttons */
.actions{position:fixed;top:20px;right:20px;z-index:999;display:flex;gap:10px;}
.btn{padding:10px 15px;border-radius:5px;border:none;cursor:pointer;font-weight:bold;box-shadow:0 2px 5px rgba(0,0,0,0.2);}
.btn-print{background:#2563eb;color:white;}
.btn-back{background:#f3f4f6;color:#333;}
</style>
</head>

<body>

<div class="actions no-print">
    <button class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
    <button class="btn btn-back" onclick="history.back()"><i class="fas fa-arrow-left"></i> Back</button>
</div>

<div class="page-container">

    <div class="header">
        <div class="company-identity">
            <img src="images/logo.png" class="logo-img" alt="Logo" onerror="this.style.display='none'">
            <div class="company-text">
                <h1>Protection One (Pvt.) Ltd.</h1>
                <p>A Complete Security Solution</p>
            </div>
        </div>
        <div class="company-address">
            <p><strong>Head Office:</strong> House 48, Road 02, Block L,</p>
            <p>Banani, Dhaka-1213, Bangladesh</p>
            <p><i class="fas fa-envelope"></i> info@protectionone.com.bd</p>
            <p><i class="fas fa-phone-alt"></i> +880 1755-551912</p>
        </div>
    </div>

    <div class="invoice-title">
        <h2>DELIVERY CHALLAN</h2>
    </div>

    <div class="info-grid">
        <div class="bill-to">
            <h4>Delivered To:</h4>
            <div class="client-name"><?php echo htmlspecialchars($display_name); ?></div>
            
            <?php if (!empty(trim($display_addr))): ?>
                <div class="client-addr"><?php echo nl2br(htmlspecialchars(trim($display_addr))); ?></div>
            <?php endif; ?>
            
            <?php if (!empty(trim($display_phone))): ?>
                <div class="client-addr"><strong>Phone:</strong> <?php echo htmlspecialchars(trim($display_phone)); ?></div>
            <?php endif; ?>
        </div>

        <div class="meta-info">
            <table class="meta-table">
                <tr><td class="meta-key">Date:</td><td class="meta-val"><?php echo date('d-M-Y', strtotime($invoice['invoice_date'])); ?></td></tr>
                <?php if(!empty($invoice['WO_No'])): ?>
                <tr><td class="meta-key">Ref Invoice:</td><td class="meta-val"><?php echo htmlspecialchars($invoice['Invoice_No']); ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="challan-intro">
        As per our quotation & valued Ref: <strong><?php echo !empty($invoice['WO_No']) ? htmlspecialchars($invoice['WO_No']) : '________________'; ?></strong> 
        dated <strong><?php echo !empty($invoice['WO_Date']) ? date('d-M-Y', strtotime($invoice['WO_Date'])) : '________________'; ?></strong> 
        of your Regional Office, under mentioned items are supplied to your ADC Division for installation & commissioning.
    </div>

    <table class="items-table">
    <thead>
    <tr>
        <th style="width:5%;">#</th>
        <th style="width:50%; text-align: left;">Description</th>
        <th style="width:15%;">Warranty</th>
        <th style="width:10%;">Qty</th>
        <th style="width:20%;">Remarks</th>
    </tr>
    </thead>
    <tbody>
    <?php 
    $count = 1;
    foreach ($grouped_items as $item):
    ?>
    <tr>
        <td class="col-center"><?php echo $count++; ?></td>
        <td>
            <div style="font-weight:600; font-size: 13px;"><?php echo htmlspecialchars($item['description']); ?></div>
            <?php if(!empty($item['serials'])): ?>
                <div style="font-size:10px;color:#555;margin-top:3px; line-height: 1.2;">
                    <strong>SN:</strong> <?php echo htmlspecialchars(implode(', ', $item['serials'])); ?>
                </div>
            <?php endif; ?>
        </td>
        <td class="col-center"><?php echo htmlspecialchars($item['warranty'] ?? '-'); ?></td>
        <td class="col-center" style="font-weight: bold;"><?php echo $item['quantity']; ?></td>
        <td class="col-center"><?php echo htmlspecialchars($item['remarks'] ?? ''); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>

    <div class="signature-section">
        <div class="signature-declaration">
            We, as consignee acknowlegde receipt of the aforementioned consignment for our branch.
        </div>

        <div class="signatures-grid">
            <div class="sig-block">
                <div class="sig-line-area"></div>
                <div class="sig-title">Customer / Receiver Signature</div>
                <div class="sig-fields">
                    <div class="sig-field-row">
                        <div class="sig-field-label">Name:</div>
                        <div class="sig-field-input"></div>
                    </div>
                    <div class="sig-field-row">
                        <div class="sig-field-label">Designation:</div>
                        <div class="sig-field-input"></div>
                    </div>
                    <div class="sig-field-row">
                        <div class="sig-field-label">Cell:</div>
                        <div class="sig-field-input"></div>
                    </div>
                    <div class="sig-field-row seal-row">
                        <div class="sig-field-label">Official Seal:</div>
                        <div class="sig-field-input seal-space"></div>
                    </div>
                </div>
            </div>

            <div class="sig-block">
                <div class="sig-line-area"></div>
                <div class="sig-title">Authorized Signature</div>
                <div class="sig-fields">
                    <div class="sig-field-row">
                        <div class="sig-field-label">Name:</div>
                        <div class="sig-field-input"> Md. Liton Ali Sardar</div>
                    </div>
                    <div class="sig-field-row">
                        <div class="sig-field-label">Designation:</div>
                        <div class="sig-field-input"> Manager & Head Technical Division</div>
                    </div>
                    <div class="sig-field-row">
                        <div class="sig-field-label">Cell:</div>
                        <div class="sig-field-input"> +880 1755 551914</div>
                    </div>
                    <div class="sig-field-row seal-row">
                        <div class="sig-field-label">Official Seal:</div>
                        <div class="sig-field-input seal-space"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="footer-meta-row">
        <div style="flex:1; text-align:left;">
            Printed By: <?php echo htmlspecialchars($printed_by); ?>
        </div>
        <div style="flex:1; text-align:center;">
            <?php echo $print_datetime; ?>
        </div>
        <div style="flex:1; text-align:right;">
             Page <span class="page-current">1</span> of <span class="page-total"></span>
        </div>
    </div>

</div>

<script>
window.onload = function(){
    // Simple estimation for total pages based on A4 height (px at 96dpi)
    const approximatePageHeight = 1123; 
    const scrollHeight = document.body.scrollHeight;
    const totalPages = Math.max(1, Math.ceil(scrollHeight / approximatePageHeight));
    
    // Update total pages text
    document.querySelectorAll('.page-total').forEach(el => el.textContent = totalPages);
    
    // Set Document Title for PDF
    document.title = "DC-<?php echo htmlspecialchars($invoice['Invoice_No']); ?>";
    
    <?php if($is_print): ?>
    setTimeout(function(){ window.print(); }, 500);
    <?php endif; ?>
}
</script>

</body>
</html>