<?php
// src/auth.php – Reusable authentication check
// Redirects to /auth/login.php

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /cafe-qr/public/auth/login.php');
    exit;
}
        