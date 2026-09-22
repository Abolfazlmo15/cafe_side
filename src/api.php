<?php
// src/api.php – Shared helpers for the JSON API layer
// ======================================================
// Used by public/client/menu.php to serve JSON to the modern
// client. Does NOT replace the legacy HTML endpoints — both
// coexist during migration.

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/urls.php';


/**
 * Read the current data version from settings.
 * Defaults to 1 if the row is missing.
 */
function apiGetVersion($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'data_version'");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? (int)$row['setting_value'] : 1;
    } catch (PDOException $e) {
        return 1;
    }
}


/**
 * Increment the data version. Called whenever underlying data changes.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE so it works even if the
 * setting row was never created.
 */
function apiBumpVersion($pdo) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO settings (setting_key, setting_value) VALUES ('data_version', '1')
            ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1
        ");
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        error_log('apiBumpVersion failed: ' . $e->getMessage());
        return false;
    }
}


/**
 * Send a JSON response with proper headers and exit.
 */
function apiRespondJson($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


/**
 * Build the menu items + categories for JSON delivery.
 * Includes image URLs and cache-busting.
 */
function apiBuildMenuData($pdo) {
    $sql = "SELECT id, name, price, category, description, image_path, available, sort_order
            FROM menu_items
            WHERE available = 1
            ORDER BY
                CASE WHEN sort_order != 0 THEN 0 ELSE 1 END,
                sort_order ASC,
                name ASC";
    $stmt = $pdo->query($sql);
    $items = $stmt->fetchAll();

    $categories = [];
    $itemsOut = [];

    foreach ($items as $item) {
        $cat = $item['category'] ?: 'Other';
        if (!in_array($cat, $categories, true)) {
            $categories[] = $cat;
        }

        // Build image URL with cache-buster
        $imageUrl = null;
        if (!empty($item['image_path'])) {
            $relativePath = ltrim($item['image_path'], '/');
            $imageUrl = BASE_URL . '/' . $relativePath;
            $fullPath = __DIR__ . '/../' . $relativePath;
            if (file_exists($fullPath)) {
                $imageUrl .= '?v=' . filemtime($fullPath);
            }
        }

        $itemsOut[] = [
            'id'          => (int)$item['id'],
            'name'        => $item['name'],
            'price'       => (int)$item['price'],
            'category'    => $cat,
            'description' => $item['description'] ?? 'Served with love',
            'image'       => $imageUrl,
            'sort_order'  => (int)$item['sort_order'],
        ];
    }
    sort($categories);

    return [
        'items'      => $itemsOut,
        'categories' => array_merge(['All'], $categories),
    ];
}


/**
 * Build the table validity payload.
 */
function apiBuildTableData($pdo, $tableNumber) {
    $valid = false;
    if ($tableNumber > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tables WHERE table_number = ?");
        $stmt->execute([$tableNumber]);
        $valid = $stmt->fetchColumn() > 0;
    }
    return [
        'number'  => (int)$tableNumber,
        'valid'   => $valid,
        'message' => $valid ? '' : 'Please use a valid table QR code. If you need assistance, ask our staff.',
    ];
}


/**
 * Build the about content payload.
 */
function apiBuildAboutData($pdo) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'about_content'");
    $stmt->execute();
    $row = $stmt->fetch();
    $aboutContent = $row['setting_value'] ?? '{}';
    $aboutData = json_decode($aboutContent, true);

    if (!is_array($aboutData) || empty($aboutData)) {
        $aboutData = [
            'welcome'   => 'Welcome to ' . SITE_NAME . ' — where every cup tells a story.',
            'offerings' => "Hand-crafted espresso drinks\nFreshly brewed pour-overs\nArtisan pastries and snacks\nPlant-based milk alternatives",
            'location'  => '123 Coffee Lane, Brewtown',
            'hours'     => 'Mon – Sun: 7:00 AM – 10:00 PM',
            'phone'     => '+1 (555) 123-4567',
            'email'     => 'hello@brewverse.cafe',
        ];
    }
    return $aboutData;
}


/**
 * Build the customer's order banner payload (structured, no HTML).
 */
function apiBuildOrdersData($pdo, $tableNumber, $deviceToken) {
    $pending = [];
    $ready = [];
    $itemDetails = [];

    if ($tableNumber > 0 && !empty($deviceToken)) {
        // Verify table is valid first
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tables WHERE table_number = ?");
        $stmt->execute([$tableNumber]);
        if ($stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("
                SELECT id, items, is_ready, created_at
                FROM orders
                WHERE table_number = ? AND device_token = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$tableNumber, $deviceToken]);
            $allOrders = $stmt->fetchAll();

            // Aggregate items from pending orders
            $aggregated = [];
            foreach ($allOrders as $order) {
                if ((int)$order['is_ready'] === 0) {
                    $pending[] = (int)$order['id'];
                    $items = json_decode($order['items'], true);
                    if ($items) {
                        foreach ($items as $id => $qty) {
                            $aggregated[$id] = ($aggregated[$id] ?? 0) + (int)$qty;
                        }
                    }
                } else {
                    $ready[] = (int)$order['id'];
                }
            }

            // Resolve item names for aggregated items
            if (!empty($aggregated)) {
                $ids = array_keys($aggregated);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                $names = [];
                while ($row = $stmt->fetch()) {
                    $names[$row['id']] = $row['name'];
                }
                foreach ($aggregated as $id => $qty) {
                    $name = $names[$id] ?? "Unknown (#$id)";
                    $itemDetails[] = ['name' => $name, 'qty' => (int)$qty];
                }
            }
        }
    }

    return [
        'pending_order_ids' => $pending,
        'ready_order_ids'   => $ready,
        'active_items'      => $itemDetails,
    ];
}