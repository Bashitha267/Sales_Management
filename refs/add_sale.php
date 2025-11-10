<?php
// 1. START SESSION AND CHECK AUTH FIRST
require_once '../config.php';
require_once '../auth.php'; // For the logout() function

// 2. Check if a user is logged in at all
if (!isset($_SESSION['user_id'])) {
    header('Location: /ref/login.php'); // Use the correct login path
    exit;
}

// 3. Check if the user has the CORRECT role (either 'rep' or 'representative')
$user_role = $_SESSION['role'] ?? null;
if ($user_role !== 'rep' && $user_role !== 'representative') {
    // If they are 'admin' or something else, kick them out
    header('Location: /ref/login.php');
    exit;
}
// --- END NEW AUTH BLOCK ---

$ref_id = $_SESSION['user_id']; // We now know this is set
$errors = [];
$sale_id = null;

/**
 * Ensure sale belongs to rep
 */
function validate_sale_owner(mysqli $mysqli, int $sale_id, int $rep_user_id): bool
{
    $stmt = $mysqli->prepare("SELECT 1 FROM sales WHERE id = ? AND rep_user_id = ?");
    $stmt->bind_param('ii', $sale_id, $rep_user_id);
    $stmt->execute();
    $owns = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $owns;
}

/* 🟢 "Save & Close" */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_and_close'])) {
    $sale_id_to_close = (int) ($_POST['sale_id'] ?? 0);

    if ($sale_id_to_close > 0 && validate_sale_owner($mysqli, $sale_id_to_close, $ref_id)) {

        $mysqli->begin_transaction();
        try {
            // (All your transaction logic is correct and universal)
            $stmt = $mysqli->prepare("SELECT 1 FROM points_ledger WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id_to_close);
            $stmt->execute();
            $already_logged = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$already_logged) {
                $stmt = $mysqli->prepare("SELECT representative_id, agency_id FROM agency_reps WHERE rep_user_id = ?");
                $stmt->bind_param('i', $ref_id);
                $stmt->execute();
                $repInfo = $stmt->get_result()->fetch_assoc();
                $representative_id = $repInfo['representative_id'] ?? null;
                $agency_id = $repInfo['agency_id'] ?? null;
                $stmt->close();

                $stmt = $mysqli->prepare("SELECT sale_date FROM sales WHERE id = ?");
                $stmt->bind_param('i', $sale_id_to_close);
                $stmt->execute();
                $sale_date = $stmt->get_result()->fetch_object()->sale_date;
                $stmt->close();

                // 4. Calculate total points for the sale
                // This logic is universal and CORRECT for both roles
                $stmt = $mysqli->prepare("
                    SELECT 
                        SUM(si.quantity * i.rep_points) AS total_rep, 
                        SUM(si.quantity * i.representative_points) AS total_rep_for_rep
                    FROM sale_items si 
                    JOIN items i ON si.item_id = i.id 
                    WHERE si.sale_id = ?
                ");
                $stmt->bind_param('i', $sale_id_to_close);
                $stmt->execute();
                $points = $stmt->get_result()->fetch_assoc();

                // This is the personal pay for the user (rep or representative)
                $points_rep = (int) $points['total_rep'];

                // This is the bonus pool contribution
                $points_representative = (int) $points['total_rep_for_rep'];
                $stmt->close();

                // 5. Insert into points_ledger
                $stmt = $mysqli->prepare("
                    INSERT INTO points_ledger 
                        (sale_id, rep_user_id, representative_id, agency_id, sale_date, points_rep, points_representative) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param('iiiisii', $sale_id_to_close, $ref_id, $representative_id, $agency_id, $sale_date, $points_rep, $points_representative);
                $stmt->execute();
                $stmt->close();

                // 6. Update agency_points summary table
                if ($agency_id) {
                    $stmt = $mysqli->prepare("
                        INSERT INTO agency_points (agency_id, total_rep_points, total_representative_points)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            total_rep_points = total_rep_points + VALUES(total_rep_points),
                            total_representative_points = total_representative_points + VALUES(total_representative_points)
                    ");
                    $stmt->bind_param('iii', $agency_id, $points_rep, $points_representative);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            $mysqli->commit();
        } catch (Exception $e) {
            $mysqli->rollback();
        }
    }

    // Redirect to a new, fresh sale page
    header('Location: add_sale.php');
    exit;
}


/* 🟡 Delete sale */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_sale'])) {
    $sale_id_to_cancel = (int) ($_POST['sale_id'] ?? 0);

    if ($sale_id_to_cancel > 0 && validate_sale_owner($mysqli, $sale_id_to_cancel, $ref_id)) {
        $mysqli->begin_transaction();
        try {
            // (All your cancel logic is correct and universal)
            $stmt = $mysqli->prepare("SELECT agency_id, points_rep, points_representative FROM points_ledger WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $ledgerEntry = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM sales WHERE id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $stmt->close();

            if ($ledgerEntry && $ledgerEntry['agency_id']) {
                $stmt = $mysqli->prepare("
                    UPDATE agency_points 
                    SET 
                        total_rep_points = total_rep_points - ?, 
                        total_representative_points = total_representative_points - ?
                    WHERE agency_id = ?
                ");
                $stmt->bind_param('iii', $ledgerEntry['points_rep'], $ledgerEntry['points_representative'], $ledgerEntry['agency_id']);
                $stmt->execute();
                $stmt->close();
            }
            $mysqli->commit();
        } catch (Exception $e) {
            $mysqli->rollback();
        }
    }

    // Cancel still goes back to the correct dashboard
    if ($user_role === 'representative') {
        header('Location: ../leader/leader_dashboard.php');
    } else {
        header('Location: ref_dashboard.php');
    }
    exit;
}

/* ✅ Create sale */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sale'])) {
    $sale_date_input = trim($_POST['sale_date'] ?? '');
    $sale_type = ($_POST['sale_type'] ?? 'full');
    if (!in_array($sale_type, ['full', 'half']))
        $sale_type = 'full';
    $saleDateTime = DateTime::createFromFormat('Y-m-d\TH:i', $sale_date_input);

    if (!$saleDateTime) {
        $errors[] = "Sale date & time required";
    }

    if (empty($errors)) {
        $sale_date = $saleDateTime->format('Y-m-d');
        $created_at = $saleDateTime->format('Y-m-d H:i:s');

        $stmt = $mysqli->prepare("INSERT INTO sales (rep_user_id, sale_date, sale_type, created_at, admin_approved) VALUES (?, ?, ?, ?, 0)");
        $stmt->bind_param('isss', $ref_id, $sale_date, $sale_type, $created_at);

        if ($stmt->execute()) {
            $sale_id = $stmt->insert_id;
            header("Location: add_sale.php?sale_id=$sale_id"); // This is correct
            exit;
        } else {
            $errors[] = "Failed to create sale.";
        }
        $stmt->close();
    }
}

/* ✅ Add item AJAX */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $sale_id = (int) ($_POST['sale_id'] ?? 0);
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $qty = (int) ($_POST['qty'] ?? 1);

    if ($sale_id <= 0 || $qty <= 0) {
        echo json_encode(["success" => false, "error" => "Missing values"]);
        exit;
    }
    if (!validate_sale_owner($mysqli, $sale_id, $ref_id)) {
        echo json_encode(["success" => false, "error" => "Unauthorized"]);
        exit;
    }
    $stmt = $mysqli->prepare("INSERT INTO sale_items (sale_id, item_id, quantity) VALUES (?, ?, ?)");
    $stmt->bind_param('iii', $sale_id, $item_id, $qty);
    if ($stmt->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "error" => "DB Error"]);
    }
    exit;
}

/* ✅ Search items */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'item_search') {
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $mysqli->prepare("SELECT id, item_code, item_name, price FROM items WHERE item_code LIKE ? OR item_name LIKE ?");
    $stmt->bind_param('ss', $q, $q);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    echo json_encode($items);
    exit;
}

/* ✅ Fetch sale items */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sale_items') {
    $sale_id = (int) $_GET['sale_id'];
    $items = [];
    if (validate_sale_owner($mysqli, $sale_id, $ref_id)) {
        $sql = "SELECT si.id, si.quantity, i.item_code, i.item_name, i.price
                FROM sale_items si
                JOIN items i ON si.item_id = i.id
                WHERE si.sale_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('i', $sale_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    echo json_encode($items);
    exit;
}

/* ✅ Existing sale info */
$existing_sale_id = (int) ($_GET['sale_id'] ?? 0);
$activeSale = null;
if ($existing_sale_id > 0) {
    $stmt = $mysqli->prepare("SELECT id, sale_date, sale_type, created_at FROM sales WHERE id = ? AND rep_user_id = ?");
    $stmt->bind_param('ii', $existing_sale_id, $ref_id);
    $stmt->execute();
    $activeSale = $stmt->get_result()->fetch_assoc();
    if (!$activeSale) {
        header("Location: add_sale.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Add Sale</title>
</head>

<body class="bg-gray-100">
    <?php
    // 🟡 --- DYNAMIC HEADER INCLUDE --- 🟡
    // This will now include the correct header based on the user's role
    if ($user_role === 'representative') {
        include '../leader/leader_header.php';
    } else {
        include 'refs_header.php';
    }
    ?>
    <div class="max-w-xl mx-auto p-6 mt-8 bg-white rounded shadow">
        <?php if (!$existing_sale_id): ?>
            <h2 class="text-xl font-bold mb-4">Create New Sale</h2>
            <?php if ($errors): ?>
                <div class="bg-red-100 text-red-700 p-3 rounded mb-3">
                    <?php foreach ($errors as $e)
                        echo "<div>$e</div>"; ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <label class="block">
                    Date & Time
                    <input type="datetime-local" name="sale_date" value="<?= date('Y-m-d\TH:i') ?>"
                        class="border w-full p-2 rounded" required>
                </label>
                <label class="block">
                    Sale Type
                    <select name="sale_type" class="border w-full p-2 rounded">
                        <option value="full">Full</option>
                        <option value="half">Half</option>
                    </select>
                </label>
                <button name="create_sale" class="bg-blue-600 text-white w-full p-2 rounded">Start Sale</button>
            </form>
        <?php else: ?>
            <h2 class="text-xl font-bold mb-4">Add Items (Sale #<?= $existing_sale_id ?>)</h2>
            <p>Date: <?= $activeSale['sale_date'] ?> • Type: <?= strtoupper($activeSale['sale_type']) ?></p>

            <form id="addItemForm" class="flex flex-wrap gap-2 my-4">
                <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                <input type="hidden" name="item_id" id="itemIdField">
                <div class="flex-1 min-w-[220px] relative">
                    <label class="block text-sm font-medium text-gray-600 mb-1" for="itemSearchInput">Find Item</label>
                    <input type="text" id="itemSearchInput" placeholder="Search by name or code" autocomplete="off"
                        class="border w-full p-2 rounded">
                    <div id="itemResults"
                        class="hidden absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded shadow max-h-60 overflow-y-auto">
                    </div>
                </div>
                <div class="flex items-end gap-2">
                    <label class="block text-sm font-medium text-gray-600">
                        Quantity
                        <input type="number" name="qty" id="itemQtyInput" value="1" min="1" class="border p-2 rounded w-24">
                    </label>
                    <button class="bg-blue-600 text-white px-4 py-2 rounded h-10 self-end" type="submit">Add</button>
                </div>
            </form>

            <div id="saleItemsContainer" class="border rounded p-2 bg-gray-50"></div>

            <div class="mt-4 flex flex-wrap gap-2">
                <form method="POST" class="flex-1 min-w-[160px]">
                    <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                    <button name="cancel_sale" class="bg-red-100 text-red-700 px-4 py-2 rounded w-full">Cancel Sale</button>
                </form>
                <form method="POST" class="flex-1 min-w-[160px]">
                    <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                    <button name="save_and_close"
                        class="w-full bg-green-600 text-white text-center px-4 py-2 rounded flex items-center justify-center">
                        Save & Close
                    </button>
                </form>
            </div>

            <script>
                // This JavaScript is correct
                document.addEventListener('DOMContentLoaded', () => {
                    const saleId = <?= $existing_sale_id ?>;
                    const saleItemsContainer = document.getElementById('saleItemsContainer');
                    const addItemForm = document.getElementById('addItemForm');
                    const itemIdField = document.getElementById('itemIdField');
                    const itemSearchInput = document.getElementById('itemSearchInput');
                    const itemResults = document.getElementById('itemResults');
                    const qtyInput = document.getElementById('itemQtyInput');
                    let searchTimer = null;

                    function loadItems() {
                        fetch(`add_sale.php?ajax=sale_items&sale_id=${saleId}`)
                            .then(r => r.json())
                            .then(items => {
                                if (!items.length) {
                                    saleItemsContainer.innerHTML = '<div class="text-gray-500 text-sm">No items added yet.</div>';
                                    return;
                                }
                                saleItemsContainer.innerHTML = items.map(i => `
                                    <div class="p-2 border-b last:border-b-0">
                                        <div class="font-medium">${i.item_code} - ${i.item_name}</div>
                                        <div class="text-sm text-gray-600">Qty: ${i.quantity} • Rs ${i.price}</div>
                                    </div>
                                `).join('');
                            })
                            .catch(() => {
                                saleItemsContainer.innerHTML = '<div class="text-red-600 text-sm">Failed to load items.</div>';
                            });
                    }

                    function hideResults() {
                        itemResults.classList.add('hidden');
                    }

                    function renderResults(items) {
                        if (!items.length) {
                            itemResults.innerHTML = '<div class="px-3 py-2 text-sm text-gray-500">No items found.</div>';
                            itemResults.classList.remove('hidden');
                            itemResults.dataset.hasItems = '0';
                            return;
                        }
                        itemResults.innerHTML = items.map(item => `
                            <button type="button" data-id="${item.id}" data-code="${item.item_code}"
                                data-name="${item.item_name}"
                                class="w-full text-left px-3 py-2 hover:bg-blue-50 focus:bg-blue-100 text-sm flex justify-between gap-2">
                                <span class="font-medium">${item.item_code}</span>
                                <span class="text-gray-600 flex-1 text-right overflow-hidden text-ellipsis whitespace-nowrap">${item.item_name}</span>
                                <span class="text-gray-500">Rs ${item.price}</span>
                            </button>
                        `).join('');
                        itemResults.classList.remove('hidden');
                        itemResults.dataset.hasItems = '1';
                    }

                    function performSearch(term) {
                        fetch(`add_sale.php?ajax=item_search&q=${encodeURIComponent(term)}`)
                            .then(r => r.json())
                            .then(renderResults)
                            .catch(() => {
                                itemResults.innerHTML = '<div class="px-3 py-2 text-sm text-red-600">Search failed.</div>';
                                itemResults.classList.remove('hidden');
                                itemResults.dataset.hasItems = '0';
                            });
                    }

                    itemSearchInput.addEventListener('input', (e) => {
                        const term = e.target.value.trim();
                        itemIdField.value = '';
                        if (term.length < 2) {
                            hideResults();
                            return;
                        }
                        clearTimeout(searchTimer);
                        searchTimer = setTimeout(() => performSearch(term), 250);
                    });

                    itemSearchInput.addEventListener('focus', () => {
                        if (itemResults.dataset.hasItems === '1') {
                            itemResults.classList.remove('hidden');
                        }
                    });

                    itemResults.addEventListener('click', (e) => {
                        const option = e.target.closest('button[data-id]');
                        if (!option) {
                            return;
                        }
                        itemIdField.value = option.dataset.id;
                        itemSearchInput.value = `${option.dataset.code} - ${option.dataset.name}`;
                        itemSearchInput.classList.remove('border-red-500');
                        hideResults();
                        qtyInput.focus();
                    });

                    document.addEventListener('click', (e) => {
                        if (!itemResults.contains(e.target) && e.target !== itemSearchInput) {
                            hideResults();
                        }
                    });

                    addItemForm.addEventListener('submit', e => {
                        e.preventDefault();

                        if (!itemIdField.value) {
                            itemSearchInput.classList.add('border-red-500');
                            itemSearchInput.focus();
                            return;
                        }

                        const fd = new FormData(addItemForm);
                        fd.append('add_item', '1');

                        fetch('add_sale.php', { method: 'POST', body: fd })
                            .then(r => r.json())
                            .then(response => {
                                if (!response.success) {
                                    alert(response.error || 'Unable to add item. Please try again.');
                                    return;
                                }
                                loadItems();
                                addItemForm.reset();
                                itemIdField.value = '';
                                itemResults.innerHTML = '';
                                hideResults();
                                qtyInput.value = 1;
                                itemSearchInput.focus();
                            })
                            .catch(() => {
                                alert('Unable to add item. Please try again.');
                            });
                    });

                    loadItems();
                });
            </script>
        <?php endif; ?>
    </div>
</body>

</html>
<?php $mysqli->close(); ?>