<?php
// src/layout/components/about_drawer.php – About drawer HTML (used in client menu)
// =================================================================================

// Load about content from settings
require_once __DIR__ . '/../../database.php';
$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'about_content'");
$stmt->execute();
$row = $stmt->fetch();
$aboutContent = $row['setting_value'] ?? '{}';
$aboutData = json_decode($aboutContent, true);

// Default data if JSON is invalid or empty
if (!is_array($aboutData) || empty($aboutData)) {
    $aboutData = [
        'welcome' => 'Welcome to ' . SITE_NAME . ' — where every cup tells a story.',
        'offerings' => "Hand‑crafted espresso drinks\nFreshly brewed pour‑overs\nArtisan pastries and snacks\nPlant‑based milk alternatives",
        'location' => '123 Coffee Lane, Brewtown',
        'hours' => 'Mon – Sun: 7:00 AM – 10:00 PM',
        'phone' => '+1 (555) 123‑4567',
        'email' => 'hello@brewverse.cafe'
    ];
}

$welcome = htmlspecialchars($aboutData['welcome'] ?? '');
$offerings = $aboutData['offerings'] ?? '';
$location = htmlspecialchars($aboutData['location'] ?? '');
$hours = htmlspecialchars($aboutData['hours'] ?? '');
$phone = htmlspecialchars($aboutData['phone'] ?? '');
$email = htmlspecialchars($aboutData['email'] ?? '');

// Split offerings into lines
$offeringLines = array_filter(array_map('trim', explode("\n", $offerings)));
?>
<!-- Off‑canvas About Drawer -->
<div class="drawer-overlay" id="drawerOverlay"></div>
<div class="drawer" id="aboutDrawer">
    <div class="drawer-header">
        <h2><i class="fas fa-info-circle"></i> About <?= SITE_NAME ?></h2>
        <button class="drawer-close" id="drawerClose">&times;</button>
    </div>
    <div class="drawer-body">
        <p><strong><?= $welcome ?></strong></p>

        <?php if (!empty($offeringLines)): ?>
            <h3>☕ Our Offerings</h3>
            <ul>
                <?php foreach ($offeringLines as $item): ?>
                    <li><?= htmlspecialchars($item) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3>📍 Visit Us</h3>
        <p><i class="fas fa-map-pin"></i> <?= $location ?></p>
        <p><i class="fas fa-clock"></i> <?= $hours ?></p>
        <p><i class="fas fa-phone"></i> <?= $phone ?></p>
        <p><i class="fas fa-envelope"></i> <?= $email ?></p>
    </div>
</div>