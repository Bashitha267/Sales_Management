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

// --- Get available years for this user (ONLY from 'full' and 'sale_approved' sales) ---
$years = [];
// MODIFIED: Changed admin_approved = 1 to sale_approved = 1
$yearStmt = $mysqli->prepare("
    SELECT DISTINCT YEAR(sale_date) AS y 
    FROM sales 
    WHERE rep_user_id = ? AND sale_type = 'full' AND sale_approved = 1
    ORDER BY y DESC
");
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

// --- Calculate personal monthly points (ONLY from 'full' and 'sale_approved' sales) ---
$monthly_points = [];
$monthly_sales_count = [];

// MODIFIED: Changed s.admin_approved = 1 to s.sale_approved = 1
$pointsStmt = $mysqli->prepare("
    SELECT
        MONTH(s.sale_date) AS month,
        SUM(si.quantity * COALESCE(i.rep_points, 0)) AS total_points,
        COUNT(DISTINCT s.id) AS sales_count
    FROM sales s
    JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN items i ON i.id = si.item_id
    WHERE s.rep_user_id = ? 
      AND YEAR(s.sale_date) = ?
      AND s.sale_type = 'full'
      AND s.sale_approved = 1
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
    <div class="max-w-3xl mx-auto px-4 py-10">
        <h1 class="text-2xl sm:text-3xl font-bold mb-6 text-blue-800">My Monthly Sales Report (Approved Full Sales)</h1>

        <form method="get" class="mb-6 flex items-center gap-3">
            <label class="font-semibold text-base sm:text-lg">Select Year:</label>
            <select name="year" class="border rounded px-3 py-2 text-base sm:text-lg" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $cur_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="space-y-3">
            <?php
            $grand_total_points = 0;
            $grand_total_sales = 0;
            foreach ($months as $num => $name):
                $points = $monthly_points[$num] ?? 0;
                $salesCount = $monthly_sales_count[$num] ?? 0;
                $grand_total_points += $points;
                $grand_total_sales += $salesCount;
                ?>
                <div class="bg-white shadow-md rounded-lg p-4 <?php echo ($points == 0) ? 'opacity-60' : ''; ?>">
                    <div class="text-lg font-semibold text-blue-800">
                        <?= $name ?>
                    </div>
                    <div class="flex justify-between items-center mt-2">
                        <div>
                            <div class="text-sm text-gray-600">Total Points</div>
                            <div
                                class="text-2xl font-bold <?php echo ($points > 0) ? 'text-blue-700' : 'text-gray-500'; ?>">
                                <?= number_format($points) ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-gray-600">Sales Count</div>
                            <div class="text-2xl font-bold text-gray-800">
                                <?= number_format($salesCount) ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="bg-blue-800 text-white shadow-lg rounded-lg p-5 mt-6">
                <div class="text-xl font-bold">
                    <?= $cur_year ?> Grand Total
                </div>
                <div class="flex justify-between items-center mt-2">
                    <div>
                        <div class="text-sm text-blue-200">Total Points</div>
                        <div class="text-3xl font-bold">
                            <?= number_format($grand_total_points) ?>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-blue-200">Total Sales</div>
                        <div class="text-3xl font-bold">
                            <?= number_format($grand_total_sales) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>