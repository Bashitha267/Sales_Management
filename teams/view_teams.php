<?php
// Connect to DB
require_once '../config.php';
require_once '../auth.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

$search_id = '';
if (isset($_GET['search_teamid'])) {
    $search_id = trim($_GET['search_teamid']);
}

// Get statistics
$total_teams = $mysqli->query("SELECT COUNT(*) AS c FROM teams")->fetch_assoc()['c'];
$total_members = $mysqli->query("SELECT COUNT(*) AS c FROM team_members")->fetch_assoc()['c'];
$avg_members = $total_teams > 0 ? round($total_members / $total_teams, 1) : 0;

// Prepare SQL with JOIN to get leader's username and count members
$sql = "
    SELECT 
        t.team_id, 
        t.team_name, 
        t.leader_id, 
        u.username AS leader_name, 
        t.created_at, 
        (
            SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.team_id
        ) as member_count
    FROM teams t
    JOIN users u ON t.leader_id = u.id
";

$params = [];
if ($search_id !== '') {
    $sql .= " WHERE t.team_id = ?";
    $params[] = $search_id;
}
$sql .= " ORDER BY t.team_id DESC";

$stmt = $mysqli->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param('i', $params[0]);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Teams - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

    <?php include '../admin_header.php'; ?>

    <!-- Main Content -->
    <main class="p-4 sm:p-10">
        <div class="max-w-6xl mx-auto bg-white p-4 sm:p-8 rounded-xl shadow-lg">

            <!-- Page Header -->
            <div class="mb-8">
                <h2 class="text-3xl font-bold text-slate-800 flex items-center gap-3 mb-2">
                    <img src="https://img.icons8.com/fluency-systems-regular/48/4f46e5/groups.png" class="w-8 h-8" />
                    Teams Overview
                </h2>
                <p class="text-slate-600 mt-2">Manage and view all teams in the system</p>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                <div class="bg-indigo-100 text-indigo-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold mb-2">Total Teams</h3>
                    <p class="text-3xl font-bold"><?= $total_teams ?></p>
                </div>

                <div class="bg-emerald-100 text-emerald-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold mb-2">Total Members</h3>
                    <p class="text-3xl font-bold"><?= $total_members ?></p>
                </div>

                <div class="bg-amber-100 text-amber-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold mb-2">Avg. Members/Team</h3>
                    <p class="text-3xl font-bold"><?= $avg_members ?></p>
                </div>
            </div>

            <!-- Search Bar -->
            <form method="get" class="flex flex-col sm:flex-row mb-6 gap-2 sm:gap-0">
                <input type="text" name="search_teamid" value="<?= htmlspecialchars($search_id) ?>"
                    placeholder="Search by Team ID..."
                    class="flex-1 px-4 py-2.5 border border-slate-300 rounded-lg sm:rounded-r-none focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-slate-700">
                <button type="submit"
                    class="px-6 py-2.5 bg-indigo-600 text-white rounded-lg sm:rounded-l-none hover:bg-indigo-700 transition font-medium">
                    Search
                </button>
            </form>

            <!-- Teams Table -->
            <div class="overflow-x-auto border border-gray-200 rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-slate-200">
                        <tr>
                            <th
                                class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider whitespace-nowrap">
                                Team ID
                            </th>
                            <th
                                class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider whitespace-nowrap">
                                Team Name
                            </th>
                            <th
                                class="px-6 py-4 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider whitespace-nowrap">
                                Leader
                            </th>
                            <th
                                class="px-6 py-4 text-center text-xs font-semibold text-slate-700 uppercase tracking-wider whitespace-nowrap">
                                Members
                            </th>
                            <th
                                class="px-6 py-4 text-center text-xs font-semibold text-slate-700 uppercase tracking-wider whitespace-nowrap">
                                Action
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">
                                        <?= $row['team_id'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-700">
                                        <?= htmlspecialchars($row['team_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-700">
                                        <?= htmlspecialchars($row['leader_name']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <span
                                            class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 text-indigo-800">
                                            <?= (int) $row['member_count'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                        <a href="edit_teams.php?team_id=<?= $row['team_id'] ?>"
                                            class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition font-medium text-sm">
                                            Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center text-gray-500">
                                        <img src="https://img.icons8.com/fluency-systems-regular/64/9ca3af/groups.png"
                                            class="w-16 h-16 mb-3 opacity-50" />
                                        <p class="text-lg font-medium">No teams found</p>
                                        <p class="text-sm mt-1">
                                            <?= $search_id !== '' ? 'Try a different search term.' : 'No teams have been created yet.' ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>

</html>

<?php
$stmt->close();
$mysqli->close();
?>