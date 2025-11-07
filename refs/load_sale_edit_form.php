<?php
session_start();
include '../config.php';

// --- Handle AJAX Item Search ---
// This part is called by the new script in view_sales.php
if (isset($_GET['action']) && $_GET['action'] === 'search_items') {
    header('Content-Type: application/json');
    $term = $_GET['term'] ?? '';

    if (strlen($term) < 2) {
        echo json_encode([]);
        exit;
    }

    $search_term = "%" . $term . "%";
    $sql = "SELECT item_code, item_name 
            FROM items 
            WHERE item_name LIKE ? OR item_code LIKE ?
            LIMIT 10";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ss", $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'code' => $row['item_code'],
            'name' => $row['item_name']
        ];
    }
    echo json_encode($items);
    exit; // Exit script after sending JSON
}
// --- End AJAX Handler ---


// Check if user is logged in and sale_id is provided
if (!isset($_SESSION['user_id']) || !isset($_GET['sale_id'])) {
    die("Authentication error.");
}

$sale_id = (int) $_GET['sale_id'];
$ref_id = (int) $_SESSION['user_id'];

// Handle form submissions (Add or Remove Item)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Security: Ensure user owns this sale
    $sql_check = "SELECT sale_id FROM sales_log WHERE sale_id = ? AND ref_id = ?";
    $stmt_check = $mysqli->prepare($sql_check);
    $stmt_check->bind_param("ii", $sale_id, $ref_id);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows == 0) {
        die("Permission denied.");
    }

    // --- Add Item ---
    if (isset($_POST['add_item'])) {
        $item_code = $_POST['item_code'];
        $qty = (int) $_POST['qty'];
        if (!empty($item_code) && $qty > 0) {
            $stmt_add = $mysqli->prepare("
                INSERT INTO sale_details (sale_id, item_code, qty) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE qty = qty + ?
            ");
            $stmt_add->bind_param("isii", $sale_id, $item_code, $qty, $qty);
            $stmt_add->execute();
        }
    }

    // --- Remove Item ---
    if (isset($_POST['remove_item'])) {
        $item_code_to_remove = $_POST['item_code'];
        $stmt_remove = $mysqli->prepare("DELETE FROM sale_details WHERE sale_id = ? AND item_code = ?");
        $stmt_remove->bind_param("is", $sale_id, $item_code_to_remove);
        $stmt_remove->execute();
    }

    // Refresh the form data
    header("Location: /ref/refs/view_sales.php");
    exit;
}


// --- Load Current Items (GET Request) ---
$sql = "SELECT sd.item_code, sd.qty, i.item_name
        FROM sale_details sd
        LEFT JOIN items i ON sd.item_code = i.item_code
        JOIN sales_log s ON sd.sale_id = s.sale_id
        WHERE sd.sale_id = ? AND s.ref_id = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ii", $sale_id, $ref_id);
$stmt->execute();
$result = $stmt->get_result();
?>

<div class="space-y-4">
    <h4 class="font-semibold text-slate-700">Current Items</h4>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-3 py-2 text-left">Item</th>
                    <th class="px-3 py-2 text-left">Qty</th>
                    <th class="px-3 py-2 text-left">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="px-3 py-2"><?= htmlspecialchars($row['item_name'] ?? $row['item_code']) ?></td>
                            <td class="px-3 py-2"><?= (int) $row['qty'] ?></td>
                            <td class="px-3 py-2">
                                <form method="POST" action="load_sale_edit_form.php?sale_id=<?= $sale_id ?>" class="inline">
                                    <input type="hidden" name="item_code" value="<?= htmlspecialchars($row['item_code']) ?>">
                                    <button type="submit" name="remove_item"
                                        class="text-red-600 hover:underline">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" class="p-4 text-center text-slate-500">No items in this sale.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <hr>

    <h4 class="font-semibold text-slate-700">Add New Item</h4>
    <form method="POST" action="load_sale_edit_form.php?sale_id=<?= $sale_id ?>" class="space-y-3" id="add-item-form">

        <div class="relative flex-grow">
            <label for="item_search" class="block text-xs font-medium text-slate-600">Search by Code or Name</label>
            <input type="text" id="item_search" class="mt-1 block w-full rounded border-slate-300 shadow-sm"
                autocomplete="off" placeholder="Type item code or name...">

            <div id="search_results"
                class="absolute z-10 w-full bg-white border border-slate-300 rounded-md shadow-lg mt-1 max-h-60 overflow-y-auto hidden">
            </div>
        </div>

        <input type="hidden" name="item_code" id="selected_item_code">

        <div id="selected_item_display" class="hidden p-2 bg-blue-50 border border-blue-200 rounded-md">
            <span class="font-medium text-blue-800">Selected: <span id="selected_item_name"></span></span>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
            <div class="w-full sm:w-24">
                <label for="qty" class="block text-xs font-medium text-slate-600">Quantity</label>
                <input type="number" name="qty" class="mt-1 block w-full rounded border-slate-300 shadow-sm" min="1"
                    value="1" required>
            </div>
            <button type="submit" name="add_item" id="add_item_button"
                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 font-medium disabled:opacity-50"
                disabled>
                Add Item</button>
        </div>
    </form>
</div>

<div class="mt-6 pt-4 border-t text-right">
    <button onclick="closeEditModal()"
        class="bg-slate-500 text-white px-5 py-2 rounded hover:bg-slate-600 font-medium">Done</button>
</div>