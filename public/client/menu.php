<?php
// public/client/menu.php – Customer ordering page with redesigned UI (cards, filters, search, off‑canvas about)
// ===========================================================================================================

require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/layout/ClientLayout.php';

session_start(); // start session to check pending quantities

$pdo = getDbConnection();
$table = isset($_GET['table']) ? (int)$_GET['table'] : 0;

// ================================================================
// ====== DEVICE TOKEN HANDLING ======
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
$GLOBALS['device_token'] = $deviceToken;

// ================================================================
// ====== HELPER: Get image URL with cache‑busting ======
// ================================================================
function getItemImageUrl($item) {
    $storedPath = $item['image_path'] ?? null;
    if (empty($storedPath)) return null;
    $relativePath = ltrim($storedPath, '/');
    $url = BASE_URL . '/' . $relativePath;
    $fullPath = __DIR__ . '/../..' . '/' . $relativePath;
    if (file_exists($fullPath)) {
        $url .= '?v=' . filemtime($fullPath);
    }
    return $url;
}

// ================================================================
// ====== TABLE VALIDATION ======
// ================================================================
function isTableValid($pdo, $table) {
    if ($table <= 0) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tables WHERE table_number = ?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() > 0;
}

function getTableStatusHtml($pdo, $table) {
    $valid = isTableValid($pdo, $table);
    $message = $valid ? '' : 'Please use a valid table QR code. If you need assistance, ask our staff.';
    return '<div id="tableStatus" data-valid="' . ($valid ? '1' : '0') . '" data-message="' . htmlspecialchars($message) . '" style="display:none;"></div>';
}

// ================================================================
// ====== AJAX ENDPOINTS ======
// ================================================================

// --- AJAX endpoint: about content ---
if (isset($_GET['about_content']) && $_GET['about_content'] == 1) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    // Load about content from settings
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'about_content'");
    $stmt->execute();
    $row = $stmt->fetch();
    $aboutContent = $row['setting_value'] ?? '{}';
    $aboutData = json_decode($aboutContent, true);

    if (!is_array($aboutData) || empty($aboutData)) {
        $aboutData = [
            'welcome' => 'Welcome to ' . SITE_NAME . ' — where every cup tells a story.',
            'offerings' => "Hand‑crafted espresso drinks\nFreshly brewed pour‑overs\nArtisan pastries and snacks\nPlant‑based milk alternatives",
            'location' => '123 Coffee Lane, Brewtown',
            'hours' => 'Mon – Sun: 7:00 AM – 10:00 PM',
            'phone' => '+1 (555) 123‑4567',
            'email' => 'hello@brewverse.cafe'
        ];
    }

    echo json_encode($aboutData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- AJAX banner endpoint (orders) ---
if (isset($_GET['banner']) && $_GET['banner'] == 1) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    $token = isset($_GET['token']) ? $_GET['token'] : ($_COOKIE['device_token'] ?? '');
    // Only show banner if table is valid
    if (isTableValid($pdo, $table)) {
        echo buildBanner($pdo, $table, $token);
    } else {
        echo ''; // empty banner
    }
    exit;
}

if (isset($_GET['menu_items']) && $_GET['menu_items'] == 1) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    // Return menu HTML + table status
    echo buildMenuHtml($pdo) . getTableStatusHtml($pdo, $table);
    exit;
}

// ================================================================
// ====== BUILD FUNCTIONS ======
// ================================================================

function buildBanner($pdo, $table, $deviceToken) {
    $html = '';
    if ($table > 0 && !empty($deviceToken) && isTableValid($pdo, $table)) {
        $stmt = $pdo->prepare("SELECT id, items, is_ready, created_at FROM orders WHERE table_number = ? AND device_token = ? ORDER BY created_at DESC");
        $stmt->execute([$table, $deviceToken]);
        $allOrders = $stmt->fetchAll();
        $pendingOrders = array_filter($allOrders, function($o) { return $o['is_ready'] == 0; });
        $readyOrders = array_filter($allOrders, function($o) { return $o['is_ready'] == 1; });
        if (empty($pendingOrders) && empty($readyOrders)) return '';

        $aggregatedItems = [];
        foreach ($pendingOrders as $order) {
            $items = json_decode($order['items'], true);
            if ($items) {
                foreach ($items as $id => $qty) {
                    $aggregatedItems[$id] = ($aggregatedItems[$id] ?? 0) + $qty;
                }
            }
        }
        $itemDetails = [];
        if (!empty($aggregatedItems)) {
            $ids = array_keys($aggregatedItems);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $names = [];
            while ($row = $stmt->fetch()) {
                $names[$row['id']] = $row['name'];
            }
            foreach ($aggregatedItems as $id => $qty) {
                $name = $names[$id] ?? "Unknown (#$id)";
                $itemDetails[] = "$name ×$qty";
            }
        }
        $orderListHtml = '';
        foreach ($allOrders as $order) {
            $color = $order['is_ready'] == 1 ? '#22c55e' : '#dc2626';
            $orderListHtml .= "<span style='color:$color; font-weight:600;'>#{$order['id']}</span> ";
        }
        $html .= '<div class="pending-banner" id="orderBanner">';
        $html .= '<i class="fas fa-clock"></i>';
        $html .= '<div>';
        if (!empty($pendingOrders)) {
            $html .= '<strong>Your active orders:</strong> ';
            $html .= '<span style="color:#78350f;">' . implode(', ', $itemDetails) . '</span>';
            $html .= '<br><span style="font-size:0.85rem; color:#92400e;">Orders: ' . $orderListHtml . '</span>';
        } else {
            $html .= '<strong>All your orders are ready!</strong> ';
            $html .= '<span style="font-size:0.85rem; color:#065f46;">Orders: ' . $orderListHtml . '</span>';
        }
        $html .= '</div></div>';
    }
    return $html;
}

function buildMenuHtml($pdo) {
    $sql = "SELECT id, name, price, category, description, image_path, available, sort_order
            FROM menu_items
            WHERE available = 1
            ORDER BY
                CASE WHEN sort_order != 0 THEN 0 ELSE 1 END,
                sort_order ASC,
                name ASC";
    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll();

    // Build grouped for categories
    $grouped = [];
    foreach ($items as $item) {
        $cat = $item['category'] ?: 'Other';
        $grouped[$cat][] = $item;
    }

    // Get category names for pills (only categories with at least one active item)
    $categoryNames = array_keys($grouped);
    sort($categoryNames);

    ob_start();
    if (empty($grouped)): ?>
        <div class="no-items">No menu items available.</div>
    <?php else: ?>
        <?php foreach ($grouped as $category => $itemList): ?>
            <div class="category-group" data-category="<?= htmlspecialchars($category) ?>">
                <h3 class="category-title"><?= htmlspecialchars($category) ?></h3>
                <div class="coffee-grid">
                    <?php foreach ($itemList as $item):
                        $imageUrl = getItemImageUrl($item);
                        // Check if we have a pending quantity for this item
                        $pendingQty = $_SESSION['pending_quantities'][$item['id']] ?? 0;
                    ?>
                        <div class="coffee-card" data-id="<?= $item['id'] ?>" data-category="<?= htmlspecialchars($category) ?>">
                            <div class="card-img">
                                <?php if ($imageUrl): ?>
                                    <img src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="menu-item-img" data-full="<?= htmlspecialchars($imageUrl) ?>">
                                <?php else: ?>
                                    <i class="fas fa-mug-saucer"></i>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <h4 class="card-title"><?= htmlspecialchars($item['name']) ?></h4>
                                <p class="card-desc"><?= htmlspecialchars($item['description'] ?? 'Served with love') ?></p>
                                <div class="card-footer">
                                    <span class="price"><?= number_format($item['price']) ?> T</span>
                                    <div class="qty-controls">
                                        <button class="qty-minus" type="button">-</button>
                                        <input type="number" class="qty-input" value="<?= $pendingQty ?>" min="0" max="99" data-id="<?= $item['id'] ?>">
                                        <button class="qty-plus" type="button">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif;

    // Return the menu HTML + hidden category data div
    return ob_get_clean() . '<div id="categoryData" data-categories=\'' . json_encode($categoryNames) . '\' style="display:none;"></div>';
}

// ================================================================
// ====== MAIN PAGE ======
// ================================================================

// If we have pending quantities from a cancelled order, use them once, then clear
$pendingQuantities = $_SESSION['pending_quantities'] ?? [];
unset($_SESSION['pending_quantities']);

$sql = "SELECT id, name, price, category, description, image_path, available, sort_order
        FROM menu_items
        WHERE available = 1
        ORDER BY
            CASE WHEN sort_order != 0 THEN 0 ELSE 1 END,
            sort_order ASC,
            name ASC";
$stmt = $pdo->query($sql);
$menuItems = $stmt->fetchAll();
$grouped = [];
foreach ($menuItems as $item) {
    $cat = $item['category'] ?: 'Other';
    $grouped[$cat][] = $item;
}
// Get all unique categories for pills
$allCategories = array_keys($grouped);
sort($allCategories);

// Check if table is valid
$tableValid = isTableValid($pdo, $table);
$errorMessage = '';
if (!$tableValid) {
    $errorMessage = 'Please use a valid table QR code. If you need assistance, ask our staff.';
}

$bannerHtml = buildBanner($pdo, $table, $deviceToken);
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : null;
if ($error && !$errorMessage) {
    // If there's a query string error but table is valid, show that error
    $errorMessage = $error;
}

ob_start();
?>
<!-- External CSS -->
<link rel="stylesheet" href="../../assets/css/client/menu.css">

<div class="menu-page">
    <!-- Header with search & table info -->
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

    <!-- Search bar (expandable) -->
    <div class="search-bar-wrap" id="searchWrap">
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" placeholder="Search menu…" id="searchInput">
        </div>
    </div>

    <!-- Order banner -->
    <div id="bannerContainer"><?= $bannerHtml ?></div>

    <?php if ($errorMessage): ?>
        <div class="alert alert-error dismissible" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span id="errorMessageText"><?= $errorMessage ?></span>
            <button class="close-btn" id="errorCloseBtn" aria-label="Close">&times;</button>
        </div>
    <?php endif; ?>

    <!-- Category pills (dynamic) -->
    <div class="categories-wrap" id="categoryContainer">
        <button class="cat-pill active" data-cat="all">All</button>
        <?php foreach ($allCategories as $cat): ?>
            <button class="cat-pill" data-cat="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></button>
        <?php endforeach; ?>
    </div>

    <!-- Menu items -->
    <div id="menuContainer">
        <?php if (empty($grouped)): ?>
            <div class="no-items">No menu items available.</div>
        <?php else: ?>
            <?php foreach ($grouped as $category => $items): ?>
                <div class="category-group" data-category="<?= htmlspecialchars($category) ?>">
                    <h3 class="category-title"><?= htmlspecialchars($category) ?></h3>
                    <div class="coffee-grid">
                        <?php foreach ($items as $item):
                            $imageUrl = getItemImageUrl($item);
                            $pendingQty = $pendingQuantities[$item['id']] ?? 0;
                        ?>
                            <div class="coffee-card" data-id="<?= $item['id'] ?>" data-category="<?= htmlspecialchars($category) ?>">
                                <div class="card-img">
                                    <?php if ($imageUrl): ?>
                                        <img src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="menu-item-img" data-full="<?= htmlspecialchars($imageUrl) ?>">
                                    <?php else: ?>
                                        <i class="fas fa-mug-saucer"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body">
                                    <h4 class="card-title"><?= htmlspecialchars($item['name']) ?></h4>
                                    <p class="card-desc"><?= htmlspecialchars($item['description'] ?? 'Served with love') ?></p>
                                    <div class="card-footer">
                                        <span class="price"><?= number_format($item['price']) ?> T</span>
                                        <div class="qty-controls">
                                            <button class="qty-minus" type="button">-</button>
                                            <input type="number" class="qty-input" value="<?= $pendingQty ?>" min="0" max="99" data-id="<?= $item['id'] ?>">
                                            <button class="qty-plus" type="button">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <!-- <div class="menu-refresh-notice">🔄 Menu auto‑updates every 5s</div> -->
    </div>

    <!-- Order form -->
    <form method="POST" action="submit_order.php" id="orderForm">
        <input type="hidden" name="table_number" value="<?= $table ?>">
        <input type="hidden" name="items_json" id="itemsJson" value="">
        <input type="hidden" name="device_token" value="<?= htmlspecialchars($deviceToken) ?>">
        <!-- Hidden inputs for current prices of all active items (for conflict detection) -->
        <div id="priceInputs" style="display:none;">
            <?php foreach ($menuItems as $item): ?>
                <input type="hidden" name="old_prices[<?= $item['id'] ?>]" value="<?= $item['price'] ?>">
            <?php endforeach; ?>
        </div>

        <div class="note-area">
            <label for="customer_note"><i class="fas fa-pen"></i> Special instructions (optional)</label>
            <textarea id="customer_note" name="customer_note" placeholder="e.g., No sugar, extra napkins..." rows="2"></textarea>
        </div>

        <button type="submit" class="submit-btn" id="submitOrderBtn" <?= !$tableValid ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
            <i class="fas fa-paper-plane"></i> Place Order
        </button>
        <?php if (!$tableValid): ?>
            <p style="color:#991b1b; font-size:0.85rem; margin-top:0.3rem;" id="tableInvalidMsg"><i class="fas fa-exclamation-triangle"></i> Ordering is disabled – invalid table.</p>
        <?php endif; ?>
    </form>
</div>
<!-- Scroll‑to‑Top Button -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</button>
<!-- Image Modal -->
<div class="image-modal" id="imageModal">
    <div class="modal-content">
        <button class="modal-close" id="modalClose">&times;</button>
        <img id="modalImage" src="#" alt="Full view">
    </div>
</div>

<!-- Off‑canvas About Drawer -->
<?php require_once __DIR__ . '/../../src/layout/components/about_drawer.php'; ?>

<!-- Pass PHP variables to JavaScript -->
<script>
    window.tableNumber = <?= (int)$table ?>;
    window.deviceToken = '<?= htmlspecialchars($deviceToken, ENT_QUOTES) ?>';
    window.allCategories = <?= json_encode($allCategories) ?>;
    window.tableValid = <?= $tableValid ? 'true' : 'false' ?>;
    window.tableInvalidMessage = '<?= addslashes($errorMessage) ?>';
</script>

<!-- External JS -->
<script src="../../assets/js/client/menu.js"></script>
<?php
$content = ob_get_clean();
$layout = new ClientLayout();
$layout->setTitle('Order')->setContent($content);
$layout->render();