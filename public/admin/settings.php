<?php
// public/admin/settings.php – Admin settings management
// ======================================================

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';

$pdo = getDbConnection();

$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $allowedKeys = ['base_url', 'max_tables', 'admin_password', 'site_name'];
    try {
        foreach ($allowedKeys as $key) {
            $value = trim($_POST[$key] ?? '');
            if ($key === 'max_tables') {
                $value = (int) $value;
                if ($value < 1) $value = 1;
            }
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$key, $value]);
        }

        // Save about data as JSON
        $aboutData = [
            'welcome' => trim($_POST['about_welcome'] ?? ''),
            'offerings' => trim($_POST['about_offerings'] ?? ''),
            'location' => trim($_POST['about_location'] ?? ''),
            'hours' => trim($_POST['about_hours'] ?? ''),
            'phone' => trim($_POST['about_phone'] ?? ''),
            'email' => trim($_POST['about_email'] ?? ''),
        ];
        $aboutJson = json_encode($aboutData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('about_content', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$aboutJson]);

        $message = '✅ Settings saved successfully!';
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = '❌ Error saving settings: ' . $e->getMessage();
        $messageType = 'error';
    }
}

$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$defaults = [
    'base_url' => 'http://localhost/cafe-qr/public',
    'max_tables' => 20,
    'admin_password' => 'cafe123',
    'site_name' => 'QR Café',
    'about_content' => '{}'
];
foreach ($defaults as $key => $default) {
    if (!isset($settings[$key])) $settings[$key] = $default;
}

// Decode about data
$aboutContent = $settings['about_content'] ?? '{}';
$aboutData = json_decode($aboutContent, true);
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

$layout = new AdminLayout();
$layout->setTitle('Settings')->setActive('settings');

ob_start();
?>
<!-- External CSS -->
<link rel="stylesheet" href="../../assets/css/admin/settings.css">

<?php if ($message): ?>
    <div class="message message-<?= $messageType === 'success' ? 'success' : 'error' ?> dismissible">
        <?= $message ?>
        <button type="button" class="close-btn" aria-label="Close">&times;</button>
    </div>
<?php endif; ?>

<form method="POST">
    <div class="form-group">
        <label for="base_url">Base URL <i class="fas fa-link"></i></label>
        <input type="text" id="base_url" name="base_url" value="<?= htmlspecialchars($settings['base_url']) ?>">
        <div class="help">The full URL to your public folder (e.g., https://cafe.example.com/public). No trailing slash.</div>
    </div>
    <div class="form-group">
        <label for="max_tables">Maximum Number of Tables <i class="fas fa-chair"></i></label>
        <input type="number" id="max_tables" name="max_tables" value="<?= (int)$settings['max_tables'] ?>" min="1">
        <div class="help">How many QR codes to generate / table numbers to support. Changes reflect immediately in QR management.</div>
    </div>
    <div class="form-group">
        <label for="admin_password">Admin Password <i class="fas fa-lock"></i></label>
        <input type="text" id="admin_password" name="admin_password" value="<?= htmlspecialchars($settings['admin_password']) ?>">
        <div class="help">The password used to log into the admin panel.</div>
    </div>
    <div class="form-group">
        <label for="site_name">Site Name <i class="fas fa-store"></i></label>
        <input type="text" id="site_name" name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>">
        <div class="help">Displayed in the admin sidebar and page titles.</div>
    </div>

    <hr style="margin:1.5rem 0; border:0; border-top:2px solid #f0e4db;">

    <h3 style="font-size:1.2rem; margin-bottom:0.8rem; color:#2d1b0e;"><i class="fas fa-info-circle"></i> About Page Content</h3>
    <p style="color:#5a3f2e; font-size:0.9rem; margin-bottom:1rem;">This content appears in the "About" drawer on the customer menu page.</p>

    <div class="form-group">
        <label for="about_welcome">Welcome Message <i class="fas fa-heart"></i></label>
        <textarea id="about_welcome" name="about_welcome" rows="2" style="width:100%; padding:0.6rem; border:1px solid #e8d9cf; border-radius:0.5rem; font-family:inherit; font-size:0.95rem;"><?= htmlspecialchars($aboutData['welcome']) ?></textarea>
        <div class="help">A warm welcome message shown at the top of the about section.</div>
    </div>

    <div class="form-group">
        <label for="about_offerings">Our Offerings <i class="fas fa-mug-saucer"></i></label>
        <textarea id="about_offerings" name="about_offerings" rows="4" style="width:100%; padding:0.6rem; border:1px solid #e8d9cf; border-radius:0.5rem; font-family:inherit; font-size:0.95rem;"><?= htmlspecialchars($aboutData['offerings']) ?></textarea>
        <div class="help">One item per line (e.g., "Espresso drinks", "Fresh pastries", etc.).</div>
    </div>

    <div class="form-group">
        <label for="about_location">Location <i class="fas fa-map-pin"></i></label>
        <input type="text" id="about_location" name="about_location" value="<?= htmlspecialchars($aboutData['location']) ?>" style="width:100%; padding:0.6rem; border:1px solid #e8d9cf; border-radius:0.5rem; font-size:0.95rem;">
        <div class="help">Your café address.</div>
    </div>

    <div class="form-group">
        <label for="about_hours">Opening Hours <i class="fas fa-clock"></i></label>
        <input type="text" id="about_hours" name="about_hours" value="<?= htmlspecialchars($aboutData['hours']) ?>" style="width:100%; padding:0.6rem; border:1px solid #e8d9cf; border-radius:0.5rem; font-size:0.95rem;">
        <div class="help">e.g., "Mon – Sun: 7:00 AM – 10:00 PM".</div>
    </div>

    <div class="form-group">
        <label for="about_phone">Phone Number <i class="fas fa-phone"></i></label>
        <input type="text" id="about_phone" name="about_phone" value="<?= htmlspecialchars($aboutData['phone']) ?>" style="width:100%; padding:0.6rem; border:1px solid #e8d9cf; border-radius:0.5rem; font-size:0.95rem;">
        <div class="help">Contact phone number.</div>
    </div>

    <div class="form-group">
        <label for="about_email">Email Address <i class="fas fa-envelope"></i></label>
        <input type="email" id="about_email" name="about_email" value="<?= htmlspecialchars($aboutData['email']) ?>" style="width:100%; padding:0.6rem; border:1px solid #e8d9cf; border-radius:0.5rem; font-size:0.95rem;">
        <div class="help">Contact email address.</div>
    </div>

    <button type="submit" name="save_settings" class="submit-btn"><i class="fas fa-save"></i> Save Settings</button>
</form>

<hr style="margin: 2rem 0;">
<p style="font-size:0.9rem; color:#6b7280;">
    <i class="fas fa-info-circle"></i> Changes take effect immediately. If you change the Base URL, remember to regenerate QR codes and update any printed materials.
</p>

<!-- External JS -->
<script src="../../assets/js/admin/settings.js"></script>
<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();