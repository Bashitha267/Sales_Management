<?php
// Require authentication and header
require_once '../auth.php';
requireLogin();

// Optionally include your shared header for team leaders
include 'leader_header.php';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Team Leader Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Feather icons CDN -->
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-4xl mx-auto py-12">
        <h1 class="text-3xl font-bold text-blue-800 mb-10 text-center flex items-center justify-center gap-2">
            <span data-feather="users"></span>
            Team Leader Dashboard
        </h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <a href="/ref/refs/add_sale.php"
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
            <a href="/ref/refs/view_sales.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-blue-50 group">
                <div class="bg-yellow-100 text-yellow-700 rounded-full p-3 mr-4">
                    <span data-feather="file-text" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-yellow-600">View Sales</div>
                    <div class="text-gray-500 text-sm">All your logged sales</div>
                </div>
            </a>

            <!-- View Team Sales -->
            <a href="view_team_sales.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-yellow-50 group">
                <div class="bg-yellow-100 text-yellow-700 rounded-full p-3 mr-4">
                    <span data-feather="file-text" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-yellow-600">View Team Sales</div>
                    <div class="text-gray-500 text-sm">All sales by your team members</div>
                </div>
            </a>

            <!-- Manage Team -->
            <a href="manage_team.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-green-50 group">
                <div class="bg-green-100 text-green-700 rounded-full p-3 mr-4">
                    <span data-feather="users" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-green-600">Manage Team</div>
                    <div class="text-gray-500 text-sm">Add, view, or remove team members</div>
                </div>
            </a>

            <!-- Profile -->
            <a href="/ref/profile.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-purple-50 group">
                <div class="bg-purple-100 text-purple-700 rounded-full p-3 mr-4">
                    <span data-feather="user" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-purple-600">Profile</div>
                    <div class="text-gray-500 text-sm">View and edit your profile details</div>
                </div>
            </a>

            <!-- Reports -->
            <a href="leader_report.php"
                class="flex items-center p-6 bg-white rounded-lg shadow transition hover:bg-red-50 group">
                <div class="bg-red-100 text-red-700 rounded-full p-3 mr-4">
                    <span data-feather="bar-chart-2" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-red-600">Reports</div>
                    <div class="text-gray-500 text-sm">Team performance and points summary</div>
                </div>
            </a>

            <!-- Payment Overview -->
            <a href="leader_payments.php"
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
</body>

</html>