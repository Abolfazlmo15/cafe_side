<?php
// public/client/update_order.php
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/api.php';          // ← ADDED

$pdo = getDbConnection();
$orderId = $_POST['id'] ?? $_GET['id'] ?? null;
if (!$orderId || !is_numeric($orderId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid order ID']);
    exit;
}
try {
    $stmt = $pdo->prepare("UPDATE orders SET is_ready = 1, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$orderId]);

    apiBumpVersion($pdo);

    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest') {
        header('Location: ' . URL_ADMIN_ORDERS);
        exit;
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
