<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ref/login.php");
    exit();
}

// Ensure only admin or sale_admin can access
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'sale_admin') {
    echo "<div class='text-center text-red-600 font-bold mt-10'>Access Denied: Admins or Sale Admins only.</div>";
    exit();
}

// Include appropriate header based on role
if ($user_role === 'admin') {
    include 'admin_header.php';
} else if ($user_role === 'sale_admin') {
    include 'sale_admin/sales_header.php';
}

// --- Get available years ---
$years = [];
$res = $mysqli->query("SELECT DISTINCT YEAR(sale_date) AS y FROM sales ORDER BY y DESC");
while ($r = $res->fetch_assoc())
    $years[] = (int) $r['y'];
if (empty($years))
    $years[] = (int) date('Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Sales Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
        <h1 class="text-3xl font-bold text-blue-800 mb-6">Sales Report (Admin)</h1>

        <form id="filterForm" class="mb-6 flex flex-col sm:flex-row gap-4 items-stretch sm:items-center">
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <label class="font-semibold text-slate-700 flex-shrink-0">Year:</label>
                <select name="year" id="yearSelect" class="border rounded px-3 py-2 w-full sm:w-auto">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex-grow">
                <label for="searchBox" class="sr-only">Search</label>
                <input type="text" name="search" id="searchBox" value="" placeholder="Search by ID or Name"
                    class="border rounded px-4 py-2 w-full">
            </div>
        </form>

        <div id="results">
            <?php
            $_GET['year'] = date('Y');
            $_GET['search'] = '';
            include 'admin_sales_table.php';
            ?>
        </div>
    </div>

    <div id="repSalesModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 transition-opacity p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[85vh] flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 flex-shrink-0">
                <h3 class="text-lg font-semibold text-slate-900" id="repSalesTitle">Representative Sales</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600 text-2xl font-bold"
                    id="closeRepSales">&times;</button>
            </div>
            <div id="repSalesContent" class="p-6 text-sm text-slate-700 overflow-y-auto">
                Loading sales...
            </div>
        </div>
    </div>

    <div id="saleItemsModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 transition-opacity p-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[80vh] flex flex-col">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 flex-shrink-0">
                <h3 class="text-lg font-semibold text-slate-900">Sale Items</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600 text-2xl font-bold"
                    id="closeSaleItems">&times;</button>
            </div>
            <div id="saleItemsContent" class="p-6 text-sm text-slate-700 overflow-y-auto">
                Loading items...
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {
            function fetchResults() {
                const year = $('#yearSelect').val();
                const search = $('#searchBox').val();

                $.ajax({
                    url: 'admin_sales_table.php',
                    type: 'GET',
                    data: { year: year, search: search },
                    success: function (data) {
                        $('#results').html(data);
                    }
                });
            }

            // Trigger live search on typing
            $('#searchBox').on('keyup', function () {
                fetchResults();
            });

            // Trigger reload on year change
            $('#yearSelect').on('change', function () {
                fetchResults();
            });

            // Modals
            const repModal = document.getElementById('repSalesModal');
            const saleModal = document.getElementById('saleItemsModal');
            const repContent = document.getElementById('repSalesContent');
            const saleContent = document.getElementById('saleItemsContent');
            const closeRepBtn = document.getElementById('closeRepSales');
            const closeSaleBtn = document.getElementById('closeSaleItems');
            const repTitle = document.getElementById('repSalesTitle');
            let currentRepId = null;

            function openModal(modal) {
                modal.classList.remove('hidden');
            }
            function closeModal(modal) {
                modal.classList.add('hidden');
            }
            closeRepBtn.addEventListener('click', () => closeModal(repModal));
            closeSaleBtn.addEventListener('click', () => closeModal(saleModal));

            // Delegate clicks from dynamic results
            document.addEventListener('click', (event) => {
                // Open rep sales from card or button
                if (event.target.classList.contains('rep-card') ||
                    event.target.classList.contains('view-rep-sales') ||
                    (event.target.closest && event.target.closest('.rep-card'))) {

                    // Prevent button click from triggering card click
                    event.stopPropagation();
                    const card = event.target.closest('.rep-card');
                    const btn = event.target.closest('.view-rep-sales');
                    const repId = (btn ? btn.getAttribute('data-rep') : (card ? card.getAttribute('data-rep') : null));

                    if (!repId) return;

                    const yearVal = $('#yearSelect').val() || 'all';
                    currentRepId = repId;
                    repTitle.textContent = 'Representative Sales';
                    repContent.innerHTML = 'Loading sales...';
                    openModal(repModal);

                    fetch(`admin_sales_table.php?rep_detail=${repId}&year=${encodeURIComponent(yearVal)}`)
                        .then(res => res.text())
                        .then(html => {
                            repContent.innerHTML = html;
                        })
                        .catch(() => {
                            repContent.innerHTML = "<div class='text-red-500'>Unable to load sales.</div>";
                        });
                }

                // Open sale items from inside rep sales table
                if (event.target.classList.contains('admin-view-sale-items')) {
                    event.stopPropagation();
                    const saleId = event.target.getAttribute('data-sale');
                    if (!saleId) return;
                    saleContent.innerHTML = 'Loading items...';
                    openModal(saleModal);

                    fetch(`admin_sales_table.php?sale_detail=${saleId}`)
                        .then(res => res.text())
                        .then(html => {
                            saleContent.innerHTML = html;
                        })
                        .catch(() => {
                            saleContent.innerHTML = "<div class='text-red-500'>Unable to load sale items.</div>";
                        });
                }
            });

            // Delegate filter changes inside Rep Sales modal
            document.addEventListener('change', (event) => {
                const target = event.target;
                if (!repModal || repModal.classList.contains('hidden')) return;
                if (target && (target.id === 'repFilterYear' || target.id === 'repFilterMonth' || target.id === 'repFilterScope')) {
                    const yearSel = document.getElementById('repFilterYear');
                    const monthSel = document.getElementById('repFilterMonth');
                    const scopeSel = document.getElementById('repFilterScope');
                    const year = yearSel ? yearSel.value : 'all';
                    const month = monthSel ? monthSel.value : 'all';
                    const scope = scopeSel ? scopeSel.value : 'direct';
                    const repId = currentRepId || (yearSel ? yearSel.getAttribute('data-rep') : null);
                    if (!repId) return;
                    repContent.innerHTML = 'Loading sales...';
                    fetch(`admin_sales_table.php?rep_detail=${encodeURIComponent(repId)}&year=${encodeURIComponent(year)}&month=${encodeURIComponent(month)}&scope=${encodeURIComponent(scope)}`)
                        .then(res => res.text())
                        .then(html => {
                            repContent.innerHTML = html;
                        })
                        .catch(() => {
                            repContent.innerHTML = "<div class='text-red-500'>Unable to load sales.</div>";
                        });
                }
            });

            // Close modals when clicking backdrop
            repModal.addEventListener('click', (event) => {
                if (event.target === repModal) {
                    closeModal(repModal);
                }
            });
            saleModal.addEventListener('click', (event) => {
                if (event.target === saleModal) {
                    closeModal(saleModal);
                }
            });
        });
    </script>
</body>

</html>