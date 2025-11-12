<?php
session_start();
include 'config.php';
include 'admin_header.php'; // Make sure you have this header file

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login.php");
    exit();
}

// --- 1. GET AND CALCULATE FILTERS ---

// Helper function to get the current week number based on your definition
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

// Define week date ranges
$week_ranges = [
    1 => ['start' => 1, 'end' => 7],
    2 => ['start' => 8, 'end' => 15],
    3 => ['start' => 16, 'end' => 23],
    4 => ['start' => 24, 'end' => 31] // 31 is a safe high number
];

$start_day = $week_ranges[$week]['start'];
$end_day = $week_ranges[$week]['end'];

// Create Y-m-d strings
$start_date_str = date('Y-m-d', mktime(0, 0, 0, $month, $start_day, $year));

if ($week == 4) {
    // Week 4 is 24th to end of month
    $end_day = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
}
$end_date_str = date('Y-m-d', mktime(0, 0, 0, $month, $end_day, $year));


// --- 2. GET DATA BASED ON FILTERS ---

// 1. Get all users
$users = [];
$res = $mysqli->query("SELECT id, first_name, last_name, role FROM users WHERE role IN ('rep','representative')");
while ($u = $res->fetch_assoc()) {
    $users[$u['id']] = $u;
}

// 2. Get UNREDEEMED points for the selected period
$unredeemed_points = [];
// Query from points_ledger_rep table for rep points
$sql = "SELECT 
            pl.rep_user_id, 
            SUM(pl.points) AS total_unredeemed_points
        FROM points_ledger_rep pl
        INNER JOIN sales s ON pl.sale_id = s.id
        WHERE pl.redeemed = 0 
          AND pl.sale_date BETWEEN ? AND ?
          AND s.sale_type = 'full'
          AND s.sale_approved = 1
        GROUP BY pl.rep_user_id";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ss', $start_date_str, $end_date_str);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $unredeemed_points[$r['rep_user_id']] = (int) $r['total_unredeemed_points'];
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Rep Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto p-4 sm:p-6 md:p-8">
        <h1 class="text-xl sm:text-2xl font-bold text-blue-700 mb-4 sm:mb-6">Weekly Rep Payments</h1>
        <p class="mb-4 text-sm sm:text-base text-gray-600">Pay reps and representatives for their unredeemed personal
            sales points for the
            selected period. (1 Point = Rs. 0.1)</p>

        <form method="GET" class="flex flex-col sm:flex-row sm:flex-wrap gap-4 mb-6 bg-white p-4 rounded shadow">
            <label class="block flex-1 min-w-[140px]">
                <span class="text-sm font-medium text-gray-700">Year</span>
                <select name="year" class="border rounded px-3 py-2 w-full text-base">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <label class="block flex-1 min-w-[140px]">
                <span class="text-sm font-medium text-gray-700">Month</span>
                <select name="month" class="border rounded px-3 py-2 w-full text-base">
                    <?php foreach (range(1, 12) as $m): ?>
                        <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                            <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="block flex-1 min-w-[140px]">
                <span class="text-sm font-medium text-gray-700">Week</span>
                <select name="week" class="border rounded px-3 py-2 w-full text-base">
                    <option value="1" <?= $week == 1 ? 'selected' : '' ?>>Week 1 (1-7)</option>
                    <option value="2" <?= $week == 2 ? 'selected' : '' ?>>Week 2 (8-15)</option>
                    <option value="3" <?= $week == 3 ? 'selected' : '' ?>>Week 3 (16-23)</option>
                    <option value="4" <?= $week == 4 ? 'selected' : '' ?>>Week 4 (24-End)</option>
                </select>
            </label>
            <div class="self-end sm:self-end">
                <button type="submit"
                    class="bg-blue-600 text-white px-5 py-2.5 sm:py-2 rounded hover:bg-blue-700 w-full sm:w-auto text-base touch-manipulation">Filter</button>
            </div>
        </form>

        <input type="text" id="searchInput" placeholder="Search user name..."
            class="w-full border border-gray-300 rounded px-4 py-2.5 sm:py-2 mb-4 focus:outline-none focus:ring focus:ring-blue-200 text-base">

        <!-- Desktop Table View (hidden on mobile) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full bg-white shadow rounded-lg border-collapse" id="paymentTable">
                <thead class="bg-blue-100">
                    <tr>
                        <th class="px-4 py-2 text-left text-sm">User</th>
                        <th class="px-4 py-2 text-center text-sm">Role</th>
                        <th class="px-4 py-2 text-center text-sm">Points (<?= $start_date_str ?> to
                            <?= $end_date_str ?>)
                        </th>
                        <th class="px-4 py-2 text-center text-sm">Amount (Rs.)</th>
                        <th class="px-4 py-2 text-center text-sm">Status</th>
                        <th class="px-4 py-2 text-center text-sm">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($users as $u):
                        $uid = $u['id'];
                        $role = $u['role'];
                        $points = $unredeemed_points[$uid] ?? 0;
                        $amount = $points * 0.1; // Your rule: 1 point = 0.1 Rs
                    
                        // Only show users who have points to redeem in this period
                        if ($points <= 0) {
                            continue;
                        }
                        ?>
                        <tr class="bg-red-100" data-name="<?= strtolower($u['first_name'] . ' ' . $u['last_name']) ?>">
                            <td class="px-4 py-2 font-medium text-sm">
                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                            </td>
                            <td class="px-4 py-2 text-center text-sm"><?= htmlspecialchars(ucfirst($role)) ?></td>
                            <td class="px-4 py-2 text-center text-sm"><?= number_format($points) ?></td>
                            <td class="px-4 py-2 text-center text-sm">Rs. <?= number_format($amount, 2) ?></td>
                            <td class="px-4 py-2 text-center text-sm">
                                <span class="status font-semibold text-red-700">Pending</span>
                            </td>
                            <td class="px-4 py-2 text-center text-sm">
                                <button
                                    class="pay-btn bg-green-600 text-white px-4 py-1.5 rounded hover:bg-green-700 transition text-sm touch-manipulation"
                                    data-id="<?= $uid ?>" data-start-date="<?= $start_date_str ?>"
                                    data-end-date="<?= $end_date_str ?>">
                                    Mark as Paid
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($unredeemed_points)): ?>
                        <tr>
                            <td colspan="6" class="p-4 text-center text-gray-500 text-sm">No unredeemed points found for
                                this period.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View (visible on mobile/tablet) -->
        <div class="md:hidden space-y-4" id="paymentCards">
            <?php
            $hasPayments = false;
            foreach ($users as $u):
                $uid = $u['id'];
                $role = $u['role'];
                $points = $unredeemed_points[$uid] ?? 0;
                $amount = $points * 0.1;

                if ($points <= 0) {
                    continue;
                }
                $hasPayments = true;
                ?>
                <div class="bg-red-100 rounded-lg shadow p-4 payment-card"
                    data-name="<?= strtolower($u['first_name'] . ' ' . $u['last_name']) ?>">
                    <div class="space-y-3">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="font-bold text-base text-gray-900">
                                    <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                </h3>
                                <p class="text-sm text-gray-600"><?= htmlspecialchars(ucfirst($role)) ?></p>
                            </div>
                            <span
                                class="status inline-block px-2 py-1 rounded text-xs font-semibold text-red-700 bg-red-200">Pending</span>
                        </div>

                        <div class="border-t border-red-200 pt-3 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Period:</span>
                                <span class="font-medium"><?= $start_date_str ?> to <?= $end_date_str ?></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Points:</span>
                                <span class="font-bold text-gray-900"><?= number_format($points) ?></span>
                            </div>
                            <div class="flex justify-between text-base font-bold">
                                <span class="text-gray-700">Amount:</span>
                                <span class="text-green-700">Rs. <?= number_format($amount, 2) ?></span>
                            </div>
                        </div>

                        <button
                            class="pay-btn w-full bg-green-600 text-white px-4 py-3 rounded hover:bg-green-700 transition text-base font-medium touch-manipulation"
                            data-id="<?= $uid ?>" data-start-date="<?= $start_date_str ?>"
                            data-end-date="<?= $end_date_str ?>">
                            Mark as Paid
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$hasPayments): ?>
                <div class="bg-white rounded-lg shadow p-6 text-center text-gray-500 text-sm">
                    No unredeemed points found for this period.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        $("#searchInput").on("keyup", function () {
            const val = $(this).val().toLowerCase();
            // Filter table rows (desktop)
            $("#paymentTable tbody tr").filter(function () {
                $(this).toggle($(this).attr("data-name").indexOf(val) > -1);
            });
            // Filter card elements (mobile)
            $(".payment-card").filter(function () {
                $(this).toggle($(this).attr("data-name").indexOf(val) > -1);
            });
        });

        $(document).on("click", ".pay-btn", function () {
            if (!confirm('Are you sure you want to mark this period as paid? This will redeem all pending points for this user *within this date range*.')) {
                return;
            }
            const btn = $(this);
            $.ajax({
                url: "rep_payment_action.php",
                method: "POST",
                data: {
                    user_id: btn.data("id"),
                    start_date: btn.data("start-date"),
                    end_date: btn.data("end-date")
                },
                success: function (res) {
                    // Update table row (desktop)
                    const tableRow = btn.closest("tr");
                    if (tableRow.length) {
                        tableRow.removeClass("bg-red-100").addClass("bg-green-100");
                        tableRow.find(".status").removeClass("text-red-700").addClass("text-green-700").text("Paid");
                        btn.replaceWith('<button disabled class="bg-gray-300 text-gray-600 px-4 py-1.5 rounded cursor-not-allowed text-sm">Paid</button>');
                    }

                    // Update card (mobile)
                    const card = btn.closest(".payment-card");
                    if (card.length) {
                        card.removeClass("bg-red-100").addClass("bg-green-100");
                        card.find(".status").removeClass("text-red-700 bg-red-200").addClass("text-green-700 bg-green-200").text("Paid");
                        btn.replaceWith('<button disabled class="w-full bg-gray-300 text-gray-600 px-4 py-3 rounded cursor-not-allowed text-base font-medium">Paid</button>');
                    }
                },
                error: function (xhr) {
                    let errorMsg = 'An error occurred. Please try again.';
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        errorMsg = xhr.responseJSON.error;
                    }
                    alert(errorMsg);
                }
            });
        });
    </script>
</body>

</html>