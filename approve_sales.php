<?php
require_once 'auth.php';
requireLogin();
require_once 'config.php'; // Assumed config is in parent directory

$message = '';
$message_type = '';

// --- Handle AJAX for sale details modal ---
if (isset($_GET['details']) && is_numeric($_GET['details'])) {
    $sale_id = (int) $_GET['details'];

    // Admin view: No need to check for rep_user_id, just get the sale items.
    // We calculate points as if it were a 'full' sale, which is what the admin is approving.
    $sql = "SELECT i.item_code, i.item_name, si.quantity, 
                   COALESCE(i.rep_points, 0) AS rep_points,
                   (si.quantity * COALESCE(i.rep_points, 0)) AS item_points
            FROM sale_items si
            LEFT JOIN items i ON si.item_id = i.id
            WHERE si.sale_id = ?";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $total_points_modal = 0;
        echo "<table class='min-w-full text-sm'><thead><tr class='bg-slate-100'>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Item Code</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Item</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Qty</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Points (ea)</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Total Points</th>";
        echo "</tr></thead><tbody class='divide-y divide-slate-200'>";

        while ($row = $result->fetch_assoc()) {
            $baseRepPoints = (int) ($row['rep_points'] ?? 0);
            $itemPoints = (int) ($row['item_points'] ?? 0);
            $total_points_modal += $itemPoints;
            echo "<tr>";
            echo "<td class='px-3 py-2 font-mono'>" . htmlspecialchars($row['item_code'] ?? 'N/A') . "</td>";
            echo "<td class='px-3 py-2'>" . htmlspecialchars($row['item_name'] ?? 'Unknown') . "</td>";
            echo "<td class='px-3 py-2'>" . (int) ($row['quantity'] ?? 0) . "</td>";
            echo "<td class='px-3 py-2'>" . $baseRepPoints . "</td>";
            echo "<td class='px-3 py-2 font-medium'>" . $itemPoints . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "<tfoot><tr class='bg-slate-50 font-bold border-t-2 border-slate-300'>";
        echo "<td colspan='4' class='px-3 py-2 text-right text-slate-800'>Total</td>";
        echo "<td class='px-3 py-2 text-slate-800'>{$total_points_modal}</td>";
        echo "</tr></tfoot></table>";
    } else {
        echo "<div class='text-slate-500 p-4'>No items found for this sale.</div>";
    }
    $stmt->close();
    exit;
}


// --- Handle POST request to approve a sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_sale_id'])) {
    $sale_id_to_approve = (int) $_POST['approve_sale_id'];

    if ($sale_id_to_approve > 0) {
        // Update the sale to 'full' and reset the request flags.
        $updateSql = "UPDATE sales 
                      SET sale_type = 'full', 
                          admin_request = 0, 
                          admin_approved = 1 
                      WHERE id = ? AND sale_type = 'half' AND admin_request = 1";

        $stmt = $mysqli->prepare($updateSql);
        if ($stmt) {
            $stmt->bind_param('i', $sale_id_to_approve);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $message = "Sale #{$sale_id_to_approve} has been successfully approved and converted to a 'Full' sale.";
                $message_type = 'success';
            } else {
                $message = "Could not find or update Sale #{$sale_id_to_approve}. It may have already been processed.";
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = "Database error. Please try again.";
            $message_type = 'error';
        }
    }
}

// --- Fetch all pending approval requests ---
// (UPDATED QUERY to get rep name, agency, agency rep, and potential points)
$pending_sales = [];
$sql = "SELECT 
            s.id, 
            s.sale_date, 
            s.rep_user_id, 
            u_rep.username AS rep_username,
            a.agency_name,
            u_main.username AS representative_username,
            COALESCE(SUM(si.quantity * i.rep_points), 0) AS potential_points
        FROM sales s
        JOIN users u_rep ON s.rep_user_id = u_rep.id
        LEFT JOIN agency_reps ar ON s.rep_user_id = ar.rep_user_id
        LEFT JOIN agencies a ON ar.agency_id = a.id
        LEFT JOIN users u_main ON ar.representative_id = u_main.id
        LEFT JOIN sale_items si ON s.id = si.sale_id
        LEFT JOIN items i ON si.item_id = i.id
        WHERE s.sale_type = 'half' AND s.admin_request = 1
        GROUP BY s.id, s.sale_date, s.rep_user_id, u_rep.username, a.agency_name, u_main.username
        ORDER BY s.sale_date ASC";

$result = $mysqli->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pending_sales[] = $row;
    }
}

include 'admin_header.php'; // Include the admin header
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Sales</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>

    <!-- Responsive Table CSS -->
    <style>
        /* On screens smaller than 768px (Tailwind's 'md' breakpoint) */
        @media (max-width: 767px) {
            .responsive-table thead {
                /* Hide table headers */
                display: none;
            }

            .responsive-table tbody tr {
                /* Make each row a block-level card */
                display: block;
                border-bottom: 2px solid #e5e7eb;
                /* Separation between cards */
                padding: 1rem 0.5rem;
            }

            .responsive-table tbody td {
                /* Use flex to align label and value */
                display: flex;
                justify-content: space-between;
                /* Label on left, value on right */
                align-items: center;
                padding: 0.75rem 0.5rem;
                /* Padding for each "field" */
                border: none;
                text-align: right;
                /* Align value to the right */
            }

            .responsive-table tbody td:before {
                /* Add the data-label as content before the value */
                content: attr(data-label);
                /* Pulls from 'data-label' attribute */
                font-weight: 600;
                text-align: left;
                padding-right: 1rem;
                color: #4b5563;
                /* Label text color */
            }

            .responsive-table .action-cell {
                /* Override flex for the action cell to stack buttons */
                display: block;
                padding-top: 1rem;
            }

            .responsive-table .action-cell:before {
                /* Hide the "Action" label on mobile */
                display: none;
            }
        }
    </style>
</head>

<body class="bg-gray-50">

    <div class="max-w-7xl mx-auto py-12 px-6"> <!-- Increased max-width for new columns -->
        <div class="flex flex-col sm:flex-row justify-between items-center mb-8 gap-4">
            <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3">

                Approve 'Half' Sale Requests
            </h1>

        </div>

        <!-- Success/Error Message -->
        <?php if ($message && $message_type === 'success'): ?>
            <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg mb-6" role="alert">
                <span class="font-medium">Success!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($message && $message_type === 'error'): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg mb-6" role="alert">
                <span class="font-medium">Error!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Sales to Approve Table -->
        <div class="bg-white rounded-lg shadow-md overflow-x-auto">
            <?php if (empty($pending_sales)): ?>
                <div class="p-10 text-center text-gray-500">
                    <span data-feather="check-circle" class="w-12 h-12 mx-auto text-green-400 mb-4"></span>
                    <h3 class="text-lg font-medium">All Caught Up!</h3>
                    <p class="text-sm">There are no pending 'half' sale requests to approve.</p>
                </div>
            <?php else: ?>
                <!-- Added 'responsive-table' class -->
                <table class="min-w-full divide-y divide-gray-200 responsive-table">
                    <!-- Added 'hidden md:table-header-group' to hide on mobile -->
                    <thead class="bg-gray-50 hidden md:table-header-group">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sale
                                ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rep
                                Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Agency</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Agency Rep</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sale
                                Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Potential Points</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200 md:divide-y-0">
                        <?php foreach ($pending_sales as $sale): ?>
                            <!-- Added 'block md:table-row' -->
                            <tr class="block md:table-row">
                                <!-- Added 'block md:table-cell' and 'data-label' to each td -->
                                <td class="px-6 py-3 md:py-4 whitespace-nowrap font-mono text-sm text-gray-800 block md:table-cell"
                                    data-label="Sale ID">
                                    #<?= htmlspecialchars($sale['id']) ?>
                                </td>
                                <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm font-medium text-gray-900 block md:table-cell"
                                    data-label="Rep Name">
                                    <?= htmlspecialchars($sale['rep_username']) ?>
                                </td>
                                <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                    data-label="Agency">
                                    <?= htmlspecialchars($sale['agency_name'] ?? 'N/A') ?>
                                </td>
                                <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                    data-label="Agency Rep">
                                    <?= htmlspecialchars($sale['representative_username'] ?? 'N/A') ?>
                                </td>
                                <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                    data-label="Sale Date">
                                    <?= htmlspecialchars(date("Y-m-d", strtotime($sale['sale_date']))) ?>
                                </td>
                                <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm font-semibold text-blue-600 block md:table-cell"
                                    data-label="Potential Points">
                                    <?= htmlspecialchars($sale['potential_points']) ?>
                                </td>
                                <!-- Special 'action-cell' class and wrapper div for buttons -->
                                <td class="px-6 py-3 md:py-4 whitespace-nowrap text-right text-sm font-medium block md:table-cell action-cell"
                                    data-label="Action">
                                    <div class="flex flex-col md:flex-row justify-end gap-2">
                                        <!-- View Button -->
                                        <button type="button" onclick="showSaleDetails(<?= (int) $sale['id'] ?>)"
                                            class="inline-flex items-center justify-center rounded-md h-12 bg-blue-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-600 w-full md:w-28">
                                            <span data-feather="eye" class="w-4 h-4 mr-1.5"></span>
                                            View
                                        </button>
                                        <!-- Approve Button -->
                                        <form method="POST" action="approve_sales.php"
                                            onsubmit="return confirm('Are you sure you want to approve this sale and make it FULL?');"
                                            class="inline w-full md:w-auto"> <!-- Full width on mobile -->
                                            <input type="hidden" name="approve_sale_id" value="<?= (int) $sale['id'] ?>">
                                            <button type="submit" class=" inline-flex items-center justify-center rounded-md
                                                bg-green-500 px-4 py-2 text-sm font-semibold text-white transition
                                                hover:bg-green-600 w-full md:w-28 h-12"> <!-- Full width on mobile -->
                                                <span data-feather="check" class="w-4 h-4 mr-1.5"></span>
                                                Approve
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sale Detail Modal -->
    <div id="saleDetailModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 transition-opacity">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg m-4">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold text-slate-800" id="modalTitle">Sale Details</h3>
                <button onclick="closeSaleDetails()"
                    class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>
            <div class="p-4 overflow-y-auto max-h-[70vh]" id="modalContent">Loading...</div>
        </div>
    </div>

    <script>
        feather.replace();

        function showSaleDetails(saleId) {
            const modal = document.getElementById('saleDetailModal');
            const content = document.getElementById('modalContent');
            content.innerHTML = 'Loading...';
            modal.classList.remove('hidden');

            fetch(`approve_sales.php?details=${saleId}`)
                .then(res => res.text())
                .then(html => { content.innerHTML = html; })
                .catch(() => { content.innerHTML = '<div class="text-red-500">Failed to load details.</div>'; });
        }

        function closeSaleDetails() {
            document.getElementById('saleDetailModal').classList.add('hidden');
        }
    </script>
</body>

</html>