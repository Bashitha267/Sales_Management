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

    // --- Calculate monthly points for each member in the agency ---
    foreach ($agencyTeams as &$agency) {
        foreach ($agency['members'] as &$member) {
            $repId = (int) $member['id'];
            $agencyRepId = (int) $agency['representative_id'];

            // Monthly points query
            $pointsStmt = $mysqli->prepare("
                SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS month,
                       IFNULL(SUM(si.quantity * i.rep_points),0) AS total_points
                FROM sales s
                INNER JOIN sale_items si ON si.sale_id = s.id
                INNER JOIN items i ON i.id = si.item_id
                WHERE s.rep_user_id = ? 
                  AND s.rep_user_id IN (SELECT rep_user_id FROM agency_reps WHERE representative_id = ?)
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
</head>

<body class="bg-gray-100 min-h-screen">
    <?php include "refs_header.php" ?>
    <div class="max-w-4xl mx-auto py-10">
        <h1 class="text-3xl font-bold text-blue-700 mb-8">My Agency</h1>
        <div class="mb-10">

            <?php if (empty($agencyTeams)): ?>
                <div class="p-6 bg-white rounded shadow text-gray-500">
                    You are not currently assigned to an agency team.
                </div>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($agencyTeams as $agency): ?>
                        <div class="bg-white rounded-xl shadow-lg p-6 border border-blue-100">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <h3 class="text-xl font-semibold text-blue-800">
                                        <?= htmlspecialchars($agency['agency_name']) ?>
                                    </h3>
                                    <p class="text-xs text-gray-500">
                                        Agency ID: <?= (int) $agency['agency_id'] ?>
                                    </p>
                                </div>
                            </div>
                            <div class="mt-4">
                                <span class="font-semibold text-blue-700">Representative:</span>
                                <?php $repName = trim($agency['representative_name'] ?? ''); ?>
                                <span class="ml-2">
                                    <?= $repName !== '' ? htmlspecialchars($repName) : 'N/A' ?>
                                    <?php if (!empty($agency['representative_id'])): ?>
                                        <span class="text-gray-500 text-xs">(ID: <?= (int) $agency['representative_id'] ?>)</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="mt-4">
                                <span class="font-semibold text-blue-700">Team Members:</span>
                                <?php if (empty($agency['members'])): ?>
                                    <span class="text-gray-500 ml-2">No team members assigned.</span>
                                <?php else: ?>
                                    <ul class="mt-2 ml-4 list-disc text-gray-700 space-y-1">
                                        <?php foreach ($agency['members'] as $member): ?>
                                            <li>
                                                <span class="font-semibold">
                                                    <?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?>
                                                </span>
                                                <span class="text-gray-500 text-xs">(ID: <?= (int) $member['id'] ?>)</span>
                                                <?php if ((int) $member['id'] === $user_id): ?>
                                                    <span class="text-xs text-green-700 font-semibold ml-1">[You]</span>
                                                <?php endif; ?>

                                                <!-- Monthly Points -->
                                                <?php if (!empty($member['monthly_points'])): ?>
                                                    <div class="text-sm text-gray-600 mt-1 ml-4">
                                                        <?php foreach ($member['monthly_points'] as $month => $points): ?>
                                                            <span><?= $month ?>: <?= $points ?> pts</span><br>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Other Teams Section -->
        <?php if ($teamsTableExists): ?>
            <h1 class="text-3xl font-bold text-blue-700 mb-6">Other Teams</h1>
            <?php if (empty($teams)): ?>
                <div class="p-6 bg-white rounded shadow text-gray-500">You are not assigned to any other teams.</div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <?php foreach ($teams as $team): ?>
                        <div class="bg-white rounded-xl shadow-lg p-6">
                            <h2 class="text-xl font-semibold mb-2 text-blue-800 flex items-center gap-2">
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
                            <div class="mb-2">
                                <strong class="text-blue-700">Team Leader:</strong>
                                <span class="font-medium"><?= $leader_name ?> (ID: <?= (int) $leader_id ?>)</span>
                                <?php if ($team['leader_id'] == $user_id): ?>
                                    <span class="text-xs text-green-600 font-semibold ml-1">[You]</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="text-blue-700 font-semibold">Members:</span>
                                <?php if ($members_result->num_rows === 0): ?>
                                    <span class="text-gray-500 ml-2">No other members in this team.</span>
                                <?php else: ?>
                                    <ul class="mt-1 ml-4 list-disc text-gray-700">
                                        <?php while ($m = $members_result->fetch_assoc()): ?>
                                            <li>
                                                <span
                                                    class="font-semibold"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></span>
                                                <span class="text-gray-500 text-xs">(ID: <?= (int) $m['id'] ?>)</span>
                                                <?php if ($m['id'] == $user_id): ?>
                                                    <span class="text-xs text-green-700 font-semibold ml-1">[You]</span>
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
</body>

</html>