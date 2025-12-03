<?php
session_start();

// Authorization logic unchanged
if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

// Error message handling (same behavior as before)
$errorMessage = '';
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Session values used in the layout
$user_name = htmlspecialchars($_SESSION["user_name"]);
$user_role = isset($_SESSION["user_role"]) ? (int)$_SESSION["user_role"] : 0;
$company_name = "Your Company"; // change if desired
$logo_url = "assets/logo.png"; // replace as needed
$topbarHeight = 64; // px - keep in sync with CSS if you change it
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Dashboard — <?php echo htmlspecialchars($company_name); ?></title>

    <!-- Tailwind CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; margin:0; height:100vh; }
        :root { --topbar-h: <?php echo $topbarHeight; ?>px; }
        /* fixed topbar */
        header { height: var(--topbar-h); line-height: var(--topbar-h); }
        /* container spanning full width and height */
        .app-shell { display:flex; height: calc(100vh - var(--topbar-h)); width:100vw; overflow: hidden; }
        /* sidebar transition and sizing */
        .sidebar-transition { transition: width 180ms ease, transform 180ms ease; }
        .sidebar-scroll::-webkit-scrollbar { width: 8px; }
        .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.12); border-radius: 4px; }
        /* iframe should fill remaining area and be scrollable */
        #appFrame { width:100%; height:100%; border:0; display:block; }
        /* when collapsed narrow width */
        .sidebar-collapsed { width: 5rem !important; } /* 80px */
        .sidebar-expanded { width: 16rem !important; } /* 256px */
        /* ensure topbar contents vertically centered */
        .topbar-content { display:flex; align-items:center; height:var(--topbar-h); }
    </style>
</head>
<body class="bg-gray-100">

<!-- Top Navbar (fixed) -->
<header class="fixed top-0 left-0 right-0 z-40 bg-white shadow-sm border-b">
  <div class="topbar-content px-4 justify-between">
    <div class="flex items-center gap-3">
      <!-- Sidebar toggle at the top-left -->
      <button id="globalSidebarToggle" class="p-2 rounded-md hover:bg-gray-100" aria-label="Toggle sidebar" title="Toggle sidebar">
        <svg id="globalSidebarToggleIcon" class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <a href="dashboard.php" class="flex items-center gap-3">
        <img src="<?php echo $logo_url; ?>" alt="Logo" class="w-10 h-10 object-contain rounded-sm border" onerror="this.style.display='none'">
        <div>
          <div class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($company_name); ?></div>
          <div class="text-xs text-gray-500">Asset Management System</div>
        </div>
      </a>
    </div>

    <!-- Right: Username dropdown -->
    <div class="flex items-center gap-4">
      <div class="relative" id="userDropdownWrap">
        <button id="userDropdownBtn" class="flex items-center gap-3 text-sm px-3 py-2 rounded-md hover:bg-gray-50 focus:ring-2 focus:ring-indigo-300" aria-expanded="false" aria-haspopup="true">
          <span class="text-gray-700 font-medium"><?php echo $user_name; ?></span>
          <svg class="w-4 h-4 text-gray-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.584l3.71-4.354a.75.75 0 011.14.976l-4 4.7a.75.75 0 01-1.08 0l-4-4.7a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
          </svg>
        </button>

        <!-- Dropdown menu -->
        <div id="userDropdownMenu" class="hidden absolute right-0 mt-2 w-44 bg-white border rounded-md shadow-lg py-1 z-50">
          <a href="my_profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" target="appFrame" onclick="loadIntoFrame(event,'my_profile.php')">My Profile</a>
          <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Logout</a>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- spacer for topbar -->
<div style="height: var(--topbar-h);"></div>

<!-- App Shell: sidebar + main iframe (fills remaining viewport) -->
<div class="app-shell">

  <!-- Sidebar: static (desktop) / slide-over (mobile) -->
  <aside id="sidebar"
         class="sidebar-transition sidebar-expanded bg-white border-r p-3 sidebar-scroll"
         style="width:16rem; min-width:5rem; max-width:22rem; overflow:auto;">
    <div class="flex items-center justify-between mb-3 px-1">
      <div class="flex items-center gap-2">
        <span id="sidebarLabel" class="text-sm font-semibold text-gray-700">Navigation</span>
      </div>
      <div class="hidden md:block text-sm text-gray-500">Role: <?php echo $user_role === 1 ? 'Admin' : 'User'; ?></div>
    </div>

    <nav id="sidebarNav" class="space-y-1">
      <!-- Links set data-target to page loaded into iframe (text-only, no icons) -->
      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="product_setup.php">
          <span class="link-text">Product Setup</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="add_vendor.php">
          <span class="link-text">Add Vendor</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="Add_Client.php">
          <span class="link-text">Add Client</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" data-target="add_work_order.php">
          <span class="link-text">Add Work Order</span>
      </a>

      <hr class="my-2">

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-green-50 text-gray-700" data-target="purchase_product.php">
          <span class="link-text">Purchased Product</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-blue-50 text-gray-700" data-target="add_to_cart.php">
          <span class="link-text">Sold Product</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-blue-50 text-gray-700" data-target="Purchase_Return.php">
          <span class="link-text">Purchase Return</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-blue-50 text-gray-700" data-target="Sales_Return.php">
          <span class="link-text">Sales Return</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-green-50 text-gray-700" data-target="make_payment.php">
          <span class="link-text">Make Payment</span>
      </a>

      <hr class="my-2">

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="product_list.php">
          <span class="link-text">View Product</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="view_vendor.php">
          <span class="link-text">View Vendors</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="view_client.php">
          <span class="link-text">View Clients</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-cyan-50 text-gray-700" data-target="ledger.php">
          <span class="link-text">Vendor Ledger</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-teal-50 text-gray-700" data-target="invoice_list.php">
          <span class="link-text">Invoice</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-teal-50 text-gray-700" data-target="Purchased_Return_List.php">
          <span class="link-text font-semibold">Purchase Return List</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-teal-50 text-gray-700" data-target="returns.php">
          <span class="link-text">Sales Return List</span>
      </a>

      <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-gray-50 text-gray-700" data-target="my_profile.php">
          <span class="link-text">My Profile</span>
      </a>

      <a href="logout.php" class="block px-3 py-2 rounded-md hover:bg-red-50 text-gray-700">
          <span class="link-text">Logout</span>
      </a>

      <?php if ($user_role === 1): ?>
          <hr class="my-2">
          <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-purple-50 text-gray-700" data-target="create_user.php">
              <span class="link-text">Create User</span>
          </a>
          <a href="#" class="sidebar-link block px-3 py-2 rounded-md hover:bg-purple-50 text-gray-700" data-target="manage_users.php">
              <span class="link-text">Manage Users</span>
          </a>
      <?php endif; ?>
    </nav>
  </aside>

  <!-- Main Content: iframe fills all vertical space and is scrollable -->
  <main id="mainContent" style="flex:1; min-width:0; overflow:auto;">
      <!-- error alert area -->
      <?php if (!empty($errorMessage)): ?>
          <div id="error-alert-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative m-4" role="alert">
              <span class="block sm:inline"><?php echo htmlspecialchars($errorMessage); ?></span>
          </div>
      <?php endif; ?>

      <!-- Welcome card (hidden when iframe is active) -->
      <div id="welcomeCard" class="m-4 bg-white rounded-lg shadow p-8 h-[calc(100vh - var(--topbar-h) - 32px)] flex flex-col justify-center items-center">
          <h2 class="text-2xl font-semibold text-gray-800 mb-2">Welcome back, <?php echo $user_name; ?> 👋</h2>
          <p class="text-gray-600 mb-6">Click any item in the left navigation to open that page without reloading the dashboard layout.</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 w-full max-w-2xl">
              <button onclick="loadIntoFrame(null,'Purchased_Return_List.php')" class="w-full bg-teal-50 p-4 rounded-lg border hover:shadow text-left">
                  <div class="text-lg font-semibold text-teal-700">Purchase Return List</div>
                  <div class="text-sm text-gray-500 mt-1">View all returned products</div>
              </button>
              <button onclick="loadIntoFrame(null,'purchase_product.php')" class="w-full bg-green-50 p-4 rounded-lg border hover:shadow text-left">
                  <div class="text-lg font-semibold text-green-700">Purchased Products</div>
                  <div class="text-sm text-gray-500 mt-1">Add or view purchases</div>
              </button>
          </div>
      </div>

      <!-- iframe container: occupies full remaining height (infinite vertical scroll inside iframe) -->
      <div id="frameWrap" class="hidden" style="height: calc(100vh - var(--topbar-h));">
          <iframe id="appFrame" name="appFrame" src="about:blank" title="Application Frame" frameborder="0"></iframe>
      </div>
  </main>
</div>

<!-- Mobile slide-over sidebar for small screens -->
<div id="mobileSidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r p-4 transform -translate-x-full transition-transform md:hidden overflow-auto">
  <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
          <img src="<?php echo $logo_url; ?>" alt="Logo" class="w-8 h-8 object-contain rounded-sm border" onerror="this.style.display='none'">
          <div class="text-sm font-semibold"><?php echo htmlspecialchars($company_name); ?></div>
      </div>
      <button id="mobileSidebarClose" class="p-2 rounded-md hover:bg-gray-100" aria-label="Close sidebar">
        <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
  </div>

  <!-- replicate a compact copy of nav (for mobile) -->
  <nav class="space-y-2">
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" onclick="loadIntoFrame(event,'Purchased_Return_List.php')">Purchase Return List</a>
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" onclick="loadIntoFrame(event,'purchase_product.php')">Purchased Product</a>
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" onclick="loadIntoFrame(event,'add_to_cart.php')">Sold Product</a>
      <a href="#" class="block px-3 py-2 rounded-md hover:bg-indigo-50 text-gray-700" onclick="loadIntoFrame(event,'my_profile.php')">My Profile</a>
      <a href="logout.php" class="block px-3 py-2 rounded-md hover:bg-red-50 text-gray-700">Logout</a>
  </nav>
</div>

<!-- JS -->
<script>
(function(){
    // Elements
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

    // mobile sidebar
    const mobileSidebar = document.getElementById('mobileSidebar');
    const mobileSidebarClose = document.getElementById('mobileSidebarClose');

    // helper: is expanded
    function isExpanded() { return localStorage.getItem(STORAGE_KEY) !== '0'; } // default true

    // apply state
    function applySidebarState(expanded) {
        if (expanded) {
            sidebar.classList.remove('sidebar-collapsed');
            sidebar.classList.add('sidebar-expanded');
            // show link text
            document.querySelectorAll('.link-text').forEach(el => el.classList.remove('hidden'));
            document.getElementById('sidebarLabel').classList.remove('hidden');
            // update global toggle icon (hamburger)
            globalToggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>';
        } else {
            sidebar.classList.add('sidebar-collapsed');
            sidebar.classList.remove('sidebar-expanded');
            // hide link text
            document.querySelectorAll('.link-text').forEach(el => el.classList.add('hidden'));
            document.getElementById('sidebarLabel').classList.add('hidden');
            // update global toggle icon (chevron)
            globalToggleIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 6-12 6"/>';
        }
    }

    // initialize
    applySidebarState(isExpanded());

    // global toggle (top-left) to open/close sidebar on mobile or toggle collapse on desktop
    globalToggle.addEventListener('click', function(e){
        if (window.innerWidth < 768) {
            // open mobile slide-over
            mobileSidebar.style.transform = 'translateX(0)';
        } else {
            // toggle collapse
            const expanded = isExpanded();
            localStorage.setItem(STORAGE_KEY, expanded ? '0' : '1');
            applySidebarState(!expanded);
        }
    });

    // mobile close
    if (mobileSidebarClose) {
        mobileSidebarClose.addEventListener('click', function(){ mobileSidebar.style.transform = 'translateX(-100%)'; });
    }
    // close mobile when clicking outside
    document.addEventListener('click', function(e){
        if (window.innerWidth < 768 && mobileSidebar.style.transform === 'translateX(0)') {
            if (!mobileSidebar.contains(e.target) && !globalToggle.contains(e.target)) {
                mobileSidebar.style.transform = 'translateX(-100%)';
            }
        }
    });

    // user dropdown
    userBtn.addEventListener('click', function(e){
        e.stopPropagation();
        userMenu.classList.toggle('hidden');
        userBtn.setAttribute('aria-expanded', String(!userMenu.classList.contains('hidden')));
    });
    document.addEventListener('click', function(){ if (!userMenu.classList.contains('hidden')) userMenu.classList.add('hidden'); });

    // helper: set active link
    function setActiveLink(target) {
        links.forEach(a => {
            if (a.dataset && a.dataset.target === target) {
                a.classList.add('bg-indigo-50', 'font-semibold');
            } else {
                a.classList.remove('bg-indigo-50', 'font-semibold');
            }
        });
    }

    // Load a page into the iframe
    window.loadIntoFrame = function(evt, target) {
        if (evt) evt.preventDefault();
        if (!target) return;
        // mark active
        setActiveLink(target);
        // hide welcome and show iframe
        if (welcomeCard) welcomeCard.classList.add('hidden');
        frameWrap.classList.remove('hidden');

        // set src
        appFrame.src = target;
    };

    // attach click handlers
    links.forEach(a => {
        a.addEventListener('click', function(e){
            const tgt = this.getAttribute('data-target');
            if (!tgt) return;
            loadIntoFrame(e, tgt);
            // if mobile, close slide-over
            if (window.innerWidth < 768) mobileSidebar.style.transform = 'translateX(-100%)';
        });
    });

    // Auto-hide error
    const errorAlertBox = document.getElementById('error-alert-box');
    if (errorAlertBox) {
        setTimeout(() => {
            errorAlertBox.style.transition = 'opacity 0.5s ease';
            errorAlertBox.style.opacity = '0';
            setTimeout(() => errorAlertBox.remove(), 500);
        }, 5000);
    }

    // Optionally load page from ?page=foo param
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
