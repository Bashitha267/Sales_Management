<?php
// leader_report.php
include '../config.php';       // gives $conn (MySQLi)
include 'leader_header.php';   // session + auth check

$leader_id = $_SESSION['user_id'] ?? null;
if (!$leader_id) {
    header("Location: ../login.php");
    exit;
}

// --- Get Years from sales involving teams led by this leader or their own rep sales ---
$year_options = [];
$sql_years = "
    SELECT DISTINCT YEAR(sale_date) as year
    FROM sales_log s
    LEFT JOIN teams t ON s.team_id = t.team_id
    WHERE t.leader_id = ? OR s.ref_id = ?
    ORDER BY year DESC
";
$stmt = $mysqli->prepare($sql_years);
$stmt->bind_param("ii", $leader_id, $leader_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $year_options[] = $row['year'];
}
$stmt->close();

$selected_year = $_GET['year'] ?? (count($year_options) > 0 ? $year_options[0] : date('Y'));


// --- Get months found for selected year ---
$months = [];
for ($i = 1; $i <= 12; $i++) {
    $months[] = $i;
}
// --- Get teams led by this leader ---
$teams = [];
$sql_teams = "SELECT team_id, team_name FROM teams WHERE leader_id = ?";
$stmt = $mysqli->prepare($sql_teams);
$stmt->bind_param("i", $leader_id);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
    $teams[] = $r;
}
$stmt->close();

// Don't show report if no team
// ----- Get each team's monthly points (team & leader points)
$team_data = [];
foreach ($teams as $team) {
    $team_id = $team['team_id'];
    $team_data[$team_id] = [
        'team_name' => $team['team_name'],
        'months' => [], // Will fill per month
    ];

    // for all 12 months of year, query sum points
    foreach ($months as $m) {
        // Team total points from all sales on the team (by anyone in the team in this team_id)
        $sql_team_pts = "
            SELECT 
                SUM(sd.qty * i.points_leader) AS sum_pts
            FROM sales_log sl
            JOIN sale_details sd ON sl.sale_id = sd.sale_id
            JOIN items i ON sd.item_code = i.item_code
            WHERE sl.team_id = ? 
              AND YEAR(sl.sale_date) = ? 
              AND MONTH(sl.sale_date) = ?
        ";
        $stmt = $mysqli->prepare($sql_team_pts);
        $stmt->bind_param("iii", $team_id, $selected_year, $m);
        $stmt->execute();
        $stmt->bind_result($team_pts);
        $stmt->fetch();
        $stmt->close();
        $team_pts = $team_pts ? intval($team_pts) : 0;

        // Leader's direct points: sum points for sales in this team_id whose ref_id is the leader
        $sql_leader_pts = "
            SELECT
                SUM(sd.qty * i.points_leader) AS sum_pts
            FROM sales_log sl
            JOIN sale_details sd ON sl.sale_id = sd.sale_id
            JOIN items i ON sd.item_code = i.item_code
            WHERE sl.team_id = ? 
              AND sl.ref_id = ?
              AND YEAR(sl.sale_date) = ? 
              AND MONTH(sl.sale_date) = ?
        ";
        $stmt = $mysqli->prepare($sql_leader_pts);
        $stmt->bind_param("iiii", $team_id, $leader_id, $selected_year, $m);
        $stmt->execute();
        $stmt->bind_result($leader_pts);
        $stmt->fetch();
        $stmt->close();
        $leader_pts = $leader_pts ? intval($leader_pts) : 0;

        $team_data[$team_id]['months'][$m] = [
            'team_pts' => $team_pts,
            'leader_pts' => $leader_pts
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Team Performance Points Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-7xl mx-auto py-10">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-blue-800">Team Points Report</h1>
            <a href="leader_dashboard.php" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                ← Back to Dashboard
            </a>
        </div>
        <!-- Year Filter -->
        <form method="get" class="mb-8 flex gap-2 items-center">
            <label for="year" class="font-medium">Select Year:</label>
            <select name="year" id="year" class="border p-2 rounded">
                <?php foreach ($year_options as $y): ?>
                    <option value="<?= htmlspecialchars($y) ?>" <?= $y == $selected_year ? 'selected' : '' ?>>
                        <?= htmlspecialchars($y) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Filter</button>
        </form>

        <?php if (empty($teams)): ?>
            <div class="bg-white p-8 rounded shadow text-center text-gray-600 mb-8">
                You don't lead any teams currently.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full table-auto border text-xs sm:text-sm">
                    <thead class="bg-blue-50 text-blue-700">
                        <tr>
                            <th class="p-3 border sticky left-0 bg-blue-50 z-10">Team Name</th>
                            <?php foreach ($months as $m): ?>
                                <th class="p-3 border"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></th>
                            <?php endforeach; ?>
                            <th class="p-3 border bg-blue-100">Year Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_data as $team_id => $data):
                            $year_total_team = 0;
                            $year_total_leader = 0;
                            ?>
                            <tr class="even:bg-gray-50 hover:bg-yellow-50">
                                <td class="p-3 border font-semibold sticky left-0 bg-white z-10 whitespace-nowrap">
                                    <?= htmlspecialchars($data['team_name']) ?>
                                </td>
                                <?php foreach ($months as $m):
                                    $tp = $data['months'][$m]['team_pts'];
                                    $lp = $data['months'][$m]['leader_pts'];
                                    $year_total_team += $tp;
                                    $year_total_leader += $lp;
                                    ?>
                                    <td class="p-2 border text-center <?= $tp > 0 ? 'bg-green-50' : '' ?>">
                                        <span class="font-bold"><?= $tp ?></span>
                                        <?php if ($lp > 0): ?>
                                            <br>
                                            <span class="text-xs text-blue-600">(You: <?= $lp ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="p-3 border bg-blue-100 font-semibold text-center">
                                    <?= $year_total_team ?>
                                    <?php if ($year_total_leader > 0): ?>
                                        <br>
                                        <span class="text-xs text-blue-700">(You: <?= $year_total_leader ?>)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</body>

</html>