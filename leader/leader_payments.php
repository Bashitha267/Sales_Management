<?php
// my_earnings.php
require_once '../auth.php';
require_once '../config.php';
requireLogin();
include 'leader_header.php'; // Includes session_start() and auth

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;
if (!$user_id) {
    header("Location: /ref/login.php");
    exit;
}

$is_representative = ($user_role === 'representative');

// --- 1. GET AND CALCULATE FILTERS ---
function get_current_week()
{
    $day = (int) date('j');
    if ($day <= 7)
        return 1;
    if ($day <= 15)
        return 2;
    if ($day <= 23)
        return 3;
    return 4;
}
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : date('n');
$week = isset($_GET['week']) ? (int) $_GET['week'] : get_current_week();
$week_ranges = [
    1 => ['start' => 1, 'end' => 7],
    2 => ['start' => 8, 'end' => 15],
    3 => ['start' => 16, 'end' => 23],
    4 => ['start' => 24, 'end' => 31]
];
$start_day = $week_ranges[$week]['start'];
$end_day = $week_ranges[$week]['end'];
if ($week == 4) {
    $end_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
}
$start_date_str = date('Y-m-d', mktime(0, 0, 0, $month, $start_day, $year));
$end_date_str = date('Y-m-d', mktime(0, 0, 0, $month, $end_day, $year));

// --- 2. FETCH PENDING POINTS (SUMMARY) ---
$total_pending_points = 0;
$stmt_total = $mysqli->prepare("
    SELECT SUM(points_rep) AS total_pending 
    FROM points_ledger 
    WHERE rep_user_id = ? AND redeemed = 0
");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$res_total = $stmt_total->get_result()->fetch_assoc();
$total_pending_points = (int) ($res_total['total_pending'] ?? 0);
$stmt_total->close();

$period_pending_points = 0;
$stmt_period = $mysqli->prepare("
    SELECT SUM(points_rep) AS period_pending 
    FROM points_ledger 
    WHERE rep_user_id = ? AND redeemed = 0 AND sale_date BETWEEN ? AND ?
");
$stmt_period->bind_param("iss", $user_id, $start_date_str, $end_date_str);
$stmt_period->execute();
$res_period = $stmt_period->get_result()->fetch_assoc();
$period_pending_points = (int) ($res_period['period_pending'] ?? 0);
$stmt_period->close();

// --- 3. FETCH PAYMENT HISTORY (FOR THE FILTERED PERIOD) ---
$payments_history = [];
$total_paid_in_period = 0;
$start_datetime_str = $start_date_str . " 00:00:00";
$end_datetime_str = $end_date_str . " 23:59:59";
$sql = "
    SELECT payment_type, points_redeemed, amount, paid_date, remarks 
    FROM payments
    WHERE user_id = ? AND paid_date BETWEEN ? AND ?
    ORDER BY paid_date DESC
";
$stmt_history = $mysqli->prepare($sql);
$stmt_history->bind_param("iss", $user_id, $start_datetime_str, $end_datetime_str);
$stmt_history->execute();
$result = $stmt_history->get_result();
while ($row = $result->fetch_assoc()) {
    $payments_history[] = $row;
    $total_paid_in_period += (float) $row['amount'];
}
$stmt_history->close();

// --- 4. 🟡 MODIFIED: FETCH ALL AGENCY STATUSES (Only for Representatives) ---
$agency_statuses = [];
if ($is_representative) {
    // 🟡 --- THIS QUERY IS FIXED --- 🟡
    // It now uses INNER JOIN and checks for points >= 0 to filter out corrupt data.
    $stmt_agency = $mysqli->prepare("
        SELECT 
            a.agency_name, 
            ap.total_rep_points, 
            ap.total_representative_points
        FROM agencies a
        INNER JOIN agency_points ap ON a.id = ap.agency_id
        WHERE a.representative_id = ?
          AND ap.total_rep_points >= 0
          AND ap.total_representative_points >= 0
        ORDER BY a.agency_name
    ");
    $stmt_agency->bind_param("i", $user_id);
    $stmt_agency->execute();

    $agency_result = $stmt_agency->get_result();
    while ($row = $agency_result->fetch_assoc()) {
        $agency_statuses[] = $row;
    }
    $stmt_agency->close();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Earnings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        .progress-bar-bg {
            background-color: #e5e7eb;
        }

        .progress-bar {
            background-color: #3b82f6;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-5xl mx-auto py-10 px-4">

        <h1 class="text-3xl font-bold text-gray-800 mb-6">My Earnings</h1>

        <form method="GET" class="flex flex-wrap gap-4 mb-6 bg-white p-4 rounded-lg shadow-sm">
            <label class="block flex-1 min-w-[120px]">
                <span class="text-sm font-medium text-gray-700">Year</span>
                <select name="year" class="border rounded px-3 py-2 w-full mt-1">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label class="block flex-1 min-w-[120px]">
                <span class="text-sm font-medium text-gray-700">Month</span>
                <select name="month" class="border rounded px-3 py-2 w-full mt-1">
                    <?php foreach (range(1, 12) as $m): ?>
                        <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                            <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block flex-1 min-w-[120px]">
                <span class="text-sm font-medium text-gray-700">Week</span>
                <select name="week" class="border rounded px-3 py-2 w-full mt-1">
                    <option value="1" <?= $week == 1 ? 'selected' : '' ?>>Week 1 (1-7)</option>
                    <option value="2" <?= $week == 2 ? 'selected' : '' ?>>Week 2 (8-15)</option>
                    <option value="3" <?= $week == 3 ? 'selected' : '' ?>>Week 3 (16-23)</option>
                    <option value="4" <?= $week == 4 ? 'selected' : '' ?>>Week 4 (24-End)</option>
                </select>
            </label>
            <div class="self-end pt-1">
                <button type="submit"
                    class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700 h-full">Filter</button>
            </div>
        </form>

        <h2 class="text-xl font-semibold text-gray-700 mb-3">My Pending Points (Personal Sales)</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
            <div class="bg-white p-6 rounded-lg shadow-sm border border-yellow-200">
                <div class="flex items-center gap-4">
                    <div class="bg-yellow-100 text-yellow-600 p-3 rounded-full">
                        <i data-feather="clock"></i>
                    </div>
                    <div>
                        <div class="text-gray-500 text-sm">Total Pending (All Time)</div>
                        <div class="text-3xl font-bold text-gray-800"><?= number_format($total_pending_points) ?> Points
                        </div>
                        <div class="text-gray-600 font-medium">Est. Value: Rs.
                            <?= number_format($total_pending_points * 0.01, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm border border-blue-200">
                <div class="flex items-center gap-4">
                    <div class="bg-blue-100 text-blue-600 p-3 rounded-full">
                        <i data-feather="calendar"></i>
                    </div>
                    <div>
                        <div class="text-gray-500 text-sm">Pending for Selected Period</div>
                        <div class="text-3xl font-bold text-gray-800"><?= number_format($period_pending_points) ?>
                            Points</div>
                        <div class="text-gray-600 font-medium">(<?= "$start_date_str to $end_date_str" ?>)</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_representative && !empty($agency_statuses)): // Check if the array is not empty ?>
            <h2 class="text-xl font-semibold text-gray-700 mb-3">My Agency Bonus Status</h2>

            <?php foreach ($agency_statuses as $agency_status): ?>

                <div class="bg-white shadow-sm rounded-lg p-6 mb-6 border border-gray-200">
                    <h3 class="text-lg font-semibold text-blue-700 mb-4"><?= htmlspecialchars($agency_status['agency_name']) ?>
                    </h3>

                    <p class="text-sm text-gray-600 mb-4">This shows the current progress towards the next Rs. 1,000 bonus. A
                        bonus is paid when *both* bars reach 5,000 points.</p>
                    <?php
                    $rep_pts = (int) ($agency_status['total_rep_points'] ?? 0);
                    $rep_for_rep_pts = (int) ($agency_status['total_representative_points'] ?? 0);

                    // Calculate percentages, capping at 100%
                    $rep_pct = min(100, ($rep_pts / 5000) * 100);
                    $rep_for_rep_pct = min(100, ($rep_for_rep_pts / 5000) * 100);

                    // Prevent negative percentages for display
                    if ($rep_pct < 0)
                        $rep_pct = 0;
                    if ($rep_for_rep_pct < 0)
                        $rep_for_rep_pct = 0;
                    ?>
                    <div class="mb-4">
                        <div class="flex justify-between items-end mb-1">
                            <span class="text-sm font-medium text-gray-700">Agency Rep Points</span>
                            <span class="text-sm text-gray-600"><?= number_format($rep_pts) ?> / 5,000</span>
                        </div>
                        <div class="w-full progress-bar-bg rounded-full h-2.5">
                            <div class="progress-bar h-2.5 rounded-full" style="width: <?= $rep_pct ?>%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between items-end mb-1">
                            <span class="text-sm font-medium text-gray-700">Agency Representative Points</span>
                            <span class="text-sm text-gray-600"><?= number_format($rep_for_rep_pts) ?> / 5,000</span>
                        </div>
                        <div class="w-full progress-bar-bg rounded-full h-2.5">
                            <div class="progress-bar h-2.5 rounded-full" style="width: <?= $rep_for_rep_pct ?>%"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?> <?php endif; ?>
        <h2 class="text-xl font-semibold text-gray-700 mb-3">My Payment History
            (<?= "$start_date_str to $end_date_str" ?>)</h2>
        <div class="bg-white shadow-sm rounded-lg overflow-hidden">
            <table class="min-w-full table-auto">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Date Paid</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Payment Type</th>
                        <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">Points Redeemed</th>
                        <th class="px-4 py-3 text-right text-sm font-semibold text-gray-600">Amount Paid (Rs)</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-600">Remarks</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($payments_history)): ?>
                        <tr>
                            <td colspan="5" class="p-4 text-center text-gray-500">
                                No payments found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments_history as $p): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-sm text-gray-700">
                                    <?= date('Y-m-d H:i', strtotime($p['paid_date'])) ?>
                                </td>
                                <td class="px-4 py-3 text-sm">
                                    <?php
                                    $type_color = $p['payment_type'] == 'agency' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800';
                                    echo "<span class='px-2 py-0.5 rounded-full text-xs font-medium $type_color'>" . ucfirst($p['payment_type']) . "</span>";
                                    ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-700 text-right">
                                    <?= number_format($p['points_redeemed']) ?>
                                </td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-900 text-right">
                                    <?= number_format($p['amount'], 2) ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($p['remarks']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-gray-100 font-bold">
                            <td class="px-4 py-3 text-right" colspan="3">Total Paid in Period:</td>
                            <td class="px-4 py-3 text-right">Rs. <?= number_format($total_paid_in_period, 2) ?></td>
                            <td class="px-4 py-3"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>

</html>