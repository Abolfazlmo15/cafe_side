<?php
// public/admin/items.php – Menu item management with image modal & clickable columns
// ==================================================================================

session_start();

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';
require_once __DIR__ . '/../../src/api.php';   // ← ADD THIS LINE

$pdo = getDbConnection();

// ================================================================
// ====== HELPER FUNCTIONS ======
// ================================================================

function sanitizeFilename($name) {
    $name = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $name);
    $name = preg_replace('/[\s_]+/', '_', $name);
    $name = trim($name, '_');
    return $name;
}

function uploadItemImage($file, $itemName) {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg'];
    if (!in_array($ext, $allowed)) return null;

    $baseName = sanitizeFilename($itemName);
    if (empty($baseName)) $baseName = 'item';
    $filename = $baseName . '.' . $ext;

    $targetDir = __DIR__ . '/../../assets/images/Items/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
    $targetPath = $targetDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'assets/images/Items/' . $filename;
    }
    return null;
}

function getItemImageUrl($item) {
    $storedPath = $item['image_path'] ?? null;
    if (empty($storedPath)) return null;

    $relativePath = ltrim($storedPath, '/');
    $url = BASE_URL . '/' . $relativePath;

    $fullPath = __DIR__ . '/../..' . '/' . $relativePath;
    if (file_exists($fullPath)) {
        $url .= '?v=' . filemtime($fullPath);
    }
    return $url;
}

// ================================================================
// ====== HANDLE POST ACTIONS ======
// ================================================================
$message = '';
$messageType = '';
$redirectAfterEdit = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? 'Served with love');
        $available = isset($_POST['available']) ? (int)$_POST['available'] : 1;
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if ($name && $price > 0 && $category) {
            try {
                $stmt = $pdo->prepare("INSERT INTO menu_items (name, price, category, description, available, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $price, $category, $description, $available, $sort_order]);
                $newId = $pdo->lastInsertId();

                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $imagePath = uploadItemImage($_FILES['image'], $name);
                    if ($imagePath) {
                        $stmt = $pdo->prepare("UPDATE menu_items SET image_path = ? WHERE id = ?");
                        $stmt->execute([$imagePath, $newId]);
                    }
                }

                apiBumpVersion($pdo);

                $_SESSION['message'] = "✅ Item added successfully!";
                $_SESSION['message_type'] = 'success';
                header('Location: items.php');
                exit;
            } catch (PDOException $e) {
                $message = "❌ Error: " . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = "❌ Please fill in all required fields (name, price, category).";
            $messageType = 'error';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? 'Served with love');
        $available = isset($_POST['available']) ? (int)$_POST['available'] : 1;
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if ($id > 0 && $name && $price > 0 && $category) {
            try {
                $stmt = $pdo->prepare("SELECT name, image_path FROM menu_items WHERE id = ?");
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                $oldName = $old['name'] ?? '';
                $oldImagePath = $old['image_path'] ?? '';

                $stmt = $pdo->prepare("UPDATE menu_items SET name = ?, price = ?, category = ?, description = ?, available = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$name, $price, $category, $description, $available, $sort_order, $id]);

                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    if ($oldImagePath) {
                        $oldFile = __DIR__ . '/../..' . ltrim($oldImagePath, '/');
                        if (file_exists($oldFile)) unlink($oldFile);
                    }
                    $newImagePath = uploadItemImage($_FILES['image'], $name);
                    if ($newImagePath) {
                        $stmt = $pdo->prepare("UPDATE menu_items SET image_path = ? WHERE id = ?");
                        $stmt->execute([$newImagePath, $id]);
                    }
                } else {
                    if ($oldImagePath && $oldName !== $name) {
                        $oldFile = __DIR__ . '/../..' . ltrim($oldImagePath, '/');
                        if (file_exists($oldFile)) {
                            $ext = pathinfo($oldFile, PATHINFO_EXTENSION);
                            $newBaseName = sanitizeFilename($name);
                            if (empty($newBaseName)) $newBaseName = 'item';
                            $newFilename = $newBaseName . '.' . $ext;
                            $newFilePath = dirname($oldFile) . '/' . $newFilename;
                            if (rename($oldFile, $newFilePath)) {
                                $newDbPath = 'assets/images/Items/' . $newFilename;
                                $stmt = $pdo->prepare("UPDATE menu_items SET image_path = ? WHERE id = ?");
                                $stmt->execute([$newDbPath, $id]);
                            }
                        }
                    }
                }

                apiBumpVersion($pdo); 

                $_SESSION['message'] = "✅ Item updated successfully!";
                $_SESSION['message_type'] = 'success';
                $redirectAfterEdit = true;
            } catch (PDOException $e) {
                $message = "❌ Error: " . $e->getMessage();
                $messageType = 'error';
            }
        } else {
            $message = "❌ Please fill in all required fields.";
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT image_path FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && $row['image_path']) {
                $file = __DIR__ . '/../..' . ltrim($row['image_path'], '/');
                if (file_exists($file)) unlink($file);
            }
            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);

            apiBumpVersion($pdo);

            $_SESSION['message'] = "🗑️ Item deleted successfully.";
            $_SESSION['message_type'] = 'success';
            header('Location: items.php');
            exit;
        }
    }

    if ($message) {
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $messageType;
    }
    if ($redirectAfterEdit) {
        header('Location: items.php');
        exit;
    }
}

// --- Display session messages ---
$displayMessage = $_SESSION['message'] ?? null;
$displayType = $_SESSION['message_type'] ?? null;
unset($_SESSION['message']);
unset($_SESSION['message_type']);

// --- Fetch items ---
$items = $pdo->query("SELECT * FROM menu_items ORDER BY sort_order, name")->fetchAll();

// --- Fetch all unique categories for the dropdown ---
$catStmt = $pdo->query("SELECT DISTINCT category FROM menu_items WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// --- Check if editing ---
$editItem = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editItem = $stmt->fetch();
}

// --- Render with layout ---
$layout = new AdminLayout();
$layout->setTitle('Menu Items')->setActive('items');
$layout->extraActions = '<a href="?add=1" class="btn-add"><i class="fas fa-plus"></i> Add New Item</a>';

ob_start();
?>
<!-- External CSS -->
<link rel="stylesheet" href="../../assets/css/admin/items.css">

<?php if ($displayMessage): ?>
    <div class="message message-<?= $displayType ?> dismissible">
        <?= $displayMessage ?>
        <button type="button" class="close-btn" aria-label="Close">&times;</button>
    </div>
<?php endif; ?>

<!-- Add/Edit Form -->
<?php if (isset($_GET['add']) || $editItem):
    $isEdit = ($editItem !== null);
    $item = $editItem ?? ['id'=>0, 'name'=>'', 'price'=>0, 'category'=>'', 'description'=>'Served with love', 'available'=>1, 'sort_order'=>0, 'image_path'=>null];
?>
    <div style="background:#f8fafc; padding:1.5rem; border-radius:1rem; margin-bottom:2rem;">
        <h3><?= $isEdit ? 'Edit Item' : 'Add New Item' ?></h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'add' ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $item['id'] ?>">
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Item Name *</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($item['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Price (Tomans) *</label>
                    <input type="number" name="price" step="1" value="<?= $item['price'] ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="position:relative;">
                    <label>Category *</label>
                    <div class="category-select-wrapper">
                        <input type="text" 
                               name="category" 
                               id="categoryInput" 
                               class="category-input"
                               value="<?= htmlspecialchars($item['category']) ?>" 
                               placeholder="Type or select a category"
                               autocomplete="off"
                               required>
                        <button type="button" id="categoryToggle" class="category-toggle" aria-label="Toggle category list">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <ul id="categoryDropdown" class="category-dropdown" style="display:none;">
                            <?php foreach ($categories as $cat): ?>
                                <li data-value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <small style="color:#6b7280;">Click the arrow to see all categories.</small>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2" style="width:100%; padding:0.5rem; border:1px solid #e8d9cf; border-radius:0.5rem; font-family:inherit;"><?= htmlspecialchars($item['description'] ?? 'Served with love') ?></textarea>
                    <small style="color:#6b7280;">A short description displayed on the menu card.</small>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Sort Order</label>
                    <input type="number" name="sort_order" value="<?= $item['sort_order'] ?>" min="0">
                    <small style="color:#6b7280;">0 = alphabetical, >0 = custom order</small>
                </div>
                <div class="form-group">
                    <label>Available?</label>
                    <select name="available">
                        <option value="1" <?= $item['available'] ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= !$item['available'] ? 'selected' : '' ?>>No (hide from menu)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Image <?= $isEdit ? '(leave empty to keep current)' : '' ?></label>
                <input type="file" name="image" accept=".png,.jpg,.jpeg" id="itemImageInput">
                <?php if ($isEdit && $item['image_path']): ?>
                    <div style="margin-top:0.5rem;">
                        <span class="preview-label">Current:</span>
                        <?php $currentImg = getItemImageUrl($item); if ($currentImg): ?>
                            <img src="<?= $currentImg ?>" style="max-width:100px; border-radius:0.5rem; border:1px solid #e2e8f0;">
                        <?php else: ?>
                            <span style="color:#6b7280; font-size:0.85rem;">No image found</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="image-preview-container" id="imagePreviewContainer" style="display:none;">
                    <span class="preview-label">New:</span>
                    <img id="imagePreview" src="#" alt="Preview">
                </div>
            </div>
            <button type="submit" class="submit-btn"><i class="fas fa-save"></i> <?= $isEdit ? 'Update' : 'Add' ?> Item</button>
            <a href="items.php" class="btn-cancel"><i class="fas fa-times"></i> Cancel</a>
        </form>
    </div>
<?php endif; ?>

<!-- Item List -->
<div class="item-list">
    <table>
        <thead><tr>
            <th>Image</th>
            <th>Name</th>
            <th>Description</th>
            <th>Price (T)</th>
            <th>Category</th>
            <th>Sort</th>
            <th>Status</th>
            <th>Actions</th>
        </tr></thead>
        <tbody>
            <?php foreach ($items as $item):
                $imageUrl = getItemImageUrl($item);
            ?>
            <tr>
                <td>
                    <?php if ($imageUrl): ?>
                        <img src="<?= $imageUrl ?>" class="item-img" alt="<?= htmlspecialchars($item['name']) ?>" data-full="<?= $imageUrl ?>">
                    <?php else: ?>
                        <div style="width:60px;height:60px;background:#e2e8f0;border-radius:0.5rem;display:flex;align-items:center;justify-content:center;color:#94a3b8;"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                </td>
                <td class="clickable-name" data-id="<?= $item['id'] ?>"><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                <td><?= htmlspecialchars($item['description'] ?? 'Served with love') ?></td>
                <td><?= number_format($item['price']) ?></td>
                <td><?= htmlspecialchars($item['category']) ?></td>
                <td><?= $item['sort_order'] ?></td>
                <td><span class="badge <?= $item['available'] ? 'badge-active' : 'badge-inactive' ?>"><?= $item['available'] ? 'Active' : 'Inactive' ?></span></td>
                <td>
                    <a href="?edit=<?= $item['id'] ?>" class="btn btn-edit"><i class="fas fa-edit"></i></a>
                    <form method="POST" style="display:inline-block;" onsubmit="return confirm('Delete item "<?= htmlspecialchars($item['name']) ?>"?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                        <button type="submit" class="btn-delete"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
                <tr><td colspan="8" style="text-align:center; padding:2rem; color:#6b7280;">No menu items yet. Add one above.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Image Modal -->
<div class="image-modal" id="imageModal">
    <div class="modal-content">
        <button class="modal-close" id="modalClose">&times;</button>
        <img id="modalImage" src="#" alt="Full view">
    </div>
</div>

<!-- External JS -->
<script src="../../assets/js/admin/items.js"></script>
<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();