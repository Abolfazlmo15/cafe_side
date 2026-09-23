<?php
// public/admin/qr.php – QR management (Vue-powered)
// ==================================================
// Phase 4.2: Vue-rendered table, modal editing, no page reloads.

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/api.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';

$pdo = getDbConnection();

// ================================================================
// ====== QR-SPECIFIC HELPERS ======
// ================================================================

function qrGetMaxTables($pdo) {
    $maxTables = (int) MAX_TABLES_FALLBACK;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'max_tables'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) {
        $maxTables = max(1, (int)$row['setting_value']);
    }
    return $maxTables;
}

function qrGetFullPath($relativePath) {
    if (empty($relativePath)) return null;
    return __DIR__ . '/../..' . '/' . ltrim($relativePath, '/');
}

function qrBuildImageUrl($relativePath, $addCacheBuster = true) {
    if (empty($relativePath)) return null;
    $url = BASE_URL . '/' . ltrim($relativePath, '/');
    if ($addCacheBuster) {
        $fullPath = qrGetFullPath($relativePath);
        if ($fullPath && file_exists($fullPath)) {
            $url .= '?v=' . filemtime($fullPath);
        }
    }
    return $url;
}

function qrBuildThumbnailUrl($relativePath) {
    if (empty($relativePath)) return null;
    $thumbPath = preg_replace('/\.([^.]+)$/', '_thumb.$1', $relativePath);
    $fullThumbPath = qrGetFullPath($thumbPath);
    if ($fullThumbPath && file_exists($fullThumbPath)) {
        return qrBuildImageUrl($thumbPath, true);
    }
    return qrBuildImageUrl($relativePath, true);
}

function qrDeleteImageFiles($relativePath) {
    if (empty($relativePath)) return false;
    $deleted = false;
    $fullPath = qrGetFullPath($relativePath);
    if ($fullPath && file_exists($fullPath)) {
        $deleted = @unlink($fullPath) || $deleted;
    }
    $thumbPath = preg_replace('/\.([^.]+)$/', '_thumb.$1', $relativePath);
    $fullThumbPath = qrGetFullPath($thumbPath);
    if ($fullThumbPath && file_exists($fullThumbPath)) {
        $deleted = @unlink($fullThumbPath) || $deleted;
    }
    return $deleted;
}

function qrGetCleanPath($tableNum, $extension = 'png') {
    return "assets/images/QRCodes/table_{$tableNum}.{$extension}";
}

function qrCreateThumbnail($sourcePath, $thumbPath, $maxWidth = 100, $maxHeight = 100) {
    if (!function_exists('imagecreatefrompng')) return false;
    $info = @getimagesize($sourcePath);
    if (!$info) return false;
    $src = null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($sourcePath);  break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($sourcePath);  break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($sourcePath); break;
        default: return false;
    }
    if (!$src) return false;
    $width = imagesx($src);
    $height = imagesy($src);
    if ($width <= 0 || $height <= 0) { imagedestroy($src); return false; }
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    $success = imagepng($thumb, $thumbPath);
    imagedestroy($src);
    imagedestroy($thumb);
    return $success;
}

function qrBuildListData($pdo) {
    $stmt = $pdo->query("SELECT table_number, qr_code_url, qr_image_path FROM tables ORDER BY table_number");
    $rows = $stmt->fetchAll();

    $maxTables = qrGetMaxTables($pdo);
    $tables = [];
    $activeCount = 0;
    $inactiveCount = 0;
    $usedNumbers = [];

    foreach ($rows as $row) {
        $num = (int)$row['table_number'];
        $usedNumbers[] = $num;
        $hasImage = !empty($row['qr_image_path']);

        $imageUrl = $hasImage ? qrBuildImageUrl($row['qr_image_path']) : null;
        $thumbUrl = $hasImage ? qrBuildThumbnailUrl($row['qr_image_path']) : null;

        if ($hasImage) $activeCount++; else $inactiveCount++;

        $tables[] = [
            'table_number' => $num,
            'qr_url'       => $row['qr_code_url'] ?? '',
            'has_image'    => $hasImage,
            'image'        => $imageUrl,
            'thumb'        => $thumbUrl,
        ];
    }

    $nextAvailable = null;
    for ($i = 1; $i <= $maxTables; $i++) {
        if (!in_array($i, $usedNumbers, true)) {
            $nextAvailable = $i;
            break;
        }
    }

    return [
        'tables'         => $tables,
        'max_tables'     => $maxTables,
        'active_count'   => $activeCount,
        'inactive_count' => $inactiveCount,
        'existing_count' => count($tables),
        'next_available' => $nextAvailable,
    ];
}

// ================================================================
// ====== API ENDPOINTS ======
// ================================================================
if (isset($_GET['api'])) {
    $api = $_GET['api'];

    if ($api === 'qr_list' || $api === 'poll') {
        $clientVersion = isset($_GET['version']) ? (int)$_GET['version'] : 0;
        $serverVersion = apiGetVersion($pdo);

        if ($api === 'poll' && $clientVersion > 0 && $clientVersion === $serverVersion) {
            apiRespondJson(['unchanged' => true, 'version' => $serverVersion]);
        }

        $data = qrBuildListData($pdo);
        apiRespondJson([
            'unchanged' => false,
            'version'   => $serverVersion,
            'data'      => $data,
        ]);
    }

    if ($api === 'qr_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $tableNum = (int)($_POST['table_number'] ?? 0);
        $newUrl   = trim($_POST['qr_url'] ?? '');

        if ($tableNum <= 0 || $newUrl === '') {
            apiRespondJson(['error' => 'Table number and URL are required.'], 400);
        }

        try {
            $stmt = $pdo->prepare("UPDATE tables SET qr_code_url = ? WHERE table_number = ?");
            $stmt->execute([$newUrl, $tableNum]);

            if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['qr_image'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['png', 'jpg', 'jpeg'];
                if (in_array($ext, $allowed, true)) {
                    $cleanPath = qrGetCleanPath($tableNum, $ext);
                    $targetDir = __DIR__ . '/../../assets/images/QRCodes/';
                    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                    $targetPath = $targetDir . 'table_' . $tableNum . '.' . $ext;

                    $stmtOld = $pdo->prepare("SELECT qr_image_path FROM tables WHERE table_number = ?");
                    $stmtOld->execute([$tableNum]);
                    $oldRow = $stmtOld->fetch();
                    if ($oldRow && $oldRow['qr_image_path']) {
                        qrDeleteImageFiles($oldRow['qr_image_path']);
                    }

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $thumbPath = $targetDir . 'table_' . $tableNum . '_thumb.' . $ext;
                        qrCreateThumbnail($targetPath, $thumbPath);

                        $stmt = $pdo->prepare("UPDATE tables SET qr_image_path = ? WHERE table_number = ?");
                        $stmt->execute([$cleanPath, $tableNum]);
                    }
                }
            }

            apiBumpVersion($pdo);
            apiRespondJson(['success' => true, 'table_number' => $tableNum]);
        } catch (PDOException $e) {
            error_log('qr_save failed: ' . $e->getMessage());
            apiRespondJson(['error' => 'Database error. Please try again.'], 500);
        }
    }

    if ($api === 'qr_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $maxTables = qrGetMaxTables($pdo);

        $stmt = $pdo->query("SELECT table_number FROM tables");
        $used = array_map('intval', array_column($stmt->fetchAll(), 'table_number'));

        $next = null;
        for ($i = 1; $i <= $maxTables; $i++) {
            if (!in_array($i, $used, true)) { $next = $i; break; }
        }

        if ($next === null) {
            apiRespondJson(['error' => 'No available table slots (1..' . $maxTables . ' are all taken).'], 400);
        }

        try {
            $url = getTableQRUrl($next);
            $stmt = $pdo->prepare("INSERT INTO tables (table_number, qr_code_url) VALUES (?, ?)");
            $stmt->execute([$next, $url]);
            apiBumpVersion($pdo);
            apiRespondJson(['success' => true, 'table_number' => $next]);
        } catch (PDOException $e) {
            error_log('qr_add failed: ' . $e->getMessage());
            apiRespondJson(['error' => 'Database error.'], 500);
        }
    }

    if ($api === 'qr_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $tableNum = (int)($_POST['table_number'] ?? 0);
        if ($tableNum <= 0) apiRespondJson(['error' => 'Invalid table number.'], 400);

        try {
            $stmt = $pdo->prepare("SELECT qr_image_path FROM tables WHERE table_number = ?");
            $stmt->execute([$tableNum]);
            $row = $stmt->fetch();
            if ($row && $row['qr_image_path']) {
                qrDeleteImageFiles($row['qr_image_path']);
            }
            $stmt = $pdo->prepare("DELETE FROM tables WHERE table_number = ?");
            $stmt->execute([$tableNum]);
            apiBumpVersion($pdo);
            apiRespondJson(['success' => true, 'table_number' => $tableNum]);
        } catch (PDOException $e) {
            error_log('qr_delete failed: ' . $e->getMessage());
            apiRespondJson(['error' => 'Database error.'], 500);
        }
    }

    apiRespondJson(['error' => 'Unknown API endpoint'], 404);
}

// ================================================================
// ====== PAGE SETUP ======
// ================================================================
$initialData    = qrBuildListData($pdo);
$initialVersion = apiGetVersion($pdo);

$layout = new AdminLayout();
$layout->setTitle('QR Codes')->setActive('qr');

ob_start();
?>
<link rel="stylesheet" href="../../assets/css/admin/qr.css">

<div id="vue-qr-root"></div>

<script>
window.__QR_DATA__ = {
    version: <?= (int)$initialVersion ?>,
    data:    <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<script src="../../assets/js/admin/qr-vue.js"></script>

<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();