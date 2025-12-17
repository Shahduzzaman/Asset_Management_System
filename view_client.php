<?php
ob_start();
require_once 'session_guard.php';
$current_user_id = $_SESSION['user_id'];
$user_role = isset($_SESSION['user_role']) ? (int)$_SESSION['user_role'] : 0; 

require_once 'connection.php';
// Disable Strict Mode for compatibility
$conn->query("SET sql_mode=''");

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Get details for a single client
    if ($_GET['action'] === 'get_client_details' && isset($_GET['id']) && isset($_GET['type'])) {
        $id = intval($_GET['id']);
        $type = $_GET['type'];
        
        if ($type === 'head') {
            $sql = "SELECT *, 'Head Office' as type_name FROM client_head WHERE client_head_id = ?";
        } else { 
            $sql = "SELECT cb.*, ch.Company_Name as parent_company_name, 'Branch Office' as type_name 
                    FROM client_branch cb 
                    JOIN client_head ch ON cb.client_head_id_fk = ch.client_head_id 
                    WHERE cb.client_branch_id = ?";
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        
        if ($data) $response = ['status' => 'success', 'data' => $data];
        echo json_encode($response); exit();
    }

    // Action: Update a client (Admin Only)
    if ($_GET['action'] === 'update_client' && $_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 1) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($data['type'] === 'head') {
            $sql = "UPDATE client_head SET Company_Name=?, Department=?, Contact_Person=?, Contact_Number=?, Address=?, is_updated=1 WHERE client_head_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", $data['Company_Name'], $data['Department'], $data['Contact_Person'], $data['Contact_Number'], $data['Address'], $data['id']);
        } else { 
            $sql = "UPDATE client_branch SET client_head_id_fk=?, Branch_Name=?, Contact_Person1=?, Contact_Number1=?, Contact_Person2=?, Contact_Number2=?, Zone=?, Address=?, is_updated=1 WHERE client_branch_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssssssi", $data['client_head_id_fk'], $data['Branch_Name'], $data['Contact_Person1'], $data['Contact_Number1'], $data['Contact_Person2'], $data['Contact_Number2'], $data['Zone'], $data['Address'], $data['id']);
        }
        
        if ($stmt->execute()) $response = ['status' => 'success', 'message' => 'Updated successfully.'];
        else $response['message'] = 'DB Error: ' . $stmt->error;
        echo json_encode($response); exit();
    }
    
    // Action: Soft delete
    if ($_GET['action'] === 'delete_client' && $_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 1) {
        $data = json_decode(file_get_contents('php://input'), true);
        $table = ($data['type'] === 'head') ? 'client_head' : 'client_branch';
        $id_col = ($data['type'] === 'head') ? 'client_head_id' : 'client_branch_id';
        
        $sql = "UPDATE $table SET is_deleted = 1 WHERE $id_col = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['id']);
        if ($stmt->execute()) $response = ['status' => 'success'];
        echo json_encode($response); exit();
    }

    // Action: Search Head Office (For Branch parent selection)
    if ($_GET['action'] === 'search_head_office' && isset($_GET['query'])) {
        $query = $_GET['query'] . '%';
        $sql = "SELECT client_head_id, Company_Name FROM client_head WHERE Company_Name LIKE ? AND is_deleted = 0 LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $query);
        $stmt->execute();
        $response = ['status' => 'success', 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)];
        echo json_encode($response); exit();
    }
}

// --- Part 2: Fetch Table Data ---
$client_list_sql = "
    (SELECT client_head_id as id, Company_Name as client_name, 'Head Office' as client_type, 
            NULL as parent_name, Department as dept_zone, Address, 'head' as rtype
     FROM client_head WHERE is_deleted = 0)
    UNION ALL
    (SELECT cb.client_branch_id as id, cb.Branch_Name as client_name, 'Branch' as client_type, 
            ch.Company_Name as parent_name, cb.Zone as dept_zone, cb.Address, 'branch' as rtype
     FROM client_branch cb JOIN client_head ch ON cb.client_head_id_fk = ch.client_head_id
     WHERE cb.is_deleted = 0 AND ch.is_deleted = 0)
    ORDER BY client_name";
$clients = $conn->query($client_list_sql)->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Directory</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.is-active { display: flex; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div id="global-message" class="fixed top-4 right-4 z-[2000] transform translate-x-full transition-transform duration-300">
        <div class="bg-white border-l-4 border-green-500 shadow-lg p-4 rounded flex items-center space-x-3">
            <span id="message-text" class="text-sm font-medium text-gray-700"></span>
        </div>
    </div>

    <div class="container mx-auto py-8 px-4">
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <h1 class="text-2xl font-bold text-gray-800">Client Management</h1>
                <input type="text" id="global-search" placeholder="Search anything..." class="w-full md:w-80 px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 outline-none">
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 text-gray-600 text-xs uppercase font-bold">
                        <tr>
                            <th class="px-6 py-4">Name</th>
                            <th class="px-6 py-4">Type</th>
                            <th class="px-6 py-4">Head Office</th>
                            <th class="px-6 py-4">Dept/Zone</th>
                            <th class="px-6 py-4">Address</th>
                            <th class="px-6 py-4 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="client-table-body" class="text-sm">
                        <?php foreach ($clients as $c): ?>
                        <tr class="border-b hover:bg-gray-50 transition" data-id="<?= $c['id'] ?>" data-type="<?= $c['rtype'] ?>">
                            <td class="px-6 py-4 font-semibold text-gray-800 client-name-cell"><?= htmlspecialchars($c['client_name']) ?></td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded-md text-[10px] font-bold uppercase <?= $c['rtype'] == 'head' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' ?>">
                                    <?= $c['client_type'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-gray-500"><?= htmlspecialchars($c['parent_name'] ?? '—') ?></td>
                            <td class="px-6 py-4 text-gray-500"><?= htmlspecialchars($c['dept_zone'] ?? '—') ?></td>
                            <td class="px-6 py-4 text-gray-400 truncate max-w-xs"><?= htmlspecialchars($c['Address'] ?? '—') ?></td>
                            <td class="px-6 py-4 text-center space-x-3">
                                <button onclick="openViewModal(<?= $c['id'] ?>, '<?= $c['rtype'] ?>')" class="text-blue-600 hover:text-blue-800 font-medium">View</button>
                                <?php if ($user_role === 1): ?>
                                <button onclick="openEditModal(<?= $c['id'] ?>, '<?= $c['rtype'] ?>')" class="text-green-600 hover:text-green-800 font-medium">Edit</button>
                                <button onclick="openDeleteModal(<?= $c['id'] ?>, '<?= $c['rtype'] ?>')" class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="view-modal" class="modal">
        <div class="bg-white rounded-xl w-full max-w-lg p-8 shadow-2xl m-4">
            <h2 id="view-title" class="text-2xl font-bold text-gray-800 mb-6"></h2>
            <div id="view-content" class="grid grid-cols-1 gap-4 text-gray-700"></div>
            <div class="mt-8 pt-4 border-t">
                <button onclick="closeModal('view-modal')" class="w-full bg-gray-100 py-3 rounded-lg font-bold hover:bg-gray-200 transition">Close</button>
            </div>
        </div>
    </div>

    <div id="edit-modal" class="modal">
        <div class="bg-white rounded-xl w-full max-w-2xl p-8 shadow-2xl m-4 overflow-y-auto max-h-[90vh]">
            <h2 class="text-2xl font-bold mb-6">Edit Information</h2>
            <form id="edit-form" class="space-y-4">
                <input type="hidden" id="edit-id"><input type="hidden" id="edit-type">
                
                <div id="branch-only-fields" class="hidden">
                    <label class="block text-sm font-bold text-gray-700 mb-1">Parent Head Office</label>
                    <input type="text" id="parent-search" placeholder="Type to search Head Office..." class="w-full p-3 border rounded-lg mb-2">
                    <input type="hidden" id="parent-id">
                    <div id="search-results" class="bg-gray-50 border rounded-lg max-h-40 overflow-y-auto hidden"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label id="name-label" class="block text-sm font-bold mb-1">Name</label>
                        <input type="text" id="edit-name" class="w-full p-3 border rounded-lg">
                    </div>
                    <div>
                        <label id="dept-zone-label" class="block text-sm font-bold mb-1">Dept/Zone</label>
                        <input type="text" id="edit-dept-zone" class="w-full p-3 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">Contact Person</label>
                        <input type="text" id="edit-contact-person" class="w-full p-3 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">Phone Number</label>
                        <input type="text" id="edit-phone" class="w-full p-3 border rounded-lg">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold mb-1">Full Address</label>
                    <textarea id="edit-address" rows="3" class="w-full p-3 border rounded-lg"></textarea>
                </div>
                
                <div class="flex space-x-4 mt-6">
                    <button type="button" onclick="closeModal('edit-modal')" class="flex-1 py-3 bg-gray-100 rounded-lg">Cancel</button>
                    <button type="submit" class="flex-1 py-3 bg-blue-600 text-white font-bold rounded-lg hover:bg-blue-700 transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="delete-modal" class="modal">
        <div class="bg-white rounded-lg p-8 w-full max-w-sm text-center shadow-2xl">
            <div class="text-red-500 text-5xl mb-4">⚠️</div>
            <h3 class="text-xl font-bold mb-2">Delete Client?</h3>
            <p class="text-gray-500 mb-6">Are you sure you want to remove this record from the active directory?</p>
            <div class="flex space-x-3">
                <button onclick="closeModal('delete-modal')" class="flex-1 py-2 bg-gray-100 rounded-lg">Cancel</button>
                <button id="confirm-delete" class="flex-1 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // State
        let currentDelete = null;

        // Utilities
        function showMsg(text) {
            const el = document.getElementById('global-message');
            document.getElementById('message-text').innerText = text;
            el.classList.remove('translate-x-full');
            setTimeout(() => el.classList.add('translate-x-full'), 3000);
        }

        function closeModal(id) { document.getElementById(id).classList.remove('is-active'); }

        // --- View Logic ---
        async function openViewModal(id, type) {
            const res = await fetch(`?action=get_client_details&id=${id}&type=${type}`);
            const json = await res.json();
            if(json.status === 'success') {
                const d = json.data;
                document.getElementById('view-title').innerText = d.Company_Name || d.Branch_Name;
                document.getElementById('view-content').innerHTML = `
                    <div class="border-b pb-2"><span class="text-xs uppercase font-bold text-gray-400">Category</span><p class="font-medium">${d.type_name}</p></div>
                    <div class="border-b pb-2"><span class="text-xs uppercase font-bold text-gray-400">Main Contact</span><p class="font-medium">${d.Contact_Person || d.Contact_Person1}</p></div>
                    <div class="border-b pb-2"><span class="text-xs uppercase font-bold text-gray-400">Phone</span><p class="font-medium">${d.Contact_Number || d.Contact_Number1}</p></div>
                    <div class="border-b pb-2"><span class="text-xs uppercase font-bold text-gray-400">${type === 'head' ? 'Department' : 'Zone'}</span><p class="font-medium">${d.Department || d.Zone || '—'}</p></div>
                    <div class="border-b pb-2"><span class="text-xs uppercase font-bold text-gray-400">Address</span><p class="font-medium">${d.Address || '—'}</p></div>
                `;
                document.getElementById('view-modal').classList.add('is-active');
            }
        }

        // --- Edit Logic ---
        async function openEditModal(id, type) {
            const res = await fetch(`?action=get_client_details&id=${id}&type=${type}`);
            const json = await res.json();
            if(json.status === 'success') {
                const d = json.data;
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-type').value = type;
                document.getElementById('edit-name').value = d.Company_Name || d.Branch_Name;
                document.getElementById('edit-dept-zone').value = d.Department || d.Zone;
                document.getElementById('edit-contact-person').value = d.Contact_Person || d.Contact_Person1;
                document.getElementById('edit-phone').value = d.Contact_Number || d.Contact_Number1;
                document.getElementById('edit-address').value = d.Address;

                if(type === 'branch') {
                    document.getElementById('branch-only-fields').classList.remove('hidden');
                    document.getElementById('parent-search').value = d.parent_company_name;
                    document.getElementById('parent-id').value = d.client_head_id_fk;
                    document.getElementById('name-label').innerText = "Branch Name";
                    document.getElementById('dept-zone-label').innerText = "Zone";
                } else {
                    document.getElementById('branch-only-fields').classList.add('hidden');
                    document.getElementById('name-label').innerText = "Company Name";
                    document.getElementById('dept-zone-label').innerText = "Department";
                }
                document.getElementById('edit-modal').classList.add('is-active');
            }
        }

        // --- Parent Search Logic (Inside Edit) ---
        document.getElementById('parent-search').addEventListener('input', async (e) => {
            const query = e.target.value;
            if(query.length < 2) return;
            const res = await fetch(`?action=search_head_office&query=${query}`);
            const json = await res.json();
            const resultsBox = document.getElementById('search-results');
            resultsBox.innerHTML = '';
            if(json.data.length > 0) {
                resultsBox.classList.remove('hidden');
                json.data.forEach(item => {
                    const div = document.createElement('div');
                    div.className = "p-3 hover:bg-blue-100 cursor-pointer text-sm border-b";
                    div.innerText = item.Company_Name;
                    div.onclick = () => {
                        document.getElementById('parent-search').value = item.Company_Name;
                        document.getElementById('parent-id').value = item.client_head_id;
                        resultsBox.classList.add('hidden');
                    };
                    resultsBox.appendChild(div);
                });
            }
        });

        // --- Save Changes ---
        document.getElementById('edit-form').onsubmit = async (e) => {
            e.preventDefault();
            const type = document.getElementById('edit-type').value;
            const payload = {
                id: document.getElementById('edit-id').value,
                type: type,
                Address: document.getElementById('edit-address').value
            };

            if(type === 'head') {
                payload.Company_Name = document.getElementById('edit-name').value;
                payload.Department = document.getElementById('edit-dept-zone').value;
                payload.Contact_Person = document.getElementById('edit-contact-person').value;
                payload.Contact_Number = document.getElementById('edit-phone').value;
            } else {
                payload.Branch_Name = document.getElementById('edit-name').value;
                payload.Zone = document.getElementById('edit-dept-zone').value;
                payload.Contact_Person1 = document.getElementById('edit-contact-person').value;
                payload.Contact_Number1 = document.getElementById('edit-phone').value;
                payload.client_head_id_fk = document.getElementById('parent-id').value;
            }

            const res = await fetch('?action=update_client', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            if((await res.json()).status === 'success') {
                location.reload();
            }
        };

        // --- Delete Logic ---
        function openDeleteModal(id, type) {
            currentDelete = {id, type};
            document.getElementById('delete-modal').classList.add('is-active');
        }

        document.getElementById('confirm-delete').onclick = async () => {
            const res = await fetch('?action=delete_client', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(currentDelete)
            });
            if((await res.json()).status === 'success') location.reload();
        };

        // --- Global Search ---
        document.getElementById('global-search').addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase();
            document.querySelectorAll('#client-table-body tr').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    </script>
</body>
</html>