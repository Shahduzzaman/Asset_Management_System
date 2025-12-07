<?php
// dashboard.php
session_start();

require_once "connection.php"; // expects $conn being a mysqli object

// Authorization
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

// Error message handling
$errorMessage = '';
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Session values used in the layout
$user_id   = (int) ($_SESSION['user_id'] ?? 0);
$user_name = htmlspecialchars($_SESSION["user_name"] ?? 'User');
$user_role = isset($_SESSION['user_role']) ? (int) $_SESSION['user_role'] : 0; // 1 = Admin

// Company info
$company_name = "Protection One (Pvt.) Ltd.";
$logo_url = "images/logo.png";
$topbarHeight = 64; // px

// --------------------------
// Dashboard data preparation
// --------------------------

// 1) Ensure branch in session (if not, load from users.branch_id_fk)
if (!isset($_SESSION['branch_id'])) {
    $stmt = $conn->prepare("SELECT branch_id_fk FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($branch_from_db);
    $stmt->fetch();
    $stmt->close();
    $_SESSION['branch_id'] = $branch_from_db ? (int)$branch_from_db : null;
}
$user_branch = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

// 2) Branch name (for display) — admins see "All Branches"
$branch_name = "All Branches";
if ($user_role !== 1 && $user_branch > 0) {
    $stmt = $conn->prepare("SELECT Name FROM branch WHERE branch_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_branch);
    $stmt->execute();
    $stmt->bind_result($bn);
    if ($stmt->fetch()) $branch_name = $bn;
    $stmt->close();
}

$applyBranchFilter = ($user_role !== 1 && $user_branch > 0);

// 3) Total Sales (count of sold_product rows, non-admins filtered by creator's branch)
if ($applyBranchFilter) {
    $sql = "
        SELECT COUNT(sp.sold_product_id) AS cnt
        FROM sold_product sp
        LEFT JOIN users u ON sp.created_by = u.user_id
        WHERE sp.is_deleted = 0 AND u.branch_id_fk = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_branch);
} else {
    $sql = "SELECT COUNT(sold_product_id) AS cnt FROM sold_product WHERE is_deleted = 0";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$stmt->bind_result($total_sales);
$stmt->fetch();
$stmt->close();
$total_sales = (int) ($total_sales ?: 0);

// 4) Total Returns (sales_return + purchase_return), scoped similarly
// sales_return
if ($applyBranchFilter) {
    $sql = "
        SELECT COUNT(sr.sales_return_id) AS cnt
        FROM sales_return sr
        LEFT JOIN users u ON sr.created_by = u.user_id
        WHERE sr.is_deleted = 0 AND u.branch_id_fk = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_branch);
} else {
    $sql = "SELECT COUNT(sales_return_id) AS cnt FROM sales_return WHERE is_deleted = 0";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$stmt->bind_result($sales_return_count);
$stmt->fetch();
$stmt->close();
$sales_return_count = (int) ($sales_return_count ?: 0);

// purchase_return (created_by exists)
if ($applyBranchFilter) {
    $sql = "
        SELECT COUNT(pr.purchase_return_id) AS cnt
        FROM purchase_return pr
        LEFT JOIN users u ON pr.created_by = u.user_id
        WHERE u.branch_id_fk = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_branch);
} else {
    $sql = "SELECT COUNT(purchase_return_id) AS cnt FROM purchase_return";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$stmt->bind_result($purchase_return_count);
$stmt->fetch();
$stmt->close();
$purchase_return_count = (int) ($purchase_return_count ?: 0);

$total_returns = $sales_return_count + $purchase_return_count;

// 5) Total Stock
// 5a) Serialized in-stock (product_sl.status = 0) resolved current branch via latest branch_to_branch or the purchase branch (purchased_products.branch_id_fk)
if ($applyBranchFilter) {
    // count serialized items whose resolved branch = user's branch
    $sql = "
        SELECT COUNT(*) AS cnt
        FROM product_sl ps
        LEFT JOIN purchased_products pp ON ps.purchase_id_fk = pp.purchase_id
        WHERE ps.status = 0
          AND (
             COALESCE(
               (SELECT bt.To_Branch_ID_FK
                FROM branch_to_branch bt
                WHERE bt.Product_ID_FK = ps.sl_id
                  AND bt.is_deleted = 0
                ORDER BY bt.branch_to_branch_id DESC
                LIMIT 1),
               pp.branch_id_fk
             ) = ?
          )
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_branch);
} else {
    $sql = "SELECT COUNT(*) AS cnt FROM product_sl WHERE status = 0";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$stmt->bind_result($serialized_in_stock);
$stmt->fetch();
$stmt->close();
$serialized_in_stock = (int) ($serialized_in_stock ?: 0);

// 5b) Non-serialized available = sum(purchased_products.quantity WHERE branch) - sum(sold_product.Quantity WHERE sold by branch users)
// Purchased sum (branch-scoped)
if ($applyBranchFilter) {
    $sql = "
        SELECT IFNULL(SUM(pp.quantity),0) AS purchased_qty
        FROM purchased_products pp
        WHERE pp.is_deleted = 0 AND pp.branch_id_fk = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_branch);
} else {
    $sql = "SELECT IFNULL(SUM(quantity),0) AS purchased_qty FROM purchased_products WHERE is_deleted = 0";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$stmt->bind_result($non_serial_purchased);
$stmt->fetch();
$stmt->close();
$non_serial_purchased = (int) ($non_serial_purchased ?: 0);

// Sold sum (sold by users of that branch)
if ($applyBranchFilter) {
    $sql = "
        SELECT IFNULL(SUM(sp.Quantity),0) AS sold_qty
        FROM sold_product sp
        LEFT JOIN users u ON sp.created_by = u.user_id
        WHERE sp.is_deleted = 0 AND u.branch_id_fk = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_branch);
} else {
    $sql = "SELECT IFNULL(SUM(Quantity),0) AS sold_qty FROM sold_product WHERE is_deleted = 0";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$stmt->bind_result($non_serial_sold);
$stmt->fetch();
$stmt->close();
$non_serial_sold = (int) ($non_serial_sold ?: 0);

$non_serial_available = $non_serial_purchased - $non_serial_sold;
if ($non_serial_available < 0) $non_serial_available = 0;

$total_stock_items = $serialized_in_stock + $non_serial_available;

// 6) Category-wise totals (simple totals only: serialized_in_branch + non-serialized available for that category)
$categoryStocks = [];
$catSql = "SELECT category_id, category_name FROM categories WHERE is_deleted = 0 ORDER BY category_name";
if ($res = $conn->query($catSql)) {
    while ($cat = $res->fetch_assoc()) {
        $cid = (int)$cat['category_id'];

        // serialized count for this category (status=0, resolved branch)
        if ($applyBranchFilter) {
            $serializedCatSql = "
                SELECT COUNT(*) AS cnt
                FROM product_sl ps
                LEFT JOIN purchased_products pp ON ps.purchase_id_fk = pp.purchase_id
                LEFT JOIN models m ON ps.model_id_fk = m.model_id
                WHERE m.category_id = {$cid} AND ps.status = 0
                  AND (
                    COALESCE(
                      (SELECT bt.To_Branch_ID_FK FROM branch_to_branch bt WHERE bt.Product_ID_FK = ps.sl_id AND bt.is_deleted = 0 ORDER BY bt.branch_to_branch_id DESC LIMIT 1),
                      pp.branch_id_fk
                    ) = {$user_branch}
                  )
            ";
        } else {
            $serializedCatSql = "
                SELECT COUNT(*) AS cnt
                FROM product_sl ps
                LEFT JOIN models m ON ps.model_id_fk = m.model_id
                WHERE m.category_id = {$cid} AND ps.status = 0
            ";
        }
        $s_cnt = (int) $conn->query($serializedCatSql)->fetch_column();

        // non-serialized purchased for this category (in the branch if filtered)
        $nonSerialPurchasedCatSql = "
            SELECT IFNULL(SUM(pp.quantity),0) AS purchased_qty
            FROM purchased_products pp
            LEFT JOIN models m ON pp.model_id = m.model_id
            WHERE pp.is_deleted = 0 AND m.category_id = {$cid}
        ";
        if ($applyBranchFilter) $nonSerialPurchasedCatSql .= " AND pp.branch_id_fk = {$user_branch}";
        $p_qty = (int) $conn->query($nonSerialPurchasedCatSql)->fetch_column();

        // non-serialized sold for this category by users of this branch
        $nonSerialSoldCatSql = "
            SELECT IFNULL(SUM(sp.Quantity),0) AS sold_qty
            FROM sold_product sp
            LEFT JOIN models m ON sp.model_id_fk = m.model_id
            LEFT JOIN users u ON sp.created_by = u.user_id
            WHERE sp.is_deleted = 0 AND m.category_id = {$cid}
        ";
        if ($applyBranchFilter) $nonSerialSoldCatSql .= " AND u.branch_id_fk = {$user_branch}";
        $s_qty = (int) $conn->query($nonSerialSoldCatSql)->fetch_column();

        $non_serial_cat_available = $p_qty - $s_qty;
        if ($non_serial_cat_available < 0) $non_serial_cat_available = 0;

        $total_cat = $s_cnt + $non_serial_cat_available;

        $categoryStocks[] = [
            'category_id' => $cid,
            'category_name' => $cat['category_name'],
            'count' => $total_cat
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Dashboard — <?php echo htmlspecialchars($company_name); ?></title>

<!-- Tailwind CDN -->
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Inter', sans-serif; margin:0; height:100vh; }
    :root { --topbar-h: <?php echo $topbarHeight; ?>px; }
    header { height: var(--topbar-h); line-height: var(--topbar-h); }
    .app-shell { display:flex; height: calc(100vh - var(--topbar-h)); width:100vw; overflow: hidden; }
    .sidebar-transition { transition: width 180ms ease, transform 180ms ease; }
    .sidebar-scroll::-webkit-scrollbar { width: 8px; }
    .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.12); border-radius: 4px; }
    #appFrame { width:100%; height:100%; border:0; display:block; }
    .sidebar-collapsed { width: 5rem !important; }
    .sidebar-expanded { width: 16rem !important; }
    .topbar-content { display:flex; align-items:center; height:var(--topbar-h); }
    /* smaller default link text for consistency */
    .sidebar .link-text, .sidebar #sidebarLabel { font-size: 0.80rem; } /* ~ text-xs */
</style>
</head>
<body class="bg-gray-100">

<!-- Top Navbar -->
<header class="fixed top-0 left-0 right-0 z-40 bg-white shadow-sm border-b">
  <div class="topbar-content px-4 justify-between">
    <div class="flex items-center gap-3">
      <!-- Global sidebar toggle -->
      <button id="globalSidebarToggle" class="p-2 rounded-md hover:bg-gray-100" aria-label="Toggle sidebar" title="Toggle sidebar">
        <svg id="globalSidebarToggleIcon" class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <a href="dashboard.php" class="flex items-center gap-3">
        <img src="<?php echo $logo_url; ?>" alt="Logo" class="w-10 h-10 object-contain rounded-sm" onerror="this.style.display='none'">
        <div>
          <div class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($company_name); ?></div>
          <div class="text-xs text-gray-500">Asset Management System</div>
        </div>
      </a>
    </div>

    <!-- User dropdown -->
    <div class="flex items-center gap-4">
      <div class="relative" id="userDropdownWrap">
        <button id="userDropdownBtn" class="flex items-center gap-3 text-sm px-3 py-2 rounded-md hover:bg-gray-50 focus:ring-2 focus:ring-indigo-300" aria-expanded="false" aria-haspopup="true">
          <span class="text-gray-700 font-medium"><?php echo $user_name; ?></span>
          <svg class="w-4 h-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.584l3.71-4.354a.75.75 0 011.14.976l-4 4.7a.75.75 0 01-1.08 0l-4-4.7a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
          </svg>
        </button>

        <div id="userDropdownMenu" class="hidden absolute right-0 mt-2 w-44 bg-white border rounded-md shadow-lg py-1 z-50">
          <a href="my_profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" target="appFrame" onclick="loadIntoFrame(event,'my_profile.php')">My Profile</a>
          <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Logout</a>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- spacer -->
<div style="height: var(--topbar-h);"></div>

<div class="app-shell">

  <!-- Sidebar -->
  <aside id="sidebar"
         class="sidebar-transition sidebar-expanded sidebar p-3 sidebar-scroll bg-white border-r"
         style="width:16rem; min-width:5rem; max-width:22rem; overflow:auto;">
    <div class="flex items-center justify-between mb-3 px-1">
      <div class="flex items-center gap-2">
        <span id="sidebarLabel" class="text-sm font-semibold text-gray-700">Navigation</span>
      </div>
      <div class="hidden md:block text-xs text-gray-500">Role: <?php echo $user_role === 1 ? 'Admin' : 'User'; ?></div>
    </div>

    <nav id="sidebarNav" class="space-y-1">
      <!-- text-only links with reduced font size -->
      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="stock_monitor.php">
          <span class="link-text text-xs">Stock Monitor</span>
      </a>
      
      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="product_setup.php">
          <span class="link-text text-xs">Product Setup</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="add_vendor.php">
          <span class="link-text text-xs">Add Vendor</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="Add_Client.php">
          <span class="link-text text-xs">Add Client</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="add_work_order.php">
          <span class="link-text text-xs">Add Work Order</span>
      </a>

      <hr class="my-2">

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-green-50 text-gray-700" data-target="purchase_product.php">
          <span class="link-text text-xs">Purchased Product</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-blue-50 text-gray-700" data-target="add_to_cart.php">
          <span class="link-text text-xs">Sold Product</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-blue-50 text-gray-700" data-target="Purchase_Return.php">
          <span class="link-text text-xs">Purchase Return</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-blue-50 text-gray-700" data-target="Sales_Return.php">
          <span class="link-text text-xs">Sales Return</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-green-50 text-gray-700" data-target="make_payment.php">
          <span class="link-text text-xs">Make Payment</span>
      </a>

      <hr class="my-2">

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="product_list.php">
          <span class="link-text text-xs">View Product</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="view_vendor.php">
          <span class="link-text text-xs">View Vendors</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="view_client.php">
          <span class="link-text text-xs">View Clients</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="ledger.php">
          <span class="link-text text-xs">Vendor Ledger</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-teal-50 text-gray-700" data-target="invoice_list.php">
          <span class="link-text text-xs">Invoice</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-teal-50 text-gray-700" data-target="Purchased_Return_List.php">
          <span class="link-text text-xs font-semibold">Purchase Return List</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-teal-50 text-gray-700" data-target="returns.php">
          <span class="link-text text-xs">Sales Return List</span>
      </a>

      <?php if ($user_role === 1): ?>
          <hr class="my-2">
          <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-purple-50 text-gray-700" data-target="create_user.php">
              <span class="link-text text-xs">Create User</span>
          </a>
          <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-purple-50 text-gray-700" data-target="manage_users.php">
              <span class="link-text text-xs">Manage Users</span>
          </a>
      <?php endif; ?>

      <a href="logout.php" class="block px-3 py-2 rounded-md hover:bg-red-50 text-gray-700">
          <span class="link-text text-xs">Logout</span>
      </a>
    </nav>
  </aside>


  <!-- Main Content -->
  <main id="mainContent" style="flex:1; min-width:0; overflow:auto;">
      <?php if (!empty($errorMessage)): ?>
          <div id="error-alert-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative m-4" role="alert">
              <span class="block sm:inline"><?php echo htmlspecialchars($errorMessage); ?></span>
          </div>
      <?php endif; ?>

      <!-- Updated Welcome Card (overview) -->
      <div id="welcomeCard" class="m-4 bg-white rounded-lg shadow p-8 h-[calc(100vh - var(--topbar-h) - 32px)] overflow-auto">

        <h2 class="text-2xl font-bold text-gray-800 mb-1">
            Welcome back, <?php echo $user_name; ?> 👋
        </h2>
        <p class="text-gray-600 mb-6">
            Branch:
            <b><?php echo ($user_role == 1) ? "All Branches (Admin)" : htmlspecialchars($branch_name); ?></b>
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">

            <div class="bg-indigo-50 p-6 rounded-xl shadow-inner">
                <h3 class="text-lg font-semibold text-indigo-700">Total Sales</h3>
                <p class="text-3xl font-bold mt-2"><?php echo number_format($total_sales); ?></p>
                <p class="text-xs text-gray-600 mt-1">Sales recorded in <?php echo $applyBranchFilter ? 'your branch' : 'all branches'; ?></p>
            </div>

            <div class="bg-red-50 p-6 rounded-xl shadow-inner">
                <h3 class="text-lg font-semibold text-red-700">Total Returns</h3>
                <p class="text-3xl font-bold mt-2"><?php echo number_format($total_returns); ?></p>
                <p class="text-xs text-gray-600 mt-1">Sales + Purchase returns</p>
            </div>

            <div class="bg-green-50 p-6 rounded-xl shadow-inner">
                <h3 class="text-lg font-semibold text-green-700">Total Stock</h3>
                <p class="text-3xl font-bold mt-2"><?php echo number_format($total_stock_items); ?></p>
                <p class="text-xs text-gray-600 mt-1">Serialized + Non-Serialized</p>
            </div>

        </div>

        <h3 class="text-xl font-bold text-gray-700 mb-3">Stock by Category</h3>

        <?php if (empty($categoryStocks)): ?>
            <p class="text-gray-500">No category data available.</p>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($categoryStocks as $c): ?>
                    <div class="border rounded-lg p-4 bg-gray-50 hover:shadow transition">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-medium text-gray-700"><?php echo htmlspecialchars($c['category_name']); ?></div>
                                <div class="text-xs text-gray-500">Items in stock</div>
                            </div>
                            <div class="text-2xl font-bold text-indigo-600"><?php echo number_format($c['count']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

      </div>

      <!-- iframe loader -->
      <div id="frameWrap" class="hidden" style="height: calc(100vh - var(--topbar-h));">
          <iframe id="appFrame" name="appFrame" src="about:blank" title="Application Frame" frameborder="0"></iframe>
      </div>
  </main>
</div>

<!-- Mobile slide-over sidebar (unchanged) -->
<div id="mobileSidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r p-4 transform -translate-x-full transition-transform md:hidden overflow-auto">
  <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
          <img src="<?php echo $logo_url; ?>" alt="Logo" class="w-8 h-8 object-contain rounded-sm" onerror="this.style.display='none'">
          <div class="text-sm font-semibold"><?php echo htmlspecialchars($company_name); ?></div>
      </div>
      <button id="mobileSidebarClose" class="p-2 rounded-md hover:bg-gray-100" aria-label="Close sidebar">
        <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
  </div>

  <nav class="space-y-2">
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700 text-xs" onclick="loadIntoFrame(event,'Purchased_Return_List.php')">Purchase Return List</a>
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700 text-xs" onclick="loadIntoFrame(event,'purchase_product.php')">Purchased Product</a>
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700 text-xs" onclick="loadIntoFrame(event,'add_to_cart.php')">Sold Product</a>
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700 text-xs" onclick="loadIntoFrame(event,'my_profile.php')">My Profile</a>
      <a href="logout.php" class="block px-3 py-2 rounded-md hover:bg-red-50 text-gray-700 text-xs">Logout</a>
  </nav>
</div>

<!-- JS (unchanged logic) -->
<script>
(function(){
    const sidebar = document.getElementById('sidebar');
    const links = document.querySelectorAll('.sidebar-link');
    const appFrame = document.getElementById('appFrame');
    const frameWrap = document.getElementById('frameWrap');
    const welcomeCard = document.getElementById('welcomeCard');
    const userBtn = document.getElementById('userDropdownBtn');
    const userMenu = document.getElementById('userDropdownMenu');
    const globalToggle = document.getElementById('globalSidebarToggle');
    const globalToggleIcon = document.getElementById('globalSidebarToggleIcon');
    const STORAGE_KEY = 'ams_sidebar_expanded';

    const mobileSidebar = document.getElementById('mobileSidebar');
    const mobileSidebarClose = document.getElementById('mobileSidebarClose');

    function isExpanded() { return localStorage.getItem(STORAGE_KEY) !== '0'; }

    function applySidebarState(expanded) {
        if (expanded) {
            sidebar.classList.remove('sidebar-collapsed');
            sidebar.classList.add('sidebar-expanded');
            document.querySelectorAll('.link-text').forEach(el => el.classList.remove('hidden'));
            document.getElementById('sidebarLabel').classList.remove('hidden');
            globalToggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>';
        } else {
            sidebar.classList.add('sidebar-collapsed');
            sidebar.classList.remove('sidebar-expanded');
            document.querySelectorAll('.link-text').forEach(el => el.classList.add('hidden'));
            document.getElementById('sidebarLabel').classList.add('hidden');
            globalToggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 6-12 6"/>';
        }
    }

    applySidebarState(isExpanded());

    globalToggle.addEventListener('click', function(e){
        if (window.innerWidth < 768) {
            mobileSidebar.style.transform = 'translateX(0)';
        } else {
            const expanded = isExpanded();
            localStorage.setItem(STORAGE_KEY, expanded ? '0' : '1');
            applySidebarState(!expanded);
        }
    });

    if (mobileSidebarClose) {
        mobileSidebarClose.addEventListener('click', function(){ mobileSidebar.style.transform = 'translateX(-100%)'; });
    }

    document.addEventListener('click', function(e){
        if (window.innerWidth < 768 && mobileSidebar.style.transform === 'translateX(0)') {
            if (!mobileSidebar.contains(e.target) && !globalToggle.contains(e.target)) {
                mobileSidebar.style.transform = 'translateX(-100%)';
            }
        }
    });

    userBtn.addEventListener('click', function(e){
        e.stopPropagation();
        userMenu.classList.toggle('hidden');
        userBtn.setAttribute('aria-expanded', String(!userMenu.classList.contains('hidden')));
    });
    document.addEventListener('click', function(){ if (!userMenu.classList.contains('hidden')) userMenu.classList.add('hidden'); });

    function setActiveLink(target) {
        links.forEach(a => {
            if (a.dataset && a.dataset.target === target) {
                a.classList.add('bg-indigo-50', 'font-semibold');
            } else {
                a.classList.remove('bg-indigo-50', 'font-semibold');
            }
        });
    }

    window.loadIntoFrame = function(evt, target) {
        if (evt) evt.preventDefault();
        if (!target) return;
        setActiveLink(target);
        if (welcomeCard) welcomeCard.classList.add('hidden');
        frameWrap.classList.remove('hidden');
        appFrame.src = target;
    };

    links.forEach(a => {
        a.addEventListener('click', function(e){
            const tgt = this.getAttribute('data-target');
            if (!tgt) return;
            loadIntoFrame(e, tgt);
            if (window.innerWidth < 768) mobileSidebar.style.transform = 'translateX(-100%)';
        });
    });

    const errorAlertBox = document.getElementById('error-alert-box');
    if (errorAlertBox) {
        setTimeout(() => {
            errorAlertBox.style.transition = 'opacity 0.5s ease';
            errorAlertBox.style.opacity = '0';
            setTimeout(() => errorAlertBox.remove(), 500);
        }, 5000);
    }

    const params = new URLSearchParams(window.location.search);
    if (params.has('page')) {
        const page = params.get('page');
        const match = Array.from(links).find(l => l.dataset && l.dataset.target === page);
        if (match) {
            loadIntoFrame(null, page);
            setActiveLink(page);
        }
    }
})();
</script>
</body>
</html>
