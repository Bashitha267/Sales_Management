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
$q = $mysqli->prepare("SELECT DISTINCT YEAR(sale_date) AS y FROM sales_log WHERE ref_id = ? ORDER BY y DESC");
$q->bind_param('i', $user_id);
$q->execute();
$res = $q->get_result();
while ($row = $res->fetch_assoc()) {
    $years[] = (int) $row['y'];
}
$q->close();
if (empty($years))
    $years[] = (int) date('Y');

// --- Get item points map ---
$item_points_map = [];
$item_q = $mysqli->query("SELECT item_code, points_leader, points_rep FROM items");
while ($i = $item_q->fetch_assoc()) {
    $item_points_map[$i['item_code']] = (int) $i['points_rep'];
}
$item_q->close();

// --- Calculate personal monthly points ---
$stmt = $mysqli->prepare("
    SELECT MONTH(sl.sale_date) AS month, sd.item_code, SUM(sd.qty) AS qty
    FROM sales_log sl
    JOIN sale_details sd ON sl.sale_id = sd.sale_id
    WHERE sl.ref_id = ? AND YEAR(sl.sale_date) = ?
    GROUP BY month, sd.item_code
");
$stmt->bind_param('ii', $user_id, $cur_year);
$stmt->execute();
$result = $stmt->get_result();

$monthly_points = [];
while ($r = $result->fetch_assoc()) {
    $m = (int) $r['month'];
    $points = ($item_points_map[$r['item_code']] ?? 0) * (int) $r['qty'];
    $monthly_points[$m] = ($monthly_points[$m] ?? 0) + $points;
}
$stmt->close();

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
                        <th class="px-6 py-3 text-center">Payment Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php
                    $grand_total = 0;
                    foreach ($months as $num => $name):
                        $points = $monthly_points[$num] ?? 0;
                        $grand_total += $points;
                        $status = ($points > 0) ? (rand(0, 1) ? "Paid" : "Not Paid") : "-";
                        ?>
                        <tr class="<?= $points > 0 ? 'bg-blue-50' : '' ?>">
                            <td class="px-6 py-3 font-medium"><?= $name ?></td>
                            <td class="px-6 py-3 text-right font-semibold text-blue-700"><?= $points ?></td>
                            <td class="px-6 py-3 text-center"><?= $status ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="bg-blue-200 font-bold">
                        <td class="px-6 py-3 text-right">Total</td>
                        <td class="px-6 py-3 text-right text-blue-900"><?= $grand_total ?></td>
                        <td class="px-6 py-3 text-center">—</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</body>

</html>