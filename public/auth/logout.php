<?php
// public/auth/logout.php – Destroy session and redirect to login
// =================================================================

require_once __DIR__ . '/../../bootstrap.php';

session_start();
session_destroy();

// Redirect to login page using URL constant
header('Location: ' . URL_LOGIN);
exit;
    