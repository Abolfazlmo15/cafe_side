<?php
require_once __DIR__ . '/config.php';

// Page URLs – use PUBLIC_URL (already includes /public)
define('URL_MENU', PUBLIC_URL . '/client/menu.php');
define('URL_SUBMIT_ORDER', PUBLIC_URL . '/client/submit_order.php');
define('URL_THANK_YOU', PUBLIC_URL . '/client/thank_you.php');
define('URL_UPDATE_ORDER', PUBLIC_URL . '/client/update_order.php');
define('URL_ADMIN', PUBLIC_URL . '/admin/orders.php');
define('URL_ADMIN_ORDERS', PUBLIC_URL . '/admin/orders.php');
define('URL_ADMIN_QR', PUBLIC_URL . '/admin/qr.php');
define('URL_ADMIN_ITEMS', PUBLIC_URL . '/admin/items.php');
define('URL_ADMIN_SETTINGS', PUBLIC_URL . '/admin/settings.php');
define('URL_LOGIN', PUBLIC_URL . '/auth/login.php');
define('URL_LOGOUT', PUBLIC_URL . '/auth/logout.php');

function getTableQRUrl($tableNumber) {
    return URL_MENU . '?table=' . (int)$tableNumber;
}

function getAllTableUrls($maxTables = null) {
    if ($maxTables === null) {
        $maxTables = (int) (defined('MAX_TABLES_FALLBACK') ? MAX_TABLES_FALLBACK : 20);
    }
    $urls = [];
    for ($i = 1; $i <= $maxTables; $i++) {
        $urls[$i] = getTableQRUrl($i);
    }
    return $urls;
}