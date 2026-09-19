<?php
// public/admin/orders.php – Admin dashboard with Jalali calendar & date navigation
// =============================================================================

// Set timezone to Tehran
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/database.php';
require_once __DIR__ . '/../../src/urls.php';
require_once __DIR__ . '/../../src/layout/AdminLayout.php';
require_once __DIR__ . '/../../src/helpers/Jalali.php';

$pdo = getDbConnection();

// --- Build menu lookup ---
$menuStmt = $pdo->query("SELECT id, name, price FROM menu_items");
$menuMap = [];
while ($row = $menuStmt->fetch()) {
    $menuMap[$row['id']] = $row;
}

// --- Format items helper ---
function formatItems($itemsJson, $menuMap) {
    $items = json_decode($itemsJson, true);
    if (!$items) return 'Invalid items';
    $parts = [];
    $totalQty = 0;
    $totalPrice = 0;
    foreach ($items as $id => $qty) {
        $name = $menuMap[$id]['name'] ?? "Unknown (#$id)";
        $price = $menuMap[$id]['price'] ?? 0;
        $subtotal = $price * $qty;
        $totalQty += $qty;
        $totalPrice += $subtotal;
        $parts[] = "<strong>$name</strong> ×$qty <span style='color:#3b82f6;'>(".number_format($subtotal)." T)</span>";
    }
    $summary = "<div style='font-size:0.85rem; margin-top:0.3rem; color:#475569;'><i class='fas fa-cubes'></i> $totalQty items <i class='fas fa-coins' style='margin-left:0.8rem;'></i> " . number_format($totalPrice) . " T</div>";
    return implode('<br>', $parts) . $summary;
}

// --- AJAX handler: mark order as ready (without page reload) ---
if (isset($_GET['mark_ready_ajax']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE orders SET is_ready = 1 WHERE id = ?");
    $stmt->execute([$id]);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'id' => $id]);
    exit;
}

// --- AJAX handler: convert Jalali date to Gregorian ---
if (isset($_GET['convert_date']) && isset($_GET['jDate'])) {
    $jDate = $_GET['jDate'];
    list($jY, $jM, $jD) = explode('-', $jDate);
    list($gY, $gM, $gD) = Jalali::jalaliToGregorian((int)$jY, (int)$jM, (int)$jD);
    echo sprintf("%04d-%02d-%02d", $gY, $gM, $gD);
    exit;
}

// --- AJAX handler: return calendar grid HTML for a given Jalali month ---
if (isset($_GET['calendar'])) {
    $jYear = (int)($_GET['jYear'] ?? 0);
    $jMonth = (int)($_GET['jMonth'] ?? 0);
    if ($jYear > 0 && $jMonth >= 1 && $jMonth <= 12) {
        $firstDay = Jalali::jMonthFirstDayOfWeek($jYear, $jMonth);
        $daysInMonth = Jalali::jMonthDays($jYear, $jMonth);
        $todayGreg = date('Y-m-d');
        list($todayJYear, $todayJMonth, $todayJDay) = Jalali::gregorianToJalali(
            (int)date('Y'), (int)date('m'), (int)date('d')
        );
        $selectedDate = $_GET['selected'] ?? '';
        $selectedJ = '';
        if ($selectedDate) {
            list($gY, $gM, $gD) = explode('-', $selectedDate);
            list($sJY, $sJM, $sJD) = Jalali::gregorianToJalali((int)$gY, (int)$gM, (int)$gD);
            $selectedJ = sprintf("%04d-%02d-%02d", $sJY, $sJM, $sJD);
        }

        $html = '';
        $dayNames = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
        for ($i = 0; $i < 7; $i++) {
            $html .= '<div style="font-size:0.75rem; color:#6b7280; padding:0.2rem 0;">' . $dayNames[$i] . '</div>';
        }

        for ($i = 0; $i < $firstDay; $i++) {
            $html .= '<div></div>';
        }

        $todayStr = sprintf("%04d-%02d-%02d", $todayJYear, $todayJMonth, $todayJDay);
        $minDateJ = $_GET['minDate'] ?? '';
        $statusMap = isset($_GET['statusMap']) ? json_decode($_GET['statusMap'], true) : [];

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = sprintf("%04d-%02d-%02d", $jYear, $jMonth, $d);
            $cellClass = 'day-cell';
            $isDisabled = false;

            if ($minDateJ && $dateStr < $minDateJ) {
                $isDisabled = true;
                $cellClass .= ' gray other-month';
            } else if ($dateStr > $todayStr) {
                $isDisabled = true;
                $cellClass .= ' gray other-month';
            } else {
                list($jY, $jM, $jD) = explode('-', $dateStr);
                list($gY, $gM, $gD) = Jalali::jalaliToGregorian((int)$jY, (int)$jM, (int)$jD);
                $gDate = sprintf("%04d-%02d-%02d", $gY, $gM, $gD);
                if (isset($statusMap[$gDate])) {
                    $status = $statusMap[$gDate];
                    if ($status['ready'] == $status['total']) {
                        $cellClass .= ' green';
                    } else {
                        $cellClass .= ' red';
                    }
                } else {
                    $cellClass .= ' gray';
                }
                if ($dateStr === $selectedJ) {
                    $cellClass .= ' selected';
                }
            }

            $html .= '<div class="' . $cellClass . '" data-date="' . $dateStr . '" style="' . ($isDisabled ? 'cursor:not-allowed;' : 'cursor:pointer;') . '" onclick="' . ($isDisabled ? '' : 'selectJalaliDate(\'' . $dateStr . '\')') . '">' . $d . '</div>';
        }
        echo $html;
        exit;
    }
    exit;
}

// --- Get selected date from query parameter (default = today) ---
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
list($selY, $selM, $selD) = explode('-', $selectedDate);
list($jSelY, $jSelM, $jSelD) = Jalali::gregorianToJalali((int)$selY, (int)$selM, (int)$selD);
$jSelectedDateStr = sprintf("%04d-%02d-%02d", $jSelY, $jSelM, $jSelD);

// --- Fetch min order date (oldest) and set max to today ---
$rangeStmt = $pdo->query("SELECT MIN(DATE(created_at)) as min_date FROM orders");
$range = $rangeStmt->fetch();
$minDate = $range['min_date'] ?? date('Y-m-d');
$todayStr = date('Y-m-d');
if ($minDate > $todayStr) $minDate = $todayStr;
$maxDate = $todayStr;

list($minY, $minM, $minD) = explode('-', $minDate);
list($jMinY, $jMinM, $jMinD) = Jalali::gregorianToJalali((int)$minY, (int)$minM, (int)$minD);
$jMinDateStr = sprintf("%04d-%02d-%02d", $jMinY, $jMinM, $jMinD);
list($todayY, $todayM, $todayD) = explode('-', $todayStr);
list($jTodayY, $jTodayM, $jTodayD) = Jalali::gregorianToJalali((int)$todayY, (int)$todayM, (int)$todayD);
$jTodayStr = sprintf("%04d-%02d-%02d", $jTodayY, $jTodayM, $jTodayD);

// --- Generate last 30 days from today for dropdown, but only those >= minDate ---
$dateList = [];
$jDateList = [];
$jDateToGregorianMap = [];
for ($i = 0; $i < 30; $i++) {
    $d = date('Y-m-d', strtotime("-$i days"));
    if ($d >= $minDate) {
        $dateList[] = $d;
        list($y, $m, $day) = explode('-', $d);
        list($jy, $jm, $jd) = Jalali::gregorianToJalali((int)$y, (int)$m, (int)$day);
        $jDateStr = sprintf("%04d-%02d-%02d", $jy, $jm, $jd);
        $jDateList[] = sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
        $jDateToGregorianMap[$jDateStr] = $d;
    }
}

// Query order status for these dates (Gregorian)
if (!empty($dateList)) {
    $inList = implode(',', array_fill(0, count($dateList), '?'));
    $statusStmt = $pdo->prepare("SELECT DATE(created_at) as order_date, 
                                 COUNT(*) as total, 
                                 SUM(is_ready) as ready_count 
                                 FROM orders 
                                 WHERE DATE(created_at) IN ($inList)
                                 GROUP BY order_date");
    $statusStmt->execute($dateList);
    $statusMap = [];
    while ($row = $statusStmt->fetch()) {
        $statusMap[$row['order_date']] = ['total' => $row['total'], 'ready' => $row['ready_count']];
    }
} else {
    $statusMap = [];
}

// --- Fetch orders for the selected date (Gregorian) ---
$orderStmt = $pdo->prepare("SELECT * FROM orders WHERE DATE(created_at) = ? ORDER BY is_ready ASC, created_at DESC");
$orderStmt->execute([$selectedDate]);
$orders = $orderStmt->fetchAll();

// --- Compute days ago (using Gregorian) ---
$today = new DateTime(date('Y-m-d'));
$selectedDateTime = new DateTime($selectedDate);
$interval = $today->diff($selectedDateTime);
$daysAgo = $interval->days;
$isToday = ($selectedDate == date('Y-m-d'));

// --- AJAX handler for table refresh (unchanged) ---
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    foreach ($orders as $order):
        $isReady = $order['is_ready'] == 1;
        $rowClass = $isReady ? 'ready' : 'pending';
        if (!$isReady && !$isToday) {
            $rowClass .= ' old-pending';
        }
        $badgeClass = $isReady ? 'badge-ready' : 'badge-pending';
        $statusText = $isReady ? 'Ready' : 'Pending';
        $itemsJson = htmlspecialchars($order['items'], ENT_QUOTES);
    ?>
        <tr class="<?= $rowClass ?>" data-ready="<?= $isReady ? '1' : '0' ?>" data-id="<?= $order['id'] ?>">
            <td><strong>#<?= $order['id'] ?></strong></td>
            <td><i class="fas fa-chair"></i> <?= $order['table_number'] ?></td>
            <td class="order-items" data-items='<?= $itemsJson ?>' style="cursor:pointer;">
                <?= formatItems($order['items'], $menuMap) ?>
                <span style="font-size:0.75rem; color:#3b82f6; margin-left:0.5rem;"><i class="fas fa-expand"></i></span>
            </td>
            <td><?= htmlspecialchars($order['customer_note'] ?: '—') ?></td>
            <td class="order-time"><?= date('H:i:s', strtotime($order['created_at'])) ?></td>
            <td><span class="badge <?= $badgeClass ?>"><?= $statusText ?></span></td>
            <td>
                <?php if (!$isReady): ?>
                    <button class="btn-ready" onclick="markReady(<?= $order['id'] ?>)"><i class="fas fa-check"></i> Ready</button>
                <?php else: ?>
                    <span style="color:#22c55e;"><i class="fas fa-check-circle"></i> Done</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (count($orders) == 0): ?>
        <tr><td colspan="7" style="text-align:center; padding:2rem; color:#6b7280;">No orders on this date.</td></tr>
    <?php endif; ?>
    <?php
    exit;
}

// --- Render page with layout ---
$layout = new AdminLayout();

$jDisplayDate = Jalali::format($jSelY, $jSelM, $jSelD);
$titleSuffix = '';
if ($isToday) {
    $titleSuffix = ' (Today)';
} elseif ($daysAgo == 1) {
    $titleSuffix = ' (1 day ago)';
} elseif ($daysAgo > 1) {
    $titleSuffix = " ($daysAgo days ago)";
}
$layout->setTitle('Orders (Date: ' . $jDisplayDate . $titleSuffix . ')')->setActive('orders');

// --- Build actions HTML with dropdown + custom calendar (Jalali) ---
$actionsHtml = '<div style="display:flex; flex-wrap:wrap; align-items:center; gap:0.6rem 1rem; width:100%;">';

$actionsHtml .= '<div style="display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap; position:relative;">';
$actionsHtml .= '<span style="font-weight:500; font-size:0.9rem; color:#475569;"><i class="fas fa-calendar"></i> Date:</span>';

$actionsHtml .= '<select id="dateSelector" onchange="window.location.href=this.value" style="padding:0.3rem 0.6rem; border-radius:0.4rem; border:1px solid #d1d5db; background:white; font-size:0.9rem; cursor:pointer; min-width:130px;">';
$jTodayDisplay = Jalali::format($jTodayY, $jTodayM, $jTodayD);
foreach ($dateList as $idx => $d) {
    $status = $statusMap[$d] ?? null;
    $jLabel = $jDateList[$idx];
    if ($d == $todayStr) $jLabel .= ' (Today)';
    $selected = ($d == $selectedDate) ? 'selected' : '';
    $style = '';
    if ($status) {
        if ($status['ready'] == $status['total']) {
            $style = 'background-color: #d1fae5;';
        } else {
            $style = 'background-color: #fee2e2;';
        }
    } else {
        $style = 'color: #9ca3af;';
    }
    $actionsHtml .= '<option value="?date=' . $d . '" ' . $selected . ' style="' . $style . '">' . htmlspecialchars($jLabel) . '</option>';
}
$actionsHtml .= '</select>';

$actionsHtml .= '<button id="calendarToggle" style="background:#e2e8f0; border:1px solid #d1d5db; border-radius:0.4rem; padding:0.25rem 0.6rem; cursor:pointer; font-size:1rem;" onclick="toggleCalendar(event)"><i class="fas fa-calendar-alt"></i></button>';

$actionsHtml .= '<div id="calendarPopup" style="display:none; position:absolute; top:100%; left:0; z-index:1000; background:white; border:1px solid #d1d5db; border-radius:0.5rem; padding:0.8rem; box-shadow:0 4px 12px rgba(0,0,0,0.15); margin-top:0.3rem; min-width:240px;">';
$actionsHtml .= '  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">';
$actionsHtml .= '    <button onclick="changeMonth(-1)" style="background:none; border:none; font-size:1.2rem; cursor:pointer;">&lt;</button>';
$actionsHtml .= '    <span id="calendarMonthYear" style="font-weight:600;"></span>';
$actionsHtml .= '    <button onclick="changeMonth(1)" style="background:none; border:none; font-size:1.2rem; cursor:pointer;">&gt;</button>';
$actionsHtml .= '  </div>';
$actionsHtml .= '  <div id="calendarGrid" style="display:grid; grid-template-columns:repeat(7,1fr); gap:2px; text-align:center;"></div>';
$actionsHtml .= '  <div style="display:flex; justify-content:center; gap:0.8rem; margin-top:0.5rem; font-size:0.75rem; color:#475569;">';
$actionsHtml .= '    <span><span style="display:inline-block; width:12px; height:12px; background:#d1fae5; border-radius:2px;"></span> All ready</span>';
$actionsHtml .= '    <span><span style="display:inline-block; width:12px; height:12px; background:#fee2e2; border-radius:2px;"></span> Some pending</span>';
$actionsHtml .= '    <span><span style="display:inline-block; width:12px; height:12px; background:#e5e7eb; border-radius:2px;"></span> No orders</span>';
$actionsHtml .= '  </div>';
$actionsHtml .= '</div>';

$actionsHtml .= '<button onclick="window.location.href=\'?date=' . $todayStr . '\'" style="background:#3b82f6; color:white; border:none; padding:0.3rem 0.8rem; border-radius:0.4rem; cursor:pointer; font-weight:500; font-size:0.85rem; transition:background 0.2s;" onmouseover="this.style.background=\'#2563eb\'" onmouseout="this.style.background=\'#3b82f6\'">Today</button>';
$actionsHtml .= '</div>';

$actionsHtml .= '<div style="display:flex; align-items:center; gap:0.4rem; margin-left:auto;">';
$actionsHtml .= '<button id="toggleCompleted" onclick="toggleCompleted()" style="background:#e2e8f0; border:none; padding:0.3rem 0.8rem; border-radius:0.4rem; cursor:pointer; font-weight:500; font-size:0.85rem; display:flex; align-items:center; gap:0.3rem; transition:background 0.2s;" onmouseover="this.style.background=\'#cbd5e1\'" onmouseout="this.style.background=\'#e2e8f0\'"><i class="fas fa-eye-slash"></i> Hide Completed</button>';
$actionsHtml .= '</div>';

$actionsHtml .= '</div>';

$layout->extraActions = $actionsHtml;

ob_start();
?>
<!-- External CSS -->
<link rel="stylesheet" href="../../assets/css/admin/orders.css">

<div style="overflow-x:auto;">
    <table class="order-table" id="orderTable">
        <thead>
            <tr>
                <th>Order #</th>
                <th>Table</th>
                <th>Items</th>
                <th>Note</th>
                <th>Time</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody id="orderTableBody">
            <?php if (count($orders) > 0): ?>
                <?php foreach ($orders as $order):
                    $isReady = $order['is_ready'] == 1;
                    $rowClass = $isReady ? 'ready' : 'pending';
                    if (!$isReady && !$isToday) {
                        $rowClass .= ' old-pending';
                    }
                    $badgeClass = $isReady ? 'badge-ready' : 'badge-pending';
                    $statusText = $isReady ? 'Ready' : 'Pending';
                    $itemsJson = htmlspecialchars($order['items'], ENT_QUOTES);
                ?>
                    <tr class="<?= $rowClass ?>" data-ready="<?= $isReady ? '1' : '0' ?>" data-id="<?= $order['id'] ?>">
                        <td><strong>#<?= $order['id'] ?></strong></td>
                        <td><i class="fas fa-chair"></i> <?= $order['table_number'] ?></td>
                        <td class="order-items" data-items='<?= $itemsJson ?>' style="cursor:pointer;">
                            <?= formatItems($order['items'], $menuMap) ?>
                            <span style="font-size:0.75rem; color:#3b82f6; margin-left:0.5rem;"><i class="fas fa-expand"></i></span>
                        </td>
                        <td><?= htmlspecialchars($order['customer_note'] ?: '—') ?></td>
                        <td class="order-time"><?= date('H:i:s', strtotime($order['created_at'])) ?></td>
                        <td><span class="badge <?= $badgeClass ?>"><?= $statusText ?></span></td>
                        <td>
                            <?php if (!$isReady): ?>
                                <button class="btn-ready" onclick="markReady(<?= $order['id'] ?>)"><i class="fas fa-check"></i> Ready</button>
                            <?php else: ?>
                                <span style="color:#22c55e;"><i class="fas fa-check-circle"></i> Done</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" style="text-align:center; padding:2rem; color:#6b7280;">No orders on this date.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<!-- END of table wrapper -->

<!-- Scroll‑to‑Top Button (outside the table wrapper) -->
<button class="scroll-top-btn" id="scrollTopBtn" aria-label="Scroll to top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Order Items Modal -->
<div class="order-modal" id="orderItemsModal">
    <div class="order-modal-content">
        <button class="order-modal-close" id="orderModalClose">&times;</button>
        <div id="orderModalBody">
            <!-- Content will be injected by JavaScript -->
        </div>
    </div>
</div>

<!-- Inline JS: set global window variables for the external script -->
<script>
    window.selectedDate = '<?= $selectedDate ?>';
    window.minDate = '<?= $minDate ?>';
    window.todayStr = '<?= $todayStr ?>';
    window.statusMap = <?= json_encode($statusMap) ?>;
    window.urlAdminOrders = '<?= URL_ADMIN_ORDERS ?>';
    window.jalaliMonth = <?= $jSelM ?>;
    window.jalaliYear = <?= $jSelY ?>;
    window.jalaliSelectedDate = '<?= $jSelectedDateStr ?>';
    window.jalaliMinDate = '<?= $jMinDateStr ?>';
    window.jalaliToday = '<?= $jTodayStr ?>';
    window.jalaliToGregorianMap = <?= json_encode($jDateToGregorianMap) ?>;
    window.menuMap = <?= json_encode($menuMap) ?>;
</script>

<!-- External JS -->
<script src="../../assets/js/admin/orders.js"></script>
<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();