<?php
// paid_invoice_list.php
require_once 'session_guard.php';
require_once 'connection.php';

// --- AJAX SEARCH HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';

    $sql = "SELECT i.invoice_id, i.Invoice_No, i.status, i.IncludingTax_TotalPrice,
            ch.Company_Name, cb.Branch_Name
            FROM invoice i
            LEFT JOIN client_head ch ON i.client_head_id_fk = ch.client_head_id
            LEFT JOIN client_branch cb ON i.client_branch_id_fk = cb.client_branch_id
            WHERE i.is_deleted = 0";

    $params = [];
    $types = "";

    if (!empty($search)) {
        // Smart Status Search Logic
        $status_search_sql = "";
        $s_lower = strtolower($search);
        
        if (strpos($s_lower, 'paid') !== false && strpos($s_lower, 'part') === false) { 
             $status_search_sql = " OR i.status = 2"; 
        }
        if (strpos($s_lower, 'due') !== false) { 
             $status_search_sql = " OR i.status = 0"; 
        }
        if (strpos($s_lower, 'part') !== false) { 
             $status_search_sql = " OR i.status = 1"; 
        }

        $term = "%" . $search . "%";
        $sql .= " AND (i.Invoice_No LIKE ? OR ch.Company_Name LIKE ? OR cb.Branch_Name LIKE ? $status_search_sql)";
        
        $params[] = $term; $params[] = $term; $params[] = $term;
        $types .= "sss";
    }

    $sql .= " ORDER BY i.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Client Name Logic
            $client = !empty($row['Branch_Name']) ? $row['Branch_Name'] : $row['Company_Name'];
            if (!empty($row['Branch_Name']) && !empty($row['Company_Name'])) {
                $client = $row['Company_Name'] . " <span class='text-gray-400 text-xs'>(" . $row['Branch_Name'] . ")</span>";
            }

            // Status Badge
            $status_badges = [
                0 => ['Due', 'bg-red-100 text-red-800'],
                1 => ['Partial', 'bg-yellow-100 text-yellow-800'],
                2 => ['Paid', 'bg-green-100 text-green-800']
            ];
            $st = $status_badges[$row['status']] ?? ['Unknown', 'bg-gray-100 text-gray-800'];
            
            echo "<tr onclick='openInvoiceModal(" . $row['invoice_id'] . ")' class='hover:bg-blue-50 cursor-pointer transition border-b border-gray-200 group'>";
            echo "<td class='px-6 py-4 font-medium text-gray-900 group-hover:text-blue-600'>" . htmlspecialchars($row['Invoice_No']) . "</td>";
            echo "<td class='px-6 py-4 text-gray-700'>" . $client . "</td>";
            echo "<td class='px-6 py-4'>";
            echo "<span class='px-2.5 py-0.5 rounded text-xs font-semibold " . $st[1] . "'>" . $st[0] . "</span>";
            echo "</td>";
            echo "<td class='px-6 py-4 text-right text-gray-600'>" . number_format($row['IncludingTax_TotalPrice'], 2) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='4' class='px-6 py-8 text-center text-gray-500'>No invoices found matching your search.</td></tr>";
    }
    exit();
}
// --- END AJAX HANDLER ---
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <main class="w-full max-w-5xl mx-auto">

            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 mb-6 sticky top-4 z-10">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <input type="text" id="search-input" 
                           placeholder="Search by Invoice No, Client Name, or Status (e.g., 'Paid')..." 
                           class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500 transition"
                           autocomplete="off">
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice No</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client Info</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                            </tr>
                        </thead>
                        <tbody id="invoice-list-body" class="bg-white divide-y divide-gray-200">
                            <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">Loading invoices...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <div id="invoice-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity backdrop-blur-sm" onclick="closeInvoiceModal()"></div>
        
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-2xl transition-all w-full max-w-3xl">
                
                <button onclick="closeInvoiceModal()" class="absolute top-4 right-4 text-gray-400 hover:text-gray-600 transition z-10">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>

                <div id="modal-content" class="p-8 max-h-[85vh] overflow-y-auto">
                    </div>
                
                <div class="bg-gray-50 px-6 py-4 flex justify-end">
                    <button type="button" onclick="closeInvoiceModal()" class="bg-white border border-gray-300 text-gray-700 font-semibold py-2 px-6 rounded-lg hover:bg-gray-100 transition">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const tableBody = document.getElementById('invoice-list-body');
        const searchInput = document.getElementById('search-input');
        const modal = document.getElementById('invoice-modal');
        const modalContent = document.getElementById('modal-content');
        let debounceTimer;

        // --- Fetch List ---
        function fetchInvoices(query = '') {
            // UPDATED FILENAME HERE:
            fetch(`paid_invoice_list.php?action=search&q=${encodeURIComponent(query)}`)
                .then(res => res.text())
                .then(html => {
                    tableBody.innerHTML = html;
                })
                .catch(err => {
                    tableBody.innerHTML = '<tr><td colspan="4" class="text-center text-red-500 py-4">Error loading data</td></tr>';
                });
        }

        // --- Event Listeners ---
        searchInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                fetchInvoices(e.target.value);
            }, 300); // 300ms debounce
        });

        // --- Modal Functions ---
        function openInvoiceModal(id) {
            modal.classList.remove('hidden');
            modalContent.innerHTML = '<div class="flex justify-center py-12"><div class="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600"></div></div>';
            
            // UPDATED FILENAME HERE:
            fetch(`view_paid_invoice.php?id=${id}`)
                .then(res => res.text())
                .then(html => {
                    modalContent.innerHTML = html;
                })
                .catch(err => {
                    modalContent.innerHTML = '<div class="text-center text-red-500">Failed to load details.</div>';
                });
        }

        function closeInvoiceModal() {
            modal.classList.add('hidden');
        }

        // Close on Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeInvoiceModal();
            }
        });

        // Initial Load
        fetchInvoices();
        function printFromUrl(url) {
            // Remove old iframe if exists to ensure clean load
            const oldFrame = document.getElementById('hidden_print_frame');
            if (oldFrame) oldFrame.remove();

            // Create hidden iframe
            const iframe = document.createElement('iframe');
            iframe.id = 'hidden_print_frame';
            iframe.style.position = 'fixed';
            iframe.style.left = '-9999px';
            iframe.style.top = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = 'none';
            
            // Set URL (The receipt page auto-prints on load)
            iframe.src = url;
            
            document.body.appendChild(iframe);
        }
    </script>
</body>
</html>