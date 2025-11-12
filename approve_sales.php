<?php
require_once 'auth.php';
requireLogin();
// Make sure config is in the root 'ref' folder
require_once 'config.php';

// Security check - ensure user is admin or sale_admin
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'sale_admin') {
    header('Location: /ref/login.php');
    exit;
}

$message = '';
$message_type = '';

// --- AJAX: Get "Half Sale" details modal ---
if (isset($_GET['details']) && is_numeric($_GET['details'])) {
    $sale_id = (int) $_GET['details'];

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
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Qty</th>";
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Points (ea)</th>";
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Total Points</th>";
        echo "</tr></thead><tbody class='divide-y divide-slate-200'>";

        while ($row = $result->fetch_assoc()) {
            $baseRepPoints = (int) ($row['rep_points'] ?? 0);
            $itemPoints = (int) ($row['item_points'] ?? 0);
            $total_points_modal += $itemPoints;
            echo "<tr>";
            echo "<td class='px-3 py-2 font-mono'>" . htmlspecialchars($row['item_code'] ?? 'N/A') . "</td>";
            echo "<td class='px-3 py-2'>" . htmlspecialchars($row['item_name'] ?? 'Unknown') . "</td>";
            echo "<td class='px-3 py-2 text-right'>" . (int) ($row['quantity'] ?? 0) . "</td>";
            echo "<td class='px-3 py-2 text-right'>" . $baseRepPoints . "</td>";
            echo "<td class='px-3 py-2 text-right font-medium text-blue-600'>" . $itemPoints . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "<tfoot><tr class='bg-slate-50 font-bold border-t-2 border-slate-300'>";
        echo "<td colspan='4' class='px-3 py-2 text-right text-slate-800'>Total</td>";
        echo "<td class='px-3 py-2 text-right font-bold text-blue-600'>{$total_points_modal}</td>";
        echo "</tr></tfoot></table>";
    } else {
        echo "<div class='text-slate-500 p-4'>No items found for this sale.</div>";
    }
    $stmt->close();
    exit;
}

// --- NEW AJAX: Get "Compare Edits" modal ---
if (isset($_GET['compare_id']) && is_numeric($_GET['compare_id'])) {
    $sale_id = (int) $_GET['compare_id'];

    // 1. Get all item names for easy lookup
    $item_names = [];
    $item_res = $mysqli->query("SELECT id, item_name, item_code FROM items");
    while ($row = $item_res->fetch_assoc()) {
        $item_names[$row['id']] = $row['item_name'] . " (" . $row['item_code'] . ")";
    }

    // 2. Get current sale data
    $stmt_orig = $mysqli->prepare("SELECT sale_date, pending_edit_data FROM sales WHERE id = ?");
    $stmt_orig->bind_param("i", $sale_id);
    $stmt_orig->execute();
    $orig_sale = $stmt_orig->get_result()->fetch_assoc();
    $stmt_orig->close();

    $original_date = $orig_sale['sale_date'];
    $pending_data = json_decode($orig_sale['pending_edit_data'], true);
    $proposed_date = $pending_data['sale_date'] ?? 'Error';
    $proposed_items = $pending_data['items'] ?? [];

    // 3. Get current sale items
    $original_items = [];
    $stmt_items = $mysqli->prepare("SELECT item_id, quantity FROM sale_items WHERE sale_id = ?");
    $stmt_items->bind_param("i", $sale_id);
    $stmt_items->execute();
    $items_res = $stmt_items->get_result();
    while ($row = $items_res->fetch_assoc()) {
        $original_items[] = $row;
    }
    $stmt_items->close();

    // 4. Build HTML
    $date_changed = $original_date !== $proposed_date;

    // We'll just build a simple string list for comparison
    $orig_items_list = [];
    foreach ($original_items as $item) {
        $name = $item_names[$item['item_id']] ?? 'Unknown Item';
        $orig_items_list[] = $item['quantity'] . " x " . htmlspecialchars($name);
    }
    $prop_items_list = [];
    foreach ($proposed_items as $item) {
        $name = $item_names[$item['item_id']] ?? 'Unknown Item';
        $prop_items_list[] = $item['quantity'] . " x " . htmlspecialchars($name);
    }

    $items_changed = (implode(',', $orig_items_list) !== implode(',', $prop_items_list));

    echo "<div class='grid grid-cols-2 gap-4 p-4 sm:p-6'>";
    // Column 1: Original
    echo "<div><h4 class='font-semibold text-slate-800 text-lg border-b pb-2 mb-2'>Original</h4>";
    echo "<div class='" . ($date_changed ? 'bg-red-100 p-2 rounded' : '') . "'>";
    echo "<strong class='text-slate-600'>Sale Date:</strong> " . htmlspecialchars($original_date);
    echo "</div>";
    echo "<div class='mt-4 " . ($items_changed ? 'bg-red-100 p-2 rounded' : '') . "'>";
    echo "<strong class='text-slate-600'>Items:</strong>";
    echo "<ul class='list-disc list-inside mt-1 space-y-1 text-sm'>";
    if (empty($orig_items_list))
        echo "<li>No items</li>";
    foreach ($orig_items_list as $item_str)
        echo "<li>" . $item_str . "</li>";
    echo "</ul></div></div>";

    // Column 2: Proposed
    echo "<div><h4 class='font-semibold text-green-700 text-lg border-b border-green-200 pb-2 mb-2'>Proposed</h4>";
    echo "<div class='" . ($date_changed ? 'bg-green-100 p-2 rounded' : '') . "'>";
    echo "<strong class='text-slate-600'>Sale Date:</strong> " . htmlspecialchars($proposed_date);
    echo "</div>";
    echo "<div class='mt-4 " . ($items_changed ? 'bg-green-100 p-2 rounded' : '') . "'>";
    echo "<strong class='text-slate-600'>Items:</strong>";
    echo "<ul class='list-disc list-inside mt-1 space-y-1 text-sm'>";
    if (empty($prop_items_list))
        echo "<li>No items</li>";
    foreach ($prop_items_list as $item_str)
        echo "<li>" . $item_str . "</li>";
    echo "</ul></div></div>";

    echo "</div>";
    exit;
}


// --- POST: Approve "Half" Sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_sale_id'])) {
    $sale_id_to_approve = (int) $_POST['approve_sale_id'];

    if ($sale_id_to_approve > 0) {
        $mysqli->begin_transaction();
        try {
            // Get sale details
            $stmt = $mysqli->prepare("SELECT sale_date, rep_user_id, agency_id FROM sales WHERE id = ? AND sale_type = 'half' AND admin_request = 1");
            $stmt->bind_param('i', $sale_id_to_approve);
            $stmt->execute();
            $saleInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$saleInfo) {
                $message = "Could not find or update Sale #{$sale_id_to_approve}. It may have already been processed.";
                $message_type = 'error';
                $mysqli->rollback();
            } else {
                $sale_date = $saleInfo['sale_date'];
                $rep_user_id = $saleInfo['rep_user_id'];
                $sale_agency_id = $saleInfo['agency_id']; // Agency ID from the sale (can be NULL)

                // Get representative_id from agencies table using agency_id from sales table
                $representative_id = null;
                if ($sale_agency_id) {
                    $stmt = $mysqli->prepare("SELECT representative_id FROM agencies WHERE id = ?");
                    $stmt->bind_param('i', $sale_agency_id);
                    $stmt->execute();
                    $agency_result = $stmt->get_result()->fetch_assoc();
                    $representative_id = $agency_result['representative_id'] ?? null;
                    $stmt->close();
                }

                // Get user role to determine how to calculate points
                $stmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->bind_param('i', $rep_user_id);
                $stmt->execute();
                $userInfo = $stmt->get_result()->fetch_assoc();
                $user_role = $userInfo['role'] ?? null;
                $stmt->close();

                // Add points if agency_id exists in sales table (regardless of user role)
                if ($sale_agency_id) {
                    // Check if points already logged in either table
                    $stmt = $mysqli->prepare("SELECT 1 FROM points_ledger_rep WHERE sale_id = ?");
                    $stmt->bind_param('i', $sale_id_to_approve);
                    $stmt->execute();
                    $already_logged_rep = $stmt->get_result()->num_rows > 0;
                    $stmt->close();

                    $stmt = $mysqli->prepare("SELECT 1 FROM points_ledger_group_points WHERE sale_id = ?");
                    $stmt->bind_param('i', $sale_id_to_approve);
                    $stmt->execute();
                    $already_logged_group = $stmt->get_result()->num_rows > 0;
                    $stmt->close();

                    if (!$already_logged_rep && !$already_logged_group) {
                        // Check if sale has items
                        $stmt = $mysqli->prepare("SELECT COUNT(*) as item_count FROM sale_items WHERE sale_id = ?");
                        $stmt->bind_param('i', $sale_id_to_approve);
                        $stmt->execute();
                        $item_check = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        if ($item_check['item_count'] > 0) {
                            // Get individual item points to insert separate records for each item
                            // Following the same pattern as confirm_sales.php
                            $stmt = $mysqli->prepare("
                                SELECT 
                                    si.item_id,
                                    si.quantity,
                                    i.rep_points,
                                    i.representative_points
                                FROM sale_items si 
                                JOIN items i ON si.item_id = i.id 
                                WHERE si.sale_id = ?
                            ");
                            $stmt->bind_param('i', $sale_id_to_approve);
                            $stmt->execute();
                            $items_result = $stmt->get_result();
                            $stmt->close();

                            // Insert points for each item separately
                            // Each item gets its own record in both tables
                            while ($item_row = $items_result->fetch_assoc()) {
                                $item_points_rep = (int) ($item_row['rep_points'] ?? 0) * (int) $item_row['quantity'];
                                $item_points_representative = (int) ($item_row['representative_points'] ?? 0) * (int) $item_row['quantity'];

                                // Insert into points_ledger_rep (rep points) - for individual sales points
                                if ($item_points_rep > 0) {
                                    $stmt = $mysqli->prepare("
                                        INSERT INTO points_ledger_rep 
                                            (sale_id, rep_user_id, agency_id, sale_date, points, redeemed) 
                                        VALUES (?, ?, ?, ?, ?, 0)
                                    ");
                                    $stmt->bind_param('iiisi', $sale_id_to_approve, $rep_user_id, $sale_agency_id, $sale_date, $item_points_rep);
                                    $stmt->execute();
                                    $stmt->close();
                                }

                                // Insert into points_ledger_group_points (representative/group points) - for agency bonus points
                                // Use representative_id from agencies table, not rep_user_id
                                if ($item_points_representative > 0 && $representative_id) {
                                    $stmt = $mysqli->prepare("
                                        INSERT INTO points_ledger_group_points 
                                            (sale_id, representative_id, agency_id, sale_date, points, redeemed) 
                                        VALUES (?, ?, ?, ?, ?, 0)
                                    ");
                                    $stmt->bind_param('iiisi', $sale_id_to_approve, $representative_id, $sale_agency_id, $sale_date, $item_points_representative);
                                    $stmt->execute();
                                    $stmt->close();
                                }
                            }
                        }
                    }
                }
                // For rep role or sales without agency: no points are added to ledgers

                // Update the sale to 'full' and reset the request flags
                $updateSql = "UPDATE sales 
                              SET sale_type = 'full', 
                                  admin_request = 0, 
                                  admin_approved = 1,
                                  sale_approved = 1
                              WHERE id = ?";
                $stmt = $mysqli->prepare($updateSql);
                $stmt->bind_param('i', $sale_id_to_approve);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $mysqli->commit();
                    $message = "Sale #{$sale_id_to_approve} has been successfully approved and converted to a 'Full' sale.";
                    $message_type = 'success';
                } else {
                    $mysqli->rollback();
                    $message = "Could not find or update Sale #{$sale_id_to_approve}. It may have already been processed.";
                    $message_type = 'error';
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error approving sale: " . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- NEW POST: Approve "Edit" Sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_edit_id'])) {
    $sale_id = (int) $_POST['approve_edit_id'];

    $mysqli->begin_transaction();
    try {
        // 1. Get the pending data and rep_user_id
        $sale_stmt = $mysqli->prepare("SELECT rep_user_id, pending_edit_data FROM sales WHERE id = ? FOR UPDATE");
        $sale_stmt->bind_param("i", $sale_id);
        $sale_stmt->execute();
        $sale_row = $sale_stmt->get_result()->fetch_assoc();
        $sale_stmt->close();

        if (!$sale_row || empty($sale_row['pending_edit_data'])) {
            throw new Exception("Sale not found or no pending data.");
        }

        $rep_user_id = $sale_row['rep_user_id'];
        $pending_data = json_decode($sale_row['pending_edit_data'], true);
        $new_sale_date = $pending_data['sale_date'];
        $new_items = $pending_data['items']; // This is an array like [['item_id' => X, 'quantity' => Y], ...]

        // 2. Get sale details - use sales table to get agency_id
        $sale_detail_stmt = $mysqli->prepare("SELECT sale_date, agency_id FROM sales WHERE id = ?");
        $sale_detail_stmt->bind_param("i", $sale_id);
        $sale_detail_stmt->execute();
        $sale_detail = $sale_detail_stmt->get_result()->fetch_assoc();
        $sale_detail_stmt->close();

        $old_sale_date = $sale_detail['sale_date'];
        $sale_agency_id = $sale_detail['agency_id'];

        // Get representative_id from agencies table using agency_id from sales table
        $representative_id = null;
        if ($sale_agency_id) {
            $agency_stmt = $mysqli->prepare("SELECT representative_id FROM agencies WHERE id = ?");
            $agency_stmt->bind_param("i", $sale_agency_id);
            $agency_stmt->execute();
            $agency_result = $agency_stmt->get_result()->fetch_assoc();
            $representative_id = $agency_result['representative_id'] ?? null;
            $agency_stmt->close();
        }

        // Get user role to determine how to handle points
        $user_role_stmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
        $user_role_stmt->bind_param("i", $rep_user_id);
        $user_role_stmt->execute();
        $user_role_result = $user_role_stmt->get_result()->fetch_assoc();
        $user_role = $user_role_result['role'] ?? null;
        $user_role_stmt->close();

        // 3. Get all item point data
        $items_data = [];
        $item_res = $mysqli->query("SELECT id, rep_points, representative_points FROM items");
        while ($row = $item_res->fetch_assoc()) {
            $items_data[$row['id']] = [
                'rep_points' => $row['rep_points'],
                'representative_points' => $row['representative_points']
            ];
        }

        // 4. Recalculate new total points
        $new_total_rep_points = 0;
        $new_total_representative_points = 0;
        foreach ($new_items as $item) {
            $item_id = $item['item_id'];
            $quantity = $item['quantity'];
            if (isset($items_data[$item_id])) {
                $new_total_rep_points += $quantity * $items_data[$item_id]['rep_points'];
                $new_total_representative_points += $quantity * $items_data[$item_id]['representative_points'];
            }
        }

        // 5. Delete old sale items
        $delete_stmt = $mysqli->prepare("DELETE FROM sale_items WHERE sale_id = ?");
        $delete_stmt->bind_param("i", $sale_id);
        $delete_stmt->execute();
        $delete_stmt->close();

        // 6. Insert new sale items
        $insert_item_stmt = $mysqli->prepare("INSERT INTO sale_items (sale_id, item_id, quantity) VALUES (?, ?, ?)");
        foreach ($new_items as $item) {
            $insert_item_stmt->bind_param("iii", $sale_id, $item['item_id'], $item['quantity']);
            $insert_item_stmt->execute();
        }
        $insert_item_stmt->close();

        // 7. Update the points_ledger_rep and points_ledger_group_points tables
        // Update points if agency_id exists in sales table (regardless of user role)
        if ($sale_agency_id) {
            // Update points_ledger_rep - delete old records and insert new ones
            $delete_rep_stmt = $mysqli->prepare("DELETE FROM points_ledger_rep WHERE sale_id = ?");
            $delete_rep_stmt->bind_param("i", $sale_id);
            $delete_rep_stmt->execute();
            $delete_rep_stmt->close();

            $delete_group_stmt = $mysqli->prepare("DELETE FROM points_ledger_group_points WHERE sale_id = ?");
            $delete_group_stmt->bind_param("i", $sale_id);
            $delete_group_stmt->execute();
            $delete_group_stmt->close();

            // Insert new records per item - each item gets its own record
            foreach ($new_items as $item) {
                $item_id = $item['item_id'];
                $quantity = $item['quantity'];
                if (isset($items_data[$item_id])) {
                    // Calculate points per item: rep_points and representative_points
                    $item_points_rep = (int) ($quantity * $items_data[$item_id]['rep_points']);
                    $item_points_representative = (int) ($quantity * $items_data[$item_id]['representative_points']);

                    // Insert into points_ledger_rep (rep_points for this item)
                    if ($item_points_rep > 0) {
                        $insert_rep_stmt = $mysqli->prepare("
                            INSERT INTO points_ledger_rep 
                                (sale_id, rep_user_id, agency_id, sale_date, points, redeemed) 
                            VALUES (?, ?, ?, ?, ?, 0)
                        ");
                        $insert_rep_stmt->bind_param("iiisi", $sale_id, $rep_user_id, $sale_agency_id, $new_sale_date, $item_points_rep);
                        $insert_rep_stmt->execute();
                        $insert_rep_stmt->close();
                    }

                    // Insert into points_ledger_group_points (representative_points for this item)
                    // Use representative_id from agencies table, not rep_user_id
                    if ($item_points_representative > 0 && $representative_id) {
                        $insert_group_stmt = $mysqli->prepare("
                            INSERT INTO points_ledger_group_points 
                                (sale_id, representative_id, agency_id, sale_date, points, redeemed) 
                            VALUES (?, ?, ?, ?, ?, 0)
                        ");
                        $insert_group_stmt->bind_param("iiisi", $sale_id, $representative_id, $sale_agency_id, $new_sale_date, $item_points_representative);
                        $insert_group_stmt->execute();
                        $insert_group_stmt->close();
                    }
                }
            }
        }

        // 8. Update the main sales table to approve the edit
        $update_sale_stmt = $mysqli->prepare(
            "UPDATE sales SET sale_date = ?, pending_edit_data = NULL, admin_request = 0, admin_approved = 1 
             WHERE id = ?"
        );
        $update_sale_stmt->bind_param("si", $new_sale_date, $sale_id);
        $update_sale_stmt->execute();
        $update_sale_stmt->close();

        // 9. Commit
        $mysqli->commit();
        $message = "Sale #{$sale_id} has been successfully updated.";
        $message_type = 'success';

    } catch (Exception $e) {
        $mysqli->rollback();
        $message = "Failed to approve edit for Sale #{$sale_id}: " . $e->getMessage();
        $message_type = 'error';
    }
}

// --- NEW POST: Reject "Edit" Sale ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_edit_id'])) {
    $sale_id = (int) $_POST['reject_edit_id'];

    // Just clear the request fields. The original sale remains as it was.
    $stmt = $mysqli->prepare(
        "UPDATE sales SET pending_edit_data = NULL, admin_request = 0, admin_approved = 1 
         WHERE id = ? AND admin_request = 2"
    );
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        $message = "Edit request for Sale #{$sale_id} has been rejected.";
        $message_type = 'success';
    } else {
        $message = "Could not find or reject edit for Sale #{$sale_id}.";
        $message_type = 'error';
    }
    $stmt->close();
}


// --- DATA FETCH: Get "Half Sale" requests (admin_request = 1) ---
$pending_sales = [];
$sql = "SELECT 
            s.id, s.sale_date, s.rep_user_id, 
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
        WHERE s.sale_type = 'half' AND s.admin_request = 1 AND s.admin_approved = 0
        GROUP BY s.id
        ORDER BY s.sale_date ASC";

$result = $mysqli->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pending_sales[] = $row;
    }
}

// --- NEW DATA FETCH: Get "Pending Edit" requests (admin_request = 2) ---
$pending_edits = [];
$sql_edits = "SELECT 
                  s.id, s.sale_date, s.rep_user_id, s.pending_edit_data,
                  u_rep.username AS rep_username,
                  a.agency_name,
                  u_main.username AS representative_username
              FROM sales s
              JOIN users u_rep ON s.rep_user_id = u_rep.id
              LEFT JOIN agency_reps ar ON s.rep_user_id = ar.rep_user_id
              LEFT JOIN agencies a ON ar.agency_id = a.id
              LEFT JOIN users u_main ON ar.representative_id = u_main.id
              WHERE s.admin_request = 2 AND s.admin_approved = 0
              GROUP BY s.id
              ORDER BY s.created_at ASC"; // Order by when they were submitted

$result_edits = $mysqli->query($sql_edits);
if ($result_edits) {
    while ($row = $result_edits->fetch_assoc()) {
        $pending_edits[] = $row;
    }
}


// Include appropriate header based on role
if ($user_role === 'admin') {
    include 'admin_header.php';
} else if ($user_role === 'sale_admin') {
    include 'sale_admin/sales_header.php';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approve Sales</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>

    <style>
        /* On screens smaller than 768px (Tailwind's 'md' breakpoint) */
        @media (max-width: 767px) {
            .responsive-table thead {
                display: none;
            }

            .responsive-table tbody tr {
                display: block;
                border-bottom: 2px solid #e5e7eb;
                padding: 1rem 0.5rem;
            }

            .responsive-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.75rem 0.5rem;
                border: none;
                text-align: right;
            }

            .responsive-table tbody td:before {
                content: attr(data-label);
                font-weight: 600;
                text-align: left;
                padding-right: 1rem;
                color: #4b5563;
            }

            .responsive-table .action-cell {
                display: block;
                padding-top: 1rem;
            }

            .responsive-table .action-cell:before {
                display: none;
            }
        }
    </style>
</head>

<body class="bg-gray-50">

    <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 space-y-12">

        <?php if ($message && $message_type === 'success'): ?>
            <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg" role="alert">
                <span class="font-medium">Success!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($message && $message_type === 'error'): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg" role="alert">
                <span class="font-medium">Error!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div>
            <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3 mb-8">
                Approve 'Half' Sale Requests
            </h1>
            <div class="bg-white rounded-lg shadow-md overflow-x-auto">
                <?php if (empty($pending_sales)): ?>
                    <div class="p-10 text-center text-gray-500">
                        <svg data-feather="check-circle" class="w-12 h-12 mx-auto text-green-400 mb-4"
                            stroke-width="1.5"></svg>
                        <h3 class="text-lg font-medium">All Caught Up!</h3>
                        <p class="text-sm">There are no pending 'half' sale requests to approve.</p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200 responsive-table">
                        <thead class="bg-gray-50 hidden md:table-header-group">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sale ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rep Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agency</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agency Rep</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sale Date</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Potential
                                    Points</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 md:divide-y-0">
                            <?php foreach ($pending_sales as $sale): ?>
                                <tr class="block md:table-row">
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
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm font-semibold text-blue-600 text-right block md:table-cell"
                                        data-label="Potential Points">
                                        <?= htmlspecialchars($sale['potential_points']) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-right text-sm font-medium block md:table-cell action-cell"
                                        data-label="Action">
                                        <div class="flex flex-col md:flex-row justify-end gap-2">
                                            <button type="button" onclick="showSaleDetails(<?= (int) $sale['id'] ?>)"
                                                class="inline-flex items-center justify-center rounded-md h-10 bg-blue-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-600 w-full md:w-auto">
                                                <span data-feather="eye" class="w-4 h-4 mr-1.5 hidden md:inline"></span>
                                                View
                                            </button>
                                            <form method="POST" action="approve_sales.php"
                                                onsubmit="return confirm('Are you sure you want to approve this sale and make it FULL?');"
                                                class="inline w-full md:w-auto">
                                                <input type="hidden" name="approve_sale_id" value="<?= (int) $sale['id'] ?>">
                                                <button type="submit" class=" inline-flex items-center justify-center rounded-md h-10
                                                    bg-green-500 px-4 py-2 text-sm font-semibold text-white transition
                                                    hover:bg-green-600 w-full md:w-auto">
                                                    <span data-feather="check" class="w-4 h-4 mr-1.5 hidden md:inline"></span>
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

        <div>
            <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3 mb-8">
                Approve Sale Edit Requests
            </h1>
            <div class="bg-white rounded-lg shadow-md overflow-x-auto">
                <?php if (empty($pending_edits)): ?>
                    <div class="p-10 text-center text-gray-500">
                        <svg data-feather="check-circle" class="w-12 h-12 mx-auto text-green-400 mb-4"
                            stroke-width="1.5"></svg>
                        <h3 class="text-lg font-medium">All Caught Up!</h3>
                        <p class="text-sm">There are no pending sale edits to approve.</p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200 responsive-table">
                        <thead class="bg-gray-50 hidden md:table-header-group">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sale ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rep Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agency</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Original Date
                                </th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 md:divide-y-0">
                            <?php foreach ($pending_edits as $sale): ?>
                                <tr class="block md:table-row bg-yellow-50">
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
                                        data-label="Sale Date">
                                        <?= htmlspecialchars(date("Y-m-d", strtotime($sale['sale_date']))) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-right text-sm font-medium block md:table-cell action-cell"
                                        data-label="Action">
                                        <div class="flex flex-col md:flex-row justify-end gap-2">
                                            <button type="button" onclick="showCompareDetails(<?= (int) $sale['id'] ?>)"
                                                class="inline-flex items-center justify-center rounded-md h-10 bg-blue-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-600 w-full md:w-auto">
                                                <span data-feather="git-pull-request"
                                                    class="w-4 h-4 mr-1.5 hidden md:inline"></span>
                                                Compare
                                            </button>

                                            <form method="POST" action="approve_sales.php"
                                                onsubmit="return confirm('Are you sure you want to REJECT this edit? The sale will revert to its original state.');"
                                                class="inline w-full md:w-auto">
                                                <input type="hidden" name="reject_edit_id" value="<?= (int) $sale['id'] ?>">
                                                <button type="submit" class=" inline-flex items-center justify-center rounded-md h-10
                                                    bg-red-500 px-4 py-2 text-sm font-semibold text-white transition
                                                    hover:bg-red-600 w-full md:w-auto">
                                                    <span data-feather="x" class="w-4 h-4 mr-1.5 hidden md:inline"></span>
                                                    Reject
                                                </button>
                                            </form>

                                            <form method="POST" action="approve_sales.php"
                                                onsubmit="return confirm('Are you sure you want to APPROVE this edit? This will overwrite the original sale data.');"
                                                class="inline w-full md:w-auto">
                                                <input type="hidden" name="approve_edit_id" value="<?= (int) $sale['id'] ?>">
                                                <button type="submit" class=" inline-flex items-center justify-center rounded-md h-10
                                                    bg-green-500 px-4 py-2 text-sm font-semibold text-white transition
                                                    hover:bg-green-600 w-full md:w-auto">
                                                    <span data-feather="check" class="w-4 h-4 mr-1.5 hidden md:inline"></span>
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

    </div>

    <div id="saleDetailModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60 transition-opacity p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl m-4 max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center p-4 border-b flex-shrink-0">
                <h3 class="text-lg font-semibold text-slate-800" id="modalTitle">Sale Details</h3>
                <button onclick="closeSaleDetails()"
                    class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>
            <div class="overflow-y-auto" id="modalContent">Loading...</div>
        </div>
    </div>

    <div id="compareDetailModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60 transition-opacity p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl m-4 max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center p-4 border-b flex-shrink-0">
                <h3 class="text-lg font-semibold text-slate-800">Compare Sale Edit</h3>
                <button onclick="closeCompareDetails()"
                    class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>
            <div class="overflow-y-auto" id="compareModalContent">Loading...</div>
        </div>
    </div>

    <script>
        feather.replace();

        // --- Logic for Modal 1 (Half Sale) ---
        function showSaleDetails(saleId) {
            const modal = document.getElementById('saleDetailModal');
            const content = document.getElementById('modalContent');
            content.innerHTML = '<div class="p-6">Loading...</div>';
            modal.classList.remove('hidden');

            fetch(`approve_sales.php?details=${saleId}`)
                .then(res => res.text())
                .then(html => { content.innerHTML = `<div class="p-4 sm:p-6">${html}</div>`; })
                .catch(() => { content.innerHTML = '<div class="text-red-500 p-6">Failed to load details.</div>'; });
        }
        function closeSaleDetails() {
            document.getElementById('saleDetailModal').classList.add('hidden');
        }

        // --- NEW Logic for Modal 2 (Compare Edit) ---
        function showCompareDetails(saleId) {
            const modal = document.getElementById('compareDetailModal');
            const content = document.getElementById('compareModalContent');
            content.innerHTML = '<div class="p-6">Loading...</div>';
            modal.classList.remove('hidden');

            fetch(`approve_sales.php?compare_id=${saleId}`)
                .then(res => res.text())
                .then(html => { content.innerHTML = html; })
                .catch(() => { content.innerHTML = '<div class="text-red-500 p-6">Failed to load comparison.</div>'; });
        }
        function closeCompareDetails() {
            document.getElementById('compareDetailModal').classList.add('hidden');
        }
    </script>
</body>

</html>