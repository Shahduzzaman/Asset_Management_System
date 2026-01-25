<?php
require_once 'session_guard.php';

$current_user_id = $_SESSION['user_id'];

require_once 'connection.php';

if (!isset($_GET['id'])) {
    die("Invalid Invoice ID");
}

$invoice_id = (int)$_GET['id'];
$is_print = isset($_GET['print']) && $_GET['print'] == 'true';

function numberToWordsBD($number)
{
    $words = array(
        0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
        5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
        14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
        18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty',
        40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy',
        80 => 'Eighty', 90 => 'Ninety'
    );

    if ($number < 0) return "Minus " . numberToWordsBD(abs($number));
    if ($number < 21) return $words[$number];
    if ($number < 100) return $words[10 * floor($number / 10)] . (($number % 10 != 0) ? " " . $words[$number % 10] : "");
    if ($number < 1000)
        return $words[floor($number / 100)] . " Hundred" . (($number % 100 != 0) ? " " . numberToWordsBD($number % 100) : "");
    if ($number < 100000)
        return numberToWordsBD(floor($number / 1000)) . " Thousand" . (($number % 1000 != 0) ? " " . numberToWordsBD($number % 1000) : "");
    if ($number < 10000000)
        return numberToWordsBD(floor($number / 100000)) . " Lac" . (($number % 100000 != 0) ? " " . numberToWordsBD($number % 100000) : "");
    return numberToWordsBD(floor($number / 10000000)) . " Crore" . (($number % 10000000 != 0) ? " " . numberToWordsBD($number % 10000000) : "");
}


$sql_master = "SELECT 
                inv.Invoice_No,
                inv.created_at as invoice_date,
                inv.ExcludingTax_TotalPrice as sub_total,
                inv.IncludingTax_TotalPrice as grand_total,
                inv.Tax_Percentage,
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

$sql_items = "SELECT 
                sp.Quantity,
                sp.Sold_Unit_Price,
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


$grouped_items = [];
while ($row = $result_items->fetch_assoc()) {
    $key = $row['model_name'] . '_' . (string)$row['Sold_Unit_Price'];

    if (!isset($grouped_items[$key])) {
        $grouped_items[$key] = [
            'description' => $row['brand_name'] . ' ' . $row['model_name'],
            'unit_price'  => $row['Sold_Unit_Price'],
            'quantity'    => 0,
            'warranty'    => $row['warranty_period'],
            'serials'     => []
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

$display_addr = '';
if (!empty($invoice['Branch_Address'])) {
    $display_addr = $invoice['Branch_Address'];
} elseif (!empty($invoice['Head_Address'])) {
    $display_addr = $invoice['Head_Address'];
}

$display_phone = '';
if (!empty($invoice['Branch_Phone'])) {
    $display_phone = $invoice['Branch_Phone'];
} elseif (!empty($invoice['Head_Phone'])) {
    $display_phone = $invoice['Head_Phone'];
}


$tax_amount = $invoice['grand_total'] - $invoice['sub_total'];
$grand = $invoice['grand_total'];
$amount_words = numberToWordsBD(floor($grand)) . " Taka Only";


date_default_timezone_set('Asia/Dhaka');
$print_datetime = date("d-M-Y h:i A");
$printed_by = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'System Admin'; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($invoice['Invoice_No']); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    background-color:#555;
    color:#111;
    line-height:1.4;
}

.page-container{
    width:210mm;
    min-height:297mm;
    background:white;
    margin:20px auto;
    padding:10mm 10mm 40mm 10mm; 
    position:relative;
    box-shadow:0 0 10px rgba(0,0,0,0.3);
}

.header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-bottom:2px solid #333;
    padding-bottom:15px;
    margin-bottom:20px;
}
.company-identity{
    display:flex; align-items:center; gap:15px;
}
.logo-img{max-height:60px;width:auto;}
.company-text h1{font-size:20px;font-weight:800;text-transform:uppercase;color:#111;white-space:nowrap;}
.company-text p{font-size:12px;color:#2563eb;font-style:italic;margin-top:2px;}
.company-address{text-align:right;font-size:10px;color:#444;}
.company-address strong{font-size:11px;color:#000;}

.invoice-title{text-align:center;margin-bottom:20px; border: 1px solid #333; width: 200px; margin: 0 auto 20px auto; padding: 5px; border-radius: 5px;}
.invoice-title h2{font-size:18px;font-weight:800;letter-spacing:4px; text-transform: uppercase;}

.info-grid{display:flex;justify-content:space-between;margin-bottom:20px;gap:20px; font-size: 12px;}
.bill-to{flex:0 0 55%;}
.bill-box{border:1px solid #ddd;padding:10px;background:#f9f9f9;border-radius:4px;}
.bill-label{font-size:10px;font-weight:700;text-transform:uppercase;color:#666;margin-bottom:5px;}
.client-name{font-weight:700;font-size:13px;color:#000;}
.client-addr{font-size:11px;color:#444;margin-top:4px;white-space:pre-line;}
.client-contact{font-size:11px;margin-top:4px;}

.meta-info{flex:0 0 40%;}
.meta-table{width:100%;font-size:11px;border-collapse:collapse;}
.meta-key{font-weight:600;color:#555;text-align:left; padding: 2px 0;}
.meta-val{font-weight:700;color:#000;text-align:right;}

.wo-bar {
    width: 100%;
    margin-bottom: 15px;
    font-size: 12px;
    border-bottom: 1px dashed #ddd;
    padding-bottom: 8px;
    color: #333;
}
.wo-label {
    font-weight: 700;
    color: #555;
    text-transform: uppercase;
    font-size: 11px;
}
.wo-value {
    font-weight: 600;
    margin-right: 25px;
    font-size: 13px;
}

.items-table{
    width:100%;border-collapse:collapse;margin-bottom:20px;font-size:11px;
}
.items-table th{
    border:1px solid #ccc;
    background: #eee;
    padding:8px 5px;text-align:center;font-weight:700;text-transform:uppercase;
}
.items-table td{
    border:1px solid #ccc;
    padding:8px 5px;vertical-align:top;color:#333;
}
.col-right{text-align:right;}
.col-center{text-align:center;}

.totals-container{display:flex;justify-content:flex-end;margin-bottom:10px;}
.totals-table{width:300px;border-collapse:collapse;font-size:11px;}
.totals-table td{padding:4px 0; text-align: right;}
.totals-table .t-label{text-align: left; padding-right: 10px; font-weight: 600; color: #555;}
.totals-table .t-val{font-weight: 700; color: #000;}
.grand-total-row td {border-top:2px solid #000;border-bottom:2px solid #000;padding:8px 0;margin-top:5px;font-size:14px; font-weight: bold;}

.amount-words{font-size:11px;font-weight:600;margin-top:8px;color:#000; border-top: 1px dotted #ccc; padding-top: 5px;}

.terms-box {margin-top:20px; font-size:10px; color:#777; border: 1px solid #eee; padding: 10px; border-radius: 5px;}
.terms-box ul {padding-left:15px; margin-top:5px;}

.payment-info { margin-top: 15px; font-size: 10px; color: #333; line-height: 1.4; border: 1px solid #eee; padding: 10px; border-radius: 5px; background: #fafafa; }
.payment-info p { margin-bottom: 6px; }
.payment-info strong { color: #000; }

.footer-wrap {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    padding: 0 10mm 15px 10mm;
}

.signatures {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
}
.sig-box { width: 180px; text-align: center; }
.sig-line { border-top: 1px dashed #333; margin-bottom: 5px; height: 1px; }
.sig-label { font-size: 11px; font-weight: bold; text-transform: uppercase; color: #333; }

.footer-meta-row {
    display: flex; 
    justify-content: space-between; 
    align-items: center;
    width: 100%;
    margin-top: 5px;
    font-size: 9px;
    color: #aaa;
    border-top: 1px dotted #eee;
    padding-top: 4px;
}
.meta-left { text-align: left; flex: 1; }
.meta-center { text-align: center; flex: 1; }
.meta-right { text-align: right; flex: 1; }

@media print {
    @page { margin: 0; size: auto; }
    body{background:white;margin:0;}
    
    .page-container{
        width:100%;
        margin:0;
        box-shadow:none;
        border:none;
        padding: 10mm 10mm 0 10mm; 
        min-height: auto;
    }

    .no-print{display:none !important;}
    .items-table th { -webkit-print-color-adjust: exact; }
    .bill-box { -webkit-print-color-adjust: exact; }

    .footer-wrap {
        position: fixed;
        bottom: 0;
        left: 0;
        width: 100%;
        padding-bottom: 10mm;
        background: white; 
    }
    
    .print-footer-spacer { height: 40mm; display: block; }
}

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
        <h2>INVOICE</h2>
    </div>

    <div class="info-grid">
        <div class="bill-to">
            <div class="bill-label">Bill To:</div>
            <div class="bill-box">
                <div class="client-name"><?php echo htmlspecialchars($display_name); ?></div>
                
                <?php if (!empty(trim($display_addr))): ?>
                    <div class="client-addr"><?php echo nl2br(htmlspecialchars(trim($display_addr))); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="meta-info">
            <table class="meta-table">
                <tr><td class="meta-key">BIN No.:</td><td class="meta-val">000380661-0101</td></tr>
                <tr><td class="meta-key">E-TIN No.:</td><td class="meta-val">831690569843</td></tr>
                <tr><td class="meta-key">Trad.:</td><td class="meta-val">TRAD/DNCC/082717/2022</td></tr>
                
                <tr><td class="meta-key">Invoice No:</td><td class="meta-val"><?php echo htmlspecialchars($invoice['Invoice_No']); ?></td></tr>
                <tr><td class="meta-key">Date:</td><td class="meta-val"><?php echo date('d-M-Y', strtotime($invoice['invoice_date'])); ?></td></tr>
                </table>
        </div>
    </div>

    <?php if(!empty($invoice['WO_No'])): ?>
    <div class="wo-bar">
        <span class="wo-label">Work Order Ref:</span>
        <span class="wo-value"><?php echo htmlspecialchars($invoice['WO_No']); ?></span>
        
        <?php if(!empty($invoice['WO_Date'])): ?>
            <span class="wo-label">Date:</span>
            <span class="wo-value"><?php echo date('d-M-Y', strtotime($invoice['WO_Date'])); ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <table class="items-table">
    <thead>
    <tr>
        <th style="width:5%;">#</th>
        <th style="width:45%; text-align: left;">Description</th>
        <th style="width:15%;">Warranty</th>
        <th style="width:10%;">Qty</th>
        <th style="width:12%; text-align: right;">Unit Price</th>
        <th style="width:13%; text-align: right;">Total</th>
    </tr>
    </thead>
    <tbody>
    <?php 
    $count = 1;
    foreach ($grouped_items as $item):
    $line_total = $item['quantity'] * $item['unit_price'];
    ?>
    <tr>
        <td class="col-center"><?php echo $count++; ?></td>
        <td>
            <div style="font-weight:600;"><?php echo htmlspecialchars($item['description']); ?></div>
            <?php if(!empty($item['serials'])): ?>
                <div style="font-size:9px;color:#555;margin-top:2px;">SN: <?php echo htmlspecialchars(implode(', ', $item['serials'])); ?></div>
            <?php endif; ?>
        </td>
        <td class="col-center"><?php echo htmlspecialchars($item['warranty'] ?? '-'); ?></td>
        <td class="col-center"><?php echo $item['quantity']; ?></td>
        <td class="col-right"><?php echo number_format($item['unit_price'],2); ?></td>
        <td class="col-right" style="font-weight:700;"><?php echo number_format($line_total,2); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>

    <div class="totals-container">
    <table class="totals-table">
        <tr><td class="t-label">Sub Total</td><td class="t-val"><?php echo number_format($invoice['sub_total'],2); ?></td></tr>
        <?php if($tax_amount > 0): ?>
        <tr><td class="t-label">Tax (<?php echo number_format($invoice['Tax_Percentage'],0); ?>%)</td>
            <td class="t-val"><?php echo number_format($tax_amount,2); ?></td></tr>
        <?php endif; ?>
        <?php 
        $calc_grand = $invoice['sub_total'] + $tax_amount;
        $discount = $calc_grand - $invoice['grand_total'];
        if($discount > 0.01):
        ?>
        <tr><td class="t-label" style="color:red;">Discount</td>
            <td class="t-val" style="color:red;">- <?php echo number_format($discount,2); ?></td></tr>
        <?php endif; ?>
        <tr class="grand-total-row">
            <td class="t-label" style="color:#000; font-size:13px;">GRAND TOTAL</td>
            <td class="t-val" style="font-size:15px;"><?php echo number_format($invoice['grand_total'],2); ?></td>
        </tr>
    </table>
    </div>

    <div class="amount-words">
        <strong>In Words:</strong> <?php echo htmlspecialchars($amount_words); ?>
    </div>

    <div class="terms-box">
        <strong>Terms & Conditions:</strong>
        <ul>
            <li>Non-warranty products are not returnable if damaged or partially damaged.</li>
            <li>Warranty void if serial number sticker is removed or damaged.</li>
        </ul>
    </div>

    <div class="payment-info">
        <p><strong>PLEASE NOTE:</strong> You are requested to send a copy of VAT & AIT Challan deducted against this bill.</p>
        
        <p>Please pay this bill by cheque / pay order in favour of <strong>Protection One (Pvt.) Ltd.</strong> Banani, Dhaka and send under registered post /courier service within 07 (seven) days after receiving the bill.</p>
        
        <p style="text-align:center; font-weight:bold; margin: 5px 0;">OR</p>
        
        <p>You may make payment to <strong>Pubali Bank PLC</strong> of Banani Branch, Account no: <strong>3311 9010 22190</strong>.</p>
        
        <p style="margin-top: 10px; font-style: italic;">Please sign and return the second bill copy.</p>
    </div>

    <div class="print-footer-spacer"></div>

    <div class="footer-wrap">
        <div class="signatures">
            <div class="sig-box">
                <div class="sig-line"></div>
                <div class="sig-label">Customer Signature</div>
            </div>
            <div class="sig-box">
                <div class="sig-line"></div>
                <div class="sig-label">Authorized Signature</div>
            </div>
        </div>

        <div class="footer-meta-row">
            <div class="meta-left">
                Printed By: <?php echo htmlspecialchars($printed_by); ?>
            </div>
            <div class="meta-center">
                <?php echo $print_datetime; ?>
            </div>
            <div class="meta-right">
                Page <span class="page-current">1</span> of <span class="page-total"></span>
            </div>
        </div>
    </div>

</div>

<?php if($is_print): ?>
<script>
window.onload = function(){
    const approximatePageHeight = 1123; 
    const scrollHeight = document.body.scrollHeight;
    const totalPages = Math.max(1, Math.ceil(scrollHeight / approximatePageHeight));
    
    document.querySelectorAll('.page-total').forEach(el => el.textContent = totalPages);
    
    document.title = "<?php echo htmlspecialchars($invoice['Invoice_No']); ?>";
    setTimeout(function(){ window.print(); }, 500);
}
</script>
<?php endif; ?>

</body>
</html>