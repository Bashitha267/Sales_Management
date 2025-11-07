<?php
require_once '../auth.php';
requireLogin();
include '../config.php';

$leader_id = $_SESSION['user_id'] ?? 0;

// Fetch all teams under this leader
$teams = [];
$q = $mysqli->prepare("SELECT team_id, team_name FROM teams WHERE leader_id = ?");
$q->bind_param('i', $leader_id);
$q->execute();
$res = $q->get_result();
while ($row = $res->fetch_assoc()) {
    $teams[] = $row;
}
$q->close();

// === AJAX: Team points data ===
if (isset($_GET['team_id']) && !isset($_GET['member_id'])) {
    $team_id = intval($_GET['team_id']);
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');

    // 1. Get all members of this team
    $members = [];
    $stmt = $mysqli->prepare("
        SELECT tm.member_id, u.first_name, u.last_name
        FROM team_members tm
        JOIN users u ON u.id = tm.member_id
        WHERE tm.team_id = ?
    ");
    $stmt->bind_param('i', $team_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $members[$row['member_id']] = $row['first_name'] . ' ' . $row['last_name'];
    }
    $stmt->close();

    $points = [];
    $leader_points = 0; // Initialize leader points

    if (!empty($members)) {
        // Create a string of member IDs for the IN () clause
        $ids = implode(',', array_map('intval', array_keys($members)));

        // 2. Get REP points for each member
        $sql_rep = "
            SELECT sl.ref_id AS member_id, SUM(sd.qty * i.points_rep) AS total_points
            FROM sales_log sl
            JOIN sale_details sd ON sl.sale_id = sd.sale_id
            JOIN items i ON sd.item_code = i.item_code
            WHERE sl.ref_id IN ($ids)
            AND YEAR(sl.sale_date) = $year
            AND MONTH(sl.sale_date) = $month
            GROUP BY sl.ref_id
        ";
        $res_rep = $mysqli->query($sql_rep);
        while ($r = $res_rep->fetch_assoc()) {
            $points[$r['member_id']] = (int) $r['total_points'];
        }

        // 3. Get total LEADER points from all member sales
        $sql_leader = "
            SELECT SUM(sd.qty * i.points_leader) AS total_leader_points
            FROM sales_log sl
            JOIN sale_details sd ON sl.sale_id = sd.sale_id
            JOIN items i ON sd.item_code = i.item_code
            WHERE sl.ref_id IN ($ids)
            AND YEAR(sl.sale_date) = $year
            AND MONTH(sl.sale_date) = $month
        ";
        $res_leader = $mysqli->query($sql_leader);
        $row_leader = $res_leader->fetch_assoc();
        $leader_points = (int) ($row_leader['total_leader_points'] ?? 0);
    }

    header('Content-Type: application/json');
    // 4. Return all data
    echo json_encode(['members' => $members, 'points' => $points, 'leader_points' => $leader_points]);
    exit;
}

// === AJAX: Member sales data ===
if (isset($_GET['member_id'])) {
    $member_id = intval($_GET['member_id']);
    $year = intval($_GET['year']);
    $month = intval($_GET['month']);

    $sales = [];
    $stmt = $mysqli->prepare("
        SELECT i.item_name, sd.qty, (sd.qty * i.points_rep) AS points, sl.sale_date
        FROM sales_log sl
        JOIN sale_details sd ON sl.sale_id = sd.sale_id
        JOIN items i ON sd.item_code = i.item_code
        WHERE sl.ref_id = ? AND YEAR(sl.sale_date) = ? AND MONTH(sl.sale_date) = ?
        ORDER BY sl.sale_date DESC
    ");
    $stmt->bind_param('iii', $member_id, $year, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $sales[] = $r;
    }
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode($sales);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Team Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <?php include 'leader_header.php' ?>
    <div class="max-w-6xl mx-auto py-10 px-6">
        <h1 class="text-3xl font-bold text-blue-800 mb-10 text-center flex items-center justify-center gap-2">
            <span data-feather="users"></span> My Teams
        </h1>

        <?php if (empty($teams)): ?>
            <p class="text-center text-gray-600">You are not leading any teams yet.</p>
        <?php else: ?>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($teams as $team): ?>
                    <div class="bg-white rounded-2xl shadow hover:shadow-lg transition cursor-pointer border border-gray-100 p-6 text-center"
                        onclick="openModal(<?= $team['team_id'] ?>, '<?= htmlspecialchars($team['team_name']) ?>')">
                        <div class="bg-blue-100 text-blue-600 rounded-full p-4 inline-block mb-3">
                            <i data-feather="layers"></i>
                        </div>
                        <h2 class="text-lg font-semibold"><?= htmlspecialchars($team['team_name']) ?></h2>
                        <p class="text-gray-600 text-sm mt-1">Click to view member sales</p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="teamModal" class="fixed inset-0 hidden bg-black/50 z-50 items-center justify-center">
        <div
            class="bg-white rounded-2xl shadow-lg w-full max-w-4xl max-h-[85vh] overflow-y-auto relative p-6 animate-fadeIn">
            <button onclick="closeModal()" class="absolute top-3 right-3 text-gray-600 hover:text-black">
                <i data-feather="x"></i>
            </button>
            <h2 id="modalTitle" class="text-2xl font-semibold text-blue-800 mb-6 flex items-center gap-2">
                <span data-feather="bar-chart-2"></span> Team Report
            </h2>

            <form id="filterForm" class="flex flex-wrap justify-center gap-4 mb-6">
                <input type="hidden" id="team_id" name="team_id">
                <div>
                    <label class="block text-gray-700 text-sm mb-1">Select Year</label>
                    <select id="year" name="year" class="border rounded px-3 py-2">
                        <?php for ($y = date('Y') - 3; $y <= date('Y') + 1; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 text-sm mb-1">Select Month</label>
                    <select id="month" name="month" class="border rounded px-3 py-2">
                        <?php
                        $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];
                        foreach ($months as $num => $name): ?>
                            <option value="<?= $num ?>" <?= $num == date('n') ? 'selected' : '' ?>><?= $name ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit"
                        class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700">Filter</button>
                </div>
            </form>

            <div id="teamData" class="bg-gray-50 rounded-lg p-4">
                <p class="text-center text-gray-500">Select filters to load data...</p>
            </div>
        </div>
    </div>

    <div id="salesModal" class="fixed inset-0 hidden bg-black/50 z-50 items-center justify-center">
        <div
            class="bg-white rounded-2xl shadow-lg w-full max-w-2xl max-h-[80vh] overflow-y-auto relative p-6 animate-fadeIn">
            <button onclick="closeSalesModal()" class="absolute top-3 right-3 text-gray-600 hover:text-black">
                <i data-feather="x"></i>
            </button>
            <h3 id="salesTitle" class="text-xl font-semibold text-blue-800 mb-4 flex items-center gap-2">
                <span data-feather="shopping-cart"></span> Member Sales
            </h3>
            <div id="salesContent" class="text-sm text-gray-700">
                <p class="text-center text-gray-500">Loading...</p>
            </div>
        </div>
    </div>

    <script>
        feather.replace();

        function openModal(id, name) {
            const modal = document.getElementById('teamModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('modalTitle').innerHTML = `<span data-feather="bar-chart-2"></span> ${name}`;
            document.getElementById('team_id').value = id;
            feather.replace();
            loadTeamData(id);
        }
        function closeModal() {
            const modal = document.getElementById('teamModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('filterForm').addEventListener('submit', function (e) {
            e.preventDefault();
            loadTeamData(document.getElementById('team_id').value);
        });

        function loadTeamData(teamId) {
            const year = document.getElementById('year').value;
            const month = document.getElementById('month').value;
            fetch(`?team_id=${teamId}&year=${year}&month=${month}`)
                .then(r => r.json())
                .then(d => {
                    let html = '';
                    if (Object.keys(d.members).length === 0) {
                        html = `<p class="text-center text-gray-600 py-4">No members found.</p>`;
                    } else {

                        // --- UPDATED: Add Leader Points Summary Box ---
                        const leaderPts = d.leader_points ?? 0;
                        const leaderVal = (leaderPts * 0.05).toFixed(2); // Using the same 0.05 rate

                        html += `<div class="bg-blue-800 text-white p-4 rounded-lg shadow-lg mb-5 text-center">
                                    <h4 class="text-sm font-semibold uppercase tracking-wider text-blue-200">Your Leader Points (from this team)</h4>
                                    <p class="text-3xl font-bold mt-1">${leaderPts}</p>
                                    <p class="text-blue-300 text-sm">Approx. $${leaderVal}</p>
                                 </div>`;
                        // --- End of new block ---

                        // This is the original table code
                        html += `<table class="min-w-full border-collapse bg-white rounded-lg shadow">
                                    <thead class="bg-blue-100 text-blue-900">
                                        <tr>
                                            <th class="px-4 py-3 text-left">Member</th>
                                            <th class="px-4 py-3 text-center">Total Points</th>
                                            <th class="px-4 py-3 text-center">Approx ($)</th>
                                            <th class="px-4 py-3 text-center">Action</th>
                                        </tr>
                                    </thead><tbody class="divide-y divide-gray-200">`;
                        for (const [id, name] of Object.entries(d.members)) {
                            const pts = d.points[id] ?? 0;
                            const val = (pts * 0.05).toFixed(2);
                            html += `<tr class="${pts > 0 ? 'bg-green-50' : ''}">
                                        <td class="px-4 py-3">${name}</td>
                                        <td class="px-4 py-3 text-center font-semibold">${pts}</td>
                                        <td class="px-4 py-3 text-center">$${val}</td>
                                        <td class="px-4 py-3 text-center">
                                            <button onclick="viewSales(${id}, '${name}', ${year}, ${month})"
                                                class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600">View</button>
                                        </td></tr>`;
                        }
                        html += `</tbody></table>`;
                    }
                    document.getElementById('teamData').innerHTML = html;
                });
        }

        // View Member Sales
        function viewSales(memberId, name, year, month) {
            const modal = document.getElementById('salesModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('salesTitle').innerHTML = `<span data-feather="shopping-cart"></span> ${name}'s Sales`;
            document.getElementById('salesContent').innerHTML = `<p class='text-center text-gray-500'>Loading...</p>`;
            feather.replace(); // <-- Add this to draw the icon
            fetch(`?member_id=${memberId}&year=${year}&month=${month}`)
                .then(r => r.json())
                .then(sales => {
                    if (sales.length === 0) {
                        document.getElementById('salesContent').innerHTML = `<p class='text-center text-gray-600 py-3'>No sales found.</p>`;
                    } else {
                        let html = `<table class='min-w-full border-collapse bg-white rounded shadow'>
                                    <thead class='bg-blue-100 text-blue-900'>
                                        <tr><th class='px-4 py-2 text-left'>Item</th><th class='px-4 py-2 text-center'>Qty</th><th class='px-4 py-2 text-center'>Points</th><th class='px-4 py-2 text-center'>Date</th></tr>
                                    </thead><tbody class='divide-y divide-gray-200'>`;
                        for (const s of sales) {
                            html += `<tr><td class='px-4 py-2'>${s.item_name}</td><td class='px-4 py-2 text-center'>${s.qty}</td><td class='px-4 py-2 text-center'>${s.points}</td><td class='px-4 py-2 text-center'>${s.sale_date}</td></tr>`;
                        }
                        html += `</tbody></table>`;
                        document.getElementById('salesContent').innerHTML = html;
                    }
                    // feather.replace(); // <-- This was in the wrong place
                });
        }

        function closeSalesModal() {
            const modal = document.getElementById('salesModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>

    <style>
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fadeIn {
            animation: fadeIn 0.3s ease;
        }
    </style>
</body>

</html>