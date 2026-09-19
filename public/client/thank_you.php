<?php
// public/client/thank_you.php – Order confirmation with itemised summary
// ========================================================================

require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/layout/ClientLayout.php';

session_start();

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$table = isset($_GET['table']) ? (int)$_GET['table'] : 0;
$reviewMode = isset($_GET['review']) && $_GET['review'] == 1;

$pdo = getDbConnection();

// Helper to validate table
function isValidTable($pdo, $tableNumber) {
    if ($tableNumber <= 0) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM tables WHERE table_number = ?");
    $stmt->execute([$tableNumber]);
    return $stmt->fetch() !== false;
}

if ($reviewMode) {
    // Review mode: get data from session
    if (!isset($_SESSION['pending_order'])) {
        header('Location: menu.php');
        exit;
    }
    $pending = $_SESSION['pending_order'];
    $table = $pending['table'];
    // Validate table in review mode
    if (!isValidTable($pdo, $table)) {
        unset($_SESSION['pending_order']);
        header('Location: menu.php?error=Invalid table');
        exit;
    }
    $items = $pending['items'];
    $customerNote = $pending['note'];
    $conflicts = $pending['conflicts'];
    $currentPrices = $pending['current_prices'] ?? [];
    $oldPrices = $pending['old_prices'] ?? [];

    // Build item details for display (using current prices)
    $menuMap = [];
    if (!empty($items)) {
        $ids = array_keys($items);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name, price FROM menu_items WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        while ($row = $stmt->fetch()) {
            $menuMap[$row['id']] = $row;
        }
    }
    $itemDetails = [];
    $totalPrice = 0;
    foreach ($items as $id => $qty) {
        $name = $menuMap[$id]['name'] ?? "Unknown (#$id)";
        $price = $menuMap[$id]['price'] ?? 0;
        $subtotal = $price * $qty;
        $totalPrice += $subtotal;
        $itemDetails[] = [
            'id' => $id,
            'name' => $name,
            'qty' => $qty,
            'subtotal' => $subtotal,
            'price' => $price,
            'conflict' => isset($conflicts[$id]) ? $conflicts[$id] : null
        ];
    }
} else {
    // Normal mode: fetch order from DB
    if ($orderId <= 0) { header('Location: menu.php'); exit; }
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { header('Location: menu.php'); exit; }
    $table = $order['table_number'];

    // Validate table (should exist, but just in case)
    if (!isValidTable($pdo, $table)) {
        header('Location: menu.php?error=Invalid table');
        exit;
    }

    $items = json_decode($order['items'], true);
    $itemDetails = [];
    $totalPrice = 0;
    if ($items) {
        $ids = array_keys($items);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name, price FROM menu_items WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $menuMap = [];
        while ($row = $stmt->fetch()) {
            $menuMap[$row['id']] = $row;
        }
        foreach ($items as $id => $qty) {
            $name = $menuMap[$id]['name'] ?? "Unknown (#$id)";
            $price = $menuMap[$id]['price'] ?? 0;
            $subtotal = $price * $qty;
            $totalPrice += $subtotal;
            $itemDetails[] = ['name' => $name, 'qty' => $qty, 'subtotal' => $subtotal];
        }
    }
    $customerNote = $order['customer_note'] ?? '';
}

ob_start();
?>
<!-- External CSS -->
<link rel="stylesheet" href="../../assets/css/client/thank_you.css">

<div class="container">
    <?php if ($reviewMode): ?>
        <div class="icon warning-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <h1 style="color:#b91c1c;">Order Review Required</h1>
        <p class="sub">Some items have changed since you added them. Please review before confirming.</p>

        <?php if (!empty($conflicts)): ?>
            <div class="conflict-box">
                <ul>
                    <?php foreach ($conflicts as $id => $conflict): ?>
                        <li>
                            <?php if ($conflict['reason'] === 'unavailable'): ?>
                                <span class="conflict-unavailable">❌ <strong><?= htmlspecialchars($itemDetails[array_search($id, array_column($itemDetails, 'id'))]['name'] ?? "Item #$id") ?></strong> is no longer available.</span>
                            <?php elseif ($conflict['reason'] === 'price_changed'): ?>
                                <span class="conflict-price">
                                    <span class="old-price"><?= number_format($conflict['old_price']) ?> T</span>
                                    → <span class="new-price"><?= number_format($conflict['new_price']) ?> T</span>
                                    for <strong><?= htmlspecialchars($itemDetails[array_search($id, array_column($itemDetails, 'id'))]['name'] ?? "Item #$id") ?></strong>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="details">
            <div class="row"><span><i class="fas fa-receipt"></i> Order #</span> <strong>Pending</strong></div>
            <div class="row"><span><i class="fas fa-chair"></i> Table</span> <strong><?= $table ?></strong></div>
            <div class="items-header">Items:</div>
            <?php foreach ($itemDetails as $item): ?>
                <div class="item-row <?= isset($item['conflict']) ? 'conflict-item' : '' ?>">
                    <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                    <span class="item-qty">× <?= $item['qty'] ?></span>
                    <span class="item-subtotal"><?= number_format($item['subtotal']) ?> T</span>
                    <?php if (isset($item['conflict'])): ?>
                        <?php if ($item['conflict']['reason'] === 'unavailable'): ?>
                            <span class="conflict-badge">Unavailable</span>
                        <?php elseif ($item['conflict']['reason'] === 'price_changed'): ?>
                            <span class="conflict-badge">Price changed</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="total-row">
                <span>Total</span>
                <span><?= number_format($totalPrice) ?> T</span>
            </div>
            <?php if (!empty($customerNote)): ?>
                <div class="note-box"><i class="fas fa-pen"></i> <?= htmlspecialchars($customerNote) ?></div>
            <?php endif; ?>
        </div>

        <div class="review-actions">
            <form method="POST" action="submit_order.php" style="display:inline;">
                <input type="hidden" name="confirm" value="1">
                <button type="submit" class="btn confirm-btn"><i class="fas fa-check"></i> Confirm Order</button>
            </form>
            <a href="submit_order.php?cancel=1&table=<?= $table ?>" class="btn cancel-btn"><i class="fas fa-times"></i> Cancel</a>
        </div>
        <p class="extra-text">If you cancel, your quantities will be restored on the menu page.</p>

    <?php else: ?>
        <!-- Normal thank you -->
        <div class="icon"><i class="fas fa-check-circle"></i></div>
        <h1>Order Placed!</h1>
        <p class="sub">Your order has been received and is being prepared.</p>

        <div class="details">
            <div class="row"><span><i class="fas fa-receipt"></i> Order #</span> <strong><?= $orderId ?></strong></div>
            <div class="row"><span><i class="fas fa-chair"></i> Table</span> <strong><?= $table ?></strong></div>
            <div class="items-header">Items:</div>
            <?php foreach ($itemDetails as $item): ?>
                <div class="item-row">
                    <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                    <span class="item-qty">× <?= $item['qty'] ?></span>
                    <span class="item-subtotal"><?= number_format($item['subtotal']) ?> T</span>
                </div>
            <?php endforeach; ?>
            <div class="total-row">
                <span>Total</span>
                <span><?= number_format($totalPrice) ?> T</span>
            </div>
            <?php if (!empty($customerNote)): ?>
                <div class="note-box"><i class="fas fa-pen"></i> <?= htmlspecialchars($customerNote) ?></div>
            <?php endif; ?>
        </div>

        <p class="extra-text">We'll bring your order to your table shortly.</p>
        <a href="menu.php?table=<?= $table ?>" class="btn"><i class="fas fa-utensils"></i> Order Again</a>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$layout = new ClientLayout();
$layout->setTitle($reviewMode ? 'Review Order' : 'Order Confirmed')->setContent($content);
$layout->render();