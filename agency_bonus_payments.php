<?php
session_start();
include 'config.php';
include 'admin_header.php'; // Make sure you have this header file

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /login.php");
    exit();
}

// 1. Find all eligible agencies and their representatives
$eligible_agencies = [];
$sql = "
    SELECT 
        ap.agency_id,
        a.agency_name,
        a.representative_id,
        u.first_name,
        u.last_name,
        ap.total_rep_points,
        ap.total_representative_points
    FROM agency_points ap
    JOIN agencies a ON ap.agency_id = a.id
    JOIN users u ON a.representative_id = u.id
    WHERE 
        ap.total_rep_points >= 5000 
    AND 
        ap.total_representative_points >= 5000
";
$res = $mysqli->query($sql);
while ($r = $res->fetch_assoc()) {
    $eligible_agencies[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Agency Bonus Payments</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto p-8">
        <h1 class="text-2xl font-bold text-blue-700 mb-6">Agency Bonus Payments</h1>
        <p class="mb-4 text-gray-600">Pay representatives for agency bonuses. A bonus is eligible when **both** Rep
            Points and Representative Points are over 5,000. (1 Batch = 10,000 Points (5k+5k) = Rs. 1000)</p>

        <table class="w-full bg-white shadow rounded-lg border-collapse" id="paymentTable">
            <thead class="bg-blue-100">
                <tr>
                    <th class="px-4 py-2 text-left">Agency / Representative</th>
                    <th class="px-4 py-2 text-center">Rep Points</th>
                    <th class="px-4 py-2 text-center">Representative Points</th>
                    <th class="px-4 py-2 text-center">Eligible Batches</th>
                    <th class="px-4 py-2 text-center">Bonus Amount (Rs.)</th>
                    <th class="px-4 py-2 text-center">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php
                if (empty($eligible_agencies)): ?>
                    <tr>
                        <td colspan="6" class="p-4 text-center text-gray-500">No agencies are eligible for a bonus right
                            now.</td>
                    </tr>
                <?php else:
                    foreach ($eligible_agencies as $agency):
                        $rep_id = $agency['representative_id'];
                        $agency_id = $agency['agency_id'];

                        // Calculate batches
                        $rep_batches = floor($agency['total_rep_points'] / 5000);
                        $rep_for_rep_batches = floor($agency['total_representative_points'] / 5000);
                        $batches_to_pay = min($rep_batches, $rep_for_rep_batches);

                        if ($batches_to_pay <= 0)
                            continue;

                        $amount = $batches_to_pay * 1000;
                        $rep_points_to_redeem = $batches_to_pay * 5000;
                        $rep_for_rep_points_to_redeem = $batches_to_pay * 5000;
                        $total_points_to_redeem = $rep_points_to_redeem + $rep_for_rep_points_to_redeem;
                        ?>
                        <tr class="hover:bg-gray-50" data-agency-id="<?= $agency_id ?>">
                            <td class="px-4 py-2">
                                <div class="font-medium"><?= htmlspecialchars($agency['agency_name']) ?></div>
                                <div class="text-sm text-gray-600">
                                    <?= htmlspecialchars($agency['first_name'] . ' ' . $agency['last_name']) ?></div>
                            </td>
                            <td class="px-4 py-2 text-center"><?= number_format($agency['total_rep_points']) ?></td>
                            <td class="px-4 py-2 text-center"><?= number_format($agency['total_representative_points']) ?></td>
                            <td class="px-4 py-2 text-center font-medium"><?= number_format($batches_to_pay) ?></td>
                            <td class="px-4 py-2 text-center font-medium">Rs. <?= number_format($amount, 2) ?></td>
                            <td class="px-4 py-2 text-center">
                                <button class="pay-btn bg-green-600 text-white px-4 py-1 rounded hover:bg-green-700 transition"
                                    data-agency-id="<?= $agency_id ?>" data-rep-id="<?= $rep_id ?>" data-amount="<?= $amount ?>"
                                    data-batches="<?= $batches_to_pay ?>" data-total-points="<?= $total_points_to_redeem ?>"
                                    data-rep-points="<?= $rep_points_to_redeem ?>"
                                    data-rep-for-rep-points="<?= $rep_for_rep_points_to_redeem ?>">
                                    Pay Bonus
                                </button>
                            </td>
                        </tr>
                    <?php endforeach;
                endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        $(".pay-btn").click(function () {
            const btn = $(this);
            const batches = btn.data('batches');
            const amount = btn.data('amount');
            if (!confirm(`Are you sure you want to pay ${batches} batch(es) (Rs. ${amount}) for this agency?`)) {
                return;
            }

            $.ajax({
                url: "agency_payment_action.php", // This is a new file you need to create
                method: "POST",
                data: {
                    agency_id: btn.data("agency-id"),
                    user_id: btn.data("rep-id"), // The representative's user_id
                    amount: btn.data("amount"),
                    points_redeemed: btn.data("total-points"),
                    rep_points_to_subtract: btn.data("rep-points"),
                    rep_for_rep_points_to_subtract: btn.data("rep-for-rep-points"),
                    remarks: `Agency bonus payout for ${batches} batch(es).`
                },
                success: function (res) {
                    // Visually disable the button and row
                    btn.closest("tr").addClass("bg-green-100 opacity-50");
                    btn.replaceWith('<button disabled class="bg-gray-300 text-gray-600 px-4 py-1 rounded cursor-not-allowed">Paid</button>');
                    // You might want to reload the page to get fresh totals
                    // location.reload(); 
                    alert('Payment successful! The table will update on next page load.');
                },
                error: function () {
                    alert('An error occurred. Please try again.');
                }
            });
        });
    </script>
</body>

</html>