<?php
session_start();
include '../config.php';
include 'refs_header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// --- Handle year selection ---
$cur_year = isset($_GET['year']) ? intval($_GET['year']) : (int) date('Y');

// --- Get available years for this user ---
$years = [];
$yearStmt = $mysqli->prepare("SELECT DISTINCT YEAR(sale_date) AS y FROM sales WHERE rep_user_id = ? ORDER BY y DESC");
if ($yearStmt) {
    $yearStmt->bind_param('i', $user_id);
    $yearStmt->execute();
    $res = $yearStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $years[] = (int) $row['y'];
    }
    $yearStmt->close();
}
if (empty($years)) {
    $years[] = (int) date('Y');
}

// --- Calculate personal monthly points ---
$monthly_points = [];
$monthly_sales_count = [];

$pointsStmt = $mysqli->prepare("
    SELECT
        MONTH(s.sale_date) AS month,
        SUM(si.quantity * COALESCE(i.rep_points, 0)) AS total_points,
        COUNT(DISTINCT s.id) AS sales_count
    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN items i ON i.id = si.item_id
    WHERE s.rep_user_id = ? AND YEAR(s.sale_date) = ?
    GROUP BY month
");

if ($pointsStmt) {
    $pointsStmt->bind_param('ii', $user_id, $cur_year);
    $pointsStmt->execute();
    $result = $pointsStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $month = (int) $row['month'];
        $monthly_points[$month] = (int) ($row['total_points'] ?? 0);
        $monthly_sales_count[$month] = (int) ($row['sales_count'] ?? 0);
    }
    $pointsStmt->close();
}

$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Sales Report</title>
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-3xl mx-auto px-6 py-10">
        <h1 class="text-3xl font-bold mb-6 text-blue-800">My Monthly Sales Report</h1>

        <form method="get" class="mb-6 flex items-center gap-3">
            <label class="font-semibold text-lg">Select Year:</label>
            <select name="year" class="border rounded px-3 py-2 text-lg" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $cur_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="bg-white shadow-md rounded-lg overflow-hidden">
            <table class="w-full text-left border-collapse text-base">
                <thead class="bg-blue-100 text-blue-900">
                    <tr>
                        <th class="px-6 py-3">Month</th>
                        <th class="px-6 py-3 text-right">Total Points</th>
                        <th class="px-6 py-3 text-center">Sales Count</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $grand_total = 0;
                    foreach ($months as $num => $name):
                        $points = $monthly_points[$num] ?? 0;
                        $salesCount = $monthly_sales_count[$num] ?? 0;
                        $grand_total += $points;
                        ?>
                        <tr class="<?= $points > 0 ? 'bg-blue-50' : '' ?>">
                            <td class="px-6 py-3 font-medium"><?= $name ?></td>
                            <td class="px-6 py-3 text-right font-semibold text-blue-700"><?= $points ?></td>
                            <td class="px-6 py-3 text-center"><?= $salesCount ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="bg-blue-200 font-bold">
                        <td class="px-6 py-3 text-right">Total</td>
                        <td class="px-6 py-3 text-right text-blue-900"><?= $grand_total ?></td>
                        <td class="px-6 py-3 text-center"><?= array_sum($monthly_sales_count) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>