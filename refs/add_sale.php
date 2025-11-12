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
            // Get sale details including agency_id and sale_type
            $stmt = $mysqli->prepare("SELECT sale_date, sale_type, agency_id FROM sales WHERE id = ? AND rep_user_id = ?");
            $stmt->bind_param('ii', $sale_id_to_close, $ref_id);
            $stmt->execute();
            $saleInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($saleInfo) {
                $sale_date = $saleInfo['sale_date'];
                $sale_type = $saleInfo['sale_type'];
                $sale_agency_id = $saleInfo['agency_id']; // Agency ID from the sale

                // Only process points for representative role and full sales
                if ($user_role === 'representative' && $sale_type === 'full' && $sale_agency_id) {
                    // Check if points already logged
                    $stmt = $mysqli->prepare("SELECT 1 FROM points_ledger_rep WHERE sale_id = ?");
                    $stmt->bind_param('i', $sale_id_to_close);
                    $stmt->execute();
                    $already_logged_rep = $stmt->get_result()->num_rows > 0;
                    $stmt->close();

                    $stmt = $mysqli->prepare("SELECT 1 FROM points_ledger_group_points WHERE sale_id = ?");
                    $stmt->bind_param('i', $sale_id_to_close);
                    $stmt->execute();
                    $already_logged_group = $stmt->get_result()->num_rows > 0;
                    $stmt->close();

                    if (!$already_logged_rep && !$already_logged_group) {
                        // Calculate total points for the sale
                        $stmt = $mysqli->prepare("
                            SELECT 
                                SUM(si.quantity * i.rep_points) AS total_points_rep, 
                                SUM(si.quantity * i.representative_points) AS total_points_representative
                            FROM sale_items si 
                            JOIN items i ON si.item_id = i.id 
                            WHERE si.sale_id = ?
                        ");
                        $stmt->bind_param('i', $sale_id_to_close);
                        $stmt->execute();
                        $points_result = $stmt->get_result()->fetch_assoc();
                        $stmt->close();

                        $total_points_rep = (int) ($points_result['total_points_rep'] ?? 0);
                        $total_points_representative = (int) ($points_result['total_points_representative'] ?? 0);

                        // Insert into points_ledger_rep (rep_points - personal points)
                        if ($total_points_rep > 0) {
                            $stmt = $mysqli->prepare("
                                INSERT INTO points_ledger_rep 
                                    (sale_id, rep_user_id, agency_id, sale_date, points, redeemed) 
                                VALUES (?, ?, ?, ?, ?, 0)
                            ");
                            $stmt->bind_param('iiisi', $sale_id_to_close, $ref_id, $sale_agency_id, $sale_date, $total_points_rep);
                            $stmt->execute();
                            $stmt->close();
                        }

                        // Insert into points_ledger_group_points (representative_points - agency group points)
                        if ($total_points_representative > 0) {
                            $stmt = $mysqli->prepare("
                                INSERT INTO points_ledger_group_points 
                                    (sale_id, representative_id, agency_id, sale_date, points, redeemed) 
                                VALUES (?, ?, ?, ?, ?, 0)
                            ");
                            $stmt->bind_param('iiisi', $sale_id_to_close, $ref_id, $sale_agency_id, $sale_date, $total_points_representative);
                            $stmt->execute();
                            $stmt->close();
                        }

                        // Ensure sale is approved (it should already be 1 for representatives, but ensure it)
                        $stmt = $mysqli->prepare("UPDATE sales SET sale_approved = 1 WHERE id = ?");
                        $stmt->bind_param('i', $sale_id_to_close);
                        $stmt->execute();
                        $stmt->close();
                    }
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
            // Delete points from both ledgers first (if they exist)
            $stmt = $mysqli->prepare("DELETE FROM points_ledger_rep WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $stmt->close();

            $stmt = $mysqli->prepare("DELETE FROM points_ledger_group_points WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $stmt->close();

            // Delete sale_items first (foreign key constraint)
            $stmt = $mysqli->prepare("DELETE FROM sale_items WHERE sale_id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $stmt->close();

            // Delete the sale
            $stmt = $mysqli->prepare("DELETE FROM sales WHERE id = ?");
            $stmt->bind_param('i', $sale_id_to_cancel);
            $stmt->execute();
            $stmt->close();

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

        // Get agency_id based on role
        $selected_agency_id = null;

        if ($user_role === 'representative') {
            // --- Logic for 'representative' (unchanged) ---
            if (isset($_POST['agency_id']) && $_POST['agency_id'] !== '' && $_POST['agency_id'] !== '0') {
                $selected_agency_id = (int) $_POST['agency_id'];
                // Validate that the agency belongs to this representative
                $stmt_check = $mysqli->prepare("SELECT id FROM agencies WHERE id = ? AND representative_id = ?");
                $stmt_check->bind_param('ii', $selected_agency_id, $ref_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows === 0) {
                    $errors[] = "Invalid agency selected. Please select a valid agency.";
                    $selected_agency_id = null;
                }
                $stmt_check->close();
            } else {
                $errors[] = "Agency selection is required for representatives.";
            }
        }
        // ***** START NEW BLOCK *****
        else if ($user_role === 'rep') {
            // --- New Logic for 'rep' ---
            // Find the rep's agency_id from the agency_reps table
            $stmt_find_agency = $mysqli->prepare("SELECT agency_id FROM agency_reps WHERE rep_user_id = ? LIMIT 1");
            $stmt_find_agency->bind_param('i', $ref_id);
            $stmt_find_agency->execute();
            $agency_result = $stmt_find_agency->get_result()->fetch_assoc();
            $stmt_find_agency->close();

            if ($agency_result && !empty($agency_result['agency_id'])) {
                $selected_agency_id = (int) $agency_result['agency_id'];
            } else {
                // This rep is not in the agency_reps table. This is a critical error.
                $errors[] = "You are not assigned to an agency. Please contact your administrator.";
            }
        }
        // ***** END NEW BLOCK *****

        // Only create sale if there are no errors
        if (empty($errors)) {
            // Representative sales are auto-approved (sale_approved = 1)
            // Rep sales need admin approval (sale_approved = 0)
            $sale_approved = ($user_role === 'representative') ? 1 : 0;
            $admin_approved = 0; // Always 0 initially

            // This INSERT logic now works for BOTH roles
            // because $selected_agency_id will be set (if no errors occurred)
            if ($selected_agency_id) {
                $stmt = $mysqli->prepare("INSERT INTO sales (rep_user_id, sale_date, sale_type, created_at, admin_approved, agency_id, sale_approved) VALUES (?, ?, ?, ?, 0, ?, ?)");
                $stmt->bind_param('isssii', $ref_id, $sale_date, $sale_type, $created_at, $selected_agency_id, $sale_approved);
            } else {
                // This branch will now only be hit if something went wrong and an error was missed
                // But we added errors, so $errors should not be empty.
                // We will keep the old logic just in case, but it shouldn't be used.
                $errors[] = "A valid agency ID could not be determined.";

                // --- OLD LOGIC (that was causing the problem) ---
                // $stmt = $mysqli->prepare("INSERT INTO sales (rep_user_id, sale_date, sale_type, created_at, admin_approved, agency_id, sale_approved) VALUES (?, ?, ?, ?, 0, NULL, ?)");
                // $stmt->bind_param('isssi', $ref_id, $sale_date, $sale_type, $created_at, $sale_approved);
            }

            // Only execute if we haven't hit that final error
            if (empty($errors) && $stmt->execute()) {
                $sale_id = $stmt->insert_id;
                header("Location: add_sale.php?sale_id=$sale_id");
                exit;
            } else {
                $errors[] = "Failed to create sale: " . ($stmt->error ?? 'Unknown error');
            }

            if (isset($stmt))
                $stmt->close();
        }
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

/* ✅ Fetch agencies for representative (if role is representative) */
$agencies = [];
if ($user_role === 'representative') {
    $stmt = $mysqli->prepare("SELECT id, agency_name FROM agencies WHERE representative_id = ? ORDER BY agency_name");
    $stmt->bind_param('i', $ref_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $agencies[] = $row;
    }
    $stmt->close();
}

/* ✅ Existing sale info */
$existing_sale_id = (int) ($_GET['sale_id'] ?? 0);
$activeSale = null;
if ($existing_sale_id > 0) {
    $stmt = $mysqli->prepare("SELECT id, sale_date, sale_type, created_at, agency_id FROM sales WHERE id = ? AND rep_user_id = ?");
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
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Add Sale</title>
</head>

<body class="bg-gray-100 min-h-screen">
    <?php
    // 🟡 --- DYNAMIC HEADER INCLUDE --- 🟡
    // This will now include the correct header based on the user's role
    if ($user_role === 'representative') {
        include '../leader/leader_header.php';
    } else {
        include 'refs_header.php';
    }
    ?>
    <div class="max-w-xl mx-2 sm:mx-auto p-4 sm:p-6 mt-4 sm:mt-8 mb-4 sm:mb-8 bg-white rounded shadow">
        <?php if (!$existing_sale_id): ?>
            <h2 class="text-lg sm:text-xl font-bold mb-4">Create New Sale</h2>
            <?php if ($errors): ?>
                <div class="bg-red-100 text-red-700 p-3 rounded mb-3 text-sm sm:text-base">
                    <?php foreach ($errors as $e)
                        echo "<div>$e</div>"; ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="space-y-4">
                <label class="block text-sm sm:text-base">
                    <span class="block mb-1 font-medium text-gray-700">Date & Time</span>
                    <input type="datetime-local" name="sale_date" value="<?= date('Y-m-d\TH:i') ?>"
                        class="border w-full p-2 sm:p-3 rounded text-sm sm:text-base" required>
                </label>
                <label class="block text-sm sm:text-base">
                    <span class="block mb-1 font-medium text-gray-700">Sale Type</span>
                    <select name="sale_type" class="border w-full p-2 sm:p-3 rounded text-sm sm:text-base">
                        <option value="full">Full</option>
                        <option value="half">Half</option>
                    </select>
                </label>
                <?php if ($user_role === 'representative'): ?>
                    <label class="block text-sm sm:text-base">
                        <span class="block mb-1 font-medium text-gray-700">Agency <span class="text-red-500">*</span></span>
                        <select name="agency_id" class="border w-full p-2 sm:p-3 rounded text-sm sm:text-base" required>
                            <option value="">-- Select Agency --</option>
                            <?php if (!empty($agencies)): ?>
                                <?php foreach ($agencies as $agency): ?>
                                    <option value="<?= htmlspecialchars($agency['id']) ?>">
                                        <?= htmlspecialchars($agency['agency_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            <?php if (!empty($agencies)): ?>
                                Select an agency to credit points to that agency. Agency sales will earn bonus points for the
                                agency.
                            <?php else: ?>
                                No agencies available. Please contact admin to set up agencies.
                            <?php endif; ?>
                        </p>
                    </label>
                <?php endif; ?>
                <button name="create_sale"
                    class="bg-blue-600 text-white w-full p-3 sm:p-3 rounded text-sm sm:text-base font-medium hover:bg-blue-700 transition">Start
                    Sale</button>
            </form>
        <?php else: ?>
            <h2 class="text-lg sm:text-xl font-bold mb-3 sm:mb-4">Add Items (Sale #<?= $existing_sale_id ?>)</h2>
            <?php
            // Get agency name if agency_id is set
            $agency_name = null;
            if (!empty($activeSale['agency_id']) && $user_role === 'representative') {
                $stmt = $mysqli->prepare("SELECT agency_name FROM agencies WHERE id = ? AND representative_id = ?");
                $stmt->bind_param('ii', $activeSale['agency_id'], $ref_id);
                $stmt->execute();
                $agency_result = $stmt->get_result()->fetch_assoc();
                $agency_name = $agency_result['agency_name'] ?? null;
                $stmt->close();
            }
            ?>
            <p class="text-sm sm:text-base text-gray-600 mb-4">
                Date: <?= htmlspecialchars($activeSale['sale_date']) ?> • Type: <?= strtoupper($activeSale['sale_type']) ?>
                <?php if ($agency_name): ?>
                    • Agency: <strong><?= htmlspecialchars($agency_name) ?></strong>
                <?php elseif ($user_role === 'representative'): ?>
                    • <span class="text-blue-600">Direct Sale</span>
                <?php endif; ?>
            </p>

            <form id="addItemForm" class="space-y-3 sm:space-y-0 sm:flex sm:flex-wrap sm:gap-2 my-4">
                <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                <input type="hidden" name="item_id" id="itemIdField">
                <div class="flex-1 w-full sm:min-w-[220px] relative">
                    <label class="block text-sm font-medium text-gray-600 mb-1" for="itemSearchInput">Find Item</label>
                    <input type="text" id="itemSearchInput" placeholder="Search by name or code" autocomplete="off"
                        class="border w-full p-2 sm:p-2.5 rounded text-sm sm:text-base">
                    <div id="itemResults"
                        class="hidden absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded shadow max-h-60 overflow-y-auto">
                    </div>
                </div>
                <div class="flex items-end gap-2 w-full sm:w-auto">
                    <label class="block text-sm font-medium text-gray-600 flex-1 sm:flex-none">
                        <span class="block mb-1 sm:mb-0 sm:inline sm:mr-2">Quantity</span>
                        <input type="number" name="qty" id="itemQtyInput" value="1" min="1"
                            class="border p-2 rounded w-full sm:w-24 text-sm sm:text-base">
                    </label>
                    <button
                        class="bg-blue-600 text-white px-4 sm:px-4 py-2.5 sm:py-2 rounded h-10 sm:h-auto w-full sm:w-auto text-sm sm:text-base font-medium hover:bg-blue-700 transition"
                        type="submit">Add</button>
                </div>
            </form>

            <div id="saleItemsContainer" class="border rounded p-2 sm:p-3 bg-gray-50 min-h-[100px]"></div>

            <div class="mt-4 flex flex-col sm:flex-row gap-2">
                <form method="POST" class="w-full sm:flex-1">
                    <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                    <button name="cancel_sale"
                        class="bg-red-100 text-red-700 px-4 py-2.5 sm:py-2 rounded w-full text-sm sm:text-base font-medium hover:bg-red-200 transition">Cancel
                        Sale</button>
                </form>
                <form method="POST" class="w-full sm:flex-1">
                    <input type="hidden" name="sale_id" value="<?= $existing_sale_id ?>">
                    <button name="save_and_close"
                        class="w-full bg-green-600 text-white text-center px-4 py-2.5 sm:py-2 rounded flex items-center justify-center text-sm sm:text-base font-medium hover:bg-green-700 transition">
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
                                    saleItemsContainer.innerHTML = '<div class="text-gray-500 text-sm sm:text-base text-center py-4">No items added yet.</div>';
                                    return;
                                }
                                saleItemsContainer.innerHTML = items.map(i => `
                                    <div class="p-2 sm:p-3 border-b last:border-b-0 hover:bg-gray-100 transition">
                                        <div class="font-medium text-sm sm:text-base break-words">${i.item_code} - ${i.item_name}</div>
                                        <div class="text-xs sm:text-sm text-gray-600 mt-1">Qty: ${i.quantity} • Rs ${i.price}</div>
                                    </div>
                                `).join('');
                            })
                            .catch(() => {
                                saleItemsContainer.innerHTML = '<div class="text-red-600 text-sm sm:text-base text-center py-4">Failed to load items.</div>';
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
                                class="w-full text-left px-3 py-2.5 sm:py-2 hover:bg-blue-50 focus:bg-blue-100 active:bg-blue-100 text-sm sm:text-base flex flex-col sm:flex-row sm:justify-between sm:items-center gap-1 sm:gap-2 touch-manipulation">
                                <div class="flex items-center gap-2 flex-1 min-w-0">
                                    <span class="font-medium text-xs sm:text-sm">${item.item_code}</span>
                                    <span class="text-gray-600 flex-1 overflow-hidden text-ellipsis whitespace-nowrap text-xs sm:text-sm">${item.item_name}</span>
                                </div>
                                <span class="text-gray-500 text-xs sm:text-sm sm:ml-auto">Rs ${item.price}</span>
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