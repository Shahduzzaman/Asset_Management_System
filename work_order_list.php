<?php
require_once 'session_guard.php';
$current_user_id = $_SESSION['user_id'];
require_once 'connection.php';

$isAdmin = (isset($_SESSION['user_role']) && (int)$_SESSION['user_role'] === 1);

if (isset($_GET['action']) && $_GET['action'] === 'search') {
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    
    $sql = "SELECT 
                w.work_order_id, w.Order_No, w.Order_Date, w.created_at,
                ch.Company_Name, cb.Branch_Name
            FROM work_order w
            LEFT JOIN client_head ch ON w.client_head_id_fk = ch.client_head_id
            LEFT JOIN client_branch cb ON w.client_branch_id_fk = cb.client_branch_id
            WHERE w.is_deleted = 0";

    $params = [];
    $types = "";

    if (!empty($search)) {
        $searchTerm = "%" . $search . "%";
        $sql .= " AND (w.Order_No LIKE ? OR ch.Company_Name LIKE ? OR cb.Branch_Name LIKE ?)";
        $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
        $types .= "sss";
    }

    $sql .= " ORDER BY w.created_at DESC";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $clientInfo = 'N/A';
            if (!empty($row['Company_Name'])) {
                $clientInfo = htmlspecialchars($row['Company_Name']);
                if (!empty($row['Branch_Name'])) $clientInfo .= " <span class='text-gray-400 text-sm'>(" . htmlspecialchars($row['Branch_Name']) . ")</span>";
            } elseif (!empty($row['Branch_Name'])) {
                $clientInfo = htmlspecialchars($row['Branch_Name']);
            }
            $orderDate = $row['Order_Date'] ? date('M d, Y', strtotime($row['Order_Date'])) : '-';

            echo "<tr class='hover:bg-gray-50 transition border-b border-gray-200'>";
            
            echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 truncate max-w-[350px]' title='" . htmlspecialchars($row['Order_No']) . "'>" . htmlspecialchars($row['Order_No']) . "</td>";
            
            echo "<td class='px-6 py-4 whitespace-normal text-sm text-gray-700'>" . $clientInfo . "</td>";
            
            echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-600'>" . $orderDate . "</td>";
            
            echo "<td class='px-6 py-4 whitespace-nowrap text-right text-sm font-medium'>";
            
            $viewMargin = $isAdmin ? 'mr-3' : ''; 
            echo "<button onclick='openOrderModal(" . $row['work_order_id'] . ")' class='text-indigo-600 hover:text-indigo-900 {$viewMargin} font-medium transition'>View</button>";

            if ($isAdmin) {
                echo "<a href='edit_work_order.php?id=" . $row['work_order_id'] . "' class='text-blue-600 hover:text-blue-900 mr-3 font-medium transition'>Edit</a>";
                
                echo "<button onclick='deleteOrder(" . $row['work_order_id'] . ")' class='text-red-600 hover:text-red-900 font-medium transition'>Delete</button>";
            }
            
            echo "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='4' class='px-6 py-4 text-center text-gray-500'>No work orders found.</td></tr>";
    }
    $stmt->close();
    $conn->close();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Order List</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <main class="w-full max-w-6xl mx-auto">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                    <input type="text" id="search-input" placeholder="Search by Order No, Company or Branch..." class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 sm:text-sm transition duration-150 ease-in-out" autocomplete="off">
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 table-fixed">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="w-[350px] px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order No</th>
                                <th class="w-auto px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client Info</th>
                                <th class="w-[150px] px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order Date</th>
                                <th class="w-[140px] px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody id="work-order-list" class="bg-white divide-y divide-gray-200">
                            <tr><td colspan="4" class="px-6 py-10 text-center text-gray-500">Loading orders...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <div id="order-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onclick="closeOrderModal()"></div>
        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <div class="relative transform overflow-hidden rounded-lg bg-white text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="text-lg font-medium leading-6 text-gray-900" id="modal-title">Work Order Details</h3>
                    <button onclick="closeOrderModal()" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div id="modal-content" class="px-4 py-5 sm:p-6 max-h-[70vh] overflow-y-auto"></div>
                <div class="bg-gray-50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                    <button type="button" onclick="closeOrderModal()" class="mt-3 inline-flex w-full justify-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:mt-0 sm:w-auto">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentQuery = '';
        const searchInput = document.getElementById('search-input');
        const tableBody = document.getElementById('work-order-list');
        let debounceTimer;

        const fetchOrders = async (query = '') => {
            currentQuery = query;
            try {
                const response = await fetch(`work_order_list.php?action=search&q=${encodeURIComponent(query)}`);
                if (!response.ok) throw new Error('Network response error');
                const html = await response.text();
                tableBody.innerHTML = html;
            } catch (error) {
                tableBody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-red-500">Error loading data.</td></tr>';
            }
        };

        fetchOrders();

        searchInput.addEventListener('input', (e) => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => { fetchOrders(e.target.value); }, 300);
        });

        const modal = document.getElementById('order-modal');
        const modalContent = document.getElementById('modal-content');

        function openOrderModal(id) {
            modal.classList.remove('hidden');
            modalContent.innerHTML = '<div class="flex justify-center py-10"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>';
            fetch(`view_order.php?id=${id}`)
                .then(response => response.text())
                .then(html => { modalContent.innerHTML = html; })
                .catch(err => { modalContent.innerHTML = '<div class="text-red-500 text-center">Failed to load order details.</div>'; });
        }

        function closeOrderModal() {
            modal.classList.add('hidden');
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape" && !modal.classList.contains('hidden')) { closeOrderModal(); }
        });

        function deleteOrder(id) {
            if (confirm("Are you sure you want to delete this Work Order?")) {
                fetch('delete_work_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        fetchOrders(currentQuery);
                    } else {
                        alert("Error: " + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert("An error occurred.");
                });
            }
        }
    </script>
</body>
</html>