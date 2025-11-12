<?php
// Require authentication and header
require_once '../auth.php';
requireLogin();

// Optionally, include your shared header for refs
include 'refs_header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ref Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col">

    <div class="max-w-5xl mx-auto py-12 px-6 flex-grow">
        <h1 class="text-3xl font-bold text-blue-800 mb-10 text-center flex items-center justify-center gap-2">
            <span data-feather="grid"></span>
            Dashboard Menu
        </h1>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <a href="add_sale.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-blue-100 text-blue-700 rounded-full p-3 mr-4">
                    <span data-feather="plus-circle" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-blue-600">Add a Sale</div>
                    <div class="text-gray-500 text-sm">Log a new sale</div>
                </div>
            </a>
            <a href="view_sales.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-yellow-100 text-yellow-700 rounded-full p-3 mr-4">
                    <span data-feather="file-text" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-yellow-600">View Sales</div>
                    <div class="text-gray-500 text-sm">All your logged sales</div>
                </div>
            </a>
            <a href="view_team.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-green-100 text-green-700 rounded-full p-3 mr-4">
                    <span data-feather="users" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-green-600">View Team</div>
                    <div class="text-gray-500 text-sm">See your team members</div>
                </div>
            </a>
            <a href="/ref/profile.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-purple-100 text-purple-700 rounded-full p-3 mr-4">
                    <span data-feather="user" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-purple-600">Profile</div>
                    <div class="text-gray-500 text-sm">View profile details</div>
                </div>
            </a>
            <a href="refs_report.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-red-100 text-red-700 rounded-full p-3 mr-4">
                    <span data-feather="bar-chart-2" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-red-600">Report</div>
                    <div class="text-gray-500 text-sm">Sales and performance report</div>
                </div>
            </a>
            <a href="refs_payment.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-indigo-50 group">
                <div class="bg-indigo-100 text-indigo-700 rounded-full p-3 mr-4">
                    <span data-feather="dollar-sign" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-indigo-600">Payment Overview</div>
                    <div class="text-gray-500 text-sm">Track your monthly payouts and statuses</div>
                </div>
            </a>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
    <?php include '../footer.php'; ?>

</body>

</html>