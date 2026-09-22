<?php
// public/admin/qr.php – QR management with clean path logic
// ==========================================================

session_start(); // <-- added for flash messages

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';
require_once __DIR__ . '/../../src/api.php'; 

$pdo = getDbConnection();

// --- Get max_tables from settings ---
$maxTables = 20;
$stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'max_tables'");
$row = $stmt->fetch();
if ($row) {
    $maxTables = (int)$row['setting_value'];
    if ($maxTables < 1) $maxTables = 1;
}

// --- Fetch existing table records ---
$stmt = $pdo->query("SELECT table_number, qr_code_url, qr_image_path FROM tables ORDER BY table_number");
$tables = $stmt->fetchAll();
$existing = [];
foreach ($tables as $t) {
    $existing[$t['table_number']] = $t;
}

// --- Calculate stats ---
$totalExisting = count($existing);
$activeCount = 0;
$inactiveCount = 0;
foreach ($existing as $r) {
    if (!empty($r['qr_image_path'])) $activeCount++;
    else $inactiveCount++;
}

// ================================================================
// ====== SIMPLE PATH HELPERS ======
// ================================================================

/**
 * Get the full filesystem path for a QR image
 * @param string $relativePath e.g., "assets/images/QRCodes/table_1.png"
 * @return string Full filesystem path
 */
function getFullPath($relativePath) {
    if (empty($relativePath)) return null;
    return __DIR__ . '/../..' . '/' . $relativePath;
}

/**
 * Get the web URL for a QR image
 * @param string $relativePath e.g., "assets/images/QRCodes/table_1.png"
 * @param bool $addCacheBuster Whether to add filemtime for cache-busting
 * @return string Full web URL
 */
function getImageUrl($relativePath, $addCacheBuster = true) {
    if (empty($relativePath)) return null;
    $url = BASE_URL . '/' . $relativePath;
    if ($addCacheBuster) {
        $fullPath = getFullPath($relativePath);
        if ($fullPath && file_exists($fullPath)) {
            $url .= '?v=' . filemtime($fullPath);
        }
    }
    return $url;
}

/**
 * Get the thumbnail URL for a QR image
 * Automatically checks if thumbnail exists, falls back to original
 */
function getThumbnailUrl($relativePath) {
    if (empty($relativePath)) return null;
    // Build thumbnail path: table_n.png -> table_n_thumb.png
    $thumbPath = preg_replace('/\.([^.]+)$/', '_thumb.$1', $relativePath);
    $fullThumbPath = getFullPath($thumbPath);
    if ($fullThumbPath && file_exists($fullThumbPath)) {
        return getImageUrl($thumbPath, true);
    }
    // Fallback to original
    return getImageUrl($relativePath, true);
}

/**
 * Delete image files from filesystem
 * @param string $relativePath e.g., "assets/images/QRCodes/table_1.png"
 * @return bool True if at least one file was deleted
 */
function deleteImageFiles($relativePath) {
    if (empty($relativePath)) return false;
    $deleted = false;
    // Delete original
    $fullPath = getFullPath($relativePath);
    if ($fullPath && file_exists($fullPath)) {
        $deleted = unlink($fullPath) || $deleted;
    }
    // Delete thumbnail
    $thumbPath = preg_replace('/\.([^.]+)$/', '_thumb.$1', $relativePath);
    $fullThumbPath = getFullPath($thumbPath);
    if ($fullThumbPath && file_exists($fullThumbPath)) {
        $deleted = unlink($fullThumbPath) || $deleted;
    }
    return $deleted;
}

/**
 * Get the clean path for a table image (for display in DB)
 * @param int $tableNum
 * @param string $extension
 * @return string e.g., "assets/images/QRCodes/table_1.png"
 */
function getCleanPath($tableNum, $extension = 'png') {
    return "assets/images/QRCodes/table_{$tableNum}.{$extension}";
}

// ================================================================
// ====== THUMBNAIL HELPER ======
// ================================================================
function createThumbnail($sourcePath, $thumbPath, $maxWidth = 100, $maxHeight = 100) {
    if (!function_exists('imagecreatefrompng') && !function_exists('imagecreatefromjpeg') && !function_exists('imagecreatefromgif') && !function_exists('imagecreatefromwebp')) {
        return false;
    }
    $info = getimagesize($sourcePath);
    if (!$info) return false;
    $src = null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG: $src = imagecreatefrompng($sourcePath); break;
        case IMAGETYPE_GIF: $src = imagecreatefromgif($sourcePath); break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($sourcePath); break;
        default: return false;
    }
    if (!$src) return false;
    $width = imagesx($src);
    $height = imagesy($src);
    $ratio = min($maxWidth/$width, $maxHeight/$height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    $success = imagepng($thumb, $thumbPath);
    imagedestroy($src);
    imagedestroy($thumb);
    return $success;
}

// ================================================================
// ====== HANDLE EDIT ======
// ================================================================
$editMessage = '';
$editSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_qr'])) {
    $tableNum = (int)($_POST['table_number'] ?? 0);
    $newUrl = trim($_POST['qr_url'] ?? '');
    if ($tableNum > 0 && $newUrl) {
        // Update URL
        $stmt = $pdo->prepare("UPDATE tables SET qr_code_url = ? WHERE table_number = ?");
        $stmt->execute([$newUrl, $tableNum]);

        // Handle image upload if provided
        if (isset($_FILES['qr_image_edit']) && $_FILES['qr_image_edit']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['qr_image_edit'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            // Only PNG and JPG allowed
            $allowed = ['png', 'jpg', 'jpeg'];
            if (in_array($ext, $allowed)) {
                // Build clean path
                $cleanPath = getCleanPath($tableNum, $ext);
                $targetDir = __DIR__ . '/../../assets/images/QRCodes/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                $targetPath = $targetDir . 'table_' . $tableNum . '.' . $ext;

                // Delete old files
                $stmtOld = $pdo->prepare("SELECT qr_image_path FROM tables WHERE table_number = ?");
                $stmtOld->execute([$tableNum]);
                $oldRow = $stmtOld->fetch();
                if ($oldRow && $oldRow['qr_image_path']) {
                    deleteImageFiles($oldRow['qr_image_path']);
                }

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    // Generate thumbnail
                    $thumbPath = $targetDir . 'table_' . $tableNum . '_thumb.' . $ext;
                    createThumbnail($targetPath, $thumbPath);

                    // Update database with clean path
                    $stmt = $pdo->prepare("UPDATE tables SET qr_image_path = ? WHERE table_number = ?");
                    $stmt->execute([$cleanPath, $tableNum]);
                    $editMessage = "✅ QR updated for Table $tableNum (image uploaded)";
                    $editSuccess = true;
                } else {
                    $editMessage = "❌ Failed to move uploaded file.";
                }
            } else {
                $editMessage = "❌ Invalid file type. Only PNG and JPG allowed.";
            }
        } else {
            // No new image, just URL updated
            $editMessage = "✅ QR URL updated for Table $tableNum";
            $editSuccess = true;
        }

        // Refresh existing
        $stmt = $pdo->query("SELECT table_number, qr_code_url, qr_image_path FROM tables ORDER BY table_number");
        $tables = $stmt->fetchAll();
        $existing = [];
        foreach ($tables as $t) {
            $existing[$t['table_number']] = $t;
        }
        $totalExisting = count($existing);
        $activeCount = 0;
        $inactiveCount = 0;
        foreach ($existing as $r) {
            if (!empty($r['qr_image_path'])) $activeCount++;
            else $inactiveCount++;
        }

        apiBumpVersion($pdo);

        // ---- CHANGED: use session flash and redirect ----
        if ($editSuccess) {
            $_SESSION['flash_message'] = $editMessage;
            $_SESSION['flash_type'] = 'success';
            header('Location: qr.php');
            exit;
        } else {
            // On error, stay on page and show message (no redirect)
            // $editMessage remains, will be displayed below
        }
    } else {
        $editMessage = "❌ Invalid URL or table number.";
    }
}

// ================================================================
// ====== HANDLE INLINE UPLOAD ======
// ================================================================
$uploadMessage = '';
$uploadSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_qr'])) {
    $tableNum = (int)($_POST['table_number'] ?? 0);
    if ($tableNum > 0 && isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['qr_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // Only PNG and JPG allowed
        $allowed = ['png', 'jpg', 'jpeg'];
        if (in_array($ext, $allowed)) {
            $cleanPath = getCleanPath($tableNum, $ext);
            $targetDir = __DIR__ . '/../../assets/images/QRCodes/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $targetPath = $targetDir . 'table_' . $tableNum . '.' . $ext;

            // Delete old files
            $stmtOld = $pdo->prepare("SELECT qr_image_path FROM tables WHERE table_number = ?");
            $stmtOld->execute([$tableNum]);
            $oldRow = $stmtOld->fetch();
            if ($oldRow && $oldRow['qr_image_path']) {
                deleteImageFiles($oldRow['qr_image_path']);
            }

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Generate thumbnail
                $thumbPath = $targetDir . 'table_' . $tableNum . '_thumb.' . $ext;
                createThumbnail($targetPath, $thumbPath);

                // Insert or update
                if (!isset($existing[$tableNum])) {
                    $stmt = $pdo->prepare("INSERT INTO tables (table_number, qr_code_url) VALUES (?, ?)");
                    $stmt->execute([$tableNum, getTableQRUrl($tableNum)]);
                }
                $stmt = $pdo->prepare("UPDATE tables SET qr_image_path = ? WHERE table_number = ?");
                $stmt->execute([$cleanPath, $tableNum]);
                apiBumpVersion($pdo);
                $uploadMessage = "✅ QR image uploaded for Table $tableNum";
                $uploadSuccess = true;

                // Refresh existing
                $stmt = $pdo->query("SELECT table_number, qr_code_url, qr_image_path FROM tables ORDER BY table_number");
                $tables = $stmt->fetchAll();
                $existing = [];
                foreach ($tables as $t) {
                    $existing[$t['table_number']] = $t;
                }
                $totalExisting = count($existing);
                $activeCount = 0;
                $inactiveCount = 0;
                foreach ($existing as $r) {
                    if (!empty($r['qr_image_path'])) $activeCount++;
                    else $inactiveCount++;
                }
            } else {
                $uploadMessage = "❌ Failed to move uploaded file.";
            }
        } else {
            $uploadMessage = "❌ Invalid file type. Only PNG and JPG allowed.";
        }
    } else {
        $uploadMessage = " No file selected or upload error.";
    }
}

// ================================================================
// ====== HANDLE DELETE ======
// ================================================================
$deleteMessage = '';
if (isset($_GET['delete_qr']) && is_numeric($_GET['delete_qr'])) {
    $tableNum = (int)$_GET['delete_qr'];
    $stmt = $pdo->prepare("SELECT qr_image_path FROM tables WHERE table_number = ?");
    $stmt->execute([$tableNum]);
    $row = $stmt->fetch();
    if ($row && $row['qr_image_path']) {
        deleteImageFiles($row['qr_image_path']);
    }
    $stmt = $pdo->prepare("DELETE FROM tables WHERE table_number = ?");
    $stmt->execute([$tableNum]);
    apiBumpVersion($pdo);
    $deleteMessage = "🗑️ QR record deleted for Table $tableNum";
    // Refresh existing
    $stmt = $pdo->query("SELECT table_number, qr_code_url, qr_image_path FROM tables ORDER BY table_number");
    $tables = $stmt->fetchAll();
    $existing = [];
    foreach ($tables as $t) {
        $existing[$t['table_number']] = $t;
    }
    $totalExisting = count($existing);
    $activeCount = 0;
    $inactiveCount = 0;
    foreach ($existing as $r) {
        if (!empty($r['qr_image_path'])) $activeCount++;
        else $inactiveCount++;
    }
}

// ================================================================
// ====== HANDLE ADD NEW QR ======
// ================================================================
$addMessage = '';
if (isset($_GET['add_qr'])) {
    $available = null;
    for ($i = 1; $i <= $maxTables; $i++) {
        if (!isset($existing[$i])) {
            $available = $i;
            break;
        }
    }
    if ($available !== null) {
        $url = getTableQRUrl($available);
        $stmt = $pdo->prepare("INSERT INTO tables (table_number, qr_code_url) VALUES (?, ?)");
        $stmt->execute([$available, $url]);
        apiBumpVersion($pdo);
        $addMessage = "✅ QR row added for Table #$available";
        // Refresh existing
        $stmt = $pdo->query("SELECT table_number, qr_code_url, qr_image_path FROM tables ORDER BY table_number");
        $tables = $stmt->fetchAll();
        $existing = [];
        foreach ($tables as $t) {
            $existing[$t['table_number']] = $t;
        }
        $totalExisting = count($existing);
        $activeCount = 0;
        $inactiveCount = 0;
        foreach ($existing as $r) {
            if (!empty($r['qr_image_path'])) $activeCount++;
            else $inactiveCount++;
        }
        header('Location: qr.php');
        exit;
    } else {
        $addMessage = "❌ No available table slots (1..$maxTables are all taken).";
    }
}

// ================================================================
// ====== DETERMINE EDIT TARGET ======
// ================================================================
$editTarget = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRowData = null;
if ($editTarget > 0 && isset($existing[$editTarget])) {
    $editRowData = $existing[$editTarget];
}

// ================================================================
// ====== RENDER WITH LAYOUT ======
// ================================================================
$layout = new AdminLayout();
$layout->setTitle('QR Codes')->setActive('qr');

ob_start();
?>
<!-- External CSS -->
<link rel="stylesheet" href="../../assets/css/admin/qr.css">

<!-- Edit Form -->
<?php if ($editTarget > 0 && $editRowData):
    $editUrl = $editRowData['qr_code_url'];
    $editHasImage = !empty($editRowData['qr_image_path']);
    $editImagePath = $editHasImage ? $editRowData['qr_image_path'] : null;
    $currentImageUrl = $editHasImage ? getImageUrl($editImagePath) : null;
    $currentThumbUrl = $editHasImage ? getThumbnailUrl($editImagePath) : null;
?>
    <div class="edit-form" id="editForm">
        <h3><i class="fas fa-edit"></i> Edit Table #<?= $editTarget ?></h3>
        <form method="POST" enctype="multipart/form-data" id="editFormElement">
            <input type="hidden" name="table_number" value="<?= $editTarget ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="qr_url_edit">QR URL *</label>
                    <input type="text" id="qr_url_edit" name="qr_url" value="<?= htmlspecialchars($editUrl) ?>" required>
                </div>
                <div class="form-group">
                    <label for="qr_image_edit">Upload New Image (optional)</label>
                    <!-- Restricted to PNG and JPG only -->
                    <input type="file" id="qr_image_edit" name="qr_image_edit" accept=".png,.jpg,.jpeg" onchange="previewEditImage(this)">
                    <div class="current-image">
                        <?php if ($editHasImage): ?>
                            <span class="preview-label">Current:</span>
                            <img src="<?= htmlspecialchars($currentThumbUrl) ?>" alt="Current QR" id="currentEditImage">
                        <?php else: ?>
                            <span style="color:#6b7280; font-size:0.85rem;">No image uploaded</span>
                        <?php endif; ?>
                        <!-- Preview container for newly selected file -->
                        <span id="editPreviewContainer" style="display:none; align-items:center; gap:0.3rem;">
                            <span class="preview-label">New:</span>
                            <img id="editPreviewImage" src="#" alt="New image preview" style="max-width:100px; max-height:100px; border-radius:0.5rem; border:1px solid #e2e8f0; object-fit:cover;">
                        </span>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:0.5rem; margin-top:0.8rem;">
                <button type="submit" name="edit_qr" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
                <a href="qr.php" class="btn-cancel"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Stats Bar -->
<div class="stats-bar">
    <span><i class="fas fa-chair"></i> Max: <span class="num"><?= $maxTables ?></span></span>
    <span><i class="fas fa-check-circle" style="color:#22c55e;"></i> Active: <span class="num active-num"><?= $activeCount ?></span></span>
    <span><i class="fas fa-circle" style="color:#f59e0b;"></i> Inactive: <span class="num inactive-num"><?= $inactiveCount ?></span></span>
    <span><i class="fas fa-database"></i> Existing: <span class="num"><?= $totalExisting ?></span></span>
</div>

<div style="display:flex; justify-content:flex-end; margin-bottom:1rem;">
    <?php if ($totalExisting < $maxTables): ?>
        <a href="?add_qr=1" class="btn-add" onclick="return confirm('Add a new QR row for the next available table number?')"><i class="fas fa-plus"></i> Add New QR</a>
    <?php else: ?>
        <span class="btn-add" style="background:#9ca3af; cursor:not-allowed;"><i class="fas fa-plus"></i> Add New QR (full)</span>
    <?php endif; ?>
</div>

<!-- Flash message from session (edit success) -->
<?php if (isset($_SESSION['flash_message'])): ?>
    <div class="message message-<?= $_SESSION['flash_type'] === 'success' ? 'success' : 'error' ?> dismissible">
        <?= $_SESSION['flash_message'] ?>
        <button type="button" class="close-btn" aria-label="Close">&times;</button>
    </div>
    <?php unset($_SESSION['flash_message']); unset($_SESSION['flash_type']); ?>
<?php endif; ?>

<!-- Other messages (add, delete, upload, edit error) -->
<?php if ($addMessage): ?>
    <div class="message message-<?= strpos($addMessage, '✅') !== false ? 'success' : 'error' ?> dismissible">
        <?= $addMessage ?>
        <button type="button" class="close-btn" aria-label="Close">&times;</button>
    </div>
<?php endif; ?>
<?php if ($deleteMessage): ?>
    <div class="message message-success dismissible">
        <?= $deleteMessage ?>
        <button type="button" class="close-btn" aria-label="Close">&times;</button>
    </div>
<?php endif; ?>
<?php if ($uploadMessage): ?>
    <div class="message message-<?= $uploadSuccess ? 'success' : 'error' ?> dismissible">
        <?= $uploadMessage ?>
        <button type="button" class="close-btn" aria-label="Close">&times;</button>
    </div>
<?php endif; ?>
<?php if ($editMessage && !$editSuccess): // only show error messages here (success goes via flash) ?>
    <div class="message message-error dismissible">
        <?= $editMessage ?>
        <button type="button" class="close-btn" aria-label="Close">&times;</button>
    </div>
<?php endif; ?>

<div style="overflow-x:auto;">
    <table>
        <thead><tr>
            <th>Table #</th>
            <th>QR URL</th>
            <th>Image</th>
            <th>Actions</th>
        </tr></thead>
        <tbody>
            <?php
            $sortedKeys = array_keys($existing);
            sort($sortedKeys);
            foreach ($sortedKeys as $i):
                $row = $existing[$i];
                $hasImage = !empty($row['qr_image_path']);
                $imagePath = $hasImage ? $row['qr_image_path'] : null;
                $thumbUrl = $hasImage ? getThumbnailUrl($imagePath) : null;
                $fullImageUrl = $hasImage ? getImageUrl($imagePath) : null;
                $url = $row['qr_code_url'];
                $isActive = $hasImage;
                $highlightClass = ($editTarget == $i) ? 'row-highlight' : '';
            ?>
            <tr data-table="<?= $i ?>" class="<?= $highlightClass ?>">
                <td class="clickable-col" data-table="<?= $i ?>">
                    <div class="table-number-cell">
                        <strong>#<?= $i ?></strong>
                        <span class="status-tag <?= $isActive ? 'active' : 'inactive' ?>">
                            <?= $isActive ? '● Active' : '○ Inactive' ?>
                        </span>
                    </div>
                </td>
                <td class="clickable-col" data-table="<?= $i ?>">
                    <code style="font-size:0.8rem;"><?= htmlspecialchars($url) ?></code>
                </td>
                <td>
                    <?php if ($hasImage): ?>
                        <img src="<?= htmlspecialchars($thumbUrl) ?>" class="qr-preview" alt="QR Table <?= $i ?>" data-full="<?= htmlspecialchars($fullImageUrl) ?>">
                    <?php else: ?>
                        <span style="color:#6b7280; font-size:0.8rem;"><i class="fas fa-image"></i> none</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="action-buttons">
                        <?php if ($hasImage): ?>
                            <a href="?edit=<?= $i ?>" class="btn-action btn-edit" title="Edit QR"><i class="fas fa-edit"></i> Edit</a>
                        <?php else: ?>
                            <form method="POST" enctype="multipart/form-data" class="upload-form" data-table="<?= $i ?>" style="display:inline-flex; align-items:center; gap:0.3rem; margin:0;">
                                <input type="hidden" name="table_number" value="<?= $i ?>">
                                <!-- Restricted to PNG and JPG only -->
                                <input type="file" name="qr_image" accept=".png,.jpg,.jpeg" class="qr-file-input">
                                <button type="button" class="btn-action btn-upload" onclick="triggerFileInput(this)"><i class="fas fa-upload"></i> Upload</button>
                                <button type="submit" name="upload_qr" class="btn-upload-save"><i class="fas fa-save"></i> Save</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($hasImage): ?>
                            <!-- CHANGED: Download button instead of Print -->
                            <a href="<?= htmlspecialchars($fullImageUrl) ?>" download="table_<?= $i ?>.png" class="btn-action btn-download" title="Download QR"><i class="fas fa-download"></i> Download</a>
                        <?php endif; ?>
                        <a href="?delete_qr=<?= $i ?>" class="btn-action btn-delete" onclick="return confirm('Delete QR record for Table #<?= $i ?>? This will also remove the image file.')"><i class="fas fa-minus-circle"></i> Delete</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($existing)): ?>
                <tr><td colspan="4" style="text-align:center; padding:2rem; color:#6b7280;">No QR codes added yet. Click "Add New QR" to get started.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Scroll‑to‑Top Button -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Image Modal -->
<div class="image-modal" id="imageModal">
    <div class="modal-content">
        <button class="modal-close" id="modalClose">&times;</button>
        <img id="modalImage" src="" alt="QR Code full view">
    </div>
</div>

<!-- External JS -->
<script src="../../assets/js/admin/qr.js"></script>
<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();