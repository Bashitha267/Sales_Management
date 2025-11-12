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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Team Leader Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen flex flex-col">

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-8 lg:py-12 flex-grow">
        <h1
            class="text-2xl sm:text-3xl lg:text-4xl font-bold text-blue-800 mb-6 sm:mb-8 lg:mb-10 text-center flex items-center justify-center gap-2">
            <span data-feather="users" class="w-6 h-6 sm:w-7 sm:h-7 lg:w-8 lg:h-8"></span>
            <span class="whitespace-nowrap">Team Leader Dashboard</span>
        </h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-4 sm:gap-5 lg:gap-6">
            <!-- <a href="add_new_reps.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-teal-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-teal-100 text-teal-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="user-plus" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-teal-600 truncate">Add New Rep
                    </div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">Add a new rep under me</div>
                </div>
            </a> -->
            <a href="/ref/refs/add_sale.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-blue-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-blue-100 text-blue-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="plus-circle" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-blue-600 truncate">Add a Sale</div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">Log a new sale</div>
                </div>
            </a>
            <a href="/ref/refs/view_sales.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-blue-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-yellow-100 text-yellow-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="file-text" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-yellow-600 truncate">View Sales
                    </div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">All your logged sales</div>
                </div>
            </a>

            <a href="view_team_sales.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-yellow-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-yellow-100 text-yellow-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="file-text" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-yellow-600 truncate">View Team Sales
                    </div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">All sales by your team members</div>
                </div>
            </a>

            <a href="manage_team.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-green-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-green-100 text-green-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="users" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-green-600 truncate">Manage Team
                    </div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">Add, view, or remove team members</div>
                </div>
            </a>

            <a href="/ref/profile.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-purple-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-purple-100 text-purple-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="user" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-purple-600 truncate">Profile</div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">View and edit your profile details</div>
                </div>
            </a>

            <a href="leader_report.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-red-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-red-100 text-red-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="bar-chart-2" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-red-600 truncate">Reports</div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">Team performance and points summary</div>
                </div>
            </a>

            <a href="leader_payments.php"
                class="flex items-center p-4 sm:p-5 lg:p-6 bg-white rounded-lg shadow-md transition-all duration-200 hover:bg-indigo-50 hover:shadow-lg active:scale-[0.98] group touch-manipulation">
                <div class="bg-indigo-100 text-indigo-700 rounded-full p-2.5 sm:p-3 mr-3 sm:mr-4 flex-shrink-0">
                    <span data-feather="dollar-sign" class="w-6 h-6 sm:w-7 sm:h-7"></span>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-base sm:text-lg font-semibold group-hover:text-indigo-600 truncate">Payment
                        Overview</div>
                    <div class="text-gray-500 text-xs sm:text-sm mt-0.5">Track your monthly payouts and statuses</div>
                </div>
            </a>



        </div>
    </div>

    <?php include '../footer.php'; ?>

    <script>
        feather.replace();
    </script>
</body>

</html>