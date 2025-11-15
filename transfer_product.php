<?php
session_start();

// --- START: SESSION & SECURITY CHECKS ---
$idleTimeout = 1800; // 30 minutes
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

if (!isset($_SESSION['branch_id']) || !isset($_SESSION['branch_name'])) {
    header("Location: index.php?reason=branch_missing");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$current_user_name = $_SESSION['user_name'] ?? 'User';
$current_user_branch_id = $_SESSION['branch_id'];
$current_user_branch_name = $_SESSION['branch_name'];

require_once 'connection.php'; // defines $conn (MySQLi)


// --- Helper function: safe table existence check for mysqli ---
function tableExists($conn, $tableName) {
    // validate table name: allow only letters, numbers, underscore
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
        return false;
    }

    // escape the table name just in case (backticks)
    $safeName = $conn->real_escape_string($tableName);
    $sql = "SHOW TABLES LIKE '" . $safeName . "'";
    $res = $conn->query($sql);
    if ($res === false) return false;
    $exists = $res->num_rows > 0;
    $res->free();
    return $exists;
}


$has_b2b_table = tableExists($conn, 'branch_to_branch');

// --- Part 1: AJAX HANDLER ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => 'Invalid request'];

    try {
        // --- Get Branches ---
        if ($_GET['action'] === 'get_branches') {
            $stmt = $conn->prepare("SELECT branch_id, Name AS branch_name 
                                    FROM branch 
                                    WHERE branch_id != ? AND is_deleted = 0 
                                    ORDER BY Name");
            $stmt->bind_param("i", $current_user_branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $branches = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $response = ['status' => 'success', 'branches' => $branches];
        }

        // --- Search Product by Serial ---
        elseif ($_GET['action'] === 'search_product_sl' && isset($_GET['query'])) {
            $query = trim($_GET['query']) . '%';
            $sql = "
                SELECT sl.sl_id, sl.product_sl AS serial_number, m.model_name AS product_name
                FROM product_sl AS sl
                LEFT JOIN models AS m ON sl.model_id_fk = m.model_id
                LEFT JOIN purchased_products AS pp ON sl.purchase_id_fk = pp.purchase_id
                WHERE sl.product_sl LIKE ? 
                  AND sl.status = 0
                  AND pp.branch_id_fk = ?
                GROUP BY sl.sl_id
                LIMIT 20
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $query, $current_user_branch_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $products = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $response = ['status' => 'success', 'products' => $products];
        }

        // --- Submit Transfer ---
        elseif ($_GET['action'] === 'submit_transfer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            $product_sl_id = $data['product_sl_id'] ?? null;
            $to_branch_id = $data['to_branch_id'] ?? null;

            if (empty($product_sl_id) || empty($to_branch_id)) {
                throw new Exception("Missing product or destination branch.");
            }
            if ($to_branch_id == $current_user_branch_id) {
                throw new Exception("Cannot transfer to your own branch.");
            }

            $conn->begin_transaction();

            // Check product status
            $sql = "
                SELECT sl.sl_id, sl.status, pp.branch_id_fk
                FROM product_sl AS sl
                LEFT JOIN purchased_products AS pp ON sl.purchase_id_fk = pp.purchase_id
                WHERE sl.sl_id = ?
                FOR UPDATE
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $product_sl_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $productStatus = $result->fetch_assoc();
            $stmt->close();

            if (!$productStatus) {
                throw new Exception("Product not found.");
            }
            if ((int)$productStatus['status'] !== 0) {
                throw new Exception("Product is not in stock or cannot be transferred.");
            }
            if ((int)$productStatus['branch_id_fk'] !== (int)$current_user_branch_id) {
                throw new Exception("Product is not at your branch.");
            }

            if ($has_b2b_table) {
                $sql = "SELECT 1 FROM branch_to_branch WHERE Product_ID_FK = ? AND Received_Date IS NULL LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $product_sl_id);
                $stmt->execute();
                $stmt->store_result();
                $pendingFound = $stmt->num_rows > 0;
                $stmt->close();

                if ($pendingFound) {
                    throw new Exception("Product already has a pending transfer.");
                }

                $sql = "INSERT INTO branch_to_branch 
                        (Product_ID_FK, Send_Date, From_Branch_ID_FK, To_Branch_ID_FK, Send_User_ID_FK)
                        VALUES (?, NOW(), ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiii", $product_sl_id, $current_user_branch_id, $to_branch_id, $current_user_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Transfer table (branch_to_branch) is missing in the database.");
            }

            $conn->commit();
            $response = ['status' => 'success', 'message' => 'Product successfully sent for transfer!'];
        }

        // --- Keep Session Alive ---
        elseif ($_GET['action'] === 'keep_alive') {
            $response = ['status' => 'success', 'message' => 'Session extended.'];
        }

    } catch (Exception $e) {
        if ($conn->errno) { $conn->rollback(); }
        $response = ['status' => 'error', 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Product - Inventory</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">

<div class="flex-1 min-h-screen bg-gray-100">
    <div class="bg-white shadow-md p-4 flex justify-between items-center">
        <h1 class="text-2xl font-bold text-gray-800">Transfer Product</h1>
        <div class="flex items-center">
            <span class="text-gray-600 mr-4">Welcome, <?php echo htmlspecialchars($current_user_name); ?></span>
            <a href="logout.php" class="text-blue-600 hover:text-blue-800" title="Logout">
                <i class="fas fa-sign-out-alt fa-lg"></i>
            </a>
        </div>
    </div>

    <div class="p-6 md:p-10">
        <div class="bg-white p-6 md:p-8 rounded-lg shadow-lg max-w-4xl mx-auto">
            <h2 class="text-xl font-semibold text-gray-800 mb-6 border-b pb-4">New Branch-to-Branch Transfer</h2>

            <div class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Branch</label>
                    <input type="text" value="<?php echo htmlspecialchars($current_user_branch_name); ?>" 
                           class="w-full px-4 py-3 border border-gray-200 bg-gray-100 rounded-lg" readonly>
                </div>

                <div class="relative">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search Product Serial Number</label>
                    <input type="text" id="product-search" 
                           class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg shadow-sm" 
                           placeholder="Type serial number to search...">
                    <ul id="product-results" 
                        class="absolute z-10 w-full bg-white border border-gray-200 rounded-lg shadow-xl mt-1 max-h-60 overflow-y-auto hidden"></ul>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 min-h-[80px]">
                    <h3 class="font-medium text-gray-700">Selected Product:</h3>
                    <p id="product-name" class="text-lg font-semibold text-blue-700 mt-1">None</p>
                    <p id="product-sn" class="text-sm text-gray-500"></p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Branch</label>
                    <select id="branch-select" class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm">
                        <option value="">Loading branches...</option>
                    </select>
                </div>

                <div class="pt-4 text-right">
                    <button id="submit-transfer" 
                            class="w-full sm:w-auto bg-blue-600 text-white font-bold py-3 px-8 rounded-lg shadow-md hover:bg-blue-700">
                        Initiate Transfer
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const productSearch = document.getElementById("product-search");
    const results = document.getElementById("product-results");
    const productName = document.getElementById("product-name");
    const productSN = document.getElementById("product-sn");
    const branchSelect = document.getElementById("branch-select");
    const submitBtn = document.getElementById("submit-transfer");

    let selectedProduct = null;

    async function loadBranches() {
        const res = await fetch("?action=get_branches");
        const data = await res.json();
        branchSelect.innerHTML = '<option value="">-- Select Destination Branch --</option>';
        if (data.status === "success") {
            data.branches.forEach(b => {
                const opt = document.createElement("option");
                opt.value = b.branch_id;
                opt.textContent = b.branch_name;
                branchSelect.appendChild(opt);
            });
        }
    }

    async function searchProduct(q) {
        const res = await fetch("?action=search_product_sl&query=" + encodeURIComponent(q));
        const data = await res.json();
        results.innerHTML = "";
        if (data.status === "success" && data.products.length > 0) {
            data.products.forEach(p => {
                const li = document.createElement("li");
                li.className = "px-4 py-3 border-b hover:bg-blue-50 cursor-pointer";
                li.innerHTML = `<div class="font-medium">${p.product_name}</div>
                                <div class="text-sm text-gray-500">SN: ${p.serial_number}</div>`;
                li.onclick = () => {
                    selectedProduct = p;
                    productName.textContent = p.product_name;
                    productSN.textContent = "SN: " + p.serial_number;
                    results.classList.add("hidden");
                    productSearch.value = p.serial_number;
                };
                results.appendChild(li);
            });
            results.classList.remove("hidden");
        } else {
            results.innerHTML = '<li class="px-4 py-3 text-gray-500 text-center">No products found.</li>';
            results.classList.remove("hidden");
        }
    }

    productSearch.addEventListener("input", e => {
        const val = e.target.value.trim();
        if (val.length >= 2) searchProduct(val);
        else results.classList.add("hidden");
    });

    submitBtn.addEventListener("click", async () => {
        if (!selectedProduct) return alert("Select a product first.");
        const toBranch = branchSelect.value;
        if (!toBranch) return alert("Select destination branch.");

        const payload = {
            product_sl_id: selectedProduct.sl_id,
            to_branch_id: toBranch
        };

        submitBtn.disabled = true;
        submitBtn.textContent = "Processing...";

        const res = await fetch("?action=submit_transfer", {
            method: "POST",
            headers: {"Content-Type": "application/json"},
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        alert(data.message);
        submitBtn.disabled = false;
        submitBtn.textContent = "Initiate Transfer";

        if (data.status === "success") {
            selectedProduct = null;
            productName.textContent = "None";
            productSN.textContent = "";
            productSearch.value = "";
            branchSelect.value = "";
        }
    });

    loadBranches();
});
</script>
</body>
</html>
