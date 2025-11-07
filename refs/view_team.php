<?php
session_start();
include '../config.php';
if (!isset($_SESSION['user_id'])) {
    // Redirect if not logged in
    header("Location: /login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// --- DB CONNECTION (assumes $mysqli used elsewhere) ---

// Find teams where user is a member or leader
$stmt = $mysqli->prepare("
    SELECT t.team_id, t.team_name, t.leader_id, t.created_at
    FROM teams t
    LEFT JOIN team_members tm ON t.team_id = tm.team_id
    WHERE t.leader_id = ? OR tm.member_id = ?
    GROUP BY t.team_id
");
$stmt->bind_param("ii", $user_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

$teams = [];
while ($row = $result->fetch_assoc()) {
    $teams[] = $row;
}
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Your Teams</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen">
    <?php include "refs_header.php" ?>
    <div class="max-w-4xl mx-auto py-10">
        <h1 class="text-3xl font-bold text-blue-700 mb-8">My Teams</h1>
        <?php if (empty($teams)): ?>
            <div class="p-6 bg-white rounded shadow text-gray-500">You are not assigned to any teams yet.</div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <?php foreach ($teams as $team): ?>
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h2 class="text-xl font-semibold mb-2 text-blue-800 flex items-center gap-2">
                            <?= htmlspecialchars($team['team_name']) ?>
                            <?php if ($team['leader_id'] == $user_id): ?>
                                <span class="ml-2 text-sm px-2 py-0.5 rounded bg-green-100 text-green-800 font-bold">You are the
                                    Leader</span>
                            <?php endif; ?>
                        </h2>
                        <p class="text-gray-500 text-xs mb-2">Team ID: <?= (int) $team['team_id'] ?> | Created on:
                            <?= htmlspecialchars(date('Y-m-d', strtotime($team['created_at']))) ?>
                        </p>
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

    </div>
</body>

</html>