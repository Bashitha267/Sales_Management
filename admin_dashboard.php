<?php
require_once 'auth.php';
requireLogin();

if (isset($_GET['logout'])) {
    logout();
}

// --- FETCH ADMIN DETAILS ---
require_once 'config.php'; // Included config for database access
$admin_details = null;
if (isset($_SESSION['user_id'])) {
    $admin_id = (int) $_SESSION['user_id'];
    // Check for role = 'admin' to be safe
    $stmt = $mysqli->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND role = 'admin'");
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $admin_details = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
// --- END FETCH ---
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen">

    <?php include 'admin_header.php'; ?>

    <div class="max-w-6xl mx-auto py-12 px-6">

        <h1 class="text-3xl font-bold text-blue-800 mb-10 text-center flex items-center justify-center gap-2">
            <span data-feather="grid"></span>
            Admin Dashboard
        </h1>

        <!-- User Management Section -->
        <div class="mb-10">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <span data-feather="users"></span>
                User Management
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="users/add_new_user.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-indigo-100 text-indigo-700 rounded-full p-3 mr-4">
                        <span data-feather="user-plus" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-indigo-600">Add User</div>
                        <div class="text-gray-500 text-sm">Create a new system user</div>
                    </div>
                </a>


                <a href="users/view_users.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-purple-100 text-purple-700 rounded-full p-3 mr-4">
                        <span data-feather="user-check" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-purple-600">View and Manage System Users
                        </div>
                        <div class="text-gray-500 text-sm">View and manage system users</div>
                    </div>
                </a>

                <a href="upgrade_user.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-teal-100 text-teal-700 rounded-full p-3 mr-4">
                        <span data-feather="trending-up" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-teal-600">Upgrade Users</div>
                        <div class="text-gray-500 text-sm">Make active and upgrade to representative</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Sales Management Section -->
        <div class="mb-10">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <span data-feather="shopping-cart"></span>
                Sales Management
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="approve_sales.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-green-100 text-green-700 rounded-full p-3 mr-4">
                        <span data-feather="check-square" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-green-600">Approve Half Sales Requests</div>
                        <div class="text-gray-500 text-sm">Review and approve 'half' sale requests</div>
                    </div>
                </a>

                <a href="sale_admin/confirm_sales.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-purple-100 text-purple-700 rounded-full p-3 mr-4">
                        <span data-feather="check-circle" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-purple-600">Confirm and Verify Sales by Reps
                        </div>
                        <div class="text-gray-500 text-sm">Confirm and verify sales</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Inventory Management Section -->
        <div class="mb-10">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <span data-feather="package"></span>
                Inventory Management
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="items/create_item.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-yellow-100 text-yellow-700 rounded-full p-3 mr-4">
                        <span data-feather="package" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-yellow-600">Add New Inventory Item</div>
                        <div class="text-gray-500 text-sm">Add a new inventory item</div>
                    </div>
                </a>

                <a href="items/view_items.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-orange-100 text-orange-700 rounded-full p-3 mr-4">
                        <span data-feather="box" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-orange-600">View and Manage Inventory Items
                        </div>
                        <div class="text-gray-500 text-sm">View and manage inventory items</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Team Management Section -->
        <div class="mb-10">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <span data-feather="users"></span>
                Team Management
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="teams/view_teams.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-blue-100 text-blue-700 rounded-full p-3 mr-4">
                        <span data-feather="users" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-blue-600">View and Manage Teams</div>
                        <div class="text-gray-500 text-sm">View and manage existing teams</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Payments Section -->
        <div class="mb-10">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <span data-feather="dollar-sign"></span>
                Payments
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="rep_payments.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-green-100 text-green-700 rounded-full p-3 mr-4">
                        <span data-feather="dollar-sign" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-green-600">Weekly Direct Sales Points
                            Payments
                        </div>
                        <div class="text-gray-500 text-sm">Pay weekly direct sales points</div>
                    </div>
                </a>

                <a href="agency_bonus_payments.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-blue-100 text-blue-700 rounded-full p-3 mr-4">
                        <span data-feather="award" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-blue-600">Agency Bonus Payouts of the Month
                        </div>
                        <div class="text-gray-500 text-sm">Pay agency threshold bonuses of the month</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Reports Section -->
        <div class="mb-10">
            <h2 class="text-2xl font-semibold text-gray-700 mb-4 flex items-center gap-2">
                <span data-feather="bar-chart-2"></span>
                Reports & Analytics
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="admin_sales_reports.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-red-100 text-red-700 rounded-full p-3 mr-4">
                        <span data-feather="bar-chart-2" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-red-600">Sales Reports and Analytics</div>
                        <div class="text-gray-500 text-sm">View sales reports and analytics</div>
                    </div>
                </a>

                <a href="payment_report.php"
                    class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                    <div class="bg-red-100 text-red-700 rounded-full p-3 mr-4">
                        <span data-feather="bar-chart-2" class="w-7 h-7"></span>
                    </div>
                    <div>
                        <div class="text-lg font-semibold group-hover:text-red-600">Payment Reports and History</div>
                        <div class="text-gray-500 text-sm">View payment reports and history</div>
                    </div>
                </a>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
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