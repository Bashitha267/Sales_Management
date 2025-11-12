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

// --- 2. FETCH PENDING POINTS (DIRECT SALES - from points_ledger_rep) ---
// Calculate from points_ledger_rep table - these are rep points for the representative
$total_pending_points = 0;
$stmt_total = $mysqli->prepare("
    SELECT SUM(pl.points) AS total_pending 
    FROM points_ledger_rep pl
    INNER JOIN sales s ON pl.sale_id = s.id
    WHERE pl.rep_user_id = ? 
      AND pl.redeemed = 0 
      AND s.sale_type = 'full'
      AND s.sale_approved = 1
");
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$res_total = $stmt_total->get_result()->fetch_assoc();
$total_pending_points = (int) ($res_total['total_pending'] ?? 0);
$stmt_total->close();

$period_pending_points = 0;
$stmt_period = $mysqli->prepare("
    SELECT SUM(pl.points) AS period_pending 
    FROM points_ledger_rep pl
    INNER JOIN sales s ON pl.sale_id = s.id
    WHERE pl.rep_user_id = ? 
      AND pl.redeemed = 0 
      AND pl.sale_date BETWEEN ? AND ? 
      AND s.sale_type = 'full'
      AND s.sale_approved = 1
");
$stmt_period->bind_param("iss", $user_id, $start_date_str, $end_date_str);
$stmt_period->execute();
$res_period = $stmt_period->get_result()->fetch_assoc();
$period_pending_points = (int) ($res_period['period_pending'] ?? 0);
$stmt_period->close();

// --- 3. FETCH PAYMENT HISTORY (FOR THE FILTERED PERIOD) ---
// This query remains unchanged as it shows past payments, not new calculations.
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

// --- 4. FETCH ALL AGENCY STATUSES (Only for Representatives) ---
$agency_statuses = [];
if ($is_representative) {
    // Query points_ledger_group_points to get points_representative for each agency
    // Only count unredeemed points from full, approved agency sales
    // Only show agencies that belong to this representative
    $stmt_agency = $mysqli->prepare("
        SELECT 
            a.id AS agency_id,
            a.agency_name, 
            COALESCE(SUM(pl.points), 0) AS total_representative_points
        FROM agencies a
        LEFT JOIN points_ledger_group_points pl ON a.id = pl.agency_id 
            AND pl.representative_id = ?
            AND pl.redeemed = 0
        LEFT JOIN sales s ON pl.sale_id = s.id
        WHERE a.representative_id = ?
          AND (s.sale_type = 'full' OR s.sale_type IS NULL)
          AND (s.sale_approved = 1 OR s.sale_approved IS NULL)
        GROUP BY a.id, a.agency_name
        ORDER BY a.agency_name
    ");
    $stmt_agency->bind_param("ii", $user_id, $user_id);
    $stmt_agency->execute();

    $agency_result = $stmt_agency->get_result();
    while ($row = $agency_result->fetch_assoc()) {
        $agency_statuses[] = $row;
    }
    $stmt_agency->close();
}

/**
 * Helper function to safely print data or a default dash.
 */
function h(?string $value, string $default = '—'): string
{
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <div class="max-w-5xl mx-auto py-4 sm:py-6 md:py-10 px-3 sm:px-4 md:px-6">

        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-4 sm:mb-6">My Earnings</h1>

        <form method="GET"
            class="flex flex-col sm:flex-row flex-wrap gap-3 sm:gap-4 mb-4 sm:mb-6 bg-white p-3 sm:p-4 md:p-6 rounded-lg shadow-sm">
            <label class="block w-full sm:flex-1 sm:min-w-[120px]">
                <span class="text-sm font-medium text-gray-700">Year</span>
                <select name="year" class="border rounded px-3 py-2 w-full mt-1 text-sm sm:text-base">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label class="block w-full sm:flex-1 sm:min-w-[120px]">
                <span class="text-sm font-medium text-gray-700">Month</span>
                <select name="month" class="border rounded px-3 py-2 w-full mt-1 text-sm sm:text-base">
                    <?php foreach (range(1, 12) as $m): ?>
                        <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                            <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block w-full sm:flex-1 sm:min-w-[120px]">
                <span class="text-sm font-medium text-gray-700">Week</span>
                <select name="week" class="border rounded px-3 py-2 w-full mt-1 text-sm sm:text-base">
                    <option value="1" <?= $week == 1 ? 'selected' : '' ?>>Week 1 (1-7)</option>
                    <option value="2" <?= $week == 2 ? 'selected' : '' ?>>Week 2 (8-15)</option>
                    <option value="3" <?= $week == 3 ? 'selected' : '' ?>>Week 3 (16-23)</option>
                    <option value="4" <?= $week == 4 ? 'selected' : '' ?>>Week 4 (24-End)</option>
                </select>
            </label>
            <div class="w-full sm:w-auto sm:self-end pt-1">
                <button type="submit"
                    class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700 w-full sm:w-auto text-sm sm:text-base">Filter</button>
            </div>
        </form>

        <h2 class="text-lg sm:text-xl font-semibold text-gray-700 mb-3">My Direct Sale Points (Personal Sales)</h2>
        <p class="text-sm text-gray-600 mb-3">These are points earned from your direct sales (no agency selected). Paid
            weekly.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4 mb-6 sm:mb-8">
            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-yellow-200">
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="bg-yellow-100 text-yellow-600 p-2 sm:p-3 rounded-full flex-shrink-0">
                        <i data-feather="clock"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-gray-500 text-xs sm:text-sm">Total Pending (All Time)</div>
                        <div class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-800 break-words">
                            <?= number_format($total_pending_points) ?> Points
                        </div>
                        <div class="text-gray-600 font-medium text-xs sm:text-sm mt-1">Est. Value: Rs.
                            <?= number_format($total_pending_points * 0.1, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-white p-4 sm:p-6 rounded-lg shadow-sm border border-blue-200">
                <div class="flex items-center gap-3 sm:gap-4">
                    <div class="bg-blue-100 text-blue-600 p-2 sm:p-3 rounded-full flex-shrink-0">
                        <i data-feather="calendar"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-gray-500 text-xs sm:text-sm">Pending for Selected Period</div>
                        <div class="text-xl sm:text-2xl md:text-3xl font-bold text-gray-800 break-words">
                            <?= number_format($period_pending_points) ?> Points
                        </div>
                        <div class="text-gray-600 font-medium text-xs sm:text-sm mt-1 break-words">
                            (<?= "$start_date_str to $end_date_str" ?>)
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($is_representative): ?>
            <h2 class="text-lg sm:text-xl font-semibold text-gray-700 mb-3">My Agency Bonus Points</h2>
            <p class="text-sm text-gray-600 mb-3">These are agency points (points_representative) from sales made for your
                agencies. Used for bonus payments when 2 agencies reach 5,000+ points each.</p>

            <?php if (empty($agency_statuses)): ?>
                <div
                    class="bg-white shadow-sm rounded-lg p-4 sm:p-6 mb-4 sm:mb-6 border border-gray-200 text-center text-gray-500">
                    <p class="text-sm">No agencies found. Contact admin to set up agencies.</p>
                </div>
            <?php else: ?>
                <?php foreach ($agency_statuses as $agency_status): ?>
                    <?php
                    // Get points_representative for this agency (agency bonus points)
                    $agency_points = (int) ($agency_status['total_representative_points'] ?? 0);

                    // Calculate percentage towards Rs. 1,000 bonus (5,000 points = 1 bonus)
                    // Bonus threshold: 5,000 points_representative = Rs. 1,000 bonus
                    $bonus_pct = min(100, ($agency_points / 5000) * 100);

                    // Prevent negative percentages for display
                    if ($bonus_pct < 0)
                        $bonus_pct = 0;
                    ?>

                    <div class="bg-white shadow-sm rounded-lg p-4 sm:p-6 mb-4 sm:mb-6 border border-gray-200">
                        <h3 class="text-base sm:text-lg font-semibold text-blue-700 mb-3 sm:mb-4">
                            <?= h($agency_status['agency_name']) ?>
                        </h3>

                        <p class="text-xs sm:text-sm text-gray-600 mb-3 sm:mb-4">
                            This shows the current <strong>Representative Points</strong> (points_representative) accumulated for
                            this agency from agency sales.
                            Bonus eligibility: 5,000 points = Rs. 1,000 bonus (requires 2 agencies with 5,000+ points each).
                        </p>

                        <div>
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-end mb-1 gap-1 sm:gap-0">
                                <span class="text-xs sm:text-sm font-medium text-gray-700">Agency Representative Points</span>
                                <span class="text-xs sm:text-sm text-gray-600"><?= number_format($agency_points) ?> / 5,000</span>
                            </div>
                            <div class="w-full progress-bar-bg rounded-full h-2 sm:h-2.5">
                                <div class="progress-bar h-2 sm:h-2.5 rounded-full" style="width: <?= $bonus_pct ?>%"></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                Progress towards bonus: <?= number_format($bonus_pct, 1) ?>%
                                <?php if ($agency_points > 0): ?>
                                    • Unredeemed points from confirmed agency sales
                                <?php else: ?>
                                    • No points yet
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

        <h2 class="text-lg sm:text-xl font-semibold text-gray-700 mb-3">
            <span class="block sm:inline">My Payment History</span>
            <span class="block text-sm sm:text-base font-normal text-gray-600 mt-1 sm:mt-0 sm:inline sm:ml-2">
                (<?= "$start_date_str to $end_date_str" ?>)
            </span>
        </h2>

        <!-- Mobile Card View (visible on small screens) -->
        <div class="block md:hidden space-y-3 mb-4">
            <?php if (empty($payments_history)): ?>
                <div class="bg-white p-4 rounded-lg shadow-sm text-center text-gray-500">
                    No payments found for this period.
                </div>
            <?php else: ?>
                <?php foreach ($payments_history as $p): ?>
                    <div class="bg-white shadow-sm rounded-lg p-4 border border-gray-200">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex-1">
                                <div class="text-xs text-gray-500 mb-1">Date Paid</div>
                                <div class="text-sm font-medium text-gray-700">
                                    <?= date('Y-m-d H:i', strtotime($p['paid_date'])) ?>
                                </div>
                            </div>
                            <div>
                                <?php
                                $type_color = $p['payment_type'] == 'agency' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800';
                                echo "<span class='px-2 py-0.5 rounded-full text-xs font-medium $type_color'>" . ucfirst($p['payment_type']) . "</span>";
                                ?>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mt-3 pt-3 border-t border-gray-100">
                            <div>
                                <div class="text-xs text-gray-500 mb-1">Points Redeemed</div>
                                <div class="text-sm font-medium text-gray-700">
                                    <?= number_format($p['points_redeemed']) ?>
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 mb-1">Amount Paid</div>
                                <div class="text-sm font-semibold text-gray-900">
                                    Rs. <?= number_format($p['amount'], 2) ?>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($p['remarks'])): ?>
                            <div class="mt-3 pt-3 border-t border-gray-100">
                                <div class="text-xs text-gray-500 mb-1">Remarks</div>
                                <div class="text-sm text-gray-600"><?= h($p['remarks']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="bg-gray-100 rounded-lg p-4 font-bold">
                    <div class="text-sm text-gray-700 mb-1">Total Paid in Period</div>
                    <div class="text-lg text-gray-900">Rs. <?= number_format($total_paid_in_period, 2) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Desktop Table View (hidden on small screens) -->
        <div class="hidden md:block bg-white shadow-sm rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
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
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= h($p['remarks']) ?></td>
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
    </div>

    <script>
        feather.replace();
    </script>
</body>

</html>