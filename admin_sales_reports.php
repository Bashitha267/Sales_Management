<?php
session_start();
include 'config.php';
include 'admin_header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

// Ensure only admin can access
$user_id = $_SESSION['user_id'];
$role_q = $mysqli->prepare("SELECT role FROM users WHERE id=?");
$role_q->bind_param('i', $user_id);
$role_q->execute();
$role = $role_q->get_result()->fetch_assoc()['role'] ?? '';
$role_q->close();

if ($role !== 'admin') {
    echo "<div class='text-center text-red-600 font-bold mt-10'>Access Denied: Admins only.</div>";
    exit();
}

// --- Get available years ---
$years = [];
$res = $mysqli->query("SELECT DISTINCT YEAR(sale_date) AS y FROM sales_log ORDER BY y DESC");
while ($r = $res->fetch_assoc())
    $years[] = (int) $r['y'];
if (empty($years))
    $years[] = (int) date('Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Admin Sales Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-6xl mx-auto px-6 py-10">
        <h1 class="text-3xl font-bold text-blue-800 mb-6">Sales Report (Admin)</h1>

        <!-- Filter Form -->
        <form id="filterForm" class="mb-6 flex flex-wrap gap-4">
            <label class="font-semibold">Year:</label>
            <select name="year" id="yearSelect" class="border rounded px-3 py-2">
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="search" id="searchBox" value="" placeholder="Search by ID or Name"
                class="border rounded px-4 py-2 flex-grow min-w-[250px]">
        </form>

        <!-- Results Table (loaded via AJAX) -->
        <div id="results">
            <?php
            $_GET['year'] = date('Y');
            $_GET['search'] = '';
            include 'admin_sales_table.php';
            ?>
        </div>
    </div>

    <!-- AJAX Script -->
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
        });
    </script>
</body>

</html>