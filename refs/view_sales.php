<?php
// FIX 1: Start the session at the very top.
session_start();

include '../config.php';

// FIX 3: Handle the AJAX request FIRST, before any HTML.
if (isset($_GET['details']) && is_numeric($_GET['details'])) {

    // Ensure the user is logged in
    if (!isset($_SESSION['user_id'])) {
        echo "<div class='text-red-500 p-2'>Authentication error.</div>";
        exit;
    }

    $sale_id = (int) $_GET['details'];
    $ref_id = (int) $_SESSION['user_id'];

    // FIX 4: Use prepared statements and check ref_id for security.
    // *** UPDATED to join 'items' table and get points breakdown ***
    $sql = "SELECT sd.item_code, sd.qty, i.item_name, i.points_rep, (sd.qty * i.points_rep) AS item_points
            FROM sale_details sd
            JOIN sales_log s ON sd.sale_id = s.sale_id
            LEFT JOIN items i ON sd.item_code = i.item_code
            WHERE sd.sale_id = ? AND s.ref_id = ?";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ii", $sale_id, $ref_id); // "ii" for two integers
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $total_points_modal = 0;
        // *** UPDATED to show new columns in modal ***
        echo "<table class='min-w-full text-sm'><thead><tr class='bg-slate-100'>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Item Code</th>"; // <-- ADDED
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Item</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Qty</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Points (ea)</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Total Points</th>";
        echo "</tr></thead><tbody class='divide-y divide-slate-200'>";

        while ($row = $result->fetch_assoc()) {
            $total_points_modal += (int) $row['item_points'];
            echo "<tr>";
            echo "<td class='px-3 py-2 font-mono'>" . htmlspecialchars($row['item_code']) . "</td>"; // <-- ADDED
            echo "<td class='px-3 py-2'>" . htmlspecialchars($row['item_name'] ?? $row['item_code']) . "</td>";
            echo "<td class='px-3 py-2'>" . (int) $row['qty'] . "</td>";
            echo "<td class='px-3 py-2'>" . (int) $row['points_rep'] . "</td>";
            echo "<td class='px-3 py-2 font-medium'>" . (int) $row['item_points'] . "</td>";
            echo "</tr>";
        }
        // Add a total footer row to the modal table
        echo "</tbody>";
        echo "<tfoot><tr class='bg-slate-50 font-bold border-t-2 border-slate-300'>";
        echo "<td colspan='4' class='px-3 py-2 text-right text-slate-800'>Total</td>"; // <-- Colspan updated to 4
        echo "<td class='px-3 py-2 text-slate-800'>{$total_points_modal}</td>";
        echo "</tr></tfoot>";
        echo "</table>";
    } else {
        echo "<div class='text-slate-500 p-4'>No items found for this sale.</div>";
    }
    exit; // Stop script execution
}

// --- Main Page Load ---

// This header is now included *after* the AJAX block
include 'refs_header.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo "Please log in.";
    exit;
}
$ref_id = (int) $_SESSION['user_id'];

// Fetch all sales with basic info
$sales = [];

// FIX 2 & 4: Use WHERE (not HAVING) and prepared statements
// *** UPDATED to JOIN items table and SUM points ***
$sql = "SELECT s.sale_id, s.ref_id, s.sale_date,s.team_id ,
                COUNT(sd.item_code) AS items_count, 
                COALESCE(SUM(sd.qty),0) as total_qty,
                COALESCE(SUM(sd.qty * i.points_rep), 0) as total_points
        FROM sales_log s
        LEFT JOIN sale_details sd ON s.sale_id = sd.sale_id
        LEFT JOIN items i ON sd.item_code = i.item_code
        WHERE s.ref_id = ? 
        GROUP BY s.sale_id
        ORDER BY s.sale_date DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $ref_id); // "i" for integer
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
    }
} else {
    echo "<div class='bg-red-100 text-red-700 px-4 py-3 rounded mb-4'>Error loading sales list.</div>";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto mt-8 bg-white rounded-xl shadow p-4 sm:p-10">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-6">
            <h2 class="text-2xl font-bold text-blue-700 flex items-center gap-3">
                <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" stroke-width="2"
                    viewBox="0 0 24 24">
                    <path d="M3 7h18M3 12h18M3 17h18" stroke-linecap="round" />
                </svg>
                All Sales
            </h2>
            <a href="add_sale.php"
                class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700 font-semibold text-base transition">
                + New Sale
            </a>
        </div>

        <?php if (empty($sales)): ?>
            <div class="py-12 text-slate-500 text-center">No sales found.</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Sale ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Team ID</th>

                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Total Items</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Quantity</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Points</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($sales as $sale): ?>
                            <tr>
                                <td class="px-4 py-3 font-mono"><?= htmlspecialchars($sale['sale_id']) ?></td>
                                <td class="px-4 py-3 font-mono"><?= htmlspecialchars($sale['team_id']) ?></td>
                                <td class="px-4 py-3"><?= htmlspecialchars(date("Y-m-d H:i", strtotime($sale['sale_date']))) ?>
                                </td>
                                <td class="px-4 py-3"><?= (int) $sale['items_count'] ?></td>
                                <td class="px-4 py-3"><?= (int) $sale['total_qty'] ?></td>
                                <td class="px-4 py-3 font-semibold text-blue-600"><?= (int) $sale['total_points'] ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
                                        <button onclick="showSaleDetails(<?= $sale['sale_id'] ?>)"
                                            class="text-white hover:underline font-medium bg-blue-400 px-3 py-1 sm:px-4 sm:py-2 rounded-md">View</button>
                                        <button onclick="showEditModal(<?= $sale['sale_id'] ?>)"
                                            class="text-white hover:underline font-medium bg-green-400 px-3 py-1 sm:px-4 sm:py-2 rounded-md">Edit</button>
                                        <button onclick="confirmDeleteSale(<?= $sale['sale_id'] ?>)"
                                            class="text-white hover:underline font-medium bg-red-400 px-3 py-1 sm:px-4 sm:py-2 rounded-md">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <div class="mt-8 text-center">
            <a href="ref_dashboard.php" class="text-blue-600 hover:underline">&larr; Back to Dashboard</a>
        </div>
    </div>

    <div id="saleDetailModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 transition-opacity">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg m-4">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold text-slate-800" id="modalTitle">Sale Details</h3>
                <button onclick="closeSaleDetails()"
                    class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>
            <div class="p-4 overflow-y-auto max-h-[70vh]" id="modalBody">
                Loading...
            </div>
        </div>
    </div>

    <div id="editSaleModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 transition-opacity">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl m-4">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-lg font-semibold text-slate-800" id="editModalTitle">Edit Sale</h3>
                <button onclick="closeEditModal()"
                    class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>
            <div class="p-4 overflow-y-auto max-h-[70vh]" id="editModalBody">
                Loading editor...
            </div>
        </div>
    </div>


    <script>
        // --- View Modal JavaScript ---
        var viewModal = document.getElementById('saleDetailModal');
        var viewModalTitle = document.getElementById('modalTitle');
        var viewModalBody = document.getElementById('modalBody');

        function showSaleDetails(saleId) {
            viewModalTitle.innerText = "Sale Details (ID: " + saleId + ")";
            viewModalBody.innerHTML = '<div class="text-center p-8 text-slate-500">Loading...</div>';
            viewModal.classList.remove('hidden');

            fetch('view_sales.php?details=' + saleId)
                .then(res => res.text())
                .then(html => {
                    viewModalBody.innerHTML = html;
                })
                .catch(() => {
                    viewModalBody.innerHTML = "<div class='text-red-500 p-4'>Failed to load details.</div>";
                });
        }

        function closeSaleDetails() {
            viewModal.classList.add('hidden');
            viewModalBody.innerHTML = ''; // Clear content
        }

        viewModal.addEventListener('click', function (e) {
            if (e.target === viewModal) {
                closeSaleDetails();
            }
        });

        // --- Edit/Delete JavaScript ---
        var editModal = document.getElementById('editSaleModal');
        var editModalTitle = document.getElementById('editModalTitle');
        var editModalBody = document.getElementById('editModalBody');

        function showEditModal(saleId) {
            editModalTitle.innerText = "Edit Sale (ID: " + saleId + ")";
            editModalBody.innerHTML = '<div class="text-center p-8 text-slate-500">Loading editor...</div>';
            editModal.classList.remove('hidden');

            // This fetch call will now work *after* you create the new file
            fetch('load_sale_edit_form.php?sale_id=' + saleId)
                .then(res => res.text())
                .then(html => {
                    editModalBody.innerHTML = html;
                    // *** THIS IS THE FIX: ***
                    // After loading the HTML, call the function to attach scripts
                    initEditFormScript(saleId);
                })
                .catch(() => {
                    editModalBody.innerHTML = "<div class='text-red-500 p-4'>Failed to load the editor.</div>";
                });
        }

        function closeEditModal() {
            editModal.classList.add('hidden');
            editModalBody.innerHTML = ''; // Clear content
            location.reload(); // Reloads the page to show any changes
        }

        function confirmDeleteSale(saleId) {
            if (confirm("Are you sure you want to delete Sale ID: " + saleId + "? This action cannot be undone.")) {
                window.location.href = 'delete_sale.php?id=' + saleId;
            }
        }

        // ==========================================================
        // NEW FUNCTION - MOVED FROM load_sale_edit_form.php
        // This function attaches listeners to the newly loaded modal HTML
        // ==========================================================
        function initEditFormScript(saleId) {

            // We use editModalBody.querySelector to find elements *only* inside the modal
            const searchInput = editModalBody.querySelector('#item_search');
            const resultsDiv = editModalBody.querySelector('#search_results');
            const hiddenInput = editModalBody.querySelector('#selected_item_code');
            const selectedDisplay = editModalBody.querySelector('#selected_item_display');
            const selectedName = editModalBody.querySelector('#selected_item_name');
            const addButton = editModalBody.querySelector('#add_item_button');

            // Safety check
            if (!searchInput) {
                console.error("Edit form script failed: Could not find #item_search");
                return;
            }

            let debounceTimeout;

            // Helper: Render AJAX search
            function handleInput() {
                const term = searchInput.value;
                hiddenInput.value = '';
                addButton.disabled = true;
                selectedDisplay.classList.add('hidden');

                clearTimeout(debounceTimeout);

                if (term.length < 2) {
                    resultsDiv.classList.add('hidden');
                    resultsDiv.innerHTML = '';
                    return;
                }

                debounceTimeout = setTimeout(() => {
                    // This URL is correct, it calls the AJAX handler at the top
                    // of load_sale_edit_form.php
                    const url = `load_sale_edit_form.php?sale_id=${saleId}&action=search_items&term=${encodeURIComponent(term)}`;

                    fetch(url)
                        .then(response => response.json())
                        .then(items => {
                            resultsDiv.innerHTML = '';
                            if (items.length > 0) {
                                items.forEach(item => {
                                    const itemDiv = document.createElement('div');
                                    itemDiv.className = 'p-2 hover:bg-slate-100 cursor-pointer';
                                    itemDiv.textContent = `${item.name} (${item.code})`;
                                    itemDiv.dataset.code = item.code;
                                    itemDiv.dataset.name = item.name;

                                    itemDiv.addEventListener('click', function () {
                                        selectItem(this.dataset.code, this.dataset.name);
                                    });

                                    resultsDiv.appendChild(itemDiv);
                                });
                                resultsDiv.classList.remove('hidden');
                            } else {
                                resultsDiv.innerHTML = '<div class="p-2 text-slate-500">No items found.</div>';
                                resultsDiv.classList.remove('hidden');
                            }
                        })
                        .catch(error => {
                            console.error('Error fetching items:', error);
                            resultsDiv.innerHTML = '<div class="p-2 text-red-500">Error loading results.</div>';
                            resultsDiv.classList.remove('hidden');
                        });
                }, 300);
            }

            // Attach main AJAX search listener (by code or name)
            searchInput.addEventListener('input', handleInput);

            // Extra: If user pastes directly, also trigger search
            searchInput.addEventListener('paste', function () {
                setTimeout(handleInput, 0);
            });

            function selectItem(code, name) {
                hiddenInput.value = code;
                selectedName.textContent = `${name} (${code})`;
                selectedDisplay.classList.remove('hidden');
                addButton.disabled = false;
                searchInput.value = '';
                resultsDiv.innerHTML = '';
                resultsDiv.classList.add('hidden');
            }

            // Dismiss results if clicking outside
            document.addEventListener('click', function (e) {
                // Check if the click is outside the *search form*
                const searchForm = editModalBody.querySelector('#add-item-form');
                if (searchForm && !searchForm.contains(e.target)) {
                    resultsDiv.classList.add('hidden');
                }
            });

            // (The 'Enter' keydown listener from your original file was removed for simplicity,
            // as the main search-as-you-type `handleInput` covers all cases,
            // but we can add it back if needed.)
        }
    </script>
</body>

</html>