<?php
session_start();
require_once 'connection.php';

// Security Check
if (!isset($_SESSION["user_id"])) {
    die("Access Denied");
}

if (!isset($_GET['id'])) {
    die("Invalid Invoice ID");
}

$invoice_id = (int)$_GET['id'];
$is_print = isset($_GET['print']) && $_GET['print'] == 'true';

// --- 1. Fetch Invoice Master Data ---
$sql_master = "SELECT 
                inv.Invoice_No,
                inv.created_at as invoice_date,
                inv.ExcludingTax_TotalPrice as sub_total,
                inv.IncludingTax_TotalPrice as grand_total,
                inv.Tax_Percentage,
                u.user_name as Created_By_User,
                ch.Company_Name,
                ch.Address as Head_Address,
                ch.Contact_Number,
                cb.Branch_Name,
                cb.Address as Branch_Address,
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

// --- 2. Fetch Sold Items ---
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

// --- Process Items (Grouping) ---
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

// --- Helper for Client Name ---
$client_name = $invoice['Company_Name'] ?? '';
if (!empty($invoice['Branch_Name']) && $invoice['Branch_Name'] != $invoice['Company_Name']) {
    $client_name .= ' - ' . $invoice['Branch_Name'];
}

$tax_amount = $invoice['grand_total'] - $invoice['sub_total'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo htmlspecialchars($invoice['Invoice_No']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Base Reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #555; /* Dark background for preview mode */
            color: #111;
            line-height: 1.4;
        }

        /* Container for A4 Page */
        .page-container {
            width: 210mm;
            min-height: 297mm;
            background: white;
            margin: 0px auto;
            /* Reduced top and bottom padding to remove empty space */
            padding: 8mm 15mm 5mm 15mm; /* Top, Right, Bottom, Left */
            position: relative;
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
        }

        /* Layout structure to push footer to bottom */
        .content-wrap {
            flex: 1; /* Takes up available space */
        }
        .footer-wrap {
            margin-top: auto; /* Pushes to bottom */
        }

        /* HEADER */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center; /* Vertically align logo and text */
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .company-identity {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-img {
            max-height: 70px; /* Slightly smaller logo to save vertical space */
            width: auto;
        }

        .company-text h1 {
            font-size: 22px; /* Decreased font size */
            font-weight: 800;
            text-transform: uppercase;
            color: #111;
            white-space: nowrap; /* Force one line */
        }

        .company-text p {
            font-size: 13px;
            font-weight: 600;
            color: #2563eb; /* Blue tagline */
            font-style: italic;
            margin-top: 2px;
        }

        .company-address {
            text-align: right;
            font-size: 11px;
            color: #444;
        }
        .company-address strong { font-size: 12px; color: #000; }

        /* INVOICE TITLE */
        .invoice-title {
            text-align: center;
            margin-bottom: 20px;
        }
        .invoice-title h2 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: 4px;
            margin: 0;
        }
        /* Original Copy span removed */

        /* INFO GRID */
        .info-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            gap: 20px;
        }

        .bill-to {
            flex: 0 0 55%;
        }

        .bill-box {
            border: 1px solid #ddd;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .bill-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 5px;
        }

        .client-name { font-weight: 700; font-size: 14px; color: #000; }
        .client-addr { font-size: 11px; color: #444; margin-top: 4px; white-space: pre-line; }
        .client-contact { font-size: 11px; margin-top: 4px; }

        .meta-info {
            flex: 0 0 40%;
            text-align: right;
        }
        .meta-table { width: 100%; font-size: 11px; border-collapse: collapse; }
        .meta-table td { padding: 3px 0; }
        .meta-key { font-weight: 600; color: #555; text-align: left; }
        .meta-val { font-weight: 700; color: #000; text-align: right; }

        /* ITEMS TABLE */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 11px;
        }
        
        .items-table th {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 8px 5px;
            text-align: left;
            font-weight: 700;
            text-transform: uppercase;
            color: #000; /* No background color */
        }
        
        .items-table td {
            padding: 8px 5px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
            color: #333;
        }
        
        .col-right { text-align: right; }
        .col-center { text-align: center; }

        /* TOTALS */
        .totals-container {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 10px;
        }
        .totals-table {
            width: 300px;
            border-collapse: collapse;
            font-size: 11px;
        }
        .totals-table td { padding: 4px 0; }
        .t-label { text-align: left; font-weight: 600; color: #555; }
        .t-val { text-align: right; font-weight: 700; color: #000; }
        .grand-total {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 8px 0;
            margin-top: 5px;
            font-size: 14px;
        }

        /* FOOTER & SIGNATURES */
        .thank-you {
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #444;
            margin-top: 15px;
            margin-bottom: 0;
            border-top: 1px dashed #ccc;
            padding-top: 8px;
        }

        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 20px; /* Spacer from content above */
            padding-top: 10px;
        }
        .sig-box {
            width: 40%;
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid #000;
            margin-bottom: 5px;
        }
        .sig-text {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* PRINT CONFIG */
        @page {
            size: A4;
            margin: 0; /* Remove browser default headers/footers */
        }
        @media print {
            body { background: white; margin: 0; }
            .page-container {
                width: 100%;
                height: 100%; /* Fill the page */
                margin: 0;
                box-shadow: none;
                border: none;
                /* Ensure padding is respected in print */
                padding: 8mm 15mm 5mm 15mm;
            }
            .no-print { display: none !important; }
            /* Force background graphics if any (though we removed bg colors) */
            -webkit-print-color-adjust: exact; 
        }

        /* UI Buttons (No Print) */
        .actions {
            position: fixed; top: 20px; right: 20px; z-index: 999;
            display: flex; gap: 10px;
        }
        .btn {
            padding: 10px 15px; border-radius: 5px; border: none; cursor: pointer; font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .btn-print { background: #2563eb; color: white; }
        .btn-back { background: #f3f4f6; color: #333; }
    </style>
</head>
<body>

    <div class="actions no-print">
        <button class="btn btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Print
        </button>
        <button class="btn btn-back" onclick="history.back()">
            <i class="fas fa-arrow-left"></i> Back
        </button>
    </div>

    <div class="page-container">
        <div class="content-wrap">
            <!-- Header -->
            <div class="header">
                <div class="company-identity">
                    <img src="images/logo.png" alt="Logo" class="logo-img" onerror="this.style.display='none'">
                    <div class="company-text">
                        <h1>Protection One (Pvt.) Ltd.</h1>
                        <p>A Complete Security Solution</p>
                    </div>
                </div>
                <div class="company-address">
                    <p><strong>Head Office:</strong> House 48, Road 02, Block L,</p>
                    <p>Banani, Dhaka- 1213, Bangladesh</p>
                    <p><i class="fas fa-phone-alt"></i> +880 1755-551912</p>
                    <p><i class="fas fa-envelope"></i> info@protectionone.com.bd</p>
                </div>
            </div>

            <!-- Title -->
            <div class="invoice-title">
                <h2>INVOICE</h2>
            </div>

            <!-- Info Grid -->
            <div class="info-grid">
                <div class="bill-to">
                    <div class="bill-label">Bill To:</div>
                    <div class="bill-box">
                        <div class="client-name"><?php echo htmlspecialchars($client_name ?: 'Walk-in Client'); ?></div>
                        <div class="client-addr"><?php echo htmlspecialchars($invoice['Branch_Address'] ?? $invoice['Head_Address'] ?? ''); ?></div>
                        <?php if(!empty($invoice['Contact_Number'])): ?>
                            <div class="client-contact"><strong>Tel:</strong> <?php echo htmlspecialchars($invoice['Contact_Number']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="meta-info">
                    <table class="meta-table">
                        <tr>
                            <td class="meta-key">Invoice No:</td>
                            <td class="meta-val"><?php echo htmlspecialchars($invoice['Invoice_No']); ?></td>
                        </tr>
                        <tr>
                            <td class="meta-key">Date:</td>
                            <td class="meta-val"><?php echo date('d-M-Y', strtotime($invoice['invoice_date'])); ?></td>
                        </tr>
                        <?php if(!empty($invoice['WO_No'])): ?>
                        <tr>
                            <td class="meta-key">Work Order:</td>
                            <td class="meta-val"><?php echo htmlspecialchars($invoice['WO_No']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="meta-key">Sales Person:</td>
                            <td class="meta-val"><?php echo htmlspecialchars($invoice['Created_By_User'] ?? '-'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Items -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 45%;">Description</th>
                        <th style="width: 15%;" class="col-center">Warranty</th>
                        <th style="width: 10%;" class="col-right">Qty</th>
                        <th style="width: 12%;" class="col-right">Unit Price</th>
                        <th style="width: 13%;" class="col-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $count = 1;
                    foreach ($grouped_items as $item): 
                        $line_total = $item['quantity'] * $item['unit_price'];
                    ?>
                    <tr>
                        <td><?php echo $count++; ?></td>
                        <td>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($item['description']); ?></div>
                            <?php if(!empty($item['serials'])): ?>
                                <div style="font-size:10px; color:#555; margin-top:2px;">
                                    SN: <?php echo htmlspecialchars(implode(', ', $item['serials'])); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="col-center"><?php echo htmlspecialchars($item['warranty'] ?? '-'); ?></td>
                        <td class="col-right"><?php echo $item['quantity']; ?></td>
                        <td class="col-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                        <td class="col-right" style="font-weight:700;"><?php echo number_format($line_total, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Totals -->
            <div class="totals-container">
                <table class="totals-table">
                    <tr>
                        <td class="t-label">Sub Total</td>
                        <td class="t-val"><?php echo number_format($invoice['sub_total'], 2); ?></td>
                    </tr>
                    <?php if($tax_amount > 0): ?>
                    <tr>
                        <td class="t-label">Tax (<?php echo number_format($invoice['Tax_Percentage'], 0); ?>%)</td>
                        <td class="t-val"><?php echo number_format($tax_amount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php 
                        $calc_grand = $invoice['sub_total'] + $tax_amount;
                        $discount = $calc_grand - $invoice['grand_total'];
                        if($discount > 0.01):
                    ?>
                    <tr>
                        <td class="t-label" style="color:red;">Discount</td>
                        <td class="t-val" style="color:red;">- <?php echo number_format($discount, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="grand-total">
                        <td class="t-label" style="color:#000; font-size:14px;">TOTAL</td>
                        <td class="t-val" style="font-size:16px;"><?php echo number_format($invoice['grand_total'], 2); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Absolute Bottom Signatures & Footer -->
        <div class="footer-wrap">
            <div class="signatures">
                <div class="sig-box">
                    <div class="sig-line"></div>
                    <div class="sig-text">Customer Signature</div>
                </div>
                <div class="sig-box">
                    <div class="sig-line"></div>
                    <div class="sig-text">Authorized Signature</div>
                </div>
            </div>

            <!-- Thank You / Contact Footer (Moved here) -->
            <div class="thank-you">
                <p>Thank you for choosing Protection One!</p>
                <p>For support: +880 1755-551912 | info@protectionone.com.bd</p>
            </div>
        </div>
    </div>

    <?php if($is_print): ?>
    <script>
        window.onload = function() {
            setTimeout(function() { window.print(); }, 500);
        }
    </script>
    <?php endif; ?>

</body>
</html>