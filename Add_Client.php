<?php
ob_start(); 
require_once 'session_guard.php';
require_once 'connection.php';

// Enable error reporting to prevent blank pages and see actual database errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$current_user_id = $_SESSION['user_id'];
$idleTimeout = 1800; 

// --- START: FLASH MESSAGE HANDLING ---
$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']);
// --- END: FLASH MESSAGE HANDLING ---

// --- Part 1: API Request Handler (AJAX) ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'search_head_office' && isset($_GET['query'])) {
            $query = trim($_GET['query']) . '%';
            // Table name changed to lowercase 'client_head' to match database schema
            $sql = "SELECT client_head_id, Company_Name, Department FROM client_head WHERE Company_Name LIKE ? AND is_deleted = FALSE LIMIT 10";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $query);
            $stmt->execute();
            echo json_encode(['status' => 'success', 'data' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)]);
            exit();
        }
        if ($_GET['action'] === 'keep_alive') {
            echo json_encode(['status' => 'success']);
            exit();
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit();
    }
}

// --- Part 2: Handle Form Submissions (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_head') {
            $company_name   = trim($_POST['Company_Name'] ?? '');
            $department     = !empty($_POST['Department']) ? trim($_POST['Department']) : null;
            $contact_person = !empty($_POST['Contact_Person']) ? trim($_POST['Contact_Person']) : null;
            $contact_number = !empty($_POST['Contact_Number']) ? trim($_POST['Contact_Number']) : null;
            $address        = !empty($_POST['Address']) ? trim($_POST['Address']) : null;

            if (empty($company_name)) {
                throw new Exception("Company Name is required.");
            }

            // Table name changed to lowercase 'client_head'
            $sql = "INSERT INTO client_head (Company_Name, Department, Contact_Person, Contact_Number, Address, created_by) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", $company_name, $department, $contact_person, $contact_number, $address, $current_user_id);
            
            if ($stmt->execute()) {
                $_SESSION['successMessage'] = "Head Office '$company_name' created successfully!";
            }
            $stmt->close();
        }

        if ($action === 'add_branch') {
            $client_head_id_fk = filter_input(INPUT_POST, 'client_head_id_fk', FILTER_VALIDATE_INT);
            $branch_name       = trim($_POST['Branch_Name'] ?? '');
            $cp1               = !empty($_POST['Contact_Person1']) ? trim($_POST['Contact_Person1']) : null;
            $cn1               = !empty($_POST['Contact_Number1']) ? trim($_POST['Contact_Number1']) : null;
            $cp2               = !empty($_POST['Contact_Person2']) ? trim($_POST['Contact_Person2']) : null;
            $cn2               = !empty($_POST['Contact_Number2']) ? trim($_POST['Contact_Number2']) : null;
            $zone              = !empty($_POST['Zone']) ? trim($_POST['Zone']) : null;
            $address           = !empty($_POST['Address']) ? trim($_POST['Address']) : null;

            if (!$client_head_id_fk || empty($branch_name)) {
                throw new Exception("Head Office selection and Branch Name are required.");
            }

            // Table name changed to lowercase 'client_branch'
            $sql = "INSERT INTO client_branch (client_head_id_fk, Branch_Name, Contact_Person1, Contact_Number1, Contact_Person2, Contact_Number2, Zone, Address, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssssssi", $client_head_id_fk, $branch_name, $cp1, $cn1, $cp2, $cn2, $zone, $address, $current_user_id);
            
            if ($stmt->execute()) {
                $_SESSION['successMessage'] = "Branch '$branch_name' created successfully!";
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        $_SESSION['errorMessage'] = "Database Error: " . $e->getMessage();
    }
    
    header("Location: Add_Client.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Client</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { display: none; } .modal.is-open { display: flex; }
        .tab-btn-active { border-bottom: 2px solid #4f46e5; color: #4f46e5; font-weight: 600; }
        .tab-btn-inactive { border-bottom: 2px solid transparent; color: #6b7280; }
        #search-results div:hover { background-color: #f3f4f6; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="container mx-auto p-4 sm:p-8">
        <main class="max-w-2xl mx-auto bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
            <div class="flex border-b border-gray-200 mb-8">
                <button id="tab-head" class="py-3 px-6 tab-btn-active transition-all">Add Head Office</button>
                <button id="tab-branch" class="py-3 px-6 tab-btn-inactive transition-all">Add Branch Office</button>
            </div>

            <?php if ($successMessage): ?>
                <div class="mb-6 p-4 bg-green-50 border-l-4 border-green-500 text-green-700">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
                <div class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 text-red-700">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <form id="head-form" action="Add_Client.php" method="POST" class="space-y-5">
                <input type="hidden" name="action" value="add_head">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Name *</label>
                    <input type="text" name="Company_Name" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                        <input type="text" name="Department" class="w-full p-3 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                        <input type="text" name="Contact_Person" class="w-full p-3 border rounded-lg">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                    <input type="tel" name="Contact_Number" class="w-full p-3 border rounded-lg">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="Address" rows="3" class="w-full p-3 border rounded-lg"></textarea>
                </div>
                <button type="submit" class="w-full bg-indigo-600 text-white font-semibold py-3 rounded-lg hover:bg-indigo-700 transition">Save Head Office</button>
            </form>

            <form id="branch-form" action="Add_Client.php" method="POST" class="space-y-5 hidden">
                <input type="hidden" name="action" value="add_branch">
                <input type="hidden" id="client_head_id_fk" name="client_head_id_fk">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Head Office *</label>
                    <div class="relative">
                        <input type="text" id="head-office-search" placeholder="Search company..." required class="w-full p-3 border rounded-lg outline-none" autocomplete="off">
                        <button type="button" id="clear-search-btn" class="absolute right-3 top-3 text-gray-400 hidden text-xl">&times;</button>
                    </div>
                    <div id="search-results" class="absolute z-10 w-full bg-white border rounded-b-lg shadow-xl hidden max-h-48 overflow-y-auto"></div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Branch Name *</label>
                    <input type="text" name="Branch_Name" required class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <input type="text" name="Contact_Person1" placeholder="Contact Person 1" class="p-3 border rounded-lg">
                    <input type="tel" name="Contact_Number1" placeholder="Number 1" class="p-3 border rounded-lg">
                </div>
                <button type="submit" class="w-full bg-indigo-600 text-white font-semibold py-3 rounded-lg hover:bg-indigo-700 transition">Save Branch Office</button>
            </form>
        </main>
    </div>

    <div id="session-timeout-modal" class="modal fixed inset-0 bg-gray-900/50 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-xl p-8 max-w-sm w-full text-center shadow-2xl">
            <h3 class="text-xl font-bold mb-2">Session Expiring</h3>
            <p class="text-gray-500 mb-6">You will be logged out in <span id="redirect-countdown" class="font-bold text-indigo-600">10</span> seconds.</p>
            <button id="stay-logged-in-btn" class="w-full bg-indigo-600 text-white py-2 rounded-lg font-semibold hover:bg-indigo-700">Keep me logged in</button>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Tabs
    const tabHead = document.getElementById('tab-head');
    const tabBranch = document.getElementById('tab-branch');
    const headForm = document.getElementById('head-form');
    const branchForm = document.getElementById('branch-form');

    tabHead.onclick = () => {
        headForm.classList.remove('hidden'); branchForm.classList.add('hidden');
        tabHead.className = 'py-3 px-6 tab-btn-active'; tabBranch.className = 'py-3 px-6 tab-btn-inactive';
    };
    tabBranch.onclick = () => {
        headForm.classList.add('hidden'); branchForm.classList.remove('hidden');
        tabBranch.className = 'py-3 px-6 tab-btn-active'; tabHead.className = 'py-3 px-6 tab-btn-inactive';
    };

    // Search
    const searchBox = document.getElementById('head-office-search');
    const resultsBox = document.getElementById('search-results');
    const hiddenInput = document.getElementById('client_head_id_fk');
    const clearBtn = document.getElementById('clear-search-btn');

    searchBox.oninput = async () => {
        const q = searchBox.value.trim();
        if (q.length < 1) return resultsBox.classList.add('hidden');
        const res = await fetch(`Add_Client.php?action=search_head_office&query=${encodeURIComponent(q)}`);
        const result = await res.json();
        resultsBox.innerHTML = '';
        if (result.status === 'success' && result.data.length > 0) {
            result.data.forEach(item => {
                const div = document.createElement('div');
                div.className = 'p-3 border-b cursor-pointer hover:bg-indigo-50';
                div.innerHTML = `<p class="font-semibold text-gray-800">${item.Company_Name}</p><p class="text-xs text-gray-500">${item.Department || 'No Dept'}</p>`;
                div.onclick = () => {
                    hiddenInput.value = item.client_head_id;
                    searchBox.value = item.Company_Name; searchBox.readOnly = true;
                    resultsBox.classList.add('hidden'); clearBtn.classList.remove('hidden');
                };
                resultsBox.appendChild(div);
            });
            resultsBox.classList.remove('hidden');
        }
    };

    clearBtn.onclick = () => {
        hiddenInput.value = ''; searchBox.value = ''; searchBox.readOnly = false;
        clearBtn.classList.add('hidden');
    };

    // Session Timer
    let timeoutId, countdownInterval;
    const idleTime = <?php echo $idleTimeout; ?> * 1000;
    const modal = document.getElementById('session-timeout-modal');
    
    function resetTimer() {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => {
            modal.classList.add('is-open');
            let count = 10;
            countdownInterval = setInterval(() => {
                count--;
                document.getElementById('redirect-countdown').textContent = count;
                if(count <= 0) window.location.href = 'logout.php?reason=idle';
            }, 1000);
        }, idleTime - 10000);
    }

    document.getElementById('stay-logged-in-btn').onclick = async () => {
        modal.classList.remove('is-open');
        clearInterval(countdownInterval);
        await fetch('Add_Client.php?action=keep_alive');
        resetTimer();
    };
    resetTimer();
});
</script>
</body>
</html>