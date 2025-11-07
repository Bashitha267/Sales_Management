<?php
require_once '../auth.php';
requireLogin();
require_once '../config.php';

// Handle Cancel Sale (must be before any HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_sale'])) {
    $sale_id_to_cancel = (int) ($_POST['sale_id'] ?? 0);

    if ($sale_id_to_cancel > 0) {
        $mysqli->begin_transaction();
        try {
            // Delete child records first
            $stmt = $mysqli->prepare("DELETE FROM sale_details WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $stmt->close();

            // Delete parent record
            $stmt = $mysqli->prepare("DELETE FROM sales_log WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
        } catch (mysqli_sql_exception $exception) {
            $mysqli->rollback();
        }
    }
    header('Location: ref_dashboard.php');
    exit;
}

// Handle form submission for creating a sale
$ref_id = $_SESSION['user_id'] ?? null;
$errors = [];
$sale_id = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sale'])) {
    $sale_date = trim($_POST['sale_date']) ?: null;
    $team_id = (int) ($_POST['team_id'] ?? 0);

    if (!$sale_date) {
        $errors[] = "Sale date is required.";
    }
    if (!$ref_id) {
        $errors[] = "Reference user not found.";
    }
    if ($team_id <= 0) {
        $errors[] = "Please select a valid team.";
    }

    if (empty($errors)) {
        $stmt = $mysqli->prepare("INSERT INTO sales_log (ref_id, team_id, sale_date) VALUES (?, ?, ?)");
        $stmt->bind_param('iis', $ref_id, $team_id, $sale_date);
        if ($stmt->execute()) {
            $sale_id = $stmt->insert_id;
            header("Location: add_sale.php?sale_id={$sale_id}");
            exit;
        } else {
            $errors[] = "Failed to create sale. Please try again.";
        }
        $stmt->close();
    }
}

// Handle adding item to sale (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $sale_id = (int) ($_POST['sale_id'] ?? 0);
    $item_code = trim($_POST['item_code'] ?? '');
    $qty = (int) ($_POST['qty'] ?? 1);
    if (!$sale_id || !$item_code || $qty < 1) {
        $resp = ["success" => false, "error" => "All fields required"];
    } else {
        $stmt = $mysqli->prepare("SELECT * FROM items WHERE item_code=?");
        $stmt->bind_param('s', $item_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows < 1) {
            $resp = ["success" => false, "error" => "Item code not found."];
        } else {
            $stmt2 = $mysqli->prepare("INSERT INTO sale_details (sale_id, item_code, qty) VALUES (?, ?, ?)");
            $stmt2->bind_param('isi', $sale_id, $item_code, $qty);
            if ($stmt2->execute()) {
                $resp = ["success" => true];
            } else {
                $resp = ["success" => false, "error" => "Failed to add item."];
            }
            $stmt2->close();
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode($resp);
    exit;
}

// AJAX endpoint: search items
if (isset($_GET['ajax']) && $_GET['ajax'] === 'item_search') {
    $query = trim($_GET['q'] ?? '');
    $items = [];
    if ($query !== '') {
        $like = '%' . $query . '%';
        $stmt = $mysqli->prepare("SELECT * FROM items WHERE item_code LIKE ? OR item_name LIKE ? ORDER BY item_name");
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode($items);
    exit;
}

// AJAX endpoint: list items in sale
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sale_items' && isset($_GET['sale_id'])) {
    $sale_id = (int) $_GET['sale_id'];
    $items = [];
    $stmt = $mysqli->prepare("SELECT d.*, i.item_name, i.price FROM sale_details d JOIN items i ON d.item_code = i.item_code WHERE d.sale_id=?");
    $stmt->bind_param('i', $sale_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    header('Content-Type: application/json');
    echo json_encode($items);
    exit;
}

// Existing sale check
$existing_sale_id = (int) ($_GET['sale_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?= $existing_sale_id ? "Manage Sale Items" : "Create New Sale" ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <?php include 'refs_header.php' ?>
    <div class="max-w-xl mx-auto mt-10 bg-white rounded-xl shadow p-6 sm:p-8">

        <?php if ($existing_sale_id == 0): ?>
            <h2 class="text-2xl font-bold mb-6 text-blue-700 flex items-center gap-2">
                <svg class="w-7 h-7 text-blue-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 4v16m8-8H4" />
                </svg>
                Create New Sale
            </h2>
            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 text-red-700 px-4 py-3 rounded mb-4">
                    <ul class="list-disc list-inside text-sm">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-6">
                <div>
                    <label class="block font-medium text-slate-700 mb-1">Sale Date<span
                            class="text-red-500">*</span></label>
                    <input type="datetime-local" name="sale_date" required value="<?= date('Y-m-d\TH:i') ?>"
                        class="w-full border rounded px-4 py-2 focus:outline-none focus:ring transition">
                </div>

                <div>
                    <label class="block font-medium text-slate-700 mb-1">Select Team<span
                            class="text-red-500">*</span></label>
                    <select name="team_id" required
                        class="w-full border rounded px-4 py-2 focus:outline-none focus:ring transition">
                        <option value="">-- Choose Team --</option>
                        <?php
                        // Select teams where this user (ref_id) is a member
                        $teamRes = $mysqli->query("SELECT DISTINCT team_id FROM team_members WHERE member_id = $ref_id ORDER BY team_id");

                        if ($teamRes && $teamRes->num_rows > 0):
                            while ($t = $teamRes->fetch_assoc()):
                                ?>
                                <option value="<?= htmlspecialchars($t['team_id']) ?>">
                                    <?= htmlspecialchars($t['team_id']) ?>
                                </option>
                                <?php
                            endwhile;
                        else:
                            ?>
                            <option value="">You are not in a team</option>
                        <?php endif; ?>
                    </select>
                </div>

                <button type="submit" name="create_sale"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded transition">
                    Start Sale
                </button>
            </form>

        <?php else: ?>
            <h2 class="text-2xl font-bold mb-6 text-blue-700 flex items-center gap-2">
                Add items to sale
                <span class="text-lg font-mono text-slate-500">(Sale #<?= $existing_sale_id ?>)</span>
            </h2>

            <div>
                <form id="addItemForm" class="flex flex-col sm:flex-row gap-3 mb-6">
                    <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                    <div class="flex-1">
                        <label class="block text-sm font-medium text-slate-600 mb-1" for="item_code">Item Code/Name</label>
                        <input type="text" id="item_code_search" name="item_code" autocomplete="off" required
                            class="w-full border px-3 py-2 rounded focus:ring focus:ring-blue-200"
                            placeholder="Enter item code or search...">
                        <div id="itemSuggestions"
                            class="bg-white border rounded shadow-lg absolute z-40 mt-1 max-w-md hidden"></div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600 mb-1" for="qty">Qty</label>
                        <input type="number" min="1" value="1" name="qty" id="qty" class="w-20 border px-3 py-2 rounded"
                            required>
                    </div>
                    <div class="self-end">
                        <button type="submit"
                            class="bg-blue-600 text-white px-4 py-2 rounded font-semibold hover:bg-blue-700 h-full">
                            Add Item
                        </button>
                    </div>
                </form>

                <div>
                    <h3 class="text-lg font-bold mb-2 text-slate-700">Items in Sale</h3>
                    <div id="saleItemsContainer" class="divide-y divide-slate-200 min-h-[50px]">
                        <div class="text-gray-500 text-sm px-2 py-4" id="items-loading">No items yet. Add some.</div>
                    </div>
                </div>

                <hr class="my-6 border-slate-200">
                <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <form method="POST"
                        onsubmit="return confirm('Are you sure you want to cancel this sale? All added items AND this sale record will be permanently deleted.');">
                        <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                        <button type="submit" name="cancel_sale"
                            class="w-full sm:w-auto px-6 py-2 text-red-700 bg-red-100 rounded-lg font-medium hover:bg-red-200 transition">
                            Cancel Sale
                        </button>
                    </form>

                    <a href="ref_dashboard.php"
                        class="w-full sm:w-auto px-6 py-2 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition text-center">
                        Save &amp; Close
                    </a>
                </div>
            </div>
            <div class="mt-4 text-center">
                <a href="view_sales.php" class="text-slate-500 hover:underline text-sm">&larr; Or go back to all sales</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($existing_sale_id > 0): ?>
        <script>
            // --- Item Search Autocomplete ---
            (function () {
                const itemInput = document.getElementById('item_code_search');
                const suggestions = document.getElementById('itemSuggestions');
                let debounceTimer;

                itemInput.addEventListener('input', function () {
                    clearTimeout(debounceTimer);
                    const val = this.value.trim();
                    if (val.length < 1) {
                        suggestions.classList.add('hidden');
                        suggestions.innerHTML = '';
                        return;
                    }
                    debounceTimer = setTimeout(() => searchItem(val), 200);
                });

                function searchItem(query) {
                    fetch('add_sale.php?ajax=item_search&q=' + encodeURIComponent(query))
                        .then(res => res.json())
                        .then(items => {
                            if (!items.length) {
                                suggestions.innerHTML = '<div class="px-4 py-2 text-gray-500">No matches found.</div>';
                                suggestions.classList.remove('hidden');
                                return;
                            }
                            suggestions.innerHTML = items.map(it =>
                                `<div class="px-4 py-2 hover:bg-blue-100 cursor-pointer" data-code="${it.item_code}">${it.item_code} <span class="text-gray-400"> - ${it.item_name}</span></div>`
                            ).join('');
                            suggestions.classList.remove('hidden');
                        });
                }

                suggestions.addEventListener('click', function (e) {
                    const el = e.target.closest('[data-code]');
                    if (el) {
                        itemInput.value = el.getAttribute('data-code');
                        suggestions.classList.add('hidden');
                    }
                });

                itemInput.addEventListener('blur', function () {
                    setTimeout(() => { suggestions.classList.add('hidden'); }, 200);
                });
            })();

            // --- Add Item to Sale ---
            (function () {
                const addItemForm = document.getElementById('addItemForm');
                const saleItemsContainer = document.getElementById('saleItemsContainer');
                const sale_id = <?= $existing_sale_id ?>;

                function fetchItems() {
                    saleItemsContainer.innerHTML = `<div class="text-gray-500 text-sm px-2 py-2">Loading...</div>`;
                    fetch('add_sale.php?ajax=sale_items&sale_id=' + sale_id)
                        .then(res => res.json())
                        .then(items => {
                            if (!items.length) {
                                saleItemsContainer.innerHTML = `<div class="text-gray-500 text-sm px-2 py-4">No items yet. Add some.</div>`;
                                return;
                            }
                            saleItemsContainer.innerHTML = items.map(it =>
                                `<div class="flex items-center justify-between px-2 py-2">
                                <div>
                                    <span class="font-mono text-sm font-semibold text-blue-800">${it.item_code}</span>
                                    <span class="text-gray-600">- ${it.item_name}</span>
                                    <span class="ml-2 text-sm bg-blue-50 rounded px-2 py-0.5 font-medium text-blue-700">Qty: ${it.qty}</span>
                                </div>
                                <div class="text-sm text-gray-800">Rs ${parseFloat(it.price).toLocaleString(undefined, { minimumFractionDigits: 2 })}</div>
                            </div>`
                            ).join('');
                        });
                }

                fetchItems();

                addItemForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    const fd = new FormData(addItemForm);
                    fd.append('add_item', '1');
                    fetch('add_sale.php', {
                        method: 'POST',
                        body: fd
                    }).then(res => res.json())
                        .then(resp => {
                            if (resp.success) {
                                addItemForm.reset();
                                document.getElementById('item_code_search').focus();
                                fetchItems();
                            } else {
                                alert(resp.error || "Unable to add item.");
                            }
                        });
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>
<?php
$mysqli->close();
?>