<?php
require_once '../auth.php';
requireLogin();

if (isset($_GET['logout'])) {
    logout();
}

// Security check - ensure user is sale_admin or admin
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'sale_admin' && $_SESSION['role'] !== 'admin')) {
    header('Location: /ref/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col">

    <?php include 'sales_header.php'; ?>

    <main class="flex-grow">
        <div class="max-w-5xl mx-auto py-12 px-6">

            <h1 class="text-3xl font-bold text-teal-800 mb-10 text-center flex items-center justify-center gap-2">
                <span data-feather="grid"></span>
                Sale Admin Dashboard
            </h1>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

                <a href="/ref/approve_sales.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-teal-50 transition group">
                    <div class="bg-green-100 text-green-700 rounded-full p-3 mr-4">
                        <span data-feather="check-square" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-green-600">Approve Sales</div>
                        <div class="text-gray-500 text-sm">Review and approve sale requests</div>
                    </div>
                </a>

                <a href="/ref/admin_sales_reports.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-teal-50 transition group">
                    <div class="bg-blue-100 text-blue-700 rounded-full p-3 mr-4">
                        <span data-feather="bar-chart-2" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-blue-600">Sales Reports</div>
                        <div class="text-gray-500 text-sm">View sales analytics & summaries</div>
                    </div>
                </a>

                <a href="/ref/sale_admin/confirm_sales.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-teal-50 transition group">
                    <div class="bg-purple-100 text-purple-700 rounded-full p-3 mr-4">
                        <span data-feather="check-circle" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-purple-600">Confirm Sales</div>
                        <div class="text-gray-500 text-sm">Confirm and verify sales</div>
                    </div>
                </a>

            </div>
        </div>
    </main>

    <?php include '../footer.php'; ?>
    <script>
        feather.replace();

        // Simple script to close the dropdown if clicking outside
        document.addEventListener('click', function (event) {
            var menu = document.getElementById('menu');
            if (menu) {
                var button = menu.previousElementSibling;
                if (!menu.classList.contains('hidden') && !menu.contains(event.target) && !button.contains(event.target)) {
                    menu.classList.add('hidden');
                }
            }
        });
    </script>
</body>

</html>