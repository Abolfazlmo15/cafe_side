<?php
// public/admin/items.php – Menu items management (Vue-powered)
// ==============================================================
// Phase 4.1: Vue-rendered list, modal add/edit, no page reloads.
// Phase 3.2: soft-delete preserves names for historical reports.

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/api.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';

$pdo = getDbConnection();

// ================================================================
// ====== HELPERS ======
// ================================================================

function sanitizeItemFilename($name) {
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

    $baseName = sanitizeItemFilename($itemName);
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

// ================================================================
// ====== API ENDPOINTS ======
// ================================================================
if (isset($_GET['api'])) {
    $api = $_GET['api'];

    // ---- GET: items list / poll ----------------------------------
    if ($api === 'items' || $api === 'poll') {
        $clientVersion = isset($_GET['version']) ? (int)$_GET['version'] : 0;
        $serverVersion = apiGetVersion($pdo);

        if ($api === 'poll' && $clientVersion > 0 && $clientVersion === $serverVersion) {
            apiRespondJson(['unchanged' => true, 'version' => $serverVersion]);
        }

        $data = apiBuildItemsListData($pdo);
        apiRespondJson([
            'unchanged'  => false,
            'version'    => $serverVersion,
            'items'      => $data['items'],
            'categories' => $data['categories'],
        ]);
    }

    // ---- POST: item_save -----------------------------------------
    if ($api === 'item_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $price       = (float)($_POST['price'] ?? 0);
        $category    = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? 'Served with love');
        $available   = isset($_POST['available']) ? (int)$_POST['available'] : 1;
        $sort_order  = (int)($_POST['sort_order'] ?? 0);

        if ($name === '' || $price <= 0 || $category === '') {
            apiRespondJson(['error' => 'Name, price, and category are required.'], 400);
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("SELECT name, image_path FROM menu_items WHERE id = ?");
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if (!$old) {
                    apiRespondJson(['error' => 'Item not found.'], 404);
                }
                $oldName = $old['name'] ?? '';
                $oldImagePath = $old['image_path'] ?? '';

                $stmt = $pdo->prepare("UPDATE menu_items SET name = ?, price = ?, category = ?, description = ?, available = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$name, $price, $category, $description, $available, $sort_order, $id]);

                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    if ($oldImagePath) {
                        $oldFile = __DIR__ . '/../..' . '/' . ltrim($oldImagePath, '/');
                        if (file_exists($oldFile)) @unlink($oldFile);
                    }
                    $newImagePath = uploadItemImage($_FILES['image'], $name);
                    if ($newImagePath) {
                        $stmt = $pdo->prepare("UPDATE menu_items SET image_path = ? WHERE id = ?");
                        $stmt->execute([$newImagePath, $id]);
                    }
                } else {
                    if ($oldImagePath && $oldName !== $name) {
                        $oldFile = __DIR__ . '/../..' . '/' . ltrim($oldImagePath, '/');
                        if (file_exists($oldFile)) {
                            $ext = pathinfo($oldFile, PATHINFO_EXTENSION);
                            $newBase = sanitizeItemFilename($name);
                            if ($newBase === '') $newBase = 'item';
                            $newFilename = $newBase . '.' . $ext;
                            $newFilePath = dirname($oldFile) . '/' . $newFilename;
                            if (@rename($oldFile, $newFilePath)) {
                                $newDbPath = 'assets/images/Items/' . $newFilename;
                                $stmt = $pdo->prepare("UPDATE menu_items SET image_path = ? WHERE id = ?");
                                $stmt->execute([$newDbPath, $id]);
                            }
                        }
                    }
                }

                apiBumpVersion($pdo);
                apiRespondJson(['success' => true, 'id' => $id, 'action' => 'update']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO menu_items (name, price, category, description, available, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $price, $category, $description, $available, $sort_order]);
                $newId = (int)$pdo->lastInsertId();

                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $imagePath = uploadItemImage($_FILES['image'], $name);
                    if ($imagePath) {
                        $stmt = $pdo->prepare("UPDATE menu_items SET image_path = ? WHERE id = ?");
                        $stmt->execute([$imagePath, $newId]);
                    }
                }

                apiBumpVersion($pdo);
                apiRespondJson(['success' => true, 'id' => $newId, 'action' => 'add']);
            }
        } catch (PDOException $e) {
            error_log('item_save failed: ' . $e->getMessage());
            apiRespondJson(['error' => 'Database error. Please try again.'], 500);
        }
    }

    // ---- POST: item_delete (soft-delete via archive table) -------
    if ($api === 'item_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) apiRespondJson(['error' => 'Invalid item ID'], 400);

        try {
            // 1. Fetch the full row before deleting.
            $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                apiRespondJson(['error' => 'Item not found.'], 404);
            }

            // 2. Copy to archive so historical orders keep resolving
            //    the item name. Image file stays on disk.
            $stmt = $pdo->prepare("
                INSERT INTO menu_items_deleted
                    (id, name, price, category, description, image_path,
                     sort_order, available, original_created_at, deleted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    name                = VALUES(name),
                    price               = VALUES(price),
                    category            = VALUES(category),
                    description         = VALUES(description),
                    image_path          = VALUES(image_path),
                    sort_order          = VALUES(sort_order),
                    available           = VALUES(available),
                    original_created_at = VALUES(original_created_at),
                    deleted_at          = NOW()
            ");
            $stmt->execute([
                $row['id'],
                $row['name'],
                $row['price'],
                $row['category'],
                $row['description'] ?? null,
                $row['image_path'] ?? null,
                $row['sort_order'] ?? 0,
                $row['available'] ?? 0,
                $row['created_at'] ?? null,
            ]);

            // 3. Remove the active row.
            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);

            apiBumpVersion($pdo);
            apiRespondJson(['success' => true, 'id' => $id]);
        } catch (PDOException $e) {
            error_log('item_delete failed: ' . $e->getMessage());
            apiRespondJson(['error' => 'Database error.'], 500);
        }
    }

    // ---- Unknown API endpoint ------------------------------------
    apiRespondJson(['error' => 'Unknown API endpoint'], 404);
}

// ================================================================
// ====== PAGE SETUP ======
// ================================================================
$initialData    = apiBuildItemsListData($pdo);
$initialVersion = apiGetVersion($pdo);

$layout = new AdminLayout();
$layout->setTitle('Menu Items')->setActive('items');

ob_start();
?>
<link rel="stylesheet" href="../../assets/css/admin/items.css">

<div id="vue-items-root"></div>

<script>
window.__ITEMS_DATA__ = {
    version:    <?= (int)$initialVersion ?>,
    items:      <?= json_encode($initialData['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    categories: <?= json_encode($initialData['categories'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>

<script src="../../assets/vendor/vue.global.prod.js"></script>
<script src="../../assets/js/admin/items-vue.js"></script>

<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();
