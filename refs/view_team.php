<?php
session_start();
include '../config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// --- Find Agency assignment(s) for this rep ---
$agencyTeams = [];
$agencyStmt = $mysqli->prepare("
    SELECT ar.agency_id,
           ag.agency_name,
           ag.representative_id,
           rep_user.first_name AS representative_first_name,
           rep_user.last_name AS representative_last_name
    FROM agency_reps ar
    INNER JOIN agencies ag ON ag.id = ar.agency_id
    LEFT JOIN users rep_user ON rep_user.id = ag.representative_id
    WHERE ar.rep_user_id = ?
    ORDER BY ag.agency_name
");

if ($agencyStmt) {
    $agencyStmt->bind_param('i', $user_id);
    $agencyStmt->execute();
    $agencyResult = $agencyStmt->get_result();

    while ($agencyRow = $agencyResult->fetch_assoc()) {
        $agencyId = (int) $agencyRow['agency_id'];
        $agencyTeams[$agencyId] = [
            'agency_id' => $agencyId,
            'agency_name' => $agencyRow['agency_name'] ?? '',
            'representative_id' => isset($agencyRow['representative_id']) ? (int) $agencyRow['representative_id'] : null,
            'representative_name' => trim(
                ($agencyRow['representative_first_name'] ?? '') . ' ' . ($agencyRow['representative_last_name'] ?? '')
            ),
            'members' => []
        ];
    }

    $agencyStmt->close();

    if (!empty($agencyTeams)) {
        $membersStmt = $mysqli->prepare("
            SELECT ar.agency_id,
                   u.id,
                   u.first_name,
                   u.last_name,
                   u.username
            FROM agency_reps ar
            INNER JOIN users u ON u.id = ar.rep_user_id
            WHERE ar.agency_id = ?
            ORDER BY u.first_name, u.last_name
        ");

        if ($membersStmt) {
            foreach ($agencyTeams as $agencyId => &$agencyData) {
                $membersStmt->bind_param('i', $agencyId);
                $membersStmt->execute();
                $membersResult = $membersStmt->get_result();

                while ($memberRow = $membersResult->fetch_assoc()) {
                    $agencyData['members'][] = $memberRow;
                }
            }
            unset($agencyData);
            $membersStmt->close();
        }
    }

    // --- Calculate monthly points for each member in the agency (FULL SALES ONLY) ---
    foreach ($agencyTeams as &$agency) {
        foreach ($agency['members'] as &$member) {
            $repId = (int) $member['id'];
            $agencyRepId = (int) $agency['representative_id'];

            // Monthly points query
            // MODIFIED: Added "AND s.sale_type = 'full'" to only count full sales
            $pointsStmt = $mysqli->prepare("
                SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS month,
                       IFNULL(SUM(si.quantity * i.rep_points),0) AS total_points
                FROM sales s
                INNER JOIN sale_items si ON si.sale_id = s.id
                INNER JOIN items i ON i.id = si.item_id
                WHERE s.rep_user_id = ? 
                  AND s.rep_user_id IN (SELECT rep_user_id FROM agency_reps WHERE representative_id = ?)
                  AND s.sale_type = 'full'
                GROUP BY month
                ORDER BY month DESC
            ");
            $pointsStmt->bind_param('ii', $repId, $agencyRepId);
            $pointsStmt->execute();
            $pointsResult = $pointsStmt->get_result();

            $monthlyPoints = [];
            while ($row = $pointsResult->fetch_assoc()) {
                $monthlyPoints[$row['month']] = (int) $row['total_points'];
            }
            $member['monthly_points'] = $monthlyPoints;
            $pointsStmt->close();
        }
        unset($member);
    }
    unset($agency);
}

// --- Find Teams (if table exists) ---
$teams = [];
$teamsTableExists = false;
try {
    $checkTeamsTable = $mysqli->query("SHOW TABLES LIKE 'teams'");
    if ($checkTeamsTable) {
        $teamsTableExists = $checkTeamsTable->num_rows > 0;
        $checkTeamsTable->free();
    }
} catch (mysqli_sql_exception $e) {
    $teamsTableExists = false;
}

if ($teamsTableExists) {
    $stmt = $mysqli->prepare("
        SELECT t.team_id, t.team_name, t.leader_id, t.created_at
        FROM teams t
        LEFT JOIN team_members tm ON t.team_id = tm.team_id
        WHERE t.leader_id = ? OR tm.member_id = ?
        GROUP BY t.team_id
    ");
    if ($stmt) {
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $teams[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>My Agency & Teams</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-gray-100 min-h-screen">
    <?php include "refs_header.php" ?>
    
    <div class="max-w-6xl mx-auto p-4 sm:p-6 lg:p-10">
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <div class="lg:col-span-2 space-y-8">
                <h1 class="text-2xl sm:text-3xl font-bold text-blue-800">My Agency</h1>

                <?php if (empty($agencyTeams)): ?>
                    <div class="p-6 bg-white rounded-lg shadow text-gray-500 text-center">
                        You are not currently assigned to an agency team.
                    </div>
                <?php else: ?>
                    <?php foreach ($agencyTeams as $agency): ?>
                        <div class="bg-white rounded-xl shadow-lg overflow-hidden border-t-4 border-blue-600">
                            <div class="p-5 sm:p-6">
                                <div class="flex flex-col sm:flex-row justify-between sm:items-start gap-2">
                                    <div>
                                        <h2 class="text-xl sm:text-2xl font-semibold text-blue-800">
                                            <?= htmlspecialchars($agency['agency_name']) ?>
                                        </h2>
                                    </div>
                                    <span class="text-xs font-medium bg-blue-100 text-blue-700 px-3 py-1 rounded-full self-start">
                                        Agency ID: <?= (int) $agency['agency_id'] ?>
                                    </span>
                                </div>

                                <div class="mt-4 bg-gray-50 rounded-lg p-4">
                                    <span class="text-sm font-semibold text-gray-600 block">REPRESENTATIVE</span>
                                    <?php $repName = trim($agency['representative_name'] ?? ''); ?>
                                    <span class="text-lg font-medium text-gray-900">
                                        <?= $repName !== '' ? htmlspecialchars($repName) : 'N/A' ?>
                                        <?php if (!empty($agency['representative_id'])): ?>
                                            <span class="text-gray-500 text-sm font-normal">(ID: <?= (int) $agency['representative_id'] ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <div class="mt-6">
                                    <h3 class="text-lg font-semibold text-blue-700 mb-3">Team Members</h3>
                                    <?php if (empty($agency['members'])): ?>
                                        <span class="text-gray-500">No team members assigned.</span>
                                    <?php else: ?>
                                        <div class="divide-y divide-gray-200">
                                            <?php foreach ($agency['members'] as $member): ?>
                                                <div class="flex flex-col sm:flex-row justify-between sm:items-start py-4 gap-4">
                                                    <div>
                                                        <span class="font-semibold text-base text-gray-800">
                                                            <?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?>
                                                        </span>
                                                        <?php if ((int) $member['id'] === $user_id): ?>
                                                            <span class="text-xs text-white font-semibold ml-2 px-2 py-0.5 bg-green-600 rounded-full">You</span>
                                                        <?php endif; ?>
                                                        <div class="text-sm text-gray-500">
                                                            ID: <?= (int) $member['id'] ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="flex-shrink-0 w-full sm:w-auto">
                                                        <?php if (!empty($member['monthly_points'])): ?>
                                                            <table class="min-w-[150px] text-xs">
                                                                <caption class="text-xs text-left text-gray-500 font-medium mb-1">Recent Points (Full Sales)</caption>
                                                                <tbody class="divide-y divide-gray-100">
                                                                    <?php $count = 0; ?>
                                                                    <?php foreach ($member['monthly_points'] as $month => $points): ?>
                                                                        <?php if ($count >= 3) break; // Show only top 3 recent months ?>
                                                                        <tr class="bg-gray-50 even:bg-white">
                                                                            <td class="px-2 py-1 text-gray-600"><?= htmlspecialchars($month) ?>:</td>
                                                                            <td class="px-2 py-1 text-right font-medium text-blue-700"><?= number_format($points) ?> pts</td>
                                                                        </tr>
                                                                        <?php $count++; ?>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        <?php else: ?>
                                                            <span class="text-xs text-gray-400">No points data.</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="lg:col-span-1 space-y-6">
                <?php if ($teamsTableExists): ?>
                    <h1 class="text-2xl sm:text-3xl font-bold text-blue-800 lg:text-2xl">Other Teams</h1>
                    <?php if (empty($teams)): ?>
                        <div class="p-6 bg-white rounded-lg shadow text-gray-500 text-center">
                            You are not assigned to any other teams.
                        </div>
                    <?php else: ?>
                        <div class="space-y-6">
                            <?php foreach ($teams as $team): ?>
                                <div class="bg-white rounded-xl shadow-lg p-5">
                                    <h2 class="text-xl font-semibold mb-3 text-blue-800">
                                        <?= htmlspecialchars($team['team_name']) ?>
                                    </h2>
                                    
                                    <?php
                                    // Get leader name
                                    $leader_q = $mysqli->prepare("SELECT id, first_name, last_name FROM users WHERE id = ?");
                                    $leader_q->bind_param('i', $team['leader_id']);
                                    $leader_q->execute();
                                    $leader_r = $leader_q->get_result()->fetch_assoc();
                                    $leader_name = $leader_r ? htmlspecialchars($leader_r['first_name'] . ' ' . $leader_r['last_name']) : 'N/A';
                                    $leader_id = $leader_r ? $leader_r['id'] : $team['leader_id'];
                                    $leader_q->close();

                                    // Get all members (other than leader)
                                    $mem_stmt = $mysqli->prepare("
                                        SELECT u.id, u.first_name, u.last_name
                                        FROM team_members tm
                                        JOIN users u ON tm.member_id=u.id
                                        WHERE tm.team_id = ? AND tm.member_id != ?
                                    ");
                                    $mem_stmt->bind_param('ii', $team['team_id'], $team['leader_id']);
                                    $mem_stmt->execute();
                                    $members_result = $mem_stmt->get_result();
                                    ?>
                                    
                                    <div class="mb-3">
                                        <strong class="text-sm text-blue-700">Team Leader:</strong>
                                        <span class="font-medium text-gray-800"><?= $leader_name ?></span>
                                        <?php if ($team['leader_id'] == $user_id): ?>
                                            <span class="text-xs text-white font-semibold ml-1 px-2 py-0.5 bg-green-600 rounded-full">You</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div>
                                        <span class="text-sm text-blue-700 font-semibold">Members:</span>
                                        <?php if ($members_result->num_rows === 0): ?>
                                            <span class="text-gray-500 text-sm ml-2">No other members.</span>
                                        <?php else: ?>
                                            <ul class="mt-1 ml-4 list-disc text-gray-700 text-sm space-y-1">
                                                <?php while ($m = $members_result->fetch_assoc()): ?>
                                                    <li>
                                                        <span class="font-medium"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></span>
                                                        <?php if ($m['id'] == $user_id): ?>
                                                            <span class="text-xs text-white font-semibold ml-1 px-2 py-0.5 bg-green-600 rounded-full">You</span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endwhile; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </div>
                                    <?php $mem_stmt->close(); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div> </div>
    
    <script>
        feather.replace();
    </script>
</body>
</html>