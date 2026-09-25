<?php
// public/admin/orders.php – Admin orders dashboard (Vue-powered + Jalali calendar)
// ==================================================================================
date_default_timezone_set('Asia/Tehran');

require_once __DIR__ . '/../../bootstrap.php';
require_once SRC_PATH . '/auth.php';

$pdo = getDbConnection();


// ================================================================
// ====== API ENDPOINTS ======
// ================================================================
if (isset($_GET['api'])) {
    $api = $_GET['api'];

    if ($api === 'orders' || $api === 'poll') {
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $clientVersion = isset($_GET['version']) ? (int)$_GET['version'] : 0;
        $serverVersion = apiGetVersion($pdo);

        if ($api === 'poll' && $clientVersion > 0 && $clientVersion === $serverVersion) {
            apiRespondJson(['unchanged' => true, 'version' => $serverVersion]);
        }

        apiRespondJson([
            'unchanged' => false,
            'version'   => $serverVersion,
            'date'      => $date,
            'orders'    => apiBuildOrdersListData($pdo, $date),
        ]);
    }

    if ($api === 'mark_ready' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) apiRespondJson(['error' => 'Invalid order ID'], 400);
        $stmt = $pdo->prepare("UPDATE orders SET is_ready = 1, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$id]);
        apiBumpVersion($pdo);
        apiRespondJson(['success' => true, 'id' => $id]);
    }

    if ($api === 'calendar') {
        $jYear  = (int)($_GET['jYear']  ?? 0);
        $jMonth = (int)($_GET['jMonth'] ?? 0);
        if ($jYear < 1300 || $jYear > 1500 || $jMonth < 1 || $jMonth > 12) {
            apiRespondJson(['error' => 'Invalid year/month'], 400);
        }

        $firstDay    = Jalali::jMonthFirstDayOfWeek($jYear, $jMonth);
        $daysInMonth = Jalali::jMonthDays($jYear, $jMonth);

        // Today in Jalali
        list($tJY, $tJM, $tJD) = Jalali::gregorianToJalali((int)date('Y'), (int)date('m'), (int)date('d'));
        $todayJ = sprintf('%04d-%02d-%02d', $tJY, $tJM, $tJD);

        // Selected date in Jalali
        $selectedDate = $_GET['selected'] ?? '';
        $selectedJ = '';
        if ($selectedDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
            list($gY, $gM, $gD) = explode('-', $selectedDate);
            list($sJY, $sJM, $sJD) = Jalali::gregorianToJalali((int)$gY, (int)$gM, (int)$gD);
            $selectedJ = sprintf('%04d-%02d-%02d', $sJY, $sJM, $sJD);
        }

        // Min order date in Jalali
        $rangeStmt = $pdo->query("SELECT MIN(DATE(created_at)) as min_date FROM orders");
        $minDateG = $rangeStmt->fetchColumn() ?: date('Y-m-d');
        list($mY, $mM, $mD) = explode('-', $minDateG);
        list($jMinY, $jMinM, $jMinD) = Jalali::gregorianToJalali((int)$mY, (int)$mM, (int)$mD);
        $minJ = sprintf('%04d-%02d-%02d', $jMinY, $jMinM, $jMinD);

        // Status map for this Jalali month (queried per-day)
        $days = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $jDateStr = sprintf('%04d-%02d-%02d', $jYear, $jMonth, $d);
            list($gY, $gM, $gD) = Jalali::jalaliToGregorian($jYear, $jMonth, $d);
            $gDateStr = sprintf('%04d-%02d-%02d', $gY, $gM, $gD);

            $status  = 'gray';
            $disabled = false;

            if ($jDateStr < $minJ || $jDateStr > $todayJ) {
                $disabled = true;
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) AS total, COALESCE(SUM(is_ready), 0) AS ready_count
                    FROM orders WHERE DATE(created_at) = ?
                ");
                $stmt->execute([$gDateStr]);
                $row = $stmt->fetch();
                if ($row && (int)$row['total'] > 0) {
                    $status = ((int)$row['ready_count'] === (int)$row['total']) ? 'green' : 'red';
                }
            }

            $days[] = [
                'day'        => $d,
                'jalali'     => $jDateStr,
                'gregorian'  => $gDateStr,
                'status'     => $status,
                'disabled'   => $disabled,
                'isToday'    => ($jDateStr === $todayJ),
                'isSelected' => ($jDateStr === $selectedJ),
            ];
        }

        $monthNames = ['Farvardin','Ordibehesht','Khordad','Tir','Mordad','Shahrivar',
                       'Mehr','Aban','Azar','Dey','Bahman','Esfand'];

        apiRespondJson([
            'year'           => $jYear,
            'month'          => $jMonth,
            'monthName'      => $monthNames[$jMonth - 1],
            'firstDayOfWeek' => $firstDay,
            'dayNames'       => ['Su','Mo','Tu','We','Th','Fr','Sa'],
            'days'           => $days,
        ]);
    }

    apiRespondJson(['error' => 'Unknown API endpoint'], 404);
}


// ================================================================
// ====== PAGE SETUP ======
// ================================================================
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $selectedDate = date('Y-m-d');

list($selY, $selM, $selD) = explode('-', $selectedDate);
list($jSelY, $jSelM, $jSelD) = Jalali::gregorianToJalali((int)$selY, (int)$selM, (int)$selD);
$jSelectedDateStr = sprintf('%04d-%02d-%02d', $jSelY, $jSelM, $jSelD);

// Date range
$rangeStmt = $pdo->query("SELECT MIN(DATE(created_at)) as min_date, MAX(DATE(created_at)) as max_date FROM orders");
$range = $rangeStmt->fetch();
$minDate = $range['min_date'] ?? date('Y-m-d');
$maxDate = $range['max_date'] ?? date('Y-m-d');
$todayStr = date('Y-m-d');
if ($maxDate > $todayStr) $maxDate = $todayStr;
if ($minDate > $maxDate) $minDate = $maxDate;

// Last-30-days dropdown
$dateList = [];
$dateListJalali = [];
for ($i = 0; $i < 30; $i++) {
    $d = date('Y-m-d', strtotime("-$i days"));
    if ($d < $minDate) continue;
    $dateList[] = $d;
    list($y, $m, $day) = explode('-', $d);
    list($jy, $jm, $jd) = Jalali::gregorianToJalali((int)$y, (int)$m, (int)$day);
    $dateListJalali[] = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

$initialOrders  = apiBuildOrdersListData($pdo, $selectedDate);
$initialVersion = apiGetVersion($pdo);


// ================================================================
// ====== RENDER ======
// ================================================================
$layout = new AdminLayout();
$layout->setTitle('Orders')->setActive('orders');

ob_start();
?>
<link rel="stylesheet" href="../../assets/css/admin/orders.css">

<div id="vue-orders-root"></div>

<script>
window.__ORDERS_DATA__ = {
    date:           <?= json_encode($selectedDate) ?>,
    version:        <?= (int)$initialVersion ?>,
    orders:         <?= json_encode($initialOrders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    dateList:       <?= json_encode($dateList) ?>,
    dateListJalali: <?= json_encode($dateListJalali) ?>,
    minDate:        <?= json_encode($minDate) ?>,
    maxDate:        <?= json_encode($maxDate) ?>,
    jalaliDate:     <?= json_encode($jSelectedDateStr) ?>,
    jalaliYear:     <?= (int)$jSelY ?>,
    jalaliMonth:    <?= (int)$jSelM ?>
};
</script>

<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<script src="../../assets/js/admin/orders-vue.js"></script>

<?php
$content = ob_get_clean();
$layout->setContent($content);
$layout->render();