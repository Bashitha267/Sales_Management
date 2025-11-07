<?php
session_start();
// Admin check (reuse from admin_header.php style, but direct here for safety)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

require_once("../config.php");

// Handle AJAX search
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT * FROM items";
    $params = [];
    $types = '';
    if ($search !== '') {
        $sql .= " WHERE item_code LIKE ? OR item_name LIKE ?";
        $searchWildcard = '%' . $search . '%';
        $params = [$searchWildcard, $searchWildcard];
        $types = 'ss';
    }
    $sql .= " ORDER BY created_at DESC";

    $stmt = $mysqli->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    header('Content-Type: application/json');
    echo json_encode($items);
    exit;
}

// Handle delete (POST for security, checks CSRF token, simplified here)
$success = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $item_id = (int) $_POST['delete_id'];
    $stmt = $mysqli->prepare("DELETE FROM items WHERE id=?");
    $stmt->bind_param('i', $item_id);
    if ($stmt->execute()) {
        $success = "Item deleted successfully.";
    } else {
        $error = "Error deleting item.";
    }
    $stmt->close();
}

// Handle edit via modal (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_id']) && isset($_POST['action']) && $_POST['action'] === 'edit_ajax') {
    // Grab fields
    $id = (int) $_POST['edit_id'];
    $code = trim($_POST['item_code'] ?? '');
    $name = trim($_POST['item_name'] ?? '');
    $pl = trim($_POST['points_leader'] ?? '');
    $pr = trim($_POST['points_rep'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $response = array('success' => false);

    if ($code === '' || $name === '' || $pl === '' || $pr === '' || $price === '') {
        $response['error'] = 'All fields are required.';
    } elseif (!ctype_digit($pl) || !ctype_digit($pr)) {
        $response['error'] = 'Points must be numbers.';
    } elseif (!is_numeric($price) || floatval($price) < 0) {
        $response['error'] = 'Price must be a valid number greater than or equal to 0.';
    } else {
        $stmt = $mysqli->prepare("UPDATE items SET item_code=?, item_name=?, points_leader=?, points_rep=?, price=? WHERE id=?");
        $stmt->bind_param('ssiidi', $code, $name, $pl, $pr, $price, $id);
        if ($stmt->execute()) {
            $response['success'] = true;
        } elseif ($mysqli->errno == 1062) {
            $response['error'] = 'Item code already exists.';
        } else {
            $response['error'] = "Database error: " . $mysqli->error;
        }
        $stmt->close();
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Normal page load: get all, possibly filtered
$search = trim($_GET['search'] ?? '');
$sql = "SELECT * FROM items";
$params = [];
$types = '';
if ($search !== '') {
    $sql .= " WHERE item_code LIKE ? OR item_name LIKE ?";
    $searchWildcard = '%' . $search . '%';
    $params = [$searchWildcard, $searchWildcard];
    $types = 'ss';
}
$sql .= " ORDER BY created_at DESC";
$stmt = $mysqli->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>View Items - Admin Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <?php include_once("../admin_header.php"); ?>

    <div class="max-w-6xl mx-auto mt-4 sm:mt-8 p-4 sm:p-8">
        <h2 class="text-2xl sm:text-3xl font-bold mb-6 text-slate-700 flex items-center">
            <img src="https://img.icons8.com/arcade/48/open-box--v1.png" class="w-7 h-7 mr-2" alt="Items" />
            <span>Item Management</span>
        </h2>
        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-800 px-4 py-2 rounded mb-4 text-sm"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm"><?= $error ?></div>
        <?php endif; ?>

        <form method="get" id="searchForm" class="flex flex-col sm:flex-row gap-2 mb-8">
            <input type="text" name="search" id="searchInput" placeholder="Search by code or name..."
                value="<?= htmlspecialchars($search) ?>"
                class="w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
            <button type="submit"
                class="px-5 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">Search</button>
        </form>

        <div id="itemsContainer" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3 gap-6">
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <div
                        class="bg-white rounded-xl shadow hover:shadow-xl transition-all border-t-4 border-transparent hover:border-indigo-500 p-5 flex flex-col group/item relative">
                        <div class="flex items-center mb-2">
                            <img src="https://img.icons8.com/arcade/48/open-box--v1.png" alt="Item" class="w-8 h-8 mr-2">
                            <span class="text-sm text-slate-400 font-mono ml-auto">#<?= $row['id'] ?></span>
                        </div>
                        <div class="mb-1">
                            <span class="text-xs text-slate-500 uppercase tracking-wider">Item Code</span>
                            <div class="font-semibold text-base text-indigo-700"><?= htmlspecialchars($row['item_code']) ?>
                            </div>
                        </div>
                        <div class="mb-2">
                            <span class="text-xs text-slate-500 uppercase tracking-wider">Item Name</span>
                            <div class="text-lg font-bold text-slate-800"><?= htmlspecialchars($row['item_name']) ?></div>
                        </div>
                        <div class="flex items-center mb-2 gap-3">
                            <span class="text-sm rounded-full bg-indigo-100 text-indigo-800 px-3 py-1 font-medium">
                                Leader: <?= (int) $row['points_leader'] ?>
                            </span>
                            <span class="text-sm rounded-full bg-amber-100 text-amber-800 px-3 py-1 font-medium">
                                Rep: <?= (int) $row['points_rep'] ?>
                            </span>
                        </div>
                        <div class="mb-2">
                            <span class="text-xs text-slate-500 uppercase tracking-wider">Price</span>
                            <div class="text-lg font-bold text-black">
                                Rs <?= number_format((float) $row['price'], 2, '.', ',') ?></div>
                        </div>
                        <div class="flex items-center justify-between mt-auto pt-2 gap-2">
                            <button
                                class="edit-btn px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600 text-sm font-medium transition"
                                data-id="<?= $row['id'] ?>" data-code="<?= htmlspecialchars($row['item_code'], ENT_QUOTES) ?>"
                                data-name="<?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>"
                                data-pl="<?= $row['points_leader'] ?>" data-pr="<?= $row['points_rep'] ?>"
                                data-price="<?= htmlspecialchars($row['price'], ENT_QUOTES) ?>">
                                Edit
                            </button>
                            <form method="post" class="inline" onsubmit="return confirm('Delete this item?');">
                                <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                <button type="submit"
                                    class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600 text-sm font-medium transition">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-span-full flex flex-col items-center justify-center text-gray-500 py-16">
                    <img src="https://img.icons8.com/arcade/64/open-box--v1.png" class="w-16 h-16 mb-3 opacity-50" />
                    <p class="text-lg font-medium">No items found</p>
                    <p class="text-sm mt-1">
                        <?= $search !== '' ? 'Try a different search term.' : 'No items have been created yet.' ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="editModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 transition-opacity hidden">
        <div class="bg-white rounded-lg shadow-lg max-w-md w-full p-6 relative">
            <button id="closeModalBtn"
                class="absolute top-2 right-2 text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            <h3 class="text-xl font-semibold text-slate-700 mb-6 flex items-center">
                <img src="https://img.icons8.com/arcade/32/open-box--v1.png" class="w-6 h-6 mr-2" alt="Item">
                Edit Item
            </h3>
            <form id="editItemForm" autocomplete="off">
                <input type="hidden" name="edit_id" id="edit_id">
                <input type="hidden" name="action" value="edit_ajax">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="edit_item_code">Item Code</label>
                    <input type="text" name="item_code" id="edit_item_code" maxlength="20" required
                        class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-100 focus:outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="edit_item_name">Item Name</label>
                    <input type="text" name="item_name" id="edit_item_name" maxlength="100" required
                        class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-100 focus:outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="edit_points_leader">Points
                        Leader</label>
                    <input type="number" name="points_leader" id="edit_points_leader" min="0" required
                        class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-100 focus:outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="edit_points_rep">Points
                        Rep</label>
                    <input type="number" name="points_rep" id="edit_points_rep" min="0" required
                        class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-100 focus:outline-none">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1" for="edit_price">Price</label>
                    <input type="number" name="price" id="edit_price" min="0" step="0.01" required
                        class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-100 focus:outline-none">
                </div>
                <div id="editModalError" class="bg-red-100 text-red-700 px-3 py-2 rounded mb-3 text-sm hidden"></div>
                <div class="flex justify-end pt-2 gap-2">
                    <button type="button" id="cancelModalBtn"
                        class="bg-gray-300 text-slate-700 px-4 py-2 rounded hover:bg-gray-400 transition">Cancel</button>
                    <button type="submit"
                        class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700 transition font-medium">Save
                        Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Real-time AJAX search for items
        (function () {
            const searchInput = document.getElementById('searchInput');
            const itemsContainer = document.getElementById('itemsContainer');
            let debounceTimer;

            // Escape HTML for safe output into attributes/innerHTML
            function escapeHtml(text) {
                const map = {
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
                };
                return ('' + text).replace(/[&<>"']/g, function (m) { return map[m]; });
            }

            function buildCard(item) {
                return `
                <div class="bg-white rounded-xl shadow hover:shadow-xl transition-all border-t-4 border-transparent hover:border-indigo-500 p-5 flex flex-col group/item relative">
                    <div class="flex items-center mb-2">
                        <img src="https://img.icons8.com/arcade/48/open-box--v1.png" alt="Item" class="w-8 h-8 mr-2">
                        <span class="text-sm text-slate-400 font-mono ml-auto">#${item.id}</span>
                    </div>
                    <div class="mb-1">
                        <span class="text-xs text-slate-500 uppercase tracking-wider">Item Code</span>
                        <div class="font-semibold text-base text-indigo-700">${escapeHtml(item.item_code)}</div>
                    </div>
                    <div class="mb-2">
                        <span class="text-xs text-slate-500 uppercase tracking-wider">Item Name</span>
                        <div class="text-lg font-bold text-slate-800">${escapeHtml(item.item_name)}</div>
                    </div>
                    <div class="flex items-center mb-2 gap-3">
                        <span class="text-sm rounded-full bg-indigo-100 text-indigo-800 px-3 py-1 font-medium">
                            Leader: ${item.points_leader}
                        </span>
                        <span class="text-sm rounded-full bg-amber-100 text-amber-800 px-3 py-1 font-medium">
                            Rep: ${item.points_rep}
                        </span>
                    </div>
                    <div class="mb-2">
                        <span class="text-xs text-slate-500 uppercase tracking-wider">Price</span>
                        <div class="text-lg font-bold text-black">Rs ${parseFloat(item.price || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',')}</div>
                    </div>
                    <div class="flex items-center justify-between mt-auto pt-2 gap-2">
                        <button 
                            class="edit-btn px-4 py-2 bg-indigo-500 text-white rounded hover:bg-indigo-600 text-sm font-medium transition"
                            data-id="${item.id}"
                            data-code="${escapeHtml(item.item_code)}"
                            data-name="${escapeHtml(item.item_name)}"
                            data-pl="${item.points_leader}"
                            data-pr="${item.points_rep}"
                            data-price="${item.price || 0}"
                        >
                            Edit
                        </button>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this item?');">
                            <input type="hidden" name="delete_id" value="${item.id}">
                            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 text-sm font-medium transition">Delete</button>
                        </form>
                    </div>
                </div>
            `;
            }

            function showNoItemsMsg(searchTerm) {
                return `
                <div class="col-span-full flex flex-col items-center justify-center text-gray-500 py-16">
                    <img src="https://img.icons8.com/arcade/64/open-box--v1.png" class="w-16 h-16 mb-3 opacity-50" />
                    <p class="text-lg font-medium">No items found</p>
                    <p class="text-sm mt-1">
                        ${searchTerm ? 'Try a different search term.' : 'No items have been created yet.'}
                    </p>
                </div>
            `;
            }

            function fetchItems(searchTerm) {
                const url = new URL(window.location.href);
                url.searchParams.set('search', searchTerm);
                url.searchParams.set('ajax', '1');
                fetch(url.toString())
                    .then(response => response.json())
                    .then(items => {
                        if (!items.length) {
                            itemsContainer.innerHTML = showNoItemsMsg(searchTerm);
                        } else {
                            let cards = '';
                            items.forEach(item => {
                                cards += buildCard(item);
                            });
                            itemsContainer.innerHTML = cards;
                        }
                    });
            }

            searchInput.addEventListener('input', function (e) {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    fetchItems(e.target.value.trim());
                }, 300);
            });

            document.getElementById('searchForm').addEventListener('submit', function (e) {
                e.preventDefault();
                fetchItems(searchInput.value.trim());
            });

            // ********* Edit Modal Logic // Reusable for static and ajax-loaded cards *********

            let editModal = document.getElementById('editModal');
            let editItemForm = document.getElementById('editItemForm');
            let editErrorDiv = document.getElementById('editModalError');
            let closeModalBtn = document.getElementById('closeModalBtn');
            let cancelModalBtn = document.getElementById('cancelModalBtn');
            let itemCardBtnsBound = false;

            function openEditModal({ id, code, name, pl, pr, price }) {
                editItemForm.reset();
                editErrorDiv.classList.add('hidden');
                editErrorDiv.innerHTML = '';
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_item_code').value = code;
                document.getElementById('edit_item_name').value = name;
                document.getElementById('edit_points_leader').value = pl;
                document.getElementById('edit_points_rep').value = pr;
                document.getElementById('edit_price').value = price;
                editModal.classList.remove('hidden');
            }
            function closeEditModal() {
                editModal.classList.add('hidden');
            }
            closeModalBtn.onclick = cancelModalBtn.onclick = closeEditModal;
            editModal.addEventListener('click', function (e) {
                if (e.target === editModal) closeEditModal();
            });

            // Since AJAX repopulates, delegate 'edit-btn' click using event delegation
            document.addEventListener('click', function (e) {
                if (e.target.classList.contains('edit-btn')) {
                    openEditModal({
                        id: e.target.getAttribute('data-id'),
                        code: e.target.getAttribute('data-code'),
                        name: e.target.getAttribute('data-name'),
                        pl: e.target.getAttribute('data-pl'),
                        pr: e.target.getAttribute('data-pr'),
                        price: e.target.getAttribute('data-price')
                    });
                }
            });

            // Edit form AJAX submit
            editItemForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var formData = new FormData(editItemForm);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                    .then(resp => resp.json())
                    .then(data => {
                        if (data.success) {
                            closeEditModal();
                            fetchItems(searchInput.value.trim());
                        } else {
                            editErrorDiv.innerHTML = escapeHtml(data.error || 'Unknown error');
                            editErrorDiv.classList.remove('hidden');
                        }
                    })
                    .catch(() => {
                        editErrorDiv.innerHTML = 'Request failed.';
                        editErrorDiv.classList.remove('hidden');
                    });
            });

        })();
    </script>
</body>

</html>
<?php
$stmt->close();
$mysqli->close();
?>