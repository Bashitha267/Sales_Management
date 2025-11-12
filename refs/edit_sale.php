<?php
session_start();
include '../config.php';

// --- Determine user role and include header ---
$user_role = $_SESSION['role'] ?? null;
if ($user_role === 'representative') {
    include '../leader/leader_header.php';
    $dashboard_link = '../leader/leader_dashboard.php';
} elseif ($user_role === 'rep') {
    include 'refs_header.php';
    $dashboard_link = 'ref_dashboard.php';
} else {
    header('Location: /ref/login.php');
    exit;
}

$user_id = $_SESSION['user_id'] ?? 0;
$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$current_sale_data = [
    'sale_date' => date('Y-m-d'),
    'items' => []
];

if ($sale_id <= 0) {
    die("Invalid Sale ID.");
}

// --- Fetch the existing sale data ---
$stmt = $mysqli->prepare("SELECT s.sale_date, s.admin_request, s.admin_approved 
                          FROM sales s 
                          WHERE s.id = ? AND s.rep_user_id = ?");
$stmt->bind_param("ii", $sale_id, $user_id);
$stmt->execute();
$sale_result = $stmt->get_result();

if ($sale_result->num_rows === 0) {
    die("Sale not found or you do not have permission to edit it.");
}
$sale_row = $sale_result->fetch_assoc();
$current_sale_data['sale_date'] = $sale_row['sale_date'];
$stmt->close();

// Check if sale is redeemed - check both points_ledger_rep and points_ledger_group_points
$redeemed = 0;
$stmt = $mysqli->prepare("SELECT MAX(redeemed) AS redeemed FROM points_ledger_rep WHERE sale_id = ?");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$rep_result = $stmt->get_result();
if ($rep_row = $rep_result->fetch_assoc()) {
    $redeemed = max($redeemed, (int)($rep_row['redeemed'] ?? 0));
}
$stmt->close();

$stmt = $mysqli->prepare("SELECT MAX(redeemed) AS redeemed FROM points_ledger_group_points WHERE sale_id = ?");
$stmt->bind_param("i", $sale_id);
$stmt->execute();
$group_result = $stmt->get_result();
if ($group_row = $group_result->fetch_assoc()) {
    $redeemed = max($redeemed, (int)($group_row['redeemed'] ?? 0));
}
$stmt->close();

// Check if sale is redeemed
if ($redeemed === 1) {
    die("This sale has been redeemed and cannot be edited.");
}

// Check if sale is locked for editing
if ($sale_row['admin_request'] === 2 && $sale_row['admin_approved'] === 0) {
    die("This sale is currently pending an edit approval from an admin and cannot be edited again.");
}

// Fetch existing sale items
$item_stmt = $mysqli->prepare("SELECT item_id, quantity FROM sale_items WHERE sale_id = ?");
$item_stmt->bind_param("i", $sale_id);
$item_stmt->execute();
$items_result = $item_stmt->get_result();
while ($row = $items_result->fetch_assoc()) {
    $current_sale_data['items'][] = [
        'id' => $row['item_id'],
        'qty' => $row['quantity']
    ];
}
$item_stmt->close();


// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_sale_date = $_POST['sale_date'] ?? date('Y-m-d');
    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    
    $pending_items = [];
    if (empty($new_sale_date)) {
        $errors[] = "Sale date is required.";
    }

    if (empty($item_ids) || count($item_ids) === 0) {
        $errors[] = "You must add at least one item to the sale.";
    }

    for ($i = 0; $i < count($item_ids); $i++) {
        $item_id = (int)$item_ids[$i];
        $quantity = (int)$quantities[$i];

        if ($item_id > 0 && $quantity > 0) {
            $pending_items[] = [
                'item_id' => $item_id,
                'quantity' => $quantity
            ];
        }
    }

    if (empty($pending_items)) {
        $errors[] = "No valid items or quantities were provided.";
    }

    if (empty($errors)) {
        // --- This is the new logic ---
        // 1. Create the pending_edit_data JSON
        $pending_data = [
            'sale_date' => $new_sale_date,
            'items' => $pending_items
        ];
        $pending_json = json_encode($pending_data);

        // 2. Update the sales table to flag it for admin approval
        $update_stmt = $mysqli->prepare(
            "UPDATE sales 
             SET pending_edit_data = ?, admin_request = 2, admin_approved = 0 
             WHERE id = ? AND rep_user_id = ?"
        );
        
        if (!$update_stmt) {
             $errors[] = "Database error: Could not prepare statement. " . $mysqli->error;
        } else {
            $update_stmt->bind_param("sii", $pending_json, $sale_id, $user_id);
            
            if ($update_stmt->execute()) {
                // Success! Redirect back to view_sales
                $_SESSION['flash_message'] = "Sale #$sale_id has been submitted for edit approval.";
                header("Location: view_sales.php");
                exit;
            } else {
                $errors[] = "Failed to submit edit request: " . $update_stmt->error;
            }
            $update_stmt->close();
        }
    }
}


// --- Fetch all items for the dropdown ---
$items = [];
$item_list_result = $mysqli->query("SELECT id, item_code, item_name FROM items ORDER BY item_name");
while ($row = $item_list_result->fetch_assoc()) {
    $items[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Sale</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>
<body class="bg-slate-100">
    <div class="max-w-4xl mx-auto py-10 px-4 sm:px-6 lg:px-8">
        <div class="bg-white rounded-xl shadow-lg p-6 sm:p-8">
            <h1 class="text-2xl font-bold text-slate-900 mb-6">Edit Sale #<?= $sale_id ?></h1>

            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-200 text-red-800 p-4 rounded-lg mb-6">
                    <p class="font-bold">Please fix the following errors:</p>
                    <ul class="list-disc list-inside mt-2">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="edit_sale.php?id=<?= $sale_id ?>">
                <div class="space-y-6">
                    <div>
                        <label for="sale_date" class="block text-sm font-medium text-slate-700 mb-1">Sale Date *</label>
                        <input type="date" name="sale_date" id="sale_date"
                               value="<?= htmlspecialchars($current_sale_data['sale_date']) ?>"
                               class="w-full sm:w-1/2 border border-slate-300 rounded-md p-2" required>
                    </div>

                    <div class="space-y-4" id="items-container">
                        <h2 class="text-lg font-semibold text-slate-800 border-b pb-2">Items</h2>
                        
                        <?php if (empty($current_sale_data['items'])): ?>
                            <div class="item-row flex flex-col sm:flex-row items-center gap-4 p-4 bg-slate-50 rounded-lg">
                                <div class="w-full sm:flex-1">
                                    <label class="block text-xs font-medium text-slate-600">Item</label>
                                    <select name="item_id[]" class="item-select mt-1 w-full border border-slate-300 rounded-md p-2" required>
                                        <option value="">Select an item...</option>
                                        <?php foreach ($items as $item): ?>
                                            <option value="<?= $item['id'] ?>"><?= htmlspecialchars($item['item_name'] . " (" . $item['item_code'] . ")") ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="w-full sm:w-1/4">
                                    <label class="block text-xs font-medium text-slate-600">Quantity</label>
                                    <input type="number" name="quantity[]" class="item-quantity mt-1 w-full border border-slate-300 rounded-md p-2" min="1" value="1" required>
                                </div>
                                <div class="w-full sm:w-auto pt-4 sm:pt-0">
                                    <button type="button" class="remove-item-btn w-full bg-red-500 text-white p-2 rounded-md hover:bg-red-600">Remove</button>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($current_sale_data['items'] as $sale_item): ?>
                                <div class="item-row flex flex-col sm:flex-row items-center gap-4 p-4 bg-slate-50 rounded-lg">
                                    <div class="w-full sm:flex-1">
                                        <label class="block text-xs font-medium text-slate-600">Item</label>
                                        <select name="item_id[]" class="item-select mt-1 w-full border border-slate-300 rounded-md p-2" required>
                                            <option value="">Select an item...</option>
                                            <?php foreach ($items as $item): ?>
                                                <option value="<?= $item['id'] ?>" <?= ($item['id'] == $sale_item['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($item['item_name'] . " (" . $item['item_code'] . ")") ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="w-full sm:w-1/4">
                                        <label class="block text-xs font-medium text-slate-600">Quantity</label>
                                        <input type="number" name="quantity[]" class="item-quantity mt-1 w-full border border-slate-300 rounded-md p-2" min="1" value="<?= htmlspecialchars($sale_item['qty']) ?>" required>
                                    </div>
                                    <div class="w-full sm:w-auto pt-4 sm:pt-0">
                                        <button type="button" class="remove-item-btn w-full bg-red-500 text-white p-2 rounded-md hover:bg-red-600">Remove</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div>
                        <button type="button" id="add-item-btn" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700">
                            + Add Another Item
                        </button>
                    </div>

                    <div class="border-t pt-6 flex items-center justify-end gap-4">
                        <a href="view_sales.php" class="text-slate-600 hover:underline">Cancel</a>
                        <button type="submit" class="bg-blue-600 text-white font-semibold px-6 py-2 rounded-lg hover:bg-blue-700">
                            Submit for Approval
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <template id="item-row-template">
        <div class="item-row flex flex-col sm:flex-row items-center gap-4 p-4 bg-slate-50 rounded-lg">
            <div class="w-full sm:flex-1">
                <label class="block text-xs font-medium text-slate-600">Item</label>
                <select name="item_id[]" class="item-select mt-1 w-full border border-slate-300 rounded-md p-2" required>
                    <option value="">Select an item...</option>
                    <?php foreach ($items as $item): ?>
                        <option value="<?= $item['id'] ?>"><?= htmlspecialchars($item['item_name'] . " (" . $item['item_code'] . ")") ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="w-full sm:w-1/4">
                <label class="block text-xs font-medium text-slate-600">Quantity</label>
                <input type="number" name="quantity[]" class="item-quantity mt-1 w-full border border-slate-300 rounded-md p-2" min="1" value="1" required>
            </div>
            <div class="w-full sm:w-auto pt-4 sm:pt-0">
                <button type="button" class="remove-item-btn w-full bg-red-500 text-white p-2 rounded-md hover:bg-red-600">Remove</button>
            </div>
        </div>
    </template>

    <script>
        document.getElementById('add-item-btn').addEventListener('click', function () {
            const template = document.getElementById('item-row-template');
            const clone = template.content.cloneNode(true);
            document.getElementById('items-container').appendChild(clone);
        });

        document.getElementById('items-container').addEventListener('click', function (e) {
            if (e.target && e.target.classList.contains('remove-item-btn')) {
                const row = e.target.closest('.item-row');
                if (document.querySelectorAll('.item-row').length > 1) {
                    row.remove();
                } else {
                    alert('You must have at least one item in a sale.');
                }
            }
        });
    </script>
</body>
</html>