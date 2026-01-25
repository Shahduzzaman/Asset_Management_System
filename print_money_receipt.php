<?php
require_once 'session_guard.php';
require_once 'connection.php';

if (!isset($_GET['id'])) {
    die("Invalid Payment ID");
}

$payment_id = (int)$_GET['id'];
$is_print = isset($_GET['print']) && $_GET['print'] == 'true';

function numberToWordsBD($number) {
    $words = array(0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety');
    
    if ($number < 0) return "Minus " . numberToWordsBD(abs($number));
    if ($number < 21) return $words[$number];
    if ($number < 100) return $words[10 * floor($number / 10)] . (($number % 10 != 0) ? " " . $words[$number % 10] : "");
    if ($number < 1000) return $words[floor($number / 100)] . " Hundred" . (($number % 100 != 0) ? " " . numberToWordsBD($number % 100) : "");
    if ($number < 100000) return numberToWordsBD(floor($number / 1000)) . " Thousand" . (($number % 1000 != 0) ? " " . numberToWordsBD($number % 1000) : "");
    if ($number < 10000000) return numberToWordsBD(floor($number / 100000)) . " Lac" . (($number % 100000 != 0) ? " " . numberToWordsBD($number % 100000) : "");
    return numberToWordsBD(floor($number / 10000000)) . " Crore" . (($number % 10000000 != 0) ? " " . numberToWordsBD($number % 10000000) : "");
}

$sql = "SELECT p.*, 
        i.Invoice_No, 
        i.IncludingTax_TotalPrice as Invoice_Total,
        ch.Company_Name, ch.Address as Head_Addr,
        cb.Branch_Name, cb.Address as Branch_Addr,
        u.user_name as Received_By
        FROM payments p
        JOIN invoice i ON p.invoice_id_fk = i.invoice_id
        LEFT JOIN client_head ch ON i.client_head_id_fk = ch.client_head_id
        LEFT JOIN client_branch cb ON i.client_branch_id_fk = cb.client_branch_id
        LEFT JOIN users u ON p.created_by = u.user_id
        WHERE p.payment_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) { die("Payment Receipt not found."); }

$client_name = !empty($data['Branch_Name']) ? $data['Branch_Name'] : $data['Company_Name'];
if (!empty($data['Branch_Name']) && !empty($data['Company_Name'])) {
    $client_name = $data['Company_Name'] . ' - ' . $data['Branch_Name'];
}
$client_address = !empty($data['Branch_Addr']) ? $data['Branch_Addr'] : $data['Head_Addr'];

$sql_due = "SELECT SUM(amount) as total_paid FROM payments WHERE invoice_id_fk = ?";
$stmt_due = $conn->prepare($sql_due);
$stmt_due->bind_param("i", $data['invoice_id_fk']);
$stmt_due->execute();
$paid_res = $stmt_due->get_result()->fetch_assoc();
$total_paid_so_far = $paid_res['total_paid'];
$current_due = $data['Invoice_Total'] - $total_paid_so_far;

$amount_words = numberToWordsBD(floor($data['amount'])) . " Taka Only";
date_default_timezone_set('Asia/Dhaka');
$print_time = date("d-M-Y h:i A");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($data['money_receipt_no']); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background-color:#555;color:#111;line-height:1.4;}
    .page-container{width:210mm;min-height:148mm; background:white;margin:20px auto;padding:10mm;position:relative;box-shadow:0 0 10px rgba(0,0,0,0.3);}
    
    .header{display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #333;padding-bottom:15px;margin-bottom:20px;}
    .company-identity{display:flex;align-items:center;gap:15px;}
    .logo-img{max-height:60px;width:auto;}
    .company-text h1{font-size:20px;font-weight:800;text-transform:uppercase;color:#111;}
    .company-text p{font-size:12px;color:#2563eb;font-style:italic;}
    .company-address{text-align:right;font-size:10px;color:#444;}
    
    .receipt-title{text-align:center;margin-bottom:20px;border: 1px solid #333; width: 200px; margin: 0 auto 20px auto; padding: 5px; border-radius: 5px;}
    .receipt-title h2{font-size:18px;font-weight:800;letter-spacing:2px; text-transform: uppercase;}

    .info-grid{display:flex;justify-content:space-between;margin-bottom:20px;font-size:12px;}
    .meta-table{width:100%;border-collapse:collapse;}
    .meta-key{font-weight:600;color:#555;padding: 2px 0;}
    .meta-val{font-weight:700;color:#000;text-align:right;}

    .content-box {border: 1px solid #ddd; padding: 15px; border-radius: 5px; margin-bottom: 20px; background: #f9f9f9;}
    .row { display: flex; margin-bottom: 10px; align-items: baseline; }
    .label { width: 120px; font-weight: bold; font-size: 12px; color: #555; }
    .value { flex: 1; border-bottom: 1px dotted #999; padding-bottom: 2px; font-weight: 600; font-size: 13px;}

    .payment-table {width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px;}
    .payment-table th, .payment-table td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    .payment-table th { background: #eee; }

    .signatures {display: flex;justify-content: space-between;margin-top: 50px;}
    .sig-box { width: 150px; text-align: center; }
    .sig-line { border-top: 1px dashed #333; margin-bottom: 5px; }
    .sig-label { font-size: 10px; font-weight: bold; text-transform: uppercase; }

    .footer-meta { font-size: 9px; color: #aaa; text-align: center; margin-top: 30px; border-top: 1px dotted #ccc; padding-top: 5px;}

    .actions{position:fixed;top:20px;right:20px;z-index:999;display:flex;gap:10px;}
    .btn{padding:10px 15px;border-radius:5px;border:none;cursor:pointer;font-weight:bold;box-shadow:0 2px 5px rgba(0,0,0,0.2);}
    .btn-print{background:#2563eb;color:white;}
    .btn-close{background:#e11d48;color:white;}

    @media print {
        @page { margin: 0; size: auto; }
        body{background:white;margin:0;}
        .page-container{width:100%;margin:0;box-shadow:none;border:none;padding:10mm;}
        .actions{display:none !important;}
        .content-box { border: 1px solid #000; background: none; }
    }
</style>
</head>
<body>

<div class="actions no-print">
    <button class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Receipt</button>
    <button class="btn btn-close" onclick="window.close()"><i class="fas fa-times"></i> Close</button>
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

    <div class="receipt-title">
        <h2>Money Receipt</h2>
    </div>

    <div class="info-grid">
        <div style="width: 45%;">
            <table class="meta-table">
                <tr><td class="meta-key">Receipt No:</td><td class="meta-val" style="text-align:left; padding-left:10px;"><?php echo htmlspecialchars($data['money_receipt_no']); ?></td></tr>
                <tr><td class="meta-key">Date:</td><td class="meta-val" style="text-align:left; padding-left:10px;"><?php echo date('d-M-Y', strtotime($data['payment_date'])); ?></td></tr>
            </table>
        </div>
        <div style="width: 45%;">
            <table class="meta-table">
                <tr><td class="meta-key">Invoice No:</td><td class="meta-val"><?php echo htmlspecialchars($data['Invoice_No']); ?></td></tr>
                <tr><td class="meta-key">Received By:</td><td class="meta-val"><?php echo htmlspecialchars($data['Received_By']); ?></td></tr>
            </table>
        </div>
    </div>

    <div class="content-box">
        <div class="row">
            <div class="label">Received From:</div>
            <div class="value"><?php echo htmlspecialchars($client_name); ?></div>
        </div>
        <div class="row">
            <div class="label">The Sum of:</div>
            <div class="value"><?php echo number_format($data['amount'], 2); ?> BDT</div>
        </div>
        <div class="row">
            <div class="label">In Words:</div>
            <div class="value" style="font-style: italic;"><?php echo $amount_words; ?></div>
        </div>
        <div class="row">
            <div class="label">Payment Mode:</div>
            <div class="value">
                <?php echo $data['payment_method']; ?>
                <?php if(!empty($data['transaction_number'])): ?>
                    (Ref: <?php echo htmlspecialchars($data['transaction_number']); ?>)
                <?php endif; ?>
            </div>
        </div>
    </div>

    <table class="payment-table">
        <thead>
            <tr>
                <th>Invoice Total</th>
                <th>Previous Paid</th>
                <th>Current Paid</th>
                <th>Balance Due</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo number_format($data['Invoice_Total'], 2); ?></td>
                <td><?php echo number_format($total_paid_so_far - $data['amount'], 2); ?></td>
                <td style="font-weight:bold; background:#f0fdf4;"><?php echo number_format($data['amount'], 2); ?></td>
                <td style="color:red;"><?php echo number_format($current_due, 2); ?></td>
            </tr>
        </tbody>
    </table>

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

    <div class="footer-meta">
        Printed On: <?php echo $print_time; ?>
    </div>
</div>

<?php if($is_print): ?>
<script>
    window.onload = function() {
        document.title = "<?php echo htmlspecialchars($data['money_receipt_no']); ?>";
        
        setTimeout(function(){ 
            window.print(); 
        }, 500); 
    }
</script>
<?php endif; ?>

</body>
</html>