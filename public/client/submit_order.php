<?php
// public/client/submit_order.php
// ==================================================================
// Three responsibilities:
//   1. Normal submission from menu.php → store in session, redirect
//      to the interactive review page (thank_you.php?review=1).
//   2. Confirm from the review page → validate, insert order, redirect
//      to the standard thank-you page.
//   3. Cancel → restore cart quantities, redirect back to the menu.
//
// No order is inserted without an explicit confirm=1 POST.
// ==================================================================

require_once __DIR__ . '/../../bootstrap.php';
  // ← ADDED
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['cancel'])) {
    header('Location: menu.php');
    exit;
}

/**
 * Drop any entry whose id isn't a positive integer or whose qty isn't a
 * positive integer. Caps qty at 99. Returns a clean [int_id => int_qty].
 */
function sanitizeCartItems($items, $maxQty = 99) {
    if (!is_array($items)) return [];
    $clean = [];
    foreach ($items as $id => $qty) {
        $idInt = (int)$id;
        if ($idInt <= 0) continue;
        if (!is_numeric($qty)) continue;
        $qtyInt = (int)$qty;
        if ($qtyInt <= 0) continue;
        if ($qtyInt > $maxQty) $qtyInt = $maxQty;
        $clean[$idInt] = $qtyInt;
    }
    return $clean;
}

function isTableValid($pdo, $table) {
    if ($table <= 0) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tables WHERE table_number = ?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Load current price + availability for a set of item IDs.
 * Returns [id => ['price' => ..., 'available' => ...]].
 */
function loadMenuData($pdo, array $ids) {
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, price, available FROM menu_items WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $out = [];
    while ($row = $stmt->fetch()) {
        $out[(int)$row['id']] = [
            'price'     => (int)$row['price'],
            'available' => (int)$row['available'],
        ];
    }
    return $out;
}

// ================================================================
// ====== PATH 1: CANCEL ======
// ================================================================
if (isset($_GET['cancel']) && $_GET['cancel'] == 1) {
    if (isset($_SESSION['pending_order'])) {
        // Restore the cart quantities so the menu shows them again.
        $_SESSION['pending_quantities'] = sanitizeCartItems($_SESSION['pending_order']['items']);
    }
    unset($_SESSION['pending_order']);
    $table = (int)($_GET['table'] ?? 0);
    header("Location: menu.php?table=$table");
    exit;
}

// ================================================================
// ====== PATH 2: CONFIRM (from review page) ======
// ================================================================
if (isset($_POST['confirm']) && $_POST['confirm'] == 1) {

    if (!isset($_SESSION['pending_order'])) {
        // Session expired or was cleared — nothing to confirm.
        header('Location: menu.php');
        exit;
    }

    $pending      = $_SESSION['pending_order'];
    $table        = (int)$pending['table'];
    $deviceToken  = $pending['device_token'];
    // Note may have been edited on the review page.
    $customerNote = trim($_POST['customer_note'] ?? $pending['note']);

    // The review page sends back the finalised cart as items_json.
    // Fall back to session items if the client somehow didn't send them.
    $postedItems = json_decode($_POST['items_json'] ?? '', true);
    $items = sanitizeCartItems($postedItems);
    if (empty($items)) {
        $items = sanitizeCartItems($pending['items']);
    }

    $pdo = getDbConnection();

    if (!isTableValid($pdo, $table)) {
        unset($_SESSION['pending_order']);
        header("Location: menu.php?table=$table&error=" . urlencode('Invalid table QR code.'));
        exit;
    }

    if (empty($items)) {
        unset($_SESSION['pending_order']);
        header('Location: menu.php?error=' . urlencode('Your order is empty.'));
        exit;
    }

    // Re-validate against the live DB. Drop unavailable items silently.
    $menuData = loadMenuData($pdo, array_keys($items));
    $finalItems = [];
    foreach ($items as $id => $qty) {
        if (isset($menuData[$id]) && $menuData[$id]['available'] == 1) {
            $finalItems[$id] = $qty;
        }
    }

    if (empty($finalItems)) {
        unset($_SESSION['pending_order']);
        header('Location: menu.php?error=' . urlencode('None of your items are available right now.'));
        exit;
    }

    try {
        $sql = "INSERT INTO orders (table_number, items, customer_note, device_token, is_ready, created_at, updated_at)
                VALUES (:table, :items, :note, :token, 0, NOW(), NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':table' => $table,
            ':items' => json_encode($finalItems),
            ':note'  => $customerNote ?: null,
            ':token' => $deviceToken,
        ]);
        $orderId = $pdo->lastInsertId();

        apiBumpVersion($pdo);

        unset($_SESSION['pending_order'], $_SESSION['pending_quantities']);
        header("Location: thank_you.php?id=$orderId&table=$table");
        exit;
    } catch (PDOException $e) {
        error_log('Order insert failed: ' . $e->getMessage());
        header("Location: menu.php?table=$table&error=" . urlencode('Order could not be placed. Please try again.'));
        exit;
    }
}

// ================================================================
// ====== PATH 3: NORMAL SUBMISSION (from menu.php) ======
// ================================================================
// We do NOT insert here. We store the pending order in the session
// and redirect to the review page. Confirm happens on PATH 2 above.

$table        = isset($_POST['table_number']) ? (int)$_POST['table_number'] : 0;
$itemsJson    = isset($_POST['items_json']) ? trim($_POST['items_json']) : '';
$customerNote = isset($_POST['customer_note']) ? trim($_POST['customer_note']) : '';
$deviceToken  = isset($_POST['device_token']) ? trim($_POST['device_token']) : '';

$pdo = getDbConnection();

if (!isTableValid($pdo, $table)) {
    header("Location: menu.php?table=$table&error=" . urlencode('Invalid table QR code.'));
    exit;
}

if (empty($itemsJson)) {
    header("Location: menu.php?table=$table&error=" . urlencode('No items selected.'));
    exit;
}
if (empty($deviceToken)) {
    header("Location: menu.php?table=$table&error=" . urlencode('Device token missing.'));
    exit;
}

$items = sanitizeCartItems(json_decode($itemsJson, true));
if (empty($items)) {
    header("Location: menu.php?table=$table&error=" . urlencode('No valid items selected.'));
    exit;
}

// Store pending order in session. This is the single source of truth
// for the review page. Cleared either by Confirm or Cancel.
$_SESSION['pending_order'] = [
    'table'        => $table,
    'items'        => $items,              // [int_id => int_qty]
    'note'         => $customerNote,
    'device_token' => $deviceToken,
    'created_at'   => time(),
];

// Keep a copy of quantities for "restore on cancel" behavior.
$_SESSION['pending_quantities'] = $items;

header("Location: thank_you.php?review=1&table=$table");
exit;
