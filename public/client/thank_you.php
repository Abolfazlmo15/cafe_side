<?php
// public/client/thank_you.php – Interactive order review + confirmation
// ======================================================================

require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/layout/ClientLayout.php';

session_start();

$orderId    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$table      = isset($_GET['table']) ? (int)$_GET['table'] : 0;
$reviewMode = isset($_GET['review']) && $_GET['review'] == 1;

$pdo = getDbConnection();

function isValidTable($pdo, $tableNumber) {
    if ($tableNumber <= 0) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM tables WHERE table_number = ?");
    $stmt->execute([$tableNumber]);
    return $stmt->fetch() !== false;
}

function filterItems($items) {
    if (!is_array($items)) return [];
    $clean = [];
    foreach ($items as $id => $qty) {
        $idInt = (int)$id;
        if ($idInt <= 0) continue;
        if (!is_numeric($qty)) continue;
        $qtyInt = (int)$qty;
        if ($qtyInt <= 0) continue;
        $clean[$idInt] = $qtyInt;
    }
    return $clean;
}

// ================================================================
// ====== REVIEW MODE ======
// ================================================================
if ($reviewMode) {

    if (!isset($_SESSION['pending_order'])) {
        header('Location: menu.php');
        exit;
    }

    $pending      = $_SESSION['pending_order'];
    $table        = (int)$pending['table'];
    $customerNote = $pending['note'];

    if (!isValidTable($pdo, $table)) {
        unset($_SESSION['pending_order']);
        header('Location: menu.php?error=Invalid table');
        exit;
    }

    $items = filterItems($pending['items']);

    if (empty($items)) {
        unset($_SESSION['pending_order']);
        header('Location: menu.php?table=' . $table);
        exit;
    }

    $ids = array_keys($items);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, price, available FROM menu_items WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $menuMap = [];
    while ($row = $stmt->fetch()) {
        $menuMap[(int)$row['id']] = [
            'name'      => $row['name'],
            'price'     => (int)$row['price'],
            'available' => (int)$row['available'],
        ];
    }

    $itemDetails = [];
    $totalPrice = 0;
    foreach ($items as $id => $qty) {
        if (!isset($menuMap[$id])) continue;
        $subtotal = $menuMap[$id]['price'] * $qty;
        $totalPrice += $subtotal;
        $itemDetails[] = [
            'id'       => $id,
            'name'     => $menuMap[$id]['name'],
            'price'    => $menuMap[$id]['price'],
            'qty'      => $qty,
            'subtotal' => $subtotal,
        ];
    }

    $jsPayload = [
        'table' => (int)$table,
        'items' => $items,
        'menu'  => $menuMap,
    ];

// ================================================================
// ====== NORMAL (thank you) MODE ======
// ================================================================
} else {

    if ($orderId <= 0) { header('Location: menu.php'); exit; }
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) { header('Location: menu.php'); exit; }
    $table = (int)$order['table_number'];

    if (!isValidTable($pdo, $table)) {
        header('Location: menu.php?error=Invalid table');
        exit;
    }

    $items = filterItems(json_decode($order['items'], true));
    $itemDetails = [];
    $totalPrice = 0;
    if (!empty($items)) {
        $ids = array_keys($items);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name, price FROM menu_items WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $menuMap = [];
        while ($row = $stmt->fetch()) {
            $menuMap[(int)$row['id']] = $row;
        }
        foreach ($items as $id => $qty) {
            $name     = $menuMap[$id]['name'] ?? "Unknown (#$id)";
            $price    = (int)($menuMap[$id]['price'] ?? 0);
            $subtotal = $price * $qty;
            $totalPrice += $subtotal;
            $itemDetails[] = ['name' => $name, 'qty' => $qty, 'subtotal' => $subtotal];
        }
    }
    $customerNote = $order['customer_note'] ?? '';
}

ob_start();
?>
<link rel="stylesheet" href="../../assets/css/client/thank_you.css">

<?php if ($reviewMode): ?>

    <!-- ============ REVIEW MODE ============ -->
    <style>
        /* Box-sizing reset — fixes the textarea overflow */
        *, *::before, *::after { box-sizing: border-box; }

        .review-container {
            max-width: 620px;
            margin: 0 auto;
            padding: 1rem 1.2rem 9rem;
        }

        /* --- Header --- */
        .review-header { text-align: center; margin-bottom: 1.6rem; }
        .review-header .icon {
            width: 54px; height: 54px;
            margin: 0 auto 0.9rem;
            background: linear-gradient(145deg, #f3e8e0, #e8d9cf);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; color: #6f4e37;
        }
        .review-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: #2d1b0e;
            margin-bottom: 0.3rem;
        }
        .review-header p {
            color: #6b7280;
            font-size: 0.92rem;
            margin-bottom: 0.7rem;
        }
        .review-table-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            background: #f3e8e0;
            color: #2d1b0e;
            padding: 0.35rem 0.95rem;
            border-radius: 100px;
            font-size: 0.88rem;
            font-weight: 600;
        }
        .review-table-badge i { color: #6f4e37; font-size: 0.85rem; }

        /* --- Section card --- */
        .review-section {
            background: #fff;
            border: 1px solid #f0e4db;
            border-radius: 1.25rem;
            padding: 1.5rem 1.6rem 1.4rem;
            margin-bottom: 1.1rem;
            box-shadow: 0 2px 14px rgba(80, 40, 20, 0.04);
        }

        .review-section-title {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #8b6b55;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }
        .review-section-title i { color: #b3947e; }

        /* --- Item list --- */
        .item-list { display: block; }

        .item-row {
            cursor: pointer;
            transition: background 0.18s;
            border-radius: 0.6rem;
            padding: 0.9rem 0.4rem;
            border-bottom: 1px dashed #f0e4db;
            -webkit-tap-highlight-color: transparent;
        }
        .item-row:last-child { border-bottom: none; }
        .item-row:hover { background: #fcf8f5; }
        .item-row.expanded {
            background: #fcf8f5;
            border-bottom-color: transparent;
            padding: 0.9rem 1rem 1rem;
        }

        .item-main {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto 30px;
            gap: 1.2rem;
            align-items: center;
        }

        .item-name {
            font-weight: 600;
            font-size: 1rem;
            color: #2d1b0e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .item-qty {
            color: #8b6b55;
            font-size: 0.95rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .item-subtotal {
            font-weight: 600;
            color: #6f4e37;
            font-size: 0.98rem;
            text-align: right;
            white-space: nowrap;
            min-width: 88px;
            font-variant-numeric: tabular-nums;
        }

        /* --- Pencil hint (right side of every row) --- */
        .item-edit-hint {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #f3e8e0;
            color: #8b6b55;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.72rem;
            transition: all 0.25s ease;
            flex-shrink: 0;
        }
        .item-row:hover .item-edit-hint {
            background: #e8d9cf;
            color: #6f4e37;
        }
        .item-row.expanded .item-edit-hint {
            background: #6f4e37;
            color: #fff;
            transform: rotate(180deg);
        }
        .item-edit-hint .icon-pencil { display: inline; }
        .item-edit-hint .icon-check  { display: none; }
        .item-row.expanded .item-edit-hint .icon-pencil { display: none; }
        .item-row.expanded .item-edit-hint .icon-check  { display: inline; }

        /* --- Expanded controls (slide-down) --- */
        .item-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.9rem;
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            margin-top: 0;
            padding-top: 0;
            border-top: 1px solid transparent;
            transition:
                max-height 0.3s cubic-bezier(0.22, 1, 0.36, 1),
                opacity 0.2s ease,
                margin-top 0.3s ease,
                padding-top 0.3s ease;
        }
        .item-row.expanded .item-controls {
            max-height: 90px;
            opacity: 1;
            margin-top: 0.95rem;
            padding-top: 0.95rem;
            border-top-color: #f0e4db;
        }

        .ctrl-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: none;
            background: #fff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
            color: #2d1b0e;
            box-shadow: 0 2px 8px rgba(80, 40, 20, 0.10);
            transition: all 0.15s ease;
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
        }
        .ctrl-btn:hover {
            background: #6f4e37;
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(111, 78, 55, 0.30);
        }
        .ctrl-btn:active { transform: translateY(0) scale(0.94); }

        .ctrl-btn.ctrl-delete { background: #fef2f2; color: #dc2626; }
        .ctrl-btn.ctrl-delete:hover {
            background: #dc2626;
            color: #fff;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.30);
        }

        .ctrl-qty {
            min-width: 38px;
            text-align: center;
            font-weight: 700;
            font-size: 1.05rem;
            color: #2d1b0e;
            font-variant-numeric: tabular-nums;
        }

        .item-row.removing {
            opacity: 0;
            max-height: 0;
            padding: 0;
            margin: 0;
            border: none;
            overflow: hidden;
            transition: all 0.28s ease;
        }

        /* --- Inline total (footer of items card) --- */
        .items-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.1rem 1.3rem;
            margin-top: 1.3rem;
            background: linear-gradient(135deg, #fcf8f5, #f3e8e0);
            border: 1px solid #f0e4db;
            border-radius: 0.95rem;
        }
        .items-total .label {
            font-weight: 700;
            font-size: 0.82rem;
            color: #8b6b55;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .items-total .amount {
            font-size: 1.35rem;
            font-weight: 800;
            color: #2d1b0e;
            font-variant-numeric: tabular-nums;
        }

        /* --- Note --- */
        .review-note-label {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-weight: 600;
            color: #2d1b0e;
            margin-bottom: 0.55rem;
            font-size: 0.92rem;
        }
        .review-note-label i { color: #6f4e37; }
        .review-note-textarea {
            display: block;
            width: 100%;
            max-width: 100%;
            padding: 0.85rem 1rem;
            border-radius: 0.85rem;
            border: 1px solid #e8d9cf;
            font-family: inherit;
            font-size: 0.95rem;
            resize: vertical;
            background: #fcf8f5;
            color: #2d1b0e;
            min-height: 78px;
            transition: 0.2s;
        }
        .review-note-textarea:focus {
            border-color: #6f4e37;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(111, 78, 55, 0.10);
            outline: none;
        }

        /* ============ FLOATING ACTION BAR ============ */
        .review-actions {
            position: fixed;
            left: 50%;
            bottom: 1.5rem;
            transform: translateX(-50%);
            display: flex;
            gap: 0.7rem;
            padding: 0.65rem;
            min-width: 420px;
            background: rgba(255, 255, 255, 0.94);
            border-radius: 100px;
            border: 1px solid rgba(240, 228, 219, 0.9);
            box-shadow:
                0 20px 60px -12px rgba(80, 40, 20, 0.22),
                0 4px 12px rgba(0, 0, 0, 0.06);
            backdrop-filter: blur(14px) saturate(140%);
            -webkit-backdrop-filter: blur(14px) saturate(140%);
            z-index: 900;
            animation: reviewBarIn 0.4s cubic-bezier(0.22, 1, 0.36, 1);
        }
        @keyframes reviewBarIn {
            from { transform: translate(-50%, 120%); opacity: 0; }
            to   { transform: translate(-50%, 0);    opacity: 1; }
        }

        .review-actions .btn {
            flex: 1;
            border: none;
            padding: 1rem 1.6rem;
            border-radius: 100px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            transition: all 0.18s ease;
            font-family: inherit;
            text-decoration: none;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
            letter-spacing: 0.01em;
        }

        .review-actions .btn-cancel {
            background: #fff;
            color: #991b1b;
            border: 1.5px solid #fecaca;
        }
        .review-actions .btn-cancel:hover {
            background: #fef2f2;
            border-color: #fca5a5;
            transform: translateY(-1px);
        }
        .review-actions .btn-cancel:active { transform: translateY(0); }

        .review-actions .btn-confirm {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #fff;
            box-shadow: 0 8px 22px -6px rgba(34, 197, 94, 0.55), 0 2px 6px rgba(0, 0, 0, 0.06);
        }
        .review-actions .btn-confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px -6px rgba(34, 197, 94, 0.65), 0 2px 6px rgba(0, 0, 0, 0.08);
        }
        .review-actions .btn-confirm:active {
            transform: translateY(0) scale(0.99);
        }
        .review-actions .btn-confirm:disabled {
            background: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
            box-shadow: none;
        }

        @media (max-width: 560px) {
            .review-container { padding-bottom: 10rem; }
            .review-section { padding: 1.2rem 1.1rem 1.2rem; }
            .item-main { gap: 0.7rem; grid-template-columns: minmax(0, 1fr) auto auto 28px; }
            .item-row { padding: 0.85rem 0.3rem; }
            .item-edit-hint { width: 28px; height: 28px; font-size: 0.68rem; }
            .items-total .amount { font-size: 1.2rem; }
            .review-actions {
                left: 1rem;
                right: 1rem;
                bottom: 1rem;
                transform: none;
                min-width: 0;
                animation: reviewBarInMobile 0.4s cubic-bezier(0.22, 1, 0.36, 1);
            }
            @keyframes reviewBarInMobile {
                from { transform: translateY(120%); opacity: 0; }
                to   { transform: translateY(0);    opacity: 1; }
            }
            .review-actions .btn { padding: 0.95rem 1rem; font-size: 0.95rem; }
        }

        .empty-review {
            text-align: center;
            padding: 3rem 1rem;
            color: #8b6b55;
        }
        .empty-review i { font-size: 3rem; color: #d1bfae; display: block; margin-bottom: 0.6rem; }
    </style>

    <div class="review-container">

        <div class="review-header">
            <div class="icon"><i class="fas fa-clipboard-check"></i></div>
            <h1>Review Your Order</h1>
            <p>Tap any item to adjust quantity or remove it.</p>
            <div class="review-table-badge">
                <i class="fas fa-chair"></i> Table <?= htmlspecialchars($table) ?>
            </div>
        </div>

        <div class="review-section">
            <div class="review-section-title"><i class="fas fa-utensils"></i> Your Items</div>

            <?php if (empty($itemDetails)): ?>
                <div class="empty-review">
                    <i class="fas fa-cart-shopping"></i>
                    Your order is empty.
                </div>
            <?php else: ?>
                <div class="item-list" id="itemsList">
                    <?php foreach ($itemDetails as $item): ?>
                        <div class="item-row" data-id="<?= (int)$item['id'] ?>" data-price="<?= (int)$item['price'] ?>">
                            <div class="item-main">
                                <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                                <span class="item-qty">× <span class="qty-value"><?= (int)$item['qty'] ?></span></span>
                                <span class="item-subtotal"><span class="subtotal-value"><?= number_format($item['subtotal']) ?></span> T</span>
                                <span class="item-edit-hint" aria-hidden="true">
                                    <i class="fas fa-pencil icon-pencil"></i>
                                    <i class="fas fa-check icon-check"></i>
                                </span>
                            </div>
                            <div class="item-controls">
                                <button type="button" class="ctrl-btn ctrl-minus" aria-label="Decrease quantity">−</button>
                                <span class="ctrl-qty"><?= (int)$item['qty'] ?></span>
                                <button type="button" class="ctrl-btn ctrl-plus" aria-label="Increase quantity">+</button>
                                <button type="button" class="ctrl-btn ctrl-delete" aria-label="Remove item"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Inline total — right after the last item, inside the card -->
                <div class="items-total">
                    <span class="label">Total</span>
                    <span class="amount"><span id="totalDisplay"><?= number_format($totalPrice) ?></span> T</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="review-section">
            <label class="review-note-label" for="reviewNote">
                <i class="fas fa-pen"></i> Special instructions
            </label>
            <textarea id="reviewNote" class="review-note-textarea"
                      placeholder="e.g., No sugar, extra napkins..."><?= htmlspecialchars($customerNote) ?></textarea>
        </div>

    </div>

    <!-- Floating action bar -->
    <div class="review-actions">
        <a href="submit_order.php?cancel=1&table=<?= (int)$table ?>" class="btn btn-cancel">
            <i class="fas fa-times"></i> Cancel
        </a>
        <button type="button" id="confirmBtn" class="btn btn-confirm">
            <i class="fas fa-check"></i> Confirm Order
        </button>
    </div>

    <!-- Hidden form used to submit the final cart on Confirm -->
    <form method="POST" action="submit_order.php" id="confirmForm" style="display:none;">
        <input type="hidden" name="confirm" value="1">
        <input type="hidden" name="items_json" id="confirmItemsJson" value="">
        <input type="hidden" name="customer_note" id="confirmNote" value="">
    </form>

    <!-- Bootstrap data for the review script -->
    <script>
    window.__REVIEW__ = <?= json_encode($jsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>

    <!-- External review script -->
    <script src="../../assets/js/client/review.js"></script>

<?php else: ?>

    <!-- ============ NORMAL THANK-YOU MODE ============ -->
    <div class="container">
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
    </div>

<?php endif; ?>
<?php
$content = ob_get_clean();
$layout = new ClientLayout();
$layout->setTitle($reviewMode ? 'Review Order' : 'Order Confirmed')->setContent($content);
$layout->render();