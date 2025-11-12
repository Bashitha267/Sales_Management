<?php
// Payment Report - Admin
// Includes: config.php (DB), admin_header.php (auth + header)

// Start session early to let admin_header read it if needed
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin_header.php';

// Helper: escape HTML
function h_pay(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Read filters
$year = isset($_GET['year']) && $_GET['year'] !== '' ? (int) $_GET['year'] : (int) date('Y');
// Default month to current month for better UX; allow "All" via empty value
$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int) $_GET['month'] : (int) date('n'); // 1..12
// Week ranges like rep_payments.php: 1 (1-7), 2 (8-15), 3 (16-23), 4 (24-end). 0 means "All"
$week = isset($_GET['week']) && $_GET['week'] !== '' ? (int) $_GET['week'] : 0; // 0..4

// Load year options from payments
$yearOptions = [];
$yrsRes = $mysqli->query("SELECT DISTINCT YEAR(paid_date) AS y FROM payments ORDER BY y DESC");
if ($yrsRes) {
    while ($r = $yrsRes->fetch_assoc()) {
        if (!empty($r['y'])) {
            $yearOptions[] = (int) $r['y'];
        }
    }
    $yrsRes->close();
}
if (empty($yearOptions)) {
    $yearOptions[] = (int) date('Y');
}
if (!in_array($year, $yearOptions, true)) {
    $year = $yearOptions[0];
}

// Month names
$monthNames = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mar',
    4 => 'Apr',
    5 => 'May',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Aug',
    9 => 'Sep',
    10 => 'Oct',
    11 => 'Nov',
    12 => 'Dec'
];

// Compute week date range (if a week is selected 1..4) based on selected year + month
$startDateStr = null;
$endDateStr = null;
if ($week >= 1 && $week <= 4) {
    $weekRanges = [
        1 => ['start' => 1, 'end' => 7],
        2 => ['start' => 8, 'end' => 15],
        3 => ['start' => 16, 'end' => 23],
        4 => ['start' => 24, 'end' => 31], // will be adjusted to month end
    ];
    $startDay = $weekRanges[$week]['start'];
    $endDay = $weekRanges[$week]['end'];
    if ($week === 4) {
        $endDay = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    }
    $startDateStr = date('Y-m-d', mktime(0, 0, 0, $month, $startDay, $year));
    $endDateStr = date('Y-m-d', mktime(0, 0, 0, $month, $endDay, $year));
}

// Build query with dynamic filters (Amount is computed at 0.01 per point)
$sql = "
    SELECT 
        id, user_id, payment_type, points_redeemed, amount, paid_date, remarks
    FROM payments
    WHERE 1=1
      AND YEAR(paid_date) = ?
";
$types = 'i';
$params = [$year];

if ($month >= 1 && $month <= 12) {
    $sql .= " AND MONTH(paid_date) = ? ";
    $types .= 'i';
    $params[] = $month;
}
// If a week (1..4) is specified, use date range within the selected month
if ($startDateStr !== null && $endDateStr !== null) {
    $sql .= " AND DATE(paid_date) BETWEEN ? AND ? ";
    $types .= 'ss';
    $params[] = $startDateStr;
    $params[] = $endDateStr;
}

$sql .= " ORDER BY paid_date DESC, id DESC";

$stmt = $mysqli->prepare($sql);
if ($stmt) {
    // dynamic bind
    $bindValues = [$types];
    foreach ($params as $k => $v) {
        $bindValues[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = false;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-4">
            <h1 class="text-2xl font-bold text-indigo-700">Payment Report</h1>
            <div class="text-sm text-slate-500">
                <?php
                date_default_timezone_set('Asia/Colombo');
                echo h_pay(date('F j, Y, g:i a'));
                ?>
            </div>
        </div>

        <!-- Filters -->
        <form method="get" class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <!-- Year -->
                <div class="flex flex-col">
                    <label for="year" class="text-sm font-medium text-slate-700 mb-1">Year</label>
                    <select id="year" name="year" class="border rounded-md px-3 py-2">
                        <?php foreach ($yearOptions as $y): ?>
                            <option value="<?= (int) $y ?>" <?= $y === $year ? 'selected' : '' ?>>
                                <?= (int) $y ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Month -->
                <div class="flex flex-col">
                    <label for="month" class="text-sm font-medium text-slate-700 mb-1">Month</label>
                    <select id="month" name="month" class="border rounded-md px-3 py-2">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>>
                                <?= h_pay($monthNames[$m]) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <!-- Week (1..4 like rep_payments.php) -->
                <div class="flex flex-col">
                    <label for="week" class="text-sm font-medium text-slate-700 mb-1">Week</label>
                    <select id="week" name="week" class="border rounded-md px-3 py-2">
                        <option value="">All</option>
                        <option value="1" <?= $week === 1 ? 'selected' : '' ?>>Week 1 (1-7)</option>
                        <option value="2" <?= $week === 2 ? 'selected' : '' ?>>Week 2 (8-15)</option>
                        <option value="3" <?= $week === 3 ? 'selected' : '' ?>>Week 3 (16-23)</option>
                        <option value="4" <?= $week === 4 ? 'selected' : '' ?>>Week 4 (24-End)</option>
                    </select>
                </div>
                <!-- Submit -->
                <div class="flex items-end">
                    <button type="submit"
                        class="w-full sm:w-auto bg-indigo-600 text-white px-4 py-2 rounded-md font-semibold hover:bg-indigo-700">
                        Apply
                    </button>
                </div>
            </div>
            <p class="mt-3 text-xs text-slate-500">
                Note: When both Month and Week are selected, the week filter applies within the chosen month. Amount is
                computed at 0.01 per point.
            </p>
        </form>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var form = document.querySelector('form[method="get"]');
                if (!form) return;
                var selects = form.querySelectorAll('select#year, select#month, select#week');
                var submitPending = false;
                function autoSubmit() {
                    if (submitPending) return;
                    submitPending = true;
                    // Debounce slightly to allow fast consecutive changes
                    setTimeout(function () {
                        form.submit();
                    }, 50);
                }
                selects.forEach(function (el) {
                    el.addEventListener('change', autoSubmit);
                });
            });
        </script>

        <!-- Table -->
        <div class="-mx-4 sm:mx-0 overflow-x-auto">
            <div class="inline-block min-w-full align-middle">
                <div class="overflow-hidden bg-white shadow ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                    <table class="min-w-full divide-y divide-slate-200 text-xs sm:text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th
                                    class="px-3 sm:px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-700">
                                    ID</th>
                                <th
                                    class="px-3 sm:px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-700">
                                    User ID</th>
                                <th
                                    class="px-3 sm:px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-700">
                                    Type</th>
                                <th
                                    class="px-3 sm:px-4 py-3 text-right font-semibold uppercase tracking-wide text-slate-700">
                                    Points</th>
                                <th
                                    class="px-3 sm:px-4 py-3 text-right font-semibold uppercase tracking-wide text-slate-700">
                                    Amount (0.01/pt)</th>
                                <th
                                    class="px-3 sm:px-4 py-3 text-right font-semibold uppercase tracking-wide text-slate-700">
                                    Stored Amount</th>
                                <th
                                    class="px-3 sm:px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-700">
                                    Paid Date</th>
                                <th
                                    class="px-3 sm:px-4 py-3 text-left font-semibold uppercase tracking-wide text-slate-700">
                                    Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php
                            $totalPoints = 0;
                            $totalComputedAmount = 0.0;
                            $totalStoredAmount = 0.0;
                            if ($result && $result->num_rows > 0):
                                while ($row = $result->fetch_assoc()):
                                    $pid = (int) ($row['id'] ?? 0);
                                    $uid = (int) ($row['user_id'] ?? 0);
                                    $ptype = (string) ($row['payment_type'] ?? '');
                                    $pts = (int) ($row['points_redeemed'] ?? 0);
                                    $storedAmount = (float) ($row['amount'] ?? 0.0);
                                    $pdate = $row['paid_date'] ?? null;
                                    $remarks = $row['remarks'] ?? '';
                                    $computed = $pts * 0.01;
                                    $totalPoints += $pts;
                                    $totalComputedAmount += $computed;
                                    $totalStoredAmount += $storedAmount;
                                    // tag color
                                    $tagClass = 'bg-slate-100 text-slate-700';
                                    if ($ptype === 'weekly')
                                        $tagClass = 'bg-green-100 text-green-700';
                                    elseif ($ptype === 'monthly')
                                        $tagClass = 'bg-blue-100 text-blue-700';
                                    elseif ($ptype === 'agency')
                                        $tagClass = 'bg-purple-100 text-purple-700';
                                    ?>
                                    <tr class="hover:bg-yellow-50">
                                        <td class="px-3 sm:px-4 py-2 font-mono"><?= h_pay((string) $pid) ?></td>
                                        <td class="px-3 sm:px-4 py-2 font-mono"><?= h_pay((string) $uid) ?></td>
                                        <td class="px-3 sm:px-4 py-2">
                                            <span
                                                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold <?= $tagClass ?>">
                                                <?= h_pay($ptype) ?>
                                            </span>
                                        </td>
                                        <td class="px-3 sm:px-4 py-2 text-right font-semibold text-indigo-700">
                                            <?= number_format($pts) ?>
                                        </td>
                                        <td class="px-3 sm:px-4 py-2 text-right text-slate-900">
                                            <?= number_format($computed, 2) ?>
                                        </td>
                                        <td class="px-3 sm:px-4 py-2 text-right text-slate-500">
                                            <?= number_format($storedAmount, 2) ?>
                                        </td>
                                        <td class="px-3 sm:px-4 py-2">
                                            <?= h_pay($pdate ? date('Y-m-d H:i', strtotime($pdate)) : '—') ?>
                                        </td>
                                        <td class="px-3 sm:px-4 py-2">
                                            <?php if (trim((string) $remarks) !== ''): ?>
                                                <span
                                                    class="inline-flex items-center rounded px-2 py-1 bg-amber-50 text-amber-700 text-[11px] font-medium">
                                                    <?= h_pay((string) $remarks) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-slate-400 text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php
                                endwhile;
                            else:
                                ?>
                                <tr>
                                    <td colspan="8" class="px-4 py-6 text-center text-slate-500">No payments found for the
                                        selected filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="bg-slate-50">
                            <tr>
                                <td colspan="3" class="px-3 sm:px-4 py-3 text-right font-semibold text-slate-700">Totals
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-right font-bold text-indigo-700">
                                    <?= number_format($totalPoints) ?>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-right font-bold text-slate-900">
                                    <?= number_format($totalComputedAmount, 2) ?>
                                </td>
                                <td class="px-3 sm:px-4 py-3 text-right font-bold text-slate-600">
                                    <?= number_format($totalStoredAmount, 2) ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>

</html>