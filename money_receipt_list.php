<?php
require_once 'session_guard.php';
require_once 'connection.php';

// --- 1. ADMIN CHECK ---
// Check if user is Admin (role id 1)
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 0;
$isAdmin = ($user_role == 1);

// ==========================================================
//                 BACKEND AJAX HANDLERS
// ==========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'] === 'fetch')) {
    
    // --- A. DELETE ACTION ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        header('Content-Type: application/json');
        if (!$isAdmin) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }

        $payment_id = (int)$_POST['id'];
        $conn->begin_transaction();
        try {
            // Get Invoice ID
            $stmt = $conn->prepare("SELECT invoice_id_fk FROM payments WHERE payment_id = ?");
            $stmt->bind_param("i", $payment_id);
            $stmt->execute();
            $inv_id = $stmt->get_result()->fetch_assoc()['invoice_id_fk'] ?? 0;

            if(!$inv_id) throw new Exception("Payment not found");

            // Soft Delete
            $conn->query("UPDATE payments SET is_deleted = 1 WHERE payment_id = $payment_id");

            // Update Invoice Status
            recalculateInvoiceStatus($conn, $inv_id);
            
            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Deleted successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // --- B. GET SINGLE RECEIPT DETAILS (For Edit Modal) ---
    if (isset($_POST['action']) && $_POST['action'] === 'get_details') {
        header('Content-Type: application/json');
        if (!$isAdmin) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }

        $id = (int)$_POST['id'];
        $sql = "SELECT p.*, i.Invoice_No, i.invoice_id, i.IncludingTax_TotalPrice as Invoice_Total,
                (SELECT SUM(amount) FROM payments WHERE invoice_id_fk = i.invoice_id AND is_deleted = 0) as Total_Paid_All
                FROM payments p 
                JOIN invoice i ON p.invoice_id_fk = i.invoice_id
                WHERE p.payment_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();

        if ($data) {
            // Calculate Max Editable Amount: (Invoice Total - (Total Paid - Current Amount))
            $paid_others = $data['Total_Paid_All'] - $data['amount'];
            $max_payable = $data['Invoice_Total'] - $paid_others;
            $data['max_payable'] = $max_payable;
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Not found']);
        }
        exit;
    }

    // --- C. UPDATE RECEIPT ---
    if (isset($_POST['action']) && $_POST['action'] === 'update') {
        header('Content-Type: application/json');
        if (!$isAdmin) { echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; }

        $id = (int)$_POST['payment_id'];
        $amount = (float)$_POST['amount'];
        $date = $_POST['payment_date'];
        $method = $_POST['payment_method'];
        $trans = ($method === 'Cash') ? null : trim($_POST['transaction_number']);
        $max_val = (float)$_POST['max_val_hidden']; // Passed from frontend for validation

        if ($amount <= 0 || $amount > ($max_val + 0.1)) {
             echo json_encode(['status' => 'error', 'message' => 'Invalid amount.']); exit;
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE payments SET payment_date=?, payment_method=?, transaction_number=?, amount=? WHERE payment_id=?");
            $stmt->bind_param("sssdi", $date, $method, $trans, $amount, $id);
            $stmt->execute();

            // Get Invoice ID to update status
            $res = $conn->query("SELECT invoice_id_fk FROM payments WHERE payment_id = $id")->fetch_assoc();
            recalculateInvoiceStatus($conn, $res['invoice_id_fk']);

            $conn->commit();
            echo json_encode(['status' => 'success', 'message' => 'Updated successfully']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    // --- D. FETCH LIST (Standard) ---
    if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
        $search = isset($_GET['q']) ? trim($_GET['q']) : '';
        $sql = "SELECT p.*, i.Invoice_No,
                CASE WHEN i.client_branch_id_fk IS NOT NULL THEN cb.Branch_Name ELSE ch.Company_Name END as Client_Name
                FROM payments p
                JOIN invoice i ON p.invoice_id_fk = i.invoice_id
                LEFT JOIN client_head ch ON i.client_head_id_fk = ch.client_head_id
                LEFT JOIN client_branch cb ON i.client_branch_id_fk = cb.client_branch_id
                WHERE p.is_deleted = 0";
        
        $params = []; $types = "";
        if (!empty($search)) {
            $sql .= " AND (p.money_receipt_no LIKE ? OR i.Invoice_No LIKE ? OR ch.Company_Name LIKE ? OR cb.Branch_Name LIKE ?)";
            $term = "%$search%";
            $params = [$term, $term, $term, $term]; $types = "ssss";
        }
        $sql .= " ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 50";
        
        $stmt = $conn->prepare($sql);
        if(!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while($row = $res->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows);
        exit;
    }
}

// Helper Function
function recalculateInvoiceStatus($conn, $invoice_id) {
    $stmt = $conn->prepare("SELECT IncludingTax_TotalPrice as total, (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id_fk = ? AND is_deleted = 0) as paid FROM invoice WHERE invoice_id = ?");
    $stmt->bind_param("ii", $invoice_id, $invoice_id);
    $stmt->execute();
    $calc = $stmt->get_result()->fetch_assoc();
    
    $status = 0; // Due
    if ($calc['paid'] >= ($calc['total'] - 0.1)) $status = 2; // Paid
    elseif ($calc['paid'] > 0) $status = 1; // Partial
    
    $conn->query("UPDATE invoice SET status = $status WHERE invoice_id = $invoice_id");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Money Receipt List</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; } </style>
</head>
<body class="p-6">

    <div class="max-w-7xl mx-auto">

        <div class="bg-white p-4 rounded-xl shadow-sm mb-6">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none"><i class="fas fa-search text-gray-400"></i></div>
                <input type="text" id="searchInput" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg leading-5 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 sm:text-sm" placeholder="Search by Receipt No, Invoice No, Client Name...">
            </div>
        </div>

        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt No</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client / Invoice</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Method</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody" class="bg-white divide-y divide-gray-200">
                        <tr><td colspan="6" class="px-6 py-10 text-center text-gray-500">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeEditModal()"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
                
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Edit Money Receipt</h3>
                            <p class="text-sm text-gray-500 mb-4" id="modal_sub_info">Loading info...</p>
                            
                            <div id="modalError" class="hidden bg-red-100 border-l-4 border-red-500 text-red-700 p-2 mb-4 text-sm"></div>

                            <form id="editForm">
                                <input type="hidden" id="edit_payment_id" name="payment_id">
                                <input type="hidden" id="edit_max_val" name="max_val_hidden">

                                <div class="grid gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Date</label>
                                        <input type="date" id="edit_date" name="payment_date" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Method</label>
                                        <select id="edit_method" name="payment_method" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                            <option value="Cash">Cash</option>
                                            <option value="Cheque">Cheque</option>
                                            <option value="Bank Transfer">Bank Transfer</option>
                                            <option value="Bank Deposit">Bank Deposit</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Transaction No</label>
                                        <input type="text" id="edit_trans" name="transaction_number" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:ring-blue-500 focus:border-blue-500 sm:text-sm disabled:bg-gray-100 disabled:text-gray-400">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">Amount</label>
                                        <input type="number" step="0.01" id="edit_amount" name="amount" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 font-bold text-gray-900 focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                        <p class="text-xs text-gray-500 mt-1">Max allowed: <span id="lbl_max_amount" class="font-bold">0.00</span></p>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" onclick="submitEdit()" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">
                        Update
                    </button>
                    <button type="button" onclick="closeEditModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
    <iframe id="hidden_print_frame" style="position:fixed; left:-9999px; top:0; width:0; height:0; border:none;"></iframe>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        const isAdmin = <?php echo ($isAdmin ? 'true' : 'false'); ?>;

        $(document).ready(function() {
            fetchData();
            $('#searchInput').on('input', function() { fetchData($(this).val()); });
            
            // Edit Modal Logic
            $('#edit_method').on('change', function() {
                const isCash = $(this).val() === 'Cash';
                $('#edit_trans').prop('disabled', isCash).val(isCash ? '' : $('#edit_trans').val());
            });
        });

        // --- FETCH LIST ---
        function fetchData(query = '') {
            $.ajax({
                url: 'money_receipt_list.php',
                type: 'GET',
                data: { action: 'fetch', q: query },
                dataType: 'json',
                success: function(data) {
                    let html = '';
                    if (data.length === 0) {
                        html = '<tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No receipts found.</td></tr>';
                    } else {
                        data.forEach(row => {
                            let methodBadge = `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">${row.payment_method}</span>`;
                            if(row.payment_method === 'Cash') methodBadge = `<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Cash</span>`;
                            
                            let adminActions = '';
                            if (isAdmin) {
                                adminActions = `
                                    <button onclick="openEditModal(${row.payment_id})" class="text-blue-600 hover:text-blue-900 ml-3" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteReceipt(${row.payment_id})" class="text-red-600 hover:text-red-900 ml-3" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                `;
                            }

                            html += `
                                <tr class="hover:bg-gray-50 transition">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        ${new Date(row.payment_date).toLocaleDateString('en-GB')}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-800 font-mono">
                                        ${row.money_receipt_no}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <div class="font-medium">${row.Client_Name}</div>
                                        <div class="text-xs text-gray-500">Inv: ${row.Invoice_No}</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        ${methodBadge}
                                        ${row.transaction_number ? `<div class="text-xs text-gray-400 mt-1">#${row.transaction_number}</div>` : ''}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-800">
                                        ${parseFloat(row.amount).toFixed(2)}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <button onclick="printReceipt(${row.payment_id})" class="text-indigo-600 hover:text-indigo-900" title="Print"><i class="fas fa-print"></i></button>
                                        ${adminActions}
                                    </td>
                                </tr>
                            `;
                        });
                    }
                    $('#tableBody').html(html);
                }
            });
        }

        // --- EDIT MODAL FUNCTIONS ---
        function openEditModal(id) {
            $('#editModal').removeClass('hidden');
            $('#modalError').addClass('hidden');
            $('#modal_sub_info').text('Loading info...');
            
            // Fetch Details
            $.ajax({
                url: 'money_receipt_list.php',
                type: 'POST',
                data: { action: 'get_details', id: id },
                dataType: 'json',
                success: function(response) {
                    if(response.status === 'success') {
                        const d = response.data;
                        $('#edit_payment_id').val(d.payment_id);
                        $('#edit_date').val(d.payment_date);
                        $('#edit_method').val(d.payment_method);
                        $('#edit_trans').val(d.transaction_number);
                        $('#edit_amount').val(d.amount);
                        $('#edit_max_val').val(d.max_payable); // Hidden input for validation
                        
                        $('#lbl_max_amount').text(parseFloat(d.max_payable).toFixed(2));
                        $('#modal_sub_info').html(`Receipt: <b>${d.money_receipt_no}</b> | Invoice: ${d.Invoice_No}`);
                        
                        // Trigger change to set disabled state correctly
                        $('#edit_method').trigger('change');
                    } else {
                        alert("Error fetching data");
                        closeEditModal();
                    }
                }
            });
        }

        function closeEditModal() {
            $('#editModal').addClass('hidden');
        }

        function submitEdit() {
            const amount = parseFloat($('#edit_amount').val());
            const max = parseFloat($('#edit_max_val').val());
            
            if (amount <= 0) {
                $('#modalError').text("Amount must be greater than 0").removeClass('hidden');
                return;
            }
            if (amount > (max + 0.1)) { // Small buffer for float issues
                $('#modalError').text(`Amount cannot exceed ${max.toFixed(2)}`).removeClass('hidden');
                return;
            }
            
            const formData = $('#editForm').serialize() + '&action=update';
            
            $.ajax({
                url: 'money_receipt_list.php',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        alert(response.message);
                        closeEditModal();
                        fetchData($('#searchInput').val()); // Refresh list
                    } else {
                        $('#modalError').text(response.message).removeClass('hidden');
                    }
                },
                error: function() {
                    $('#modalError').text("Server error during update").removeClass('hidden');
                }
            });
        }

        // --- PRINT & DELETE ---
        function printReceipt(id) {
            document.getElementById('hidden_print_frame').src = `print_money_receipt.php?id=${id}&print=true`;
        }

        function deleteReceipt(id) {
            if(!confirm(`Are you sure? This will recalculate the invoice due amount.`)) return;
            $.post('money_receipt_list.php', { action: 'delete', id: id }, function(response) {
                if (response.status === 'success') {
                    alert(response.message);
                    fetchData($('#searchInput').val());
                } else {
                    alert(response.message);
                }
            }, 'json');
        }
    </script>
</body>
</html>