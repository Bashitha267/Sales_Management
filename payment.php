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

// --- 1️⃣ REPS’ SALES POINTS ---
$rep_points = [];
$res = $mysqli->query("
    SELECT sl.ref_id, SUM(sd.qty * i.points_rep) AS total_points
    FROM sales_log sl
    JOIN sale_details sd ON sl.sale_id = sd.sale_id
    JOIN items i ON sd.item_code = i.item_code
    WHERE YEAR(sl.sale_date) = $year AND MONTH(sl.sale_date) = $month
    GROUP BY sl.ref_id
");
while ($r = $res->fetch_assoc()) {
    $rep_points[$r['ref_id']] = (int) $r['total_points'];
}

// --- 2️⃣ GET USERS ---
$users = [];
$res = $mysqli->query("SELECT id, first_name, last_name, role FROM users WHERE role IN ('rep','team leader')");
while ($u = $res->fetch_assoc()) {
    $users[$u['id']] = $u;
}

// --- 3️⃣ TEAM LEADER POINTS ---
$leader_points = [];
$teams = [];

// Get team members grouped by leader
$teamRes = $mysqli->query("
    SELECT t.leader_id, tm.member_id 
    FROM teams t 
    JOIN team_members tm ON t.team_id = tm.team_id
");
while ($t = $teamRes->fetch_assoc()) {
    $teams[$t['leader_id']][] = $t['member_id'];
}

// Calculate total points for each leader (team members’ sales * points_leader)
foreach ($teams as $leader_id => $members) {
    $total = 0;
    foreach ($members as $mid) {
        $res2 = $mysqli->query("
            SELECT SUM(sd.qty * i.points_leader) AS pts
            FROM sales_log sl
            JOIN sale_details sd ON sl.sale_id = sd.sale_id
            JOIN items i ON sd.item_code = i.item_code
            WHERE sl.ref_id = $mid AND YEAR(sl.sale_date) = $year AND MONTH(sl.sale_date) = $month
        ");
        if ($r2 = $res2->fetch_assoc()) {
            $total += (int) $r2['pts'];
        }
    }

    // Add leader’s own sales (points_rep)
    if (isset($rep_points[$leader_id])) {
        $total += $rep_points[$leader_id];
    }

    if ($total > 0) {
        $leader_points[$leader_id] = $total;
    }
}

// Merge leader and rep data (leader overrides)
$points_data = $rep_points;
foreach ($leader_points as $lid => $pts) {
    $points_data[$lid] = $pts;
}

// --- 4️⃣ PAYMENT STATUS ---
$pay_status = [];
$res = $mysqli->query("SELECT user_id, status FROM payments WHERE year=$year AND month=$month");
while ($r = $res->fetch_assoc()) {
    $pay_status[$r['user_id']] = $r['status'];
}
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

        <!-- 🔽 FILTER BAR -->
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

        <!-- 🔍 Search -->
        <input type="text" id="searchInput" placeholder="Search user name..."
            class="w-full border border-gray-300 rounded px-4 py-2 mb-4 focus:outline-none focus:ring focus:ring-blue-200">

        <!-- 🧾 PAYMENT TABLE -->
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
                    $points = $points_data[$uid] ?? 0;
                    $amount = $points * 0.05;
                    $status = $pay_status[$uid] ?? 'pending';
                    $rowClass = $status === 'paid' ? 'bg-green-100' : 'bg-red-100';
                    ?>
                    <tr class="<?= $rowClass ?>" data-name="<?= strtolower($u['first_name'] . ' ' . $u['last_name']) ?>">
                        <td class="px-4 py-2 font-medium"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                        </td>
                        <td class="px-4 py-2 text-center"><?= htmlspecialchars(ucfirst($role)) ?></td>
                        <td class="px-4 py-2 text-center"><?= number_format($points) ?></td>
                        <td class="px-4 py-2 text-center">$<?= number_format($amount, 2) ?></td>
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
                                    data-id="<?= $uid ?>" data-points="<?= $points ?>" data-year="<?= $year ?>"
                                    data-month="<?= $month ?>">
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
                    points: btn.data("points"),
                    year: btn.data("year"),
                    month: btn.data("month")
                },
                success: function (res) {
                    btn.closest("tr").removeClass("bg-red-100").addClass("bg-green-100");
                    btn.closest("tr").find(".status").removeClass("text-red-700").addClass("text-green-700").text("Paid");
                    btn.replaceWith('<button disabled class="bg-gray-300 text-gray-600 px-4 py-1 rounded cursor-not-allowed">Paid</button>');
                }
            });
        });
    </script>
</body>

</html>