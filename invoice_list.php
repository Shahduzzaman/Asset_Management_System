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

// --- Fetch Invoices ---
// Strategy: The invoice table doesn't have client info in your schema.
// We must find the client via the sold_product table linked to the invoice.
// We use a subquery or distinct join to fetch the client name associated with the invoice.
$sql = "SELECT 
            inv.invoice_id,
            inv.Invoice_No,
            inv.created_at as invoice_date,
            inv.ExcludingTax_TotalPrice as sub_total,
            inv.IncludingTax_TotalPrice as grand_total,
            u.user_name as created_by_name,
            -- Subquery to get Company Name from sold_product -> client_head
            (SELECT ch.Company_Name 
             FROM sold_product sp 
             JOIN client_head ch ON sp.client_head_id_fk = ch.client_head_id 
             WHERE sp.invoice_id_fk = inv.invoice_id 
             LIMIT 1) as Company_Name,
            -- Subquery to get Branch Name from sold_product -> client_branch
            (SELECT cb.Branch_Name 
             FROM sold_product sp 
             JOIN client_branch cb ON sp.client_branch_id_fk = cb.client_branch_id 
             WHERE sp.invoice_id_fk = inv.invoice_id 
             LIMIT 1) as Branch_Name
        FROM invoice inv
        LEFT JOIN users u ON inv.created_by = u.user_id
        ORDER BY inv.invoice_id DESC";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice History - AMS</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100 font-sans text-gray-800">

    <!-- Top Bar -->
    <div class="bg-white shadow p-4 flex items-center justify-between mb-6 sticky top-0 z-10">
        <h1 class="text-2xl font-bold text-gray-800">Invoice History</h1>
        <div>
            <a href="sold_product.php" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 mr-2">
                <i class="fas fa-plus mr-2"></i>New Sale
            </a>
            <a href="dashboard.php" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                <i class="fas fa-arrow-left mr-2"></i>Dashboard
            </a>
        </div>
    </div>

    <div class="container mx-auto px-4 pb-8">
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Sub Total</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Grand Total</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200 hover:bg-gray-50">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr class="hover:bg-blue-50 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap font-medium text-blue-600">
                                        <?php echo htmlspecialchars($row['Invoice_No']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?php echo date('d M Y', strtotime($row['invoice_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-700">
                                        <div class="font-bold"><?php echo htmlspecialchars($row['Company_Name'] ?? 'N/A'); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($row['Branch_Name'] ?? ''); ?></div>
                                        <div class="text-xs text-gray-400 mt-1">Created by: <?php echo htmlspecialchars($row['created_by_name'] ?? 'Unknown'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-600">
                                        <?php echo number_format($row['sub_total'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                        <?php echo number_format($row['grand_total'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <!-- View Button (Opens Popup) -->
                                        <button onclick="openInvoiceModal(<?php echo $row['invoice_id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-900 mr-3" title="View Details">
                                            <i class="fas fa-eye fa-lg"></i>
                                        </button>
                                        
                                        <!-- Print Button (Opens New Window) -->
                                        <a href="invoice_view.php?id=<?php echo $row['invoice_id']; ?>&print=true" target="_blank" 
                                           class="text-gray-600 hover:text-gray-900" title="Print Invoice">
                                            <i class="fas fa-print fa-lg"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-500">No invoices found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Invoice Details Modal -->
    <div id="invoiceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50" style="display: none;">
        <div class="relative top-10 mx-auto p-0 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-md bg-white">
            <!-- Modal Header -->
            <div class="flex justify-between items-center bg-gray-100 px-4 py-2 border-b rounded-t-md">
                <h3 class="text-lg font-medium text-gray-900">Invoice Details</h3>
                <button onclick="closeInvoiceModal()" class="text-gray-600 hover:text-red-600">
                    <i class="fas fa-times fa-lg"></i>
                </button>
            </div>
            
            <!-- Modal Body (Content Loaded via AJAX) -->
            <div id="modalContent" class="p-6 max-h-[80vh] overflow-y-auto">
                <div class="flex justify-center items-center py-10">
                    <i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex justify-end px-4 py-3 bg-gray-50 border-t rounded-b-md">
                <a id="modalPrintBtn" href="#" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 mr-2">
                    <i class="fas fa-print mr-1"></i> Print
                </a>
                <button onclick="closeInvoiceModal()" class="bg-gray-300 text-gray-700 px-4 py-2 rounded hover:bg-gray-400">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        function openInvoiceModal(invoiceId) {
            const modal = document.getElementById('invoiceModal');
            const content = document.getElementById('modalContent');
            const printBtn = document.getElementById('modalPrintBtn');
            
            $(modal).fadeIn(200);
            printBtn.href = `invoice_view.php?id=${invoiceId}&print=true`;

            $.ajax({
                url: 'invoice_view.php',
                type: 'GET',
                data: { id: invoiceId, mode: 'preview' },
                success: function(response) {
                    $(content).html(response);
                },
                error: function() {
                    $(content).html('<div class="text-center text-red-500">Failed to load invoice details.</div>');
                }
            });
        }

        function closeInvoiceModal() {
            $('#invoiceModal').fadeOut(200, function() {
                $('#modalContent').html('<div class="flex justify-center items-center py-10"><i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i></div>');
            });
        }

        $(window).click(function(event) {
            if (event.target.id == 'invoiceModal') {
                closeInvoiceModal();
            }
        });
    </script>

</body>
</html>