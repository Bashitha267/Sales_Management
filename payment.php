<?php
session_start();
include 'config.php';
include 'admin_header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

// --- GET YEAR & MONTH FILTER ---
$year = isset($_GET['year']) ? (int) $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int) $_GET['month'] : date('n');

// Dropdown for months
$month_names = [
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

// --- 1️⃣ USER POINTS FROM points_ledger (FULL SALES ONLY) ---
// We only consider FULL SALES (points_rep) and only UNREDEEMED points for this month
$rep_points = [];
// MODIFIED: Joined with sales table
$stmt = $mysqli->prepare("
    SELECT pl.rep_user_id, SUM(pl.points_rep) AS total_points
    FROM points_ledger pl
    INNER JOIN sales s ON pl.sale_id = s.id
    WHERE pl.redeemed = 0
      AND YEAR(pl.sale_date) = ?
      AND MONTH(pl.sale_date) = ?
      AND s.sale_type = 'full'
    GROUP BY pl.rep_user_id
");
$stmt->bind_param('ii', $year, $month);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $rep_points[$r['rep_user_id']] = (int) $r['total_points'];
}
$stmt->close();

// --- 2️⃣ GET USERS ---
$users = [];
$res = $mysqli->query("SELECT id, first_name, last_name, role FROM users WHERE role IN ('rep','representative','team leader')");
while ($u = $res->fetch_assoc()) {
    $users[$u['id']] = $u;
}

// --- 3️⃣ PAYMENT STATUS (already paid this month?) ---
$pay_status = [];
$start_of_month = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$end_of_month = date('Y-m-t 23:59:59', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$stmtPaid = $mysqli->prepare("
    SELECT user_id
    FROM payments
    WHERE payment_type = 'monthly'
      AND paid_date BETWEEN ? AND ?
");
$stmtPaid->bind_param('ss', $start_of_month, $end_of_month);
$stmtPaid->execute();
$resPaid = $stmtPaid->get_result();
while ($r = $resPaid->fetch_assoc()) {
    $pay_status[$r['user_id']] = 'paid';
}
$stmtPaid->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Monthly Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto p-8">
        <h1 class="text-2xl font-bold text-blue-700 mb-6">Make Payments</h1>

        <form method="GET" class="flex gap-4 mb-6">
            <select name="month" class="border rounded px-3 py-2">
                <?php foreach ($month_names as $num => $name): ?>
                    <option value="<?= $num ?>" <?= $num == $month ? 'selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
            </select>

            <select name="year" class="border rounded px-3 py-2">
                <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>

            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filter</button>
        </form>

        <input type="text" id="searchInput" placeholder="Search user name..."
            class="w-full border border-gray-300 rounded px-4 py-2 mb-4 focus:outline-none focus:ring focus:ring-blue-200">

        <table class="w-full bg-white shadow rounded-lg border-collapse" id="paymentTable">
            <thead class="bg-blue-100">
                <tr>
                    <th class="px-4 py-2 text-left">User</th>
                    <th class="px-4 py-2 text-center">Role</th>
                    <th class="px-4 py-2 text-center">Points</th>
                    <th class="px-4 py-2 text-center">Amount</th>
                    <th class="px-4 py-2 text-center">Status</th>
                    <th class="px-4 py-2 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($users as $u):
                    $uid = $u['id'];
                    $role = $u['role'];
                    $points = $rep_points[$uid] ?? 0;
                    if ($points <= 0 && !isset($pay_status[$uid])) {
                        continue; // Show only users with pending points or already paid
                    }
                    $amount = $points * 0.1; // Rs per point
                    $status = $pay_status[$uid] ?? 'pending';
                    $rowClass = $status === 'paid' ? 'bg-green-100' : 'bg-red-100';
                    ?>
                    <tr class="<?= $rowClass ?>" data-name="<?= strtolower($u['first_name'] . ' ' . $u['last_name']) ?>">
                        <td class="px-4 py-2 font-medium"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                        </td>
                        <td class="px-4 py-2 text-center"><?= htmlspecialchars(ucfirst($role)) ?></td>
                        <td class="px-4 py-2 text-center"><?= number_format($points) ?></td>
                        <td class="px-4 py-2 text-center">Rs. <?= number_format($amount, 2) ?></td>
                        <td class="px-4 py-2 text-center">
                            <span
                                class="status font-semibold <?= $status === 'paid' ? 'text-green-700' : 'text-red-700' ?>">
                                <?= ucfirst($status) ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-center">
                            <?php if ($status === 'paid'): ?>
                                <button disabled
                                    class="bg-gray-300 text-gray-600 px-4 py-1 rounded cursor-not-allowed">Paid</button>
                            <?php else: ?>
                                <button class="pay-btn bg-green-600 text-white px-4 py-1 rounded hover:bg-green-700 transition"
                                    data-id="<?= $uid ?>" data-year="<?= $year ?>" data-month="<?= $month ?>">
                                    Mark as Paid
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        $("#searchInput").on("keyup", function () {
            const val = $(this).val().toLowerCase();
            $("#paymentTable tbody tr").filter(function () {
                $(this).toggle($(this).attr("data-name").indexOf(val) > -1);
            });
        });

        $(".pay-btn").click(function () {
            const btn = $(this);
            $.ajax({
                url: "payment_action.php",
                method: "POST",
                data: {
                    user_id: btn.data("id"),
                    year: btn.data("year"),
                    month: btn.data("month")
                },
                dataType: "json",
                success: function (res) {
                    if (!res.success) {
                        alert(res.error || 'Failed to mark as paid.');
                        return;
                    }
                    btn.closest("tr").removeClass("bg-red-100").addClass("bg-green-100");
                    btn.closest("tr").find(".status").removeClass("text-red-700").addClass("text-green-700").text("Paid");
                    btn.replaceWith('<button disabled class="bg-gray-300 text-gray-600 px-4 py-1 rounded cursor-not-allowed">Paid</button>');
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                }
            });
        });
    </script>
</body>

</html>