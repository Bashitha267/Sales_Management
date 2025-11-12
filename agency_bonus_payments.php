<?php
session_start();
include 'config.php';
include 'admin_header.php'; // Make sure you have this header file

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login.php");
    exit();
}

// 1. Find all eligible REPRESENTATIVES
// Check based on points_representative from points_ledger_group_points (unredeemed points only)
// Get all representatives who have at least 2 agencies with >= 5000 points_representative each
$eligible_representatives = [];

// First, get all representatives with their agencies and points
// Only count points from points_ledger_group_points table (agency bonus points)
$sql_agencies = "
    SELECT
        a.representative_id,
        a.id AS agency_id,
        a.agency_name,
        u.first_name,
        u.last_name,
        COALESCE(SUM(pl.points), 0) AS agency_points
    FROM
        agencies a
    JOIN
        users u ON a.representative_id = u.id
    LEFT JOIN
        points_ledger_group_points pl ON a.id = pl.agency_id 
            AND pl.representative_id = a.representative_id
            AND pl.redeemed = 0
    LEFT JOIN
        sales s ON pl.sale_id = s.id
    WHERE
        u.role = 'representative'
        AND (s.sale_type = 'full' OR s.sale_type IS NULL)
        AND (s.sale_approved = 1 OR s.sale_approved IS NULL)
    GROUP BY
        a.representative_id, a.id, a.agency_name, u.first_name, u.last_name
    HAVING
        agency_points >= 5000
    ORDER BY
        a.representative_id, a.agency_name;
";

$res_agencies = $mysqli->query($sql_agencies);
if (!$res_agencies) {
    die("Query failed: " . $mysqli->error);
}

// Group agencies by representative and check if they have at least 2 agencies with >= 5000 points
$rep_agencies = [];
while ($row = $res_agencies->fetch_assoc()) {
    $rep_id = $row['representative_id'];
    if (!isset($rep_agencies[$rep_id])) {
        $rep_agencies[$rep_id] = [
            'representative_id' => $rep_id,
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'agencies' => []
        ];
    }
    $rep_agencies[$rep_id]['agencies'][] = [
        'id' => $row['agency_id'],
        'name' => $row['agency_name'],
        'points' => (int) $row['agency_points']
    ];
}

// Filter to only representatives with at least 2 agencies eligible (each with >= 5000 points)
foreach ($rep_agencies as $rep_id => $rep_data) {
    // Filter agencies that have >= 5000 points
    $eligible_agencies = array_filter($rep_data['agencies'], function ($agency) {
        return $agency['points'] >= 5000;
    });

    if (count($eligible_agencies) >= 2) {
        // Get first two eligible agencies (sorted by name)
        usort($eligible_agencies, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $eligible_agencies = array_values($eligible_agencies); // Re-index array
        $agency_1 = $eligible_agencies[0];
        $agency_2 = $eligible_agencies[1];

        $eligible_representatives[] = [
            'representative_id' => $rep_id,
            'first_name' => $rep_data['first_name'],
            'last_name' => $rep_data['last_name'],
            'agency_1_id' => $agency_1['id'],
            'agency_1_name' => $agency_1['name'],
            'agency_1_points' => $agency_1['points'],
            'agency_2_id' => $agency_2['id'],
            'agency_2_name' => $agency_2['name'],
            'agency_2_points' => $agency_2['points']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agency Bonus Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto p-8">
        <h1 class="text-2xl font-bold text-blue-700 mb-6">Agency Bonus Payments</h1>
        <p class="mb-4 text-gray-600">Pay representatives for agency bonuses. A bonus is eligible when
            <strong>both</strong> Agency 1
            and Agency 2 each have at least 5,000 <strong>Representative Points</strong> (points_representative).
            Payment: Rs. 1,000 for 10,000 points (5,000 from each agency). All points will be redeemed after payment.
        </p>

        <!-- Mobile cards -->
        <div class="sm:hidden space-y-4" id="paymentCards">
            <?php if (empty($eligible_representatives)): ?>
                <div class="p-4 text-center text-gray-500 bg-white rounded-lg shadow">No representatives are eligible for a
                    bonus right now.</div>
            <?php else: ?>
                <?php foreach ($eligible_representatives as $rep):
                    $rep_id = $rep['representative_id'];
                    $agency_1_id = $rep['agency_1_id'];
                    $agency_2_id = $rep['agency_2_id'];
                    $agency_1_points = (int) $rep['agency_1_points'];
                    $agency_2_points = (int) $rep['agency_2_points'];

                    // Eligibility check: both agencies must have >= 5000 points_representative
                    if ($agency_1_points < 5000 || $agency_2_points < 5000)
                        continue;

                    // Fixed payment: Rs. 1,000 for 10,000 points (5,000 from each agency)
                    // ALL points from both agencies will be redeemed, but payment is fixed at Rs. 1,000
                    $amount = 1000; // Fixed payment amount
                    $payment_points = 10000; // Payment is recorded as 10,000 points (5,000 from each agency)
                    // Note: All points from both agencies will be redeemed, regardless of amount
                    ?>
                    <div class="bg-white shadow rounded-lg p-4" data-rep-id="<?= $rep_id ?>">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-gray-900">
                                    <?= htmlspecialchars($rep['first_name'] . ' ' . $rep['last_name']) ?>
                                </div>
                                <div class="text-sm text-gray-600">ID: <?= $rep_id ?></div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">Payment</div>
                                <div class="text-base font-semibold">Rs. <?= number_format($amount) ?></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <div class="bg-blue-50 rounded p-2">
                                <div class="text-xs text-blue-800"><?= htmlspecialchars($rep['agency_1_name']) ?> Points</div>
                                <div class="text-sm font-medium text-blue-900"><?= number_format($rep['agency_1_points']) ?>
                                </div>
                            </div>
                            <div class="bg-blue-50 rounded p-2">
                                <div class="text-xs text-blue-800"><?= htmlspecialchars($rep['agency_2_name']) ?> Points</div>
                                <div class="text-sm font-medium text-blue-900"><?= number_format($rep['agency_2_points']) ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center justify-between mt-4">
                            <div class="text-sm">
                                <span class="text-gray-500">Bonus:</span>
                                <span class="font-semibold">Rs. <?= number_format($amount, 2) ?></span>
                            </div>
                            <button
                                class="pay-btn bg-green-600 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700 transition"
                                data-rep-id="<?= $rep_id ?>" data-agency-1-id="<?= $agency_1_id ?>"
                                data-agency-2-id="<?= $agency_2_id ?>" data-amount="<?= $amount ?>"
                                data-agency-1-points="<?= $agency_1_points ?>" data-agency-2-points="<?= $agency_2_points ?>"
                                data-payment-points="<?= $payment_points ?>">
                                Pay Bonus (Rs. <?= number_format($amount) ?>)
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Desktop table -->
        <div class="hidden sm:block">
            <div class="overflow-x-auto">
                <table class="min-w-[800px] w-full bg-white shadow rounded-lg border-collapse" id="paymentTable">
                    <thead class="bg-blue-100">
                        <tr>
                            <th class="px-4 py-2 text-left">Representative</th>
                            <th class="px-4 py-2 text-center">Agencies</th>
                            <th class="px-4 py-2 text-center">Points (Representative Points)</th>
                            <th class="px-4 py-2 text-center">Bonus Amount (Rs.)</th>
                            <th class="px-4 py-2 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php
                        if (empty($eligible_representatives)): ?>
                            <tr>
                                <td colspan="5" class="p-4 text-center text-gray-500">No representatives are eligible for a
                                    bonus right now.</td>
                            </tr>
                        <?php else:
                            foreach ($eligible_representatives as $rep):
                                $rep_id = $rep['representative_id'];
                                $agency_1_id = $rep['agency_1_id'];
                                $agency_2_id = $rep['agency_2_id'];
                                $agency_1_points = (int) $rep['agency_1_points'];
                                $agency_2_points = (int) $rep['agency_2_points'];

                                // Eligibility check: both agencies must have >= 5000 points_representative
                                if ($agency_1_points < 5000 || $agency_2_points < 5000)
                                    continue;

                                // Fixed payment: Rs. 1,000 for 10,000 points (5,000 from each agency)
                                // ALL points from both agencies will be redeemed, but payment is fixed at Rs. 1,000
                                $amount = 1000; // Fixed payment amount
                                $payment_points = 10000; // Payment is recorded as 10,000 points (5,000 from each agency)
                                ?>
                                <tr class="hover:bg-gray-50" data-rep-id="<?= $rep_id ?>">
                                    <td class="px-4 py-2">
                                        <div class="font-medium">
                                            <?= htmlspecialchars($rep['first_name'] . ' ' . $rep['last_name']) ?>
                                        </div>
                                        <div class="text-sm text-gray-600">
                                            ID: <?= $rep_id ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <div class="text-sm font-medium"><?= htmlspecialchars($rep['agency_1_name']) ?></div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($rep['agency_2_name']) ?></div>
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <div class="text-sm font-medium"><?= number_format($rep['agency_1_points']) ?></div>
                                        <div class="text-xs text-gray-500"><?= number_format($rep['agency_2_points']) ?></div>
                                    </td>
                                    <td class="px-4 py-2 text-center font-medium">Rs. <?= number_format($amount, 2) ?></td>
                                    <td class="px-4 py-2 text-center">
                                        <button
                                            class="pay-btn bg-green-600 text-white px-4 py-1 rounded hover:bg-green-700 transition"
                                            data-rep-id="<?= $rep_id ?>" data-agency-1-id="<?= $agency_1_id ?>"
                                            data-agency-2-id="<?= $agency_2_id ?>" data-amount="<?= $amount ?>"
                                            data-agency-1-points="<?= $agency_1_points ?>"
                                            data-agency-2-points="<?= $agency_2_points ?>"
                                            data-payment-points="<?= $payment_points ?>">
                                            Pay Bonus (Rs. <?= number_format($amount) ?>)
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        $(".pay-btn").click(function () {
            const btn = $(this);
            const amount = btn.data('amount');
            const agency1Points = btn.data('agency-1-points');
            const agency2Points = btn.data('agency-2-points');
            if (!confirm(`Pay Rs. ${amount} for this representative?\n\nAgency 1 Points: ${agency1Points.toLocaleString()}\nAgency 2 Points: ${agency2Points.toLocaleString()}\n\nAll points will be redeemed, but payment is for 10,000 points (5,000 from each agency).`)) {
                return;
            }

            $.ajax({
                url: "agency_payment_action.php", // Corrected file name
                method: "POST",
                data: {
                    user_id: btn.data("rep-id"), // The representative's user_id
                    agency_1_id: btn.data("agency-1-id"),
                    agency_2_id: btn.data("agency-2-id"),
                    agency_1_points: btn.data("agency-1-points"),
                    agency_2_points: btn.data("agency-2-points"),
                    amount: btn.data("amount"),
                    payment_points: btn.data("payment-points"), // 10000 points for payment record
                    remarks: `Agency bonus payout: Rs. ${amount} for 10,000 points (all points redeemed from both agencies).`
                },
                dataType: "json",
                success: function (res) {
                    if (res.success) {
                        // Visually disable the button and container (row/card)
                        const container = btn.closest("[data-rep-id]");
                        container.addClass("bg-green-100 opacity-50");
                        btn.replaceWith('<button disabled class="bg-gray-300 text-gray-600 px-4 py-2 rounded cursor-not-allowed text-sm">Paid</button>');
                        alert('Payment successful! The table will update on next page load.');
                    } else {
                        alert('Error: ' + (res.error || 'Unknown error occurred.'));
                    }
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                }
            });
        });
    </script>
</body>

</html>