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

// --- Initialize Search ---
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// --- Fetch Invoices ---
// Base SQL
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
        LEFT JOIN users u ON inv.created_by = u.user_id";

// Apply Search Filter if exists
if (!empty($search)) {
    $sql .= " WHERE inv.Invoice_No LIKE ?";
}

$sql .= " ORDER BY inv.invoice_id DESC";

// Execute Query
if (!empty($search)) {
    $stmt = $conn->prepare($sql);
    $searchTerm = "%" . $search . "%";
    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice History - AMS</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100 font-sans text-gray-800">

    <div class="container mx-auto px-4 pb-8">
        
        <div class="mt-8 mb-4 flex justify-between items-center">
            <h2 class="text-2xl font-semibold text-gray-700">Invoice History</h2>
            <form action="" method="GET" class="flex gap-2 w-full md:w-1/3">
                <div class="relative w-full">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           class="w-full pl-10 pr-4 py-2 border rounded-lg focus:outline-none focus:border-blue-500 text-gray-600 border-gray-300" 
                           placeholder="Search by Invoice No...">
                    <div class="absolute left-3 top-2.5 text-gray-400">
                        <i class="fas fa-search"></i>
                    </div>
                </div>
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                    Search
                </button>
                <?php if(!empty($search)): ?>
                    <a href="?" class="bg-gray-500 text-white px-4 py-2 rounded-lg hover:bg-gray-600 transition flex items-center">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

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
                                        <div class="font-bold">
                                            <?php 
                                            if (!empty($row['Company_Name'])) {
                                                echo htmlspecialchars($row['Company_Name']);
                                            } elseif (!empty($row['Branch_Name'])) {
                                                // If Company is empty but Branch exists, show Branch in bold
                                                echo htmlspecialchars($row['Branch_Name']); 
                                            }
                                            ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php 
                                            // Only show Branch in subtext if Company was shown above (to avoid duplicate)
                                            if (!empty($row['Company_Name']) && !empty($row['Branch_Name'])) {
                                                echo htmlspecialchars($row['Branch_Name']); 
                                            }
                                            ?>
                                        </div>
                                        <div class="text-xs text-gray-400 mt-1">Created by: <?php echo htmlspecialchars($row['created_by_name'] ?? 'Unknown'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-600">
                                        <?php echo number_format($row['sub_total'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                        <?php echo number_format($row['grand_total'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <button onclick="openInvoiceModal(<?php echo $row['invoice_id']; ?>)" 
                                                class="text-blue-600 hover:text-blue-900 mr-3" title="View Details">
                                            <i class="fas fa-eye fa-lg"></i>
                                        </button>
                                        
                                        <a href="invoice_view.php?id=<?php echo $row['invoice_id']; ?>&print=true" target="_blank" 
                                           class="text-gray-600 hover:text-gray-900" title="Print Invoice">
                                            <i class="fas fa-print fa-lg"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-500">
                                    <?php echo !empty($search) ? 'No invoices found matching "'.htmlspecialchars($search).'".' : 'No invoices found.'; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="invoiceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50" style="display: none;">
        <div class="relative top-10 mx-auto p-0 border w-11/12 md:w-3/4 lg:w-2/3 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center bg-gray-100 px-4 py-2 border-b rounded-t-md">
                <h3 class="text-lg font-medium text-gray-900">Invoice Details</h3>
                <button onclick="closeInvoiceModal()" class="text-gray-600 hover:text-red-600">
                    <i class="fas fa-times fa-lg"></i>
                </button>
            </div>
            
            <div id="modalContent" class="p-6 max-h-[80vh] overflow-y-auto">
                <div class="flex justify-center items-center py-10">
                    <i class="fas fa-spinner fa-spin fa-3x text-blue-500"></i>
                </div>
            </div>

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