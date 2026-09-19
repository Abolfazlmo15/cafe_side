<?php
// public/client/submit_order.php
require_once __DIR__ . '/../../src/database.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: menu.php');
    exit;
}

// Helper function to validate table
function isTableValid($pdo, $table) {
    if ($table <= 0) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tables WHERE table_number = ?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() > 0;
}

// If this is a confirmation after review
if (isset($_POST['confirm']) && $_POST['confirm'] == 1) {
    // Retrieve session data
    if (!isset($_SESSION['pending_order'])) {
        header('Location: menu.php');
        exit;
    }
    $pending = $_SESSION['pending_order'];
    $table = $pending['table'];
    $customerNote = $pending['note'];
    $deviceToken = $pending['device_token'];
    $items = $pending['items']; // items with quantities

    // Validate table
    $pdo = getDbConnection();
    if (!isTableValid($pdo, $table)) {
        unset($_SESSION['pending_order']);
        header("Location: menu.php?table=$table&error=" . urlencode('Invalid table QR code.'));
        exit;
    }

    // Build items array with current prices and availability
    $ids = array_keys($items);
    if (empty($ids)) {
        unset($_SESSION['pending_order']);
        header('Location: menu.php?error=No items');
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, price, available FROM menu_items WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $currentData = [];
    while ($row = $stmt->fetch()) {
        $currentData[$row['id']] = ['price' => $row['price'], 'available' => $row['available']];
    }

    // Build final items (only available items)
    $finalItems = [];
    foreach ($items as $id => $qty) {
        if (isset($currentData[$id]) && $currentData[$id]['available'] == 1) {
            $finalItems[$id] = $qty;
        }
    }
    if (empty($finalItems)) {
        // no available items
        unset($_SESSION['pending_order']);
        header('Location: menu.php?error=No items available');
        exit;
    }
    // Insert order
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
        unset($_SESSION['pending_order']);
        // Clear any pending quantities (if we stored them)
        unset($_SESSION['pending_quantities']);
        header("Location: thank_you.php?id=$orderId&table=$table");
        exit;
    } catch (PDOException $e) {
        error_log('Order insert failed: ' . $e->getMessage());
        header("Location: menu.php?table=$table&error=" . urlencode('Order could not be placed. Please try again.'));
        exit;
    }
}

// If this is a cancel request
if (isset($_GET['cancel']) && $_GET['cancel'] == 1) {
    // Store the quantities in session to pre-fill menu
    if (isset($_SESSION['pending_order'])) {
        $_SESSION['pending_quantities'] = $_SESSION['pending_order']['items'];
    }
    unset($_SESSION['pending_order']);
    $table = $_GET['table'] ?? 0;
    header("Location: menu.php?table=$table");
    exit;
}

// Normal order submission: validate and check for conflicts
$table = isset($_POST['table_number']) ? (int)$_POST['table_number'] : 0;
$itemsJson = isset($_POST['items_json']) ? trim($_POST['items_json']) : '';
$customerNote = isset($_POST['customer_note']) ? trim($_POST['customer_note']) : '';
$deviceToken = isset($_POST['device_token']) ? trim($_POST['device_token']) : '';
$oldPrices = isset($_POST['old_prices']) ? $_POST['old_prices'] : [];

$pdo = getDbConnection();

// Validate table first
if (!isTableValid($pdo, $table)) {
    header("Location: menu.php?table=$table&error=" . urlencode('Invalid table QR code.'));
    exit;
}

$errors = [];
if (empty($itemsJson)) $errors[] = 'No items selected.';
if (empty($deviceToken)) $errors[] = 'Device token missing.';

if (!empty($errors)) {
    $errorMsg = urlencode(implode('; ', $errors));
    header("Location: menu.php?table=$table&error=$errorMsg");
    exit;
}

$items = json_decode($itemsJson, true);
if (!is_array($items) || empty($items)) {
    header("Location: menu.php?table=$table&error=" . urlencode('Invalid items data.'));
    exit;
}

$ids = array_keys($items);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id, price, available FROM menu_items WHERE id IN ($placeholders)");
$stmt->execute($ids);
$dbItems = [];
while ($row = $stmt->fetch()) {
    $dbItems[$row['id']] = ['price' => $row['price'], 'available' => $row['available']];
}

// Check for conflicts
$conflicts = [];
$currentPrices = [];
$currentAvailability = [];
foreach ($items as $id => $qty) {
    if (!isset($dbItems[$id]) || $dbItems[$id]['available'] == 0) {
        $conflicts[$id] = ['reason' => 'unavailable'];
        continue;
    }
    $currentPrices[$id] = $dbItems[$id]['price'];
    $currentAvailability[$id] = $dbItems[$id]['available'];
    // Check price change if old price exists
    if (isset($oldPrices[$id]) && $oldPrices[$id] != $dbItems[$id]['price']) {
        $conflicts[$id] = [
            'reason' => 'price_changed',
            'old_price' => (float)$oldPrices[$id],
            'new_price' => (float)$dbItems[$id]['price']
        ];
    }
}

// If conflicts exist, store in session and redirect to review
if (!empty($conflicts)) {
    $_SESSION['pending_order'] = [
        'table' => $table,
        'items' => $items,
        'note' => $customerNote,
        'device_token' => $deviceToken,
        'conflicts' => $conflicts,
        'current_prices' => $currentPrices,
        'current_availability' => $currentAvailability,
        'old_prices' => $oldPrices,
    ];
    // Also store the original quantities to pre-fill menu if canceled
    $_SESSION['pending_quantities'] = $items;
    header("Location: thank_you.php?review=1&table=$table");
    exit;
}

// No conflicts: proceed with order
try {
    $sql = "INSERT INTO orders (table_number, items, customer_note, device_token, is_ready, created_at, updated_at)
            VALUES (:table, :items, :note, :token, 0, NOW(), NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':table' => $table,
        ':items' => $itemsJson,
        ':note'  => $customerNote ?: null,
        ':token' => $deviceToken,
    ]);
    $orderId = $pdo->lastInsertId();
    header("Location: thank_you.php?id=$orderId&table=$table");
    exit;
} catch (PDOException $e) {
    error_log('Order insert failed: ' . $e->getMessage());
    header("Location: menu.php?table=$table&error=" . urlencode('Order could not be placed. Please try again.'));
    exit;
}