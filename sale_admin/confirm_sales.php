<?php
require_once '../auth.php';
requireLogin();

// Security check - ensure user is sale_admin or admin
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'sale_admin' && $_SESSION['role'] !== 'admin')) {
    header('Location: /ref/login.php');
    exit;
}

require_once '../config.php';

$message = '';
$message_type = '';

// --- POST: Confirm Sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_sale_id'])) {
    $sale_id = (int) $_POST['confirm_sale_id'];

    if ($sale_id > 0) {
        $mysqli->begin_transaction();
        try {
            // Check if sale exists and is not already approved
            $stmt = $mysqli->prepare("SELECT sale_date, rep_user_id, sale_type FROM sales WHERE id = ? AND sale_approved = 0");
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();
            $saleInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$saleInfo) {
                $message = "Could not find or confirm Sale #{$sale_id}. It may have already been processed.";
                $message_type = 'error';
                $mysqli->rollback();
            } else {
                $sale_date = $saleInfo['sale_date'];
                $rep_user_id = $saleInfo['rep_user_id'];
                $sale_type = $saleInfo['sale_type'];

                $can_confirm = true;
                $error_message = '';
                $agency_id = null;
                $representative_id = null;

                // Process points only for full sales
                if ($sale_type === 'full') {

                    // Since this page is for 'rep' roles, find their agency and representative
                    $stmt = $mysqli->prepare("SELECT agency_id, representative_id FROM agency_reps WHERE rep_user_id = ? LIMIT 1");
                    $stmt->bind_param('i', $rep_user_id);
                    $stmt->execute();
                    $agencyInfo = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$agencyInfo) {
                        $can_confirm = false;
                        $error_message = "Error: Could not find agency/representative for Rep ID #{$rep_user_id}. Cannot assign points.";
                    } else {
                        $agency_id = (int) $agencyInfo['agency_id'];
                        $representative_id = (int) $agencyInfo['representative_id'];

                        if ($agency_id <= 0 || $representative_id <= 0) {
                            $can_confirm = false;
                            $error_message = "Error: Rep ID #{$rep_user_id} has invalid agency data.";
                        }
                    }

                    if ($can_confirm) {
                        // Check if points already logged in either table
                        $stmt = $mysqli->prepare("SELECT 1 FROM points_ledger_rep WHERE sale_id = ?");
                        $stmt->bind_param('i', $sale_id);
                        $stmt->execute();
                        $already_logged_rep = $stmt->get_result()->num_rows > 0;
                        $stmt->close();

                        $stmt = $mysqli->prepare("SELECT 1 FROM points_ledger_group_points WHERE sale_id = ?");
                        $stmt->bind_param('i', $sale_id);
                        $stmt->execute();
                        $already_logged_group = $stmt->get_result()->num_rows > 0;
                        $stmt->close();

                        if (!$already_logged_rep && !$already_logged_group) {
                            // Calculate points per item
                            $stmt = $mysqli->prepare("
                                SELECT 
                                    SUM(si.quantity * i.rep_points) AS total_points_rep, 
                                    SUM(si.quantity * i.representative_points) AS total_points_representative
                                FROM sale_items si 
                                INNER JOIN items i ON si.item_id = i.id 
                                WHERE si.sale_id = ?
                            ");
                            $stmt->bind_param('i', $sale_id);
                            $stmt->execute();
                            $points_result = $stmt->get_result()->fetch_assoc();
                            $stmt->close();

                            $total_points_rep = (int) ($points_result['total_points_rep'] ?? 0);
                            $total_points_representative = (int) ($points_result['total_points_representative'] ?? 0);

                            // Insert into points_ledger_rep
                            if ($total_points_rep > 0) {
                                $stmt = $mysqli->prepare("
                                    INSERT INTO points_ledger_rep 
                                        (sale_id, rep_user_id, agency_id, sale_date, points, redeemed) 
                                    VALUES (?, ?, ?, ?, ?, 0)
                                ");
                                $stmt->bind_param('iiisi', $sale_id, $rep_user_id, $agency_id, $sale_date, $total_points_rep);
                                if (!$stmt->execute()) {
                                    $can_confirm = false;
                                    $error_message = "Error adding points to points_ledger_rep: " . $stmt->error;
                                }
                                $stmt->close();
                            }

                            // Insert into points_ledger_group_points
                            if ($can_confirm && $total_points_representative > 0) {
                                $stmt = $mysqli->prepare("
                                    INSERT INTO points_ledger_group_points 
                                        (sale_id, representative_id, agency_id, sale_date, points, redeemed) 
                                    VALUES (?, ?, ?, ?, ?, 0)
                                ");
                                // Use $representative_id and $agency_id from the agency_reps lookup
                                $stmt->bind_param('iiisi', $sale_id, $representative_id, $agency_id, $sale_date, $total_points_representative);
                                if (!$stmt->execute()) {
                                    $can_confirm = false;
                                    $error_message = "Error adding points to points_ledger_group_points: " . $stmt->error;
                                }
                                $stmt->close();
                            }
                        }
                    }
                }
                // For half sales: no points are added, but sale can still be confirmed

                // Update the sale to mark it as confirmed (only if validation passed)
                if ($can_confirm) {
                    $updateSql = "UPDATE sales SET sale_approved = 1 WHERE id = ?";
                    $stmt = $mysqli->prepare($updateSql);
                    $stmt->bind_param('i', $sale_id);
                    $stmt->execute();
                    $stmt->close();

                    $mysqli->commit();
                    $message = "Sale #{$sale_id} has been successfully confirmed.";
                    $message_type = 'success';
                } else {
                    $mysqli->rollback();
                    $message = $error_message ?: "Error: Could not confirm Sale #{$sale_id}.";
                    $message_type = 'error';
                }
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error confirming sale: " . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- POST: Decline/Delete Sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decline_sale_id'])) {
    $sale_id = (int) $_POST['decline_sale_id'];

    if ($sale_id > 0) {
        $mysqli->begin_transaction();
        try {
            // Delete points from both ledgers first
            $stmt = $mysqli->prepare("DELETE FROM points_ledger_rep WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM points_ledger_group_points WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();
            $stmt->close();

            // Delete sale_items
            $stmt = $mysqli->prepare("DELETE FROM sale_items WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();
            $stmt->close();

            // Delete the sale
            $stmt = $mysqli->prepare("DELETE FROM sales WHERE id = ? AND sale_approved = 0");
            $stmt->bind_param('i', $sale_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $mysqli->commit();
                $message = "Sale #{$sale_id} has been declined and deleted.";
                $message_type = 'success';
            } else {
                $mysqli->rollback();
                $message = "Could not delete Sale #{$sale_id}. It may have already been confirmed or does not exist.";
                $message_type = 'error';
            }
            $stmt->close();
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error deleting sale: " . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- DATA FETCH: Get unconfirmed sales for 'rep' role ONLY ---
$unconfirmed_sales = [];
$sql = "SELECT 
            s.id, s.sale_date, s.rep_user_id, s.sale_type,
            u_rep.username AS rep_username,
            u_rep.first_name AS rep_first_name,
            u_rep.last_name AS rep_last_name,
            u_rep.role AS rep_role,
            ar_agency.agency_name AS agency_name,
            ar_agency.id AS display_agency_id,
            ar.representative_id AS representative_id,
            ar_rep_user.username AS representative_username,
            COALESCE(SUM(si.quantity * i.rep_points), 0) AS total_points_rep,
            COALESCE(SUM(si.quantity * i.representative_points), 0) AS total_points_group,
            COALESCE(SUM(si.quantity * i.price), 0) AS total_amount
        FROM sales s
        JOIN users u_rep ON s.rep_user_id = u_rep.id
        -- Use INNER JOIN for agency_reps to ONLY get reps
        JOIN agency_reps ar ON s.rep_user_id = ar.rep_user_id
        JOIN agencies ar_agency ON ar.agency_id = ar_agency.id
        JOIN users ar_rep_user ON ar.representative_id = ar_rep_user.id
        LEFT JOIN sale_items si ON s.id = si.sale_id
        LEFT JOIN items i ON si.item_id = i.id
        WHERE s.sale_approved = 0
        AND u_rep.role = 'rep' -- Ensures we only get 'rep' roles
        GROUP BY s.id
        ORDER BY s.sale_date DESC, s.id DESC";

$result = $mysqli->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $unconfirmed_sales[] = $row;
    }
} else {
    // Handle query error
    $message = "Error fetching sales: " . $mysqli->error;
    $message_type = 'error';
}

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    include '../admin_header.php';
} else {
    include 'sales_header.php';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Rep Sales</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen">

    <div class="max-w-7xl mx-auto py-6 sm:py-8 md:py-12 px-4 sm:px-6">

        <h1 class="text-2xl sm:text-3xl font-bold text-teal-800 flex items-center gap-2 sm:gap-3 mb-6 sm:mb-8">
            <span data-feather="check-circle" class="w-5 h-5 sm:w-6 sm:h-6"></span>
            Confirm Rep Sales
        </h1>

        <?php if ($message && $message_type === 'success'): ?>
            <div class="bg-green-100 border border-green-300 text-green-800 px-3 sm:px-4 py-2 sm:py-3 rounded-lg mb-4 sm:mb-6 text-sm sm:text-base"
                role="alert">
                <span class="font-medium">Success!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($message && $message_type === 'error'): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 px-3 sm:px-4 py-2 sm:py-3 rounded-lg mb-4 sm:mb-6 text-sm sm:text-base"
                role="alert">
                <span class="font-medium">Error!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($unconfirmed_sales) && $message_type !== 'error'): ?>
            <div class="bg-white rounded-lg shadow-md p-6 sm:p-8 md:p-10 text-center text-gray-500">
                <svg data-feather="check-circle" class="w-10 h-10 sm:w-12 sm:h-12 mx-auto text-green-400 mb-3 sm:mb-4"
                    stroke-width="1.5"></svg>
                <h3 class="text-base sm:text-lg font-medium">All Rep Sales Confirmed!</h3>
                <p class="text-xs sm:text-sm mt-1">There are no unconfirmed sales from reps.</p>
            </div>
        <?php elseif (!empty($unconfirmed_sales)): ?>
            <div class="space-y-4 md:hidden">
                <?php foreach ($unconfirmed_sales as $sale): ?>
                    <div class="bg-white rounded-lg shadow-md p-4 border border-gray-200">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 font-mono">#<?= htmlspecialchars($sale['id']) ?>
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?= htmlspecialchars(date("Y-m-d", strtotime($sale['sale_date']))) ?>
                                </p>
                            </div>
                            <span
                                class="px-2 py-1 text-xs font-semibold rounded-full 
                                <?= $sale['sale_type'] === 'full' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                <?= htmlspecialchars(strtoupper($sale['sale_type'])) ?>
                            </span>
                        </div>

                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600 font-medium">Rep Name:</span>
                                <span
                                    class="text-gray-900"><?= htmlspecialchars(trim(($sale['rep_first_name'] ?? '') . ' ' . ($sale['rep_last_name'] ?? '')) ?: $sale['rep_username']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 font-medium">Agency:</span>
                                <span class="text-gray-900">
                                    <?= htmlspecialchars($sale['agency_name'] ?? 'N/A') ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 font-medium">Representative:</span>
                                <span
                                    class="text-gray-900"><?= htmlspecialchars($sale['representative_username'] ?? 'N/A') ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 font-medium">Rep Points:</span>
                                <span
                                    class="text-blue-600 font-semibold"><?= number_format((float) $sale['total_points_rep'], 0) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 font-medium">Group Points:</span>
                                <span
                                    class="text-purple-600 font-semibold"><?= number_format((float) $sale['total_points_group'], 0) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 font-medium">Total Amount:</span>
                                <span
                                    class="text-gray-900 font-semibold"><?= number_format((float) $sale['total_amount'], 2) ?></span>
                            </div>
                        </div>

                        <div class="mt-4 pt-4 border-t border-gray-200 flex gap-2">
                            <form method="POST" action="confirm_sales.php" class="flex-1"
                                onsubmit="return confirm('Are you sure you want to confirm Sale #<?= (int) $sale['id'] ?>?');">
                                <input type="hidden" name="confirm_sale_id" value="<?= (int) $sale['id'] ?>">
                                <button type="submit" class="w-full inline-flex items-center justify-center rounded-md h-10
                                    bg-teal-500 px-4 py-2 text-sm font-semibold text-white transition
                                    hover:bg-teal-600">
                                    <span data-feather="check" class="w-4 h-4 mr-1.5"></span>
                                    Confirm
                                </button>
                            </form>
                            <form method="POST" action="confirm_sales.php" class="flex-1"
                                onsubmit="return confirm('Are you sure you want to decline and delete Sale #<?= (int) $sale['id'] ?>? This action cannot be undone.');">
                                <input type="hidden" name="decline_sale_id" value="<?= (int) $sale['id'] ?>">
                                <button type="submit" class="w-full inline-flex items-center justify-center rounded-md h-10
                                    bg-red-500 px-4 py-2 text-sm font-semibold text-white transition
                                    hover:bg-red-600">
                                    <span data-feather="x" class="w-4 h-4 mr-1.5"></span>
                                    Decline
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="hidden md:block bg-white rounded-lg shadow-md overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sale
                                ID</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rep
                                Name</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Agency</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Representative</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sale
                                Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sale
                                Type</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Rep Points</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Group Points</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Total Amount</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($unconfirmed_sales as $sale): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-4 whitespace-nowrap font-mono text-sm text-gray-800">
                                    #<?= htmlspecialchars($sale['id']) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars(trim(($sale['rep_first_name'] ?? '') . ' ' . ($sale['rep_last_name'] ?? '')) ?: $sale['rep_username']) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars($sale['agency_name'] ?? 'N/A') ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars($sale['representative_username'] ?? 'N/A') ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                                    <?= htmlspecialchars(date("Y-m-d", strtotime($sale['sale_date']))) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm">
                                    <span
                                        class="px-2 py-1 text-xs font-semibold rounded-full 
                                        <?= $sale['sale_type'] === 'full' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                        <?= htmlspecialchars(strtoupper($sale['sale_type'])) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-semibold text-blue-600 text-right">
                                    <?= number_format((float) $sale['total_points_rep'], 0) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-semibold text-purple-600 text-right">
                                    <?= number_format((float) $sale['total_points_group'], 0) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 text-right">
                                    <?= number_format((float) $sale['total_amount'], 2) ?>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" action="confirm_sales.php"
                                            onsubmit="return confirm('Are you sure you want to confirm Sale #<?= (int) $sale['id'] ?>?');"
                                            class="inline">
                                            <input type="hidden" name="confirm_sale_id" value="<?= (int) $sale['id'] ?>">
                                            <button type="submit" class="inline-flex items-center justify-center rounded-md h-9
                                                bg-teal-500 px-3 py-1.5 text-xs sm:text-sm font-semibold text-white transition
                                                hover:bg-teal-600">
                                                <span data-feather="check" class="w-4 h-4 mr-1"></span>
                                                Confirm
                                            </button>
                                        </form>
                                        <form method="POST" action="confirm_sales.php"
                                            onsubmit="return confirm('Are you sure you want to decline and delete Sale #<?= (int) $sale['id'] ?>? This action cannot be undone.');"
                                            class="inline">
                                            <input type="hidden" name="decline_sale_id" value="<?= (int) $sale['id'] ?>">
                                            <button type="submit" class="inline-flex items-center justify-center rounded-md h-9
                                                bg-red-500 px-3 py-1.5 text-xs sm:text-sm font-semibold text-white transition
                                                hover:bg-red-600">
                                                <span data-feather="x" class="w-4 h-4 mr-1"></span>
                                                Decline
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>


    <script>
        feather.replace();
    </script>
</body>

</html>