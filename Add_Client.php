<?php
session_start();

// --- START: SESSION & SECURITY CHECKS ---
$idleTimeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idleTimeout)) {
    session_unset(); session_destroy(); header("Location: index.php?reason=idle"); exit();
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php"); exit();
}
$current_user_id = $_SESSION['user_id'];
// --- END: SESSION & SECURITY CHECKS ---

// --- START: FLASH MESSAGE HANDLING ---
// Check for messages from a previous redirect and clear them
$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']);
// --- END: FLASH MESSAGE HANDLING ---


require_once 'connection.php';

// --- Part 1: API Request Handler (AJAX) ---
// This block must come BEFORE the POST check
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    // Action: Search for Head Offices
    if ($_GET['action'] === 'search_head_office' && isset($_GET['query'])) {
        $query = trim($_GET['query']) . '%'; // Use LIKE 'query%' for speed
        // *** MODIFIED SQL to select Department instead of Contact_Person ***
        $sql = "SELECT client_head_id, Company_Name, Department FROM Client_Head WHERE Company_Name LIKE ? AND is_deleted = FALSE LIMIT 10";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $query);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $response = ['status' => 'success', 'data' => $data];
    }
    
    // Action: Keep-alive ping for session timeout
    if ($_GET['action'] === 'keep_alive') {
        $response = ['status' => 'success'];
    }

    echo json_encode($response);
    $conn->close();
    exit();
}

// --- Part 2: Handle Form Submissions (POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    // --- ACTION: ADD HEAD OFFICE ---
    if ($action === 'add_head') {
        $company_name = trim($_POST['Company_Name']);
        $department = !empty($_POST['Department']) ? trim($_POST['Department']) : null;
        $contact_person = !empty($_POST['Contact_Person']) ? trim($_POST['Contact_Person']) : null;
        $contact_number = !empty($_POST['Contact_Number']) ? trim($_POST['Contact_Number']) : null;
        $address = !empty($_POST['Address']) ? trim($_POST['Address']) : null;

        if (empty($company_name)) {
            $_SESSION['errorMessage'] = "Company Name is required to add a Head Office.";
        } else {
            $sql = "INSERT INTO Client_Head (Company_Name, Department, Contact_Person, Contact_Number, Address, created_by) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", $company_name, $department, $contact_person, $contact_number, $address, $current_user_id);
            if ($stmt->execute()) {
                $_SESSION['successMessage'] = "Head Office '$company_name' created successfully!";
            } else {
                $_SESSION['errorMessage'] = "Error creating Head Office: " . $stmt->error;
            }
            $stmt->close();
        }
    }

    // --- ACTION: ADD BRANCH OFFICE ---
    if ($action === 'add_branch') {
        $client_head_id_fk = filter_input(INPUT_POST, 'client_head_id_fk', FILTER_VALIDATE_INT);
        $branch_name = trim($_POST['Branch_Name']);
        $contact_person1 = !empty($_POST['Contact_Person1']) ? trim($_POST['Contact_Person1']) : null;
        $contact_number1 = !empty($_POST['Contact_Number1']) ? trim($_POST['Contact_Number1']) : null;
        $contact_person2 = !empty($_POST['Contact_Person2']) ? trim($_POST['Contact_Person2']) : null;
        $contact_number2 = !empty($_POST['Contact_Number2']) ? trim($_POST['Contact_Number2']) : null;
        $zone = !empty($_POST['Zone']) ? trim($_POST['Zone']) : null;
        $address = !empty($_POST['Address']) ? trim($_POST['Address']) : null;

        if (empty($client_head_id_fk) || empty($branch_name)) {
            $_SESSION['errorMessage'] = "You must select a Head Office and provide a Branch Name.";
        } else {
            $sql = "INSERT INTO Client_Branch (client_head_id_fk, Branch_Name, Contact_Person1, Contact_Number1, Contact_Person2, Contact_Number2, Zone, Address, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssssssi", $client_head_id_fk, $branch_name, $contact_person1, $contact_number1, $contact_person2, $contact_number2, $zone, $address, $current_user_id);
            if ($stmt->execute()) {
                $_SESSION['successMessage'] = "Branch '$branch_name' created successfully!";
            } else {
                $_SESSION['errorMessage'] = "Error creating branch: " . $stmt->error;
            }
            $stmt->close();
        }
    }
    
    $conn->close();
    // --- START: P-R-G PATTERN ---
    // Redirect back to this same page using a GET request
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit();
    // --- END: P-R-G PATTERN ---
}

// This connection close is for the GET request (page load) if it needed to fetch data,
// but since this page doesn't fetch data on load, it's safe to remove.
// $conn->close(); 
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
        .tab-btn-active { border-bottom-width: 2px; border-color: #4f46e5; color: #4f46e5; font-weight: 600; }
        .tab-btn-inactive { border-bottom-width: 2px; border-color: transparent; color: #6b7280; }
        #search-results div:hover { background-color: #f3f4f6; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <div class="container mx-auto p-4 sm:p-6 lg:p-8">

        <main class="w-full max-w-2xl mx-auto">
            <div class="bg-white rounded-2xl shadow-xl p-8">
                
                <!-- Tab Buttons -->
                <div class="mb-6 flex border-b">
                    <button id="tab-head" type="button" class="py-3 px-6 tab-btn-active">Add Head Office</button>
                    <button id="tab-branch" type="button" class="py-3 px-6 tab-btn-inactive">Add Branch Office</button>
                </div>

                <!-- Global Success/Error Messages -->
                <?php if ($successMessage): ?><div id="alert-box" class="bg-green-100 border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $successMessage; ?></span></div><?php endif; ?>
                <?php if ($errorMessage): ?><div id="alert-box" class="bg-red-100 border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><span><?php echo $errorMessage; ?></span></div><?php endif; ?>

                <!-- Form 1: Add Head Office -->
                <form id="head-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="add_head">
                    
                    <div>
                        <label for="Company_Name" class="block text-sm font-medium text-gray-700 mb-1">Company Name <span class="text-red-500">*</span></label>
                        <input type="text" id="Company_Name" name="Company_Name" required class="w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="Department" class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                            <input type="text" id="Department" name="Department" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label for="Contact_Person" class="block text-sm font-medium text-gray-700 mb-1">Contact Person Name</label>
                            <input type="text" id="Contact_Person" name="Contact_Person" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                    </div>

                    <div>
                        <label for="Contact_Number" class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                        <input type="tel" id="Contact_Number" name="Contact_Number" class="w-full p-3 border border-gray-300 rounded-lg">
                    </div>

                    <div>
                        <label for="Address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea id="Address" name="Address" rows="3" class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-blue-700">Save Head Office</button>
                    </div>
                </form>

                <!-- Form 2: Add Branch Office -->
                <form id="branch-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="space-y-6 hidden">
                    <input type="hidden" name="action" value="add_branch">
                    <input type="hidden" id="client_head_id_fk" name="client_head_id_fk">
                    
                    <div>
                        <label for="head-office-search" class="block text-sm font-medium text-gray-700 mb-1">Select Head Office <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="text" id="head-office-search" placeholder="Type company name to search..." required class="w-full p-3 border border-gray-300 rounded-lg" autocomplete="off">
                            <button type="button" id="clear-search-btn" class="absolute right-3 top-3 text-gray-400 hover:text-gray-600 hidden">&times;</button>
                        </div>
                        <div id="search-results" class="border border-gray-300 rounded-b-lg -mt-1 bg-white max-h-40 overflow-y-auto hidden">
                            <!-- Dynamic results here -->
                        </div>
                    </div>

                    <div>
                        <label for="Branch_Name" class="block text-sm font-medium text-gray-700 mb-1">Branch Name <span class="text-red-500">*</span></label>
                        <input type="text" id="Branch_Name" name="Branch_Name" required class="w-full p-3 border border-gray-300 rounded-lg">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="Contact_Person1" class="block text-sm font-medium text-gray-700 mb-1">Contact Person 1</label>
                            <input type="text" id="Contact_Person1" name="Contact_Person1" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label for="Contact_Number1" class="block text-sm font-medium text-gray-700 mb-1">Contact Number 1</label>
                            <input type="tel" id="Contact_Number1" name="Contact_Number1" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="Contact_Person2" class="block text-sm font-medium text-gray-700 mb-1">Contact Person 2</label>
                            <input type="text" id="Contact_Person2" name="Contact_Person2" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                        <div>
                            <label for="Contact_Number2" class="block text-sm font-medium text-gray-700 mb-1">Contact Number 2</label>
                            <input type="tel" id="Contact_Number2" name="Contact_Number2" class="w-full p-3 border border-gray-300 rounded-lg">
                        </div>
                    </div>

                    <div>
                        <label for="Zone" class="block text-sm font-medium text-gray-700 mb-1">Zone</label>
                        <input type="text" id="Zone" name="Zone" placeholder="e.g., North, South-West" class="w-full p-3 border border-gray-300 rounded-lg">
                    </div>
                    
                    <div>
                        <label for="Address_Branch" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <textarea id="Address_Branch" name="Address" rows="3" class="w-full p-3 border border-gray-300 rounded-lg"></textarea>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-3 px-4 rounded-lg hover:bg-blue-700">Save Branch Office</button>
                    </div>
                </form>

            </div>
        </main>
    </div>

<!-- Session Timeout Modal (standard) -->
<div id="session-timeout-modal" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-xl p-6 md:p-8 w-11/12 max-w-md text-center">
        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4"><svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126z" /></svg></div>
        <h3 class="text-2xl font-bold text-gray-800">Session Expiring Soon</h3>
        <p class="text-gray-600 mt-2">You will be logged out due to inactivity.</p>
        <p class="text-gray-500 text-sm mt-4">Redirecting in <span id="redirect-countdown" class="font-semibold">10</span> seconds...</p>
        <div class="mt-6"><button id="stay-logged-in-btn" class="w-full bg-indigo-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-indigo-700 transition">Stay Logged In</button></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // --- Tab Switching ---
    const tabHead = document.getElementById('tab-head');
    const tabBranch = document.getElementById('tab-branch');
    const headForm = document.getElementById('head-form');
    const branchForm = document.getElementById('branch-form');

    tabHead.addEventListener('click', () => {
        headForm.classList.remove('hidden');
        branchForm.classList.add('hidden');
        tabHead.classList.add('tab-btn-active');
        tabHead.classList.remove('tab-btn-inactive');
        tabBranch.classList.add('tab-btn-inactive');
        tabBranch.classList.remove('tab-btn-active');
    });
    tabBranch.addEventListener('click', () => {
        headForm.classList.add('hidden');
        branchForm.classList.remove('hidden');
        tabBranch.classList.add('tab-btn-active');
        tabBranch.classList.remove('tab-btn-inactive');
        tabHead.classList.add('tab-btn-inactive');
        tabHead.classList.remove('tab-btn-active');
    });

    // --- Dynamic Head Office Search ---
    const searchBox = document.getElementById('head-office-search');
    const resultsBox = document.getElementById('search-results');
    const hiddenInput = document.getElementById('client_head_id_fk');
    const clearBtn = document.getElementById('clear-search-btn');
    let searchTimeout;

    searchBox.addEventListener('input', () => {
        const query = searchBox.value.trim();
        resultsBox.innerHTML = '';
        if (query.length < 1) {
            resultsBox.classList.add('hidden');
            return;
        }

        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(async () => {
            try {
                const response = await fetch(`Add_Client.php?action=search_head_office&query=${encodeURIComponent(query)}`);
                const result = await response.json();
                
                if (result.status === 'success' && result.data.length > 0) {
                    result.data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'p-3 border-t cursor-pointer';
                        // *** MODIFIED: Show Department instead of Contact_Person ***
                        div.innerHTML = `<strong class="block">${item.Company_Name}</strong><small class="text-gray-500">${item.Department || ''}</small>`;
                        div.dataset.id = item.client_head_id;
                        div.dataset.name = item.Company_Name;
                        
                        div.addEventListener('click', () => selectHeadOffice(item));
                        resultsBox.appendChild(div);
                    });
                    resultsBox.classList.remove('hidden');
                } else {
                    resultsBox.innerHTML = `<div class="p-3 text-gray-500">No results found.</div>`;
                    resultsBox.classList.remove('hidden');
                }
            } catch (error) {
                console.error("Error fetching head offices:", error);
                resultsBox.innerHTML = `<div class="p-3 text-red-500">Error loading results.</div>`;
                resultsBox.classList.remove('hidden');
            }
        }, 300); // Debounce
    });

    function selectHeadOffice(item) {
        hiddenInput.value = item.client_head_id;
        searchBox.value = item.Company_Name;
        searchBox.readOnly = true;
        resultsBox.classList.add('hidden');
        clearBtn.classList.remove('hidden');
    }

    clearBtn.addEventListener('click', () => {
        hiddenInput.value = '';
        searchBox.value = '';
        searchBox.readOnly = false;
        clearBtn.classList.add('hidden');
        resultsBox.classList.add('hidden');
        searchBox.focus();
    });

    // Auto-hide alerts
    const alertBox = document.getElementById('alert-box');
    if (alertBox) { setTimeout(() => { alertBox.style.transition = 'opacity 0.5s ease'; alertBox.style.opacity = '0'; setTimeout(() => alertBox.remove(), 500); }, 5000); }

    // --- Session Timeout Logic ---
    (function() {
        const sessionModal = document.getElementById('session-timeout-modal');
        const stayLoggedInBtn = document.getElementById('stay-logged-in-btn');
        const countdownElement = document.getElementById('redirect-countdown');
        const idleTimeout = <?php echo $idleTimeout; ?> * 1000;
        const redirectDelay = 10000;
        let timeoutId, countdownInterval;

        function showTimeoutModal() {
            sessionModal.classList.add('is-open');
            let countdown = redirectDelay / 1000;
            countdownElement.textContent = countdown;
            countdownInterval = setInterval(() => {
                countdown--;
                countdownElement.textContent = countdown;
                if (countdown <= 0) { clearInterval(countdownInterval); window.location.href = 'logout.php?reason=idle'; }
            }, 1000);
        }
        function startTimer() { clearTimeout(timeoutId); timeoutId = setTimeout(showTimeoutModal, idleTimeout - redirectDelay); }
        
        stayLoggedInBtn.addEventListener('click', async () => {
            clearInterval(countdownInterval);
            sessionModal.classList.remove('is-open');
            try {
                await fetch('Add_Client.php?action=keep_alive');
                startTimer();
            } catch (error) { window.location.href = 'logout.php?reason=idle'; }
        });
        startTimer();
    })();
});
</script>

</body>
</html>

