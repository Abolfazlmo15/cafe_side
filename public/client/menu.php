<?php
// public/client/menu.php – Customer ordering page (Vue-powered)
// ===================================================================
// Phase 2: Vue renders menu, categories, banner, image modal.
// The Place Order button is inline HTML with fixed-position CSS so it
// is always visible regardless of Vue state. Vue manages the cart;
// an inline submit handler serializes it into itemsJson before POST.

require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/layout/ClientLayout.php';
require_once __DIR__ . '/../../src/api.php';

session_start();

$pdo = getDbConnection();
$table = isset($_GET['table']) ? (int)$_GET['table'] : 0;


// ================================================================
// ====== JSON API BRANCHES (Phase 1) ======
// ================================================================
if (isset($_GET['api'])) {
    $apiEndpoint = $_GET['api'];
    $apiTable    = isset($_GET['table']) ? (int)$_GET['table'] : 0;
    $apiToken    = isset($_GET['token']) ? $_GET['token'] : ($_COOKIE['device_token'] ?? '');

    if ($apiEndpoint === 'menu') {
        $menu = apiBuildMenuData($pdo);
        apiRespondJson([
            'version'     => apiGetVersion($pdo),
            'server_time' => date('c'),
            'table'       => apiBuildTableData($pdo, $apiTable),
            'categories'  => $menu['categories'],
            'items'       => $menu['items'],
        ]);
    }

    if ($apiEndpoint === 'poll') {
        $clientVersion = isset($_GET['version']) ? (int)$_GET['version'] : 0;
        $serverVersion = apiGetVersion($pdo);

        if ($clientVersion > 0 && $clientVersion === $serverVersion) {
            apiRespondJson([
                'unchanged' => true,
                'version'   => $serverVersion,
            ]);
        }

        $menu = apiBuildMenuData($pdo);
        apiRespondJson([
            'unchanged' => false,
            'version'   => $serverVersion,
            'table'     => apiBuildTableData($pdo, $apiTable),
            'menu'      => [
                'categories' => $menu['categories'],
                'items'      => $menu['items'],
            ],
            'orders'    => apiBuildOrdersData($pdo, $apiTable, $apiToken),
            'about'     => apiBuildAboutData($pdo),
        ]);
    }

    if ($apiEndpoint === 'about') {
        apiRespondJson(apiBuildAboutData($pdo));
    }

    if ($apiEndpoint === 'version') {
        apiRespondJson(['version' => apiGetVersion($pdo)]);
    }

    apiRespondJson(['error' => 'Unknown API endpoint'], 404);
}
// ====== END JSON API BRANCHES ======


// ================================================================
// ====== DEVICE TOKEN ======
// ================================================================
$deviceToken = '';
if (isset($_COOKIE['device_token'])) {
    $deviceToken = $_COOKIE['device_token'];
} else {
    $deviceToken = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
    setcookie('device_token', $deviceToken, time() + 365 * 24 * 3600, '/');
}


// ================================================================
// ====== BUILD INITIAL DATA FOR VUE BOOTSTRAP ======
// ================================================================
$initialMenu    = apiBuildMenuData($pdo);
$initialVersion = apiGetVersion($pdo);
$initialTable   = apiBuildTableData($pdo, $table);
$initialOrders  = apiBuildOrdersData($pdo, $table, $deviceToken);

$validIds = [];
foreach ($initialMenu['items'] as $it) {
    $validIds[(string)$it['id']] = true;
}

$pendingQuantities = $_SESSION['pending_quantities'] ?? [];
unset($_SESSION['pending_quantities']);

$initialCart = [];
foreach ($pendingQuantities as $id => $qty) {
    $idStr  = (string)(int)$id;
    $qtyInt = (int)$qty;
    if ($qtyInt <= 0) continue;
    if (!isset($validIds[$idStr])) continue;
    $initialCart[$idStr] = $qtyInt;
}

$tableValid   = $initialTable['valid'];
$errorMessage = $tableValid ? '' : $initialTable['message'];

$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : null;
if ($error && !$errorMessage) {
    $errorMessage = $error;
}

ob_start();
?>
<!-- External CSS -->
<link rel="stylesheet" href="../../assets/css/client/menu.css">

<!-- Page-specific styles: floating order bar + CartBar suppression -->
<style>
    .menu-page {
        padding-bottom: 130px;
    }

    .floating-order-bar {
        position: fixed;
        left: 50%;
        bottom: 1.5rem;
        transform: translateX(-50%);
        z-index: 900;
        width: calc(100% - 3rem);
        max-width: 500px;
        animation: floatIn 0.35s cubic-bezier(0.22, 1, 0.36, 1);
    }

    .floating-order-bar .submit-btn {
        width: 100%;
        margin-top: 0;
        box-shadow: 0 12px 40px rgba(111, 78, 55, 0.45), 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    @keyframes floatIn {
        from { transform: translate(-50%, 100%); opacity: 0; }
        to   { transform: translate(-50%, 0);    opacity: 1; }
    }

    .cart-bar { display: none !important; }

    @media (max-width: 500px) {
        .floating-order-bar {
            left: 1rem;
            right: 1rem;
            transform: none;
            width: auto;
            max-width: none;
            animation: floatInMobile 0.35s cubic-bezier(0.22, 1, 0.36, 1);
        }
        @keyframes floatInMobile {
            from { transform: translateY(100%); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }
    }
</style>

<div class="menu-page">

    <!-- Header -->
    <header class="menu-header">
        <div class="header-left">
            <h1>
                <img src="../../assets/images/icons/side_coffee.png" alt="<?= SITE_NAME ?>" class="brand-icon">
                <span class="brand-name"><?= SITE_NAME ?></span>
            </h1>
            <span class="table-badge"><i class="fas fa-chair"></i> Table <?= htmlspecialchars($table) ?></span>
        </div>
        <div class="header-actions">
            <button class="search-toggle" id="searchToggle" aria-label="Toggle search">
                <i class="fas fa-search"></i>
            </button>
            <button class="about-toggle" id="aboutToggle" aria-label="About">
                <i class="fas fa-info-circle"></i>
            </button>
        </div>
    </header>

    <!-- Search bar -->
    <div class="search-bar-wrap" id="searchWrap">
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search menu…" id="searchInput">
        </div>
    </div>

    <!-- Vue mounts here -->
    <div id="vue-menu-root"></div>

    <!-- Order form -->
    <form method="POST" action="submit_order.php" id="orderForm">
        <input type="hidden" name="table_number" value="<?= $table ?>">
        <input type="hidden" name="items_json" id="itemsJson" value="">
        <input type="hidden" name="device_token" value="<?= htmlspecialchars($deviceToken) ?>">

        <div id="priceInputs" style="display:none;">
            <?php foreach ($initialMenu['items'] as $it): ?>
                <input type="hidden"
                       name="old_prices[<?= $it['id'] ?>]"
                       value="<?= $it['price'] ?>">
            <?php endforeach; ?>
        </div>

        <div class="note-area">
            <label for="customer_note"><i class="fas fa-pen"></i> Special instructions (optional)</label>
            <textarea id="customer_note" name="customer_note"
                      placeholder="e.g., No sugar, extra napkins..." rows="2"></textarea>
        </div>

        <!-- Floating Place Order button (inline HTML, always visible) -->
        <div class="floating-order-bar">
            <button type="submit" class="submit-btn" id="submitOrderBtn"
                    <?= !$tableValid ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                <i class="fas fa-paper-plane"></i> Place Order
            </button>
        </div>

        <?php if (!$tableValid): ?>
            <p style="color:#991b1b; font-size:0.85rem; margin-top:0.3rem;" id="tableInvalidMsg">
                <i class="fas fa-exclamation-triangle"></i> Ordering is disabled – invalid table.
            </p>
        <?php endif; ?>
    </form>
</div>

<!-- Scroll-to-top -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- About drawer -->
<?php require_once __DIR__ . '/../../src/layout/components/about_drawer.php'; ?>

<!-- Bootstrap data for Vue -->
<script>
window.__INITIAL_DATA__ = {
    table:               <?= (int)$table ?>,
    deviceToken:         <?= json_encode($deviceToken) ?>,
    tableValid:          <?= $tableValid ? 'true' : 'false' ?>,
    tableInvalidMessage: <?= json_encode($errorMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    version:             <?= (int)$initialVersion ?>,
    menu:                <?= json_encode($initialMenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    orders:              <?= json_encode($initialOrders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    cart:                <?= json_encode($initialCart) ?>
};
</script>

<!-- Vue 3 -->
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>

<!-- Vue app -->
<script src="../../assets/js/client/menu-vue.js"></script>

<!-- Inline submit handler: pulls cart from Vue store into itemsJson before POST -->
<script>
(function () {
    function attachSubmitHandler() {
        var form = document.getElementById('orderForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            var store = window.__STORE__;
            if (!store) {
                return;
            }

            if (!store.table.valid) {
                e.preventDefault();
                alert('Ordering is disabled – invalid table.');
                return;
            }

            var cart = store.cart || {};
            var validIds = {};
            (store.menu.items || []).forEach(function (it) {
                validIds[String(it.id)] = true;
            });

            var clean = {};
            Object.keys(cart).forEach(function (id) {
                var qty = parseInt(cart[id], 10) || 0;
                if (qty <= 0) return;
                if (!validIds[String(id)]) return;
                clean[String(id)] = qty;
            });

            if (Object.keys(clean).length === 0) {
                e.preventDefault();
                alert('Please select at least one item.');
                return;
            }

            document.getElementById('itemsJson').value = JSON.stringify(clean);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachSubmitHandler);
    } else {
        attachSubmitHandler();
    }
})();
</script>

<?php
$content = ob_get_clean();
$layout = new ClientLayout();
$layout->setTitle('Order')->setContent($content);
$layout->render();