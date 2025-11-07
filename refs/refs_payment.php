<?php
// leader_payments.php
include '../config.php';       // gives $conn (MySQLi)
include 'refs_header.php';   // session + auth check

// Ensure only logged-in team leaders
$leader_id = $_SESSION['user_id'] ?? null;
if (!$leader_id) {
    header("Location: ../login.php");
    exit;
}

// ✅ Get available years from this leader’s payment history
$year_options = [];
$year_query = "SELECT DISTINCT year FROM payments WHERE user_id = ? ORDER BY year DESC";
$stmt = $mysqli->prepare($year_query);
$stmt->bind_param("i", $leader_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $year_options[] = $row['year'];
}
$stmt->close();

$selected_year = $_GET['year'] ?? date('Y');

// ✅ Fetch this leader’s own monthly payments
$sql = "
    SELECT 
        month,
        SUM(points_earned) AS total_points,
        SUM(amount_paid) AS total_amount,
        MAX(status) AS payment_status,
        MAX(paid_at) AS last_paid_date
    FROM payments
    WHERE user_id = ? AND year = ?
    GROUP BY month
    ORDER BY month ASC
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ii", $leader_id, $selected_year);
$stmt->execute();
$result = $stmt->get_result();

$payments = [];
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Leader Payment Summary</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-5xl mx-auto py-10">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-blue-800">My Payment Summary</h1>
            <a href="leader_dashboard.php" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                ← Back to Dashboard
            </a>
        </div>

        <!-- Filter by Year -->
        <form method="get" class="mb-6 flex items-center gap-3">
            <label for="year" class="font-medium">Select Year:</label>
            <select name="year" id="year" class="border p-2 rounded">
                <?php foreach ($year_options as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $selected_year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Filter</button>
        </form>

        <div class="bg-white shadow rounded-lg p-6">
            <?php if (empty($payments)): ?>
                <div class="text-center text-gray-600 py-4">
                    No payment records found for <?= htmlspecialchars($selected_year) ?>.
                </div>
            <?php else: ?>
                <table class="min-w-full table-auto border">
                    <thead class="bg-blue-50 text-blue-700">
                        <tr>
                            <th class="p-3 border">Month</th>
                            <th class="p-3 border">Points Earned</th>
                            <th class="p-3 border">Amount Paid (Rs)</th>
                            <th class="p-3 border">Status</th>
                            <th class="p-3 border">Paid Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_points = 0;
                        $total_amount = 0;
                        foreach ($payments as $p):
                            $total_points += $p['total_points'];
                            $total_amount += $p['total_amount'];
                            ?>
                            <tr class="<?= $p['payment_status'] === 'paid' ? 'bg-green-50' : 'bg-red-50' ?> hover:bg-gray-50">
                                <td class="p-3 border font-medium">
                                    <?= date('F', mktime(0, 0, 0, $p['month'], 1)) ?>
                                </td>
                                <td class="p-3 border text-center"><?= number_format($p['total_points']) ?></td>
                                <td class="p-3 border text-center"><?= number_format($p['total_amount'], 2) ?></td>
                                <td class="p-3 border text-center">
                                    <span class="px-2 py-1 rounded text-white text-sm 
                                        <?= $p['payment_status'] === 'paid' ? 'bg-green-600' : 'bg-yellow-500' ?>">
                                        <?= ucfirst($p['payment_status']) ?>
                                    </span>
                                </td>
                                <td class="p-3 border text-center">
                                    <?= $p['last_paid_date'] ? date('Y-m-d', strtotime($p['last_paid_date'])) : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Totals -->
                        <tr class="bg-blue-100 font-semibold">
                            <td class="p-3 border text-right">Total</td>
                            <td class="p-3 border text-center"><?= number_format($total_points) ?></td>
                            <td class="p-3 border text-center"><?= number_format($total_amount, 2) ?></td>
                            <td class="p-3 border text-center" colspan="2"></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>