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

    <nav class="bg-indigo-600 text-white px-6 py-4 shadow-md">
        <div class="max-w-6xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-2">
                <span data-feather="settings" class="w-6 h-6"></span>
                <h1 class="text-xl font-semibold">Admin Portal</h1>
            </div>

            <div class="relative">
                <button onclick="document.getElementById('menu').classList.toggle('hidden')"
                    class="flex items-center gap-2 hover:text-gray-200 transition">
                    <span class="hidden sm:inline font-medium"><?php echo $_SESSION['username']; ?></span>
                    <span data-feather="user"></span>
                    <span data-feather="chevron-down" class="w-4 h-4"></span>
                </button>

                <div id="menu"
                    class="hidden absolute right-0 mt-3 w-48 bg-white text-gray-700 rounded-md shadow-md border">
                    <?php if ($admin_details): ?>
                        <div class="px-4 py-3 border-b">
                            <p class="text-sm font-semibold text-gray-900 truncate"
                                title="<?= htmlspecialchars($admin_details['first_name'] . ' ' . $admin_details['last_name']) ?>">
                                <?= htmlspecialchars($admin_details['first_name'] . ' ' . $admin_details['last_name']) ?>
                            </p>
                            <p class="text-xs text-gray-500">
                                Admin ID: <?= htmlspecialchars($admin_details['id']) ?>
                            </p>
                        </div>
                    <?php endif; ?>
                    <a href="profile.php" class="flex items-center px-4 py-2 hover:bg-gray-100">
                        <span data-feather="user" class="w-4 h-4 mr-2"></span> Profile
                    </a>
                    <a href="?logout=1" class="flex items-center px-4 py-2 hover:bg-gray-100">
                        <span data-feather="log-out" class="w-4 h-4 mr-2"></span> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-5xl mx-auto py-12 px-6">

        <h1 class="text-3xl font-bold text-blue-800 mb-10 text-center flex items-center justify-center gap-2">
            <span data-feather="grid"></span>
            Admin Dashboard
        </h1>

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

            <a href="approve_sales.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                <div class="bg-green-100 text-green-700 rounded-full p-3 mr-4">
                    <span data-feather="check-square" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-green-600">Approve Sales</div>
                    <div class="text-gray-500 text-sm">Review 'half' sale requests</div>
                </div>
            </a>

            <a href="items/create_item.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                <div class="bg-yellow-100 text-yellow-700 rounded-full p-3 mr-4">
                    <span data-feather="package" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-yellow-600">Add Item</div>
                    <div class="text-gray-500 text-sm">Add new inventory item</div>
                </div>
            </a>

            <a href="users/view_users.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                <div class="bg-purple-100 text-purple-700 rounded-full p-3 mr-4">
                    <span data-feather="user-check" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-purple-600">View Users</div>
                    <div class="text-gray-500 text-sm">Edit or remove users</div>
                </div>
            </a>

            <a href="teams/view_teams.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                <div class="bg-blue-100 text-blue-700 rounded-full p-3 mr-4">
                    <span data-feather="users" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-blue-600">View Teams</div>
                    <div class="text-gray-500 text-sm">Manage existing teams</div>
                </div>
            </a>

            <a href="items/view_items.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                <div class="bg-orange-100 text-orange-700 rounded-full p-3 mr-4">
                    <span data-feather="box" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-orange-600">View Items</div>
                    <div class="text-gray-500 text-sm">Edit inventory items</div>
                </div>
            </a>

            <a href="rep_payments.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                <div class="bg-green-100 text-green-700 rounded-full p-3 mr-4">
                    <span data-feather="dollar-sign" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-green-600">Weekly Payments</div>
                    <div class="text-gray-500 text-sm">Pay weekly personal points</div>
                </div>
            </a>

            <a href="agency_bonus_payments.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-50 transition group">
                <div class="bg-blue-100 text-blue-700 rounded-full p-3 mr-4">
                    <span data-feather="award" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-blue-600">Agency Bonus</div>
                    <div class="text-gray-500 text-sm">Pay agency threshold bonuses</div>
                </div>
            </a>

            <a href="admin_sales_reports.php"
                class="flex items-center p-6 bg-white rounded-lg shadow hover:bg-blue-5D0 transition group">
                <div class="bg-red-100 text-red-700 rounded-full p-3 mr-4">
                    <span data-feather="bar-chart-2" class="w-7 h-7"></span>
                </div>
                <div>
                    <div class="text-lg font-semibold group-hover:text-red-600">Reports</div>
                    <div class="text-gray-500 text-sm">View analytics & summaries</div>
                </div>
            </a>
        </div>
    </div>

    <script>
        feather.replace();

        // Simple script to close the dropdown if clicking outside
        document.addEventListener('click', function (event) {
            var menu = document.getElementById('menu');
            var button = menu.previousElementSibling;
            if (!menu.classList.contains('hidden') && !menu.contains(event.target) && !button.contains(event.target)) {
                menu.classList.add('hidden');
            }
        });
    </script>
</body>

</html>