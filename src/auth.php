<?php
require_once __DIR__ . '/urls.php';
ini_set('session.gc_probability', '0');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ' . URL_LOGIN);
    exit;
}