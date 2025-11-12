<?php
require_once '../auth.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

require_once '../config.php';

// --- Count statistics ---
$admin_count = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin'")->fetch_assoc()['c'];
$leader_count = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'representative'")->fetch_assoc()['c'];
$rep_count = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'rep'")->fetch_assoc()['c'];

// --- Search logic ---
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
// Default fetch (10 users on initial load, mobile-first ordering by latest)
if ($search === '') {
    $result = $mysqli->query("SELECT * FROM users ORDER BY id DESC LIMIT 10");
} else {
    // Broaden search to common fields (id, username, first/last name, email, nic_number, role)
    // Cast id to CHAR to allow partial matches
    $search_param = "%" . $search . "%";
    $stmt = $mysqli->prepare("
        SELECT *
        FROM users
        WHERE CAST(id AS CHAR) LIKE ?
           OR username LIKE ?
           OR first_name LIKE ?
           OR last_name LIKE ?
           OR email LIKE ?
           OR nic_number LIKE ?
           OR role LIKE ?
        ORDER BY id DESC
    ");
    $stmt->bind_param('sssssss', $search_param, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
}

// Handle AJAX requests for real-time search
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    $users = [];
    // Re-execute the query for AJAX requests to ensure fresh data
    if ($search === '') {
        // Show 10 users when search is empty
        $ajax_result = $mysqli->query("SELECT * FROM users ORDER BY id DESC LIMIT 10");
    } else {
        $search_param = "%" . $search . "%";
        $stmt = $mysqli->prepare("
            SELECT *
            FROM users
            WHERE CAST(id AS CHAR) LIKE ?
               OR username LIKE ?
               OR first_name LIKE ?
               OR last_name LIKE ?
               OR email LIKE ?
               OR nic_number LIKE ?
               OR role LIKE ?
            ORDER BY id DESC
        ");
        $stmt->bind_param('sssssss', $search_param, $search_param, $search_param, $search_param, $search_param, $search_param, $search_param);
        $stmt->execute();
        $ajax_result = $stmt->get_result();
    }

    if ($ajax_result->num_rows > 0) {
        while ($row = $ajax_result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    echo json_encode($users);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <!-- Add the responsive viewport tag -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

    <!-- Corrected the include path to match your other file -->
    <?php include '../admin_header.php'; ?>

    <!-- Main Content -->
    <!-- Added responsive padding (p-4 on mobile, p-10 on desktop) -->
    <main class="p-4 sm:p-10">
        <!-- Added responsive padding (p-4 on mobile, p-8 on desktop) -->
        <div class="max-w-6xl mx-auto bg-white p-4 sm:p-8 rounded-xl shadow-lg">

            <h2 class="text-3xl font-bold mb-8 text-slate-800">User Overview</h2>

            <!-- Stats Row - This is already responsive due to grid-cols-1 (default) and sm:grid-cols-3 -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                <div class="bg-indigo-100 text-indigo-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold">Admins</h3>
                    <p class="text-3xl font-bold mt-2"><?= $admin_count ?></p>
                </div>

                <div class="bg-amber-100 text-amber-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold">Representatives</h3>
                    <p class="text-3xl font-bold mt-2"><?= $leader_count ?></p>
                </div>

                <div class="bg-emerald-100 text-emerald-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold">Reps</h3>
                    <p class="text-3xl font-bold mt-2"><?= $rep_count ?></p>
                </div>
            </div>

            <!-- Search Bar -->
            <!-- Made the search bar stack on mobile (flex-col) and go side-by-side on desktop (sm:flex-row) -->
            <form method="get" class="flex flex-col sm:flex-row mb-6 gap-2 sm:gap-0" id="searchForm">
                <input type="text" name="search" id="searchInput" placeholder="Search by ID or name..."
                    value="<?= htmlspecialchars($search) ?>"
                    class="w-full px-4 py-2 border rounded-lg sm:rounded-r-none focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <button type="submit"
                    class="px-5 py-2 bg-indigo-600 text-white rounded-lg sm:rounded-l-none hover:bg-indigo-700 transition">Search</button>
            </form>

            <!-- Users Table -->
            <!-- 
                - Added a wrapper div with 'overflow-x-auto' to allow horizontal scrolling on small screens.
                - Moved border and rounded-lg to the wrapper div.
            -->
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                <table class="min-w-full">
                    <thead>
                        <tr class="bg-slate-200 text-left text-sm font-semibold text-slate-700">
                            <!-- Added 'whitespace-nowrap' to prevent headers from wrapping -->
                            <th class="p-3 border-b whitespace-nowrap">ID</th>
                            <th class="p-3 border-b whitespace-nowrap">Username</th>
                            <th class="p-3 border-b whitespace-nowrap">Role</th>
                            <th class="p-3 border-b whitespace-nowrap">Email</th>
                            <th class="p-3 border-b text-center whitespace-nowrap">Action</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-3 border-b"><?= $row['id'] ?></td>
                                    <td class="p-3 border-b"><?= htmlspecialchars($row['username']) ?></td>
                                    <td class="p-3 border-b capitalize">
                                        <span class="flex items-center gap-2">
                                            <?= htmlspecialchars($row['role']) ?>
                                            <?php if ($row['role'] === 'representative'): ?>
                                                <?php if ($row['status'] === 'active'): ?>
                                                    <span class="inline-block px-2 py-1 text-white rounded-full bg-green-500"
                                                        title="Active">Active</span>
                                                <?php else: ?>
                                                    <span class="inline-block px-2 py-1 text-white rounded-full bg-red-500"
                                                        title="Inactive">Inactive</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="p-3 border-b"><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                    <td class="p-3 border-b text-center">
                                        <a href="edit_users.php?id=<?= $row['id'] ?>"
                                            class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700 transition whitespace-nowrap">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <!-- Show a message if no users are found -->
                            <tr>
                                <td colspan="5" class="p-4 text-center text-gray-500">
                                    No users found matching your search.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
        // Real-time search functionality
        (function () {
            const searchInput = document.getElementById('searchInput');
            const tableBody = document.getElementById('usersTableBody');
            let debounceTimer;

            // Function to update the table with search results
            function updateTable(users) {
                if (users.length === 0) {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="5" class="p-4 text-center text-gray-500">
                                No users found matching your search.
                            </td>
                        </tr>
                    `;
                    return;
                }

                let html = '';
                users.forEach(user => {
                    let roleDisplay;
                    if (user.role === 'representative') {
                        const statusIndicator = user.status === 'active'
                            ? '<span class="inline-block rounded-full bg-green-500 px-2 py-1 text-white" title="Active">Active</span>'
                            : '<span class="inline-block rounded-full bg-red-500 px-2 py-1 text-white" title="Inactive">Inactive</span>';
                        roleDisplay = `<span class="flex items-center gap-2">${escapeHtml(user.role)} ${statusIndicator}</span>`;
                    } else {
                        roleDisplay = escapeHtml(user.role);
                    }
                    html += `
                        <tr class="hover:bg-slate-50 transition">
                            <td class="p-3 border-b">${user.id}</td>
                            <td class="p-3 border-b">${escapeHtml(user.username)}</td>
                            <td class="p-3 border-b capitalize">${roleDisplay}</td>
                            <td class="p-3 border-b">${escapeHtml(user.email || '-')}</td>
                            <td class="p-3 border-b text-center">
                                <a href="edit_users.php?id=${user.id}"
                                    class="px-3 py-1 bg-indigo-600 text-white rounded hover:bg-indigo-700 transition whitespace-nowrap">
                                    Edit
                                </a>
                            </td>
                        </tr>
                    `;
                });
                tableBody.innerHTML = html;
            }

            // Helper function to escape HTML
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // Function to perform search via AJAX
            function performSearch(searchTerm) {
                const url = new URL(window.location.href);
                url.searchParams.set('search', searchTerm);
                url.searchParams.set('ajax', '1');

                fetch(url.toString())
                    .then(response => response.json())
                    .then(users => {
                        updateTable(users);
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                    });
            }

            // Listen to input events with debouncing
            searchInput.addEventListener('input', function (e) {
                const searchTerm = e.target.value.trim();

                // Clear previous timer
                clearTimeout(debounceTimer);

                // Set a new timer to perform search after user stops typing (300ms delay)
                debounceTimer = setTimeout(() => {
                    performSearch(searchTerm);
                }, 300);
            });

            // Prevent form submission on Enter key (optional, but keeps the real-time feel)
            document.getElementById('searchForm').addEventListener('submit', function (e) {
                e.preventDefault();
                const searchTerm = searchInput.value.trim();
                performSearch(searchTerm);
            });
        })();
    </script>

</body>

</html>