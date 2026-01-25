<?php
ob_start(); 
require_once 'session_guard.php';
require_once 'connection.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$current_user_id = $_SESSION['user_id'];
$idleTimeout = 1800; 

$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']);

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['action'] === 'search_head_office' && isset($_GET['query'])) {
            $query = trim($_GET['query']) . '%';
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
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
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
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <main class="w-full max-w-2xl mx-auto bg-white rounded-2xl shadow-xl p-8">
            <div class="mb-6 flex border-b">
                <button id="tab-head" type="button" class="py-3 px-6 tab-btn-active">Add Head Office</button>
                <button id="tab-branch" type="button" class="py-3 px-6 tab-btn-inactive">Add Branch Office</button>
            </div>

            <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
            <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>

            <form id="head-form" action="Add_Client.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="add_head">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Company Name *</label>
                    <input type="text" name="Company_Name" required class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <input type="text" name="Department" placeholder="Department" class="w-full p-3 border border-gray-300 rounded-lg">
                    <input type="text" name="Contact_Person" placeholder="Contact Person Name" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                <input type="tel" name="Contact_Number" placeholder="Contact Number" class="w-full p-3 border border-gray-300 rounded-lg">
                <textarea name="Address" rows="3" placeholder="Address" class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-blue-700">Save Head Office</button>
            </form>

            <form id="branch-form" action="Add_Client.php" method="POST" class="space-y-6 hidden">
                <input type="hidden" name="action" value="add_branch">
                <input type="hidden" id="client_head_id_fk" name="client_head_id_fk">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Head Office *</label>
                    <div class="relative">
                        <input type="text" id="head-office-search" placeholder="Search Company..." required class="w-full p-3 border border-gray-300 rounded-lg" autocomplete="off">
                        <button type="button" id="clear-search-btn" class="absolute right-3 top-3 text-gray-400 hidden text-xl">&times;</button>
                    </div>
                    <div id="search-results" class="border border-gray-300 rounded-b-lg -mt-1 bg-white max-h-40 overflow-y-auto hidden shadow-lg"></div>
                </div>

                <input type="text" name="Branch_Name" placeholder="Branch Name *" required class="w-full p-3 border border-gray-300 rounded-lg">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <input type="text" name="Contact_Person1" placeholder="Contact Person 1" class="w-full p-3 border border-gray-300 rounded-lg">
                    <input type="tel" name="Contact_Number1" placeholder="Contact Number 1" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <input type="text" name="Contact_Person2" placeholder="Contact Person 2" class="w-full p-3 border border-gray-300 rounded-lg">
                    <input type="tel" name="Contact_Number2" placeholder="Contact Number 2" class="w-full p-3 border border-gray-300 rounded-lg">
                </div>

                <input type="text" name="Zone" placeholder="Zone (e.g. North)" class="w-full p-3 border border-gray-300 rounded-lg">
                <textarea name="Address" rows="3" placeholder="Branch Address" class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
                
                <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-blue-700">Save Branch Office</button>
            </form>
        </main>
    </div>

    <div id="session-timeout-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg p-8 max-w-sm w-full text-center">
            <h3 class="text-xl font-bold mb-2">Session Expiring</h3>
            <p class="text-gray-500 mb-6">Redirecting in <span id="redirect-countdown" class="font-bold text-indigo-600">10</span> seconds.</p>
            <button id="stay-logged-in-btn" class="w-full bg-indigo-600 text-white py-2 rounded-lg font-semibold">Stay Logged In</button>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
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
                div.className = 'p-3 border-t cursor-pointer hover:bg-gray-50';
                div.innerHTML = `<strong>${item.Company_Name}</strong><br><small>${item.Department || ''}</small>`;
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
        modal.classList.remove('is-open'); clearInterval(countdownInterval);
        await fetch('Add_Client.php?action=keep_alive'); resetTimer();
    };
    resetTimer();
});
</script>
</body>
</html>