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
    <title>Ref Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Feather icons CDN -->
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-3xl mx-auto py-12">
        <h1 class="text-3xl font-bold text-blue-800 mb-8 text-center flex items-center justify-center gap-2">
            <span data-feather="grid"></span>
            Ref Dashboard
        </h1>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <!-- Add a Sale -->
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
            <!-- View Sales -->
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
            <!-- View Team -->
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
            <!-- Profile -->
            <a href="profile.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-purple-100 text-purple-700 rounded-full p-3 mr-4">
                    <span data-feather="user" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-purple-600">Profile</div>
                    <div class="text-gray-500 text-sm">View and edit profile</div>
                </div>
            </a>
            <!-- Report -->
            <a href="report.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-red-100 text-red-700 rounded-full p-3 mr-4">
                    <span data-feather="bar-chart-2" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-red-600">Report</div>
                    <div class="text-gray-500 text-sm">Sales and performance report</div>
                </div>
            </a>
        </div>
    </div>
    <script>
        feather.replace();
    </script>
</body>

</html>