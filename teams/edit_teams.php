<?php
require_once '../config.php';
require_once '../auth.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

// Handle AJAX request for leader search
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_leaders') {
    header('Content-Type: application/json');
    $search_term = isset($_GET['q']) ? trim($_GET['q']) : '';
    $team_id_param = isset($_GET['team_id']) ? intval($_GET['team_id']) : 0;

    // Get current leader if team_id is provided
    $current_leader_id = null;
    if ($team_id_param > 0) {
        $team_stmt = $mysqli->prepare("SELECT leader_id FROM teams WHERE team_id = ?");
        $team_stmt->bind_param("i", $team_id_param);
        $team_stmt->execute();
        $team_result = $team_stmt->get_result();
        $team_row = $team_result->fetch_assoc();
        if ($team_row && $team_row['leader_id']) {
            $current_leader_id = $team_row['leader_id'];
        }
        $team_stmt->close();
    }

    // Build query to search team leaders
    $query = "SELECT id, username, first_name, last_name, role FROM users WHERE role = 'team leader'";
    $params = [];
    $types = '';

    if (!empty($search_term)) {
        $query .= " AND (username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR id = ?)";
        $search_pattern = '%' . $search_term . '%';
        $params = [$search_pattern, $search_pattern, $search_pattern, intval($search_term)];
        $types = 'sssi';
    }

    $query .= " ORDER BY username ASC LIMIT 20";

    $stmt = $mysqli->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $leaders = [];
    $leader_ids = [];
    while ($row = $result->fetch_assoc()) {
        $leaders[] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'role' => $row['role'] ?? '',
            'display_text' => $row['username'] .
                (!empty($row['first_name']) || !empty($row['last_name'])
                    ? ' (' . trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) . ')'
                    : '') .
                ' - ID: ' . $row['id']
        ];
        $leader_ids[] = $row['id'];
    }
    $stmt->close();

    // Include current leader if not already in results and matches search
    if ($current_leader_id && !in_array($current_leader_id, $leader_ids)) {
        $current_leader_stmt = $mysqli->prepare("SELECT id, username, first_name, last_name, role FROM users WHERE id = ?");
        $current_leader_stmt->bind_param("i", $current_leader_id);
        $current_leader_stmt->execute();
        $current_leader_result = $current_leader_stmt->get_result();
        $current_leader_row = $current_leader_result->fetch_assoc();
        $current_leader_stmt->close();

        if ($current_leader_row) {
            // Check if it matches search term (if provided)
            $matches = true;
            if (!empty($search_term)) {
                $term_lower = strtolower($search_term);
                $matches =
                    strpos(strtolower($current_leader_row['username']), $term_lower) !== false ||
                    strpos(strtolower($current_leader_row['first_name'] ?? ''), $term_lower) !== false ||
                    strpos(strtolower($current_leader_row['last_name'] ?? ''), $term_lower) !== false ||
                    $current_leader_row['id'] == intval($search_term);
            }

            if ($matches) {
                $leaders[] = [
                    'id' => $current_leader_row['id'],
                    'username' => $current_leader_row['username'],
                    'first_name' => $current_leader_row['first_name'] ?? '',
                    'last_name' => $current_leader_row['last_name'] ?? '',
                    'role' => $current_leader_row['role'] ?? '',
                    'display_text' => $current_leader_row['username'] .
                        (!empty($current_leader_row['first_name']) || !empty($current_leader_row['last_name'])
                            ? ' (' . trim(($current_leader_row['first_name'] ?? '') . ' ' . ($current_leader_row['last_name'] ?? '')) . ')'
                            : '') .
                        ' - ID: ' . $current_leader_row['id']
                ];
            }
        }
    }

    $mysqli->close();

    echo json_encode($leaders);
    exit;
}

$team_id = isset($_GET['team_id']) ? intval($_GET['team_id']) : 0;
if ($team_id <= 0) {
    header('Location: view_teams.php');
    exit;
}

$message = '';
$message_type = '';

// Get team info
$stmt = $mysqli->prepare("SELECT team_id, team_name, leader_id FROM teams WHERE team_id = ?");
$stmt->bind_param("i", $team_id);
$stmt->execute();
$result = $stmt->get_result();
$team = $result->fetch_assoc();
$stmt->close();

if (!$team) {
    $message = "Team not found.";
    $message_type = 'error';
}

// Get leader info
$leader = null;
if ($team && $team['leader_id']) {
    $stmt = $mysqli->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->bind_param("i", $team['leader_id']);
    $stmt->execute();
    $leader_result = $stmt->get_result();
    $leader = $leader_result->fetch_assoc();
    $stmt->close();
}

// Get members
$members = [];
if ($team) {
    $stmt = $mysqli->prepare(
        "SELECT u.id, u.username FROM team_members tm 
         JOIN users u ON tm.member_id = u.id
         WHERE tm.team_id = ?"
    );
    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    $members_result = $stmt->get_result();
    while ($row = $members_result->fetch_assoc()) {
        // Exclude leader from members list for editing
        if (!$team['leader_id'] || $row['id'] != $team['leader_id'])
            $members[] = $row;
    }
    $stmt->close();
}

// Fetch all users for member selection (including role)
$all_users = [];
$user_stmt = $mysqli->query("SELECT id, username, first_name, last_name, role FROM users ORDER BY username ASC");
while ($urow = $user_stmt->fetch_assoc()) {
    $all_users[] = $urow;
}

// Fetch only team leaders for leader selection
$team_leaders = [];
$leader_stmt = $mysqli->query("SELECT id, username FROM users WHERE role = 'team leader' ORDER BY username ASC");
while ($lrow = $leader_stmt->fetch_assoc()) {
    $team_leaders[] = $lrow;
}

// Include current leader in team_leaders array if not already present (for display purposes)
// This ensures the dropdown shows the current leader even if they don't have 'team leader' role
if ($leader && !in_array($leader['id'], array_column($team_leaders, 'id'))) {
    $team_leaders[] = ['id' => $leader['id'], 'username' => $leader['username']];
}

// Handle add/remove member actions
if ($team && isset($_GET['action'])) {
    if ($_GET['action'] === 'remove_member' && isset($_GET['member_id'])) {
        $remove_member_id = (int) $_GET['member_id'];
        if ($remove_member_id > 0 && $remove_member_id != $team['leader_id']) {
            $delete_stmt = $mysqli->prepare("DELETE FROM team_members WHERE team_id = ? AND member_id = ?");
            $delete_stmt->bind_param("ii", $team_id, $remove_member_id);
            if ($delete_stmt->execute()) {
                $message = "Member removed successfully.";
                $message_type = "success";
            } else {
                $message = "Could not remove member.";
                $message_type = "error";
            }
            $delete_stmt->close();
            header("Location: edit_teams.php?team_id=$team_id&updated=1");
            exit;
        }
    } elseif ($_GET['action'] === 'add_member' && isset($_GET['member_id'])) {
        $add_member_id = (int) $_GET['member_id'];
        if ($add_member_id > 0 && $add_member_id != $team['leader_id']) {
            // Check if member already exists
            $check_stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM team_members WHERE team_id = ? AND member_id = ?");
            $check_stmt->bind_param("ii", $team_id, $add_member_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_row = $check_result->fetch_assoc();
            $check_stmt->close();

            if ($check_row['cnt'] == 0) {
                $insert_stmt = $mysqli->prepare("INSERT INTO team_members (team_id, member_id) VALUES (?, ?)");
                $insert_stmt->bind_param("ii", $team_id, $add_member_id);
                if ($insert_stmt->execute()) {
                    $message = "Member added successfully.";
                    $message_type = "success";
                } else {
                    $message = "Could not add member.";
                    $message_type = "error";
                }
                $insert_stmt->close();
            } else {
                $message = "Member is already in the team.";
                $message_type = "error";
            }
            header("Location: edit_teams.php?team_id=$team_id&updated=1");
            exit;
        }
    }
}

// Handle form submission for team name and leader update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $team) {
    $new_team_name = trim($_POST['team_name']);
    $new_leader_id = (int) ($_POST['leader_id'] ?? 0);

    if ($new_leader_id === 0) {
        $message = "A leader must be selected for the team.";
        $message_type = "error";
    } else {
        // Validate that the selected user has 'team leader' role
        $validate_stmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
        $validate_stmt->bind_param("i", $new_leader_id);
        $validate_stmt->execute();
        $validate_result = $validate_stmt->get_result();
        $validate_user = $validate_result->fetch_assoc();
        $validate_stmt->close();

        if (!$validate_user || $validate_user['role'] !== 'team leader') {
            $message = "Only users with 'team leader' role can be assigned as team leaders.";
            $message_type = "error";
        } else {
            $mysqli->begin_transaction();
            try {
                // Update team name and leader
                $update_stmt = $mysqli->prepare("UPDATE teams SET team_name = ?, leader_id = ? WHERE team_id = ?");
                $update_stmt->bind_param("sii", $new_team_name, $new_leader_id, $team_id);
                $update_stmt->execute();
                $update_stmt->close();

                // Ensure leader is in team_members
                $check_leader_stmt = $mysqli->prepare("SELECT COUNT(*) as cnt FROM team_members WHERE team_id = ? AND member_id = ?");
                $check_leader_stmt->bind_param("ii", $team_id, $new_leader_id);
                $check_leader_stmt->execute();
                $check_leader_result = $check_leader_stmt->get_result();
                $check_leader_row = $check_leader_result->fetch_assoc();
                $check_leader_stmt->close();

                if ($check_leader_row['cnt'] == 0) {
                    $insert_leader_stmt = $mysqli->prepare("INSERT INTO team_members (team_id, member_id) VALUES (?, ?)");
                    $insert_leader_stmt->bind_param("ii", $team_id, $new_leader_id);
                    $insert_leader_stmt->execute();
                    $insert_leader_stmt->close();
                }

                $mysqli->commit();
                $message = "Team updated successfully.";
                $message_type = "success";
                header("Location: edit_teams.php?team_id=$team_id&updated=1");
                exit;
            } catch (Exception $e) {
                $mysqli->rollback();
                $message = "Could not update team: " . htmlspecialchars($e->getMessage());
                $message_type = "error";
            }
        }
    }
}

// If redirected after successful update
if (isset($_GET['updated'])) {
    $message = "Team updated successfully.";
    $message_type = "success";
}

// Refresh members/leader in display if needed
$stmt = $mysqli->prepare("SELECT team_id, team_name, leader_id FROM teams WHERE team_id = ?");
$stmt->bind_param("i", $team_id);
$stmt->execute();
$team = $stmt->get_result()->fetch_assoc();
$stmt->close();
$leader = null;
if ($team && $team['leader_id']) {
    $stmt = $mysqli->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->bind_param("i", $team['leader_id']);
    $stmt->execute();
    $leader_result = $stmt->get_result();
    $leader = $leader_result->fetch_assoc();
    $stmt->close();
}
$members = [];
$member_ids = [];
if ($team) {
    $stmt = $mysqli->prepare(
        "SELECT u.id, u.username FROM team_members tm 
         JOIN users u ON tm.member_id = u.id
         WHERE tm.team_id = ?"
    );
    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    $members_result = $stmt->get_result();
    while ($row = $members_result->fetch_assoc()) {
        if (!$team['leader_id'] || $row['id'] != $team['leader_id']) {
            $members[] = $row;
            $member_ids[] = $row['id'];
        }
    }
    $stmt->close();
}

// Include current leader in team_leaders array if not already present (for display purposes)
// This ensures the dropdown shows the current leader even if they don't have 'team leader' role
if ($leader && !in_array($leader['id'], array_column($team_leaders, 'id'))) {
    $team_leaders[] = ['id' => $leader['id'], 'username' => $leader['username']];
}

// Get available users (not in team, excluding leader)
$available_users = [];
foreach ($all_users as $user) {
    if ($team && $team['leader_id'] && $user['id'] == $team['leader_id']) {
        continue; // Skip leader
    }
    if (!in_array($user['id'], $member_ids)) {
        $available_users[] = $user;
    }
}

$mysqli->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Team <?= isset($team['team_name']) ? '-' . htmlspecialchars($team['team_name']) : '' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <?php include '../admin_header.php'; ?>
    <main class="max-w-3xl mx-auto p-8 bg-white my-12 rounded-xl shadow-lg">
        <h2 class="text-3xl font-bold text-slate-800 flex items-center gap-3 mb-6">
            <img src="https://img.icons8.com/fluency-systems-regular/48/4f46e5/groups.png" class="w-9 h-9">
            Edit Team
        </h2>

        <?php if ($message): ?>
            <div class="mb-6">
                <div class="px-4 py-3 rounded <?=
                    $message_type == 'success'
                    ? 'bg-emerald-100 text-emerald-800 border border-emerald-300'
                    : 'bg-red-100 text-red-800 border border-red-300'
                    ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$team): ?>
            <a href="view_teams.php" class="inline-block px-5 py-2 bg-indigo-600 text-white rounded-lg mt-8">← Back to
                Teams</a>
        <?php else: ?>
            <form method="POST" class="space-y-8 mb-16">
                <!-- Team ID and Name -->
                <div class="flex items-center space-x-4">
                    <div>
                        <label class="block text-sm text-slate-600 font-semibold mb-1">Team ID</label>
                        <input type="text" readonly
                            class="bg-slate-200 cursor-not-allowed border px-4 py-2 rounded w-24 text-center"
                            value="<?= $team['team_id'] ?>">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm text-slate-600 font-semibold mb-1">Team Name</label>
                        <input type="text" name="team_name" class="border px-4 py-2 rounded w-full"
                            value="<?= htmlspecialchars($team['team_name']) ?>" required>
                    </div>
                </div>

                <!-- Current Leader Card -->
                <div>
                    <label class="block text-sm text-slate-600 font-semibold mb-2">Leader</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                        <!-- Current Leader Card -->
                        <?php if ($leader): ?>
                            <div
                                class="flex items-center space-x-3 bg-indigo-50 border border-indigo-200 rounded-lg px-5 py-3 shadow-inner">
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($leader['username']) ?>&background=4f46e5&color=fff"
                                    class="w-10 h-10 rounded-full">
                                <div>
                                    <span
                                        class="block text-indigo-800 font-semibold"><?= htmlspecialchars($leader['username']) ?></span>
                                    <span class="block text-xs text-slate-500">Current Leader</span>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="text-red-500 font-semibold py-3 px-4">No leader assigned!</span>
                        <?php endif; ?>

                        <!-- Leader Selection -->
                        <div>
                            <label for="leader_search" class="text-xs block mb-1">Change Leader</label>
                            <div class="relative">
                                <input type="text" id="leader_search" placeholder="Search by name, username, or ID..."
                                    autocomplete="off"
                                    value="<?= $leader ? htmlspecialchars($leader['username'] . ' - ID: ' . $leader['id']) : '' ?>"
                                    class="w-full border px-4 py-2 pl-10 pr-10 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                <svg xmlns="http://www.w3.org/2000/svg"
                                    class="h-5 w-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <button type="button" id="clear_leader_btn"
                                    class="absolute right-2 top-1/2 transform -translate-y-1/2 p-1.5 text-slate-400 hover:text-slate-600 rounded transition <?= $leader ? 'opacity-100' : 'opacity-0 pointer-events-none' ?>"
                                    title="Clear selection">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>

                                <!-- Dropdown Results -->
                                <div id="leader_dropdown_results"
                                    class="hidden absolute z-10 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                    <!-- Results will be populated by AJAX -->
                                </div>
                            </div>

                            <!-- Hidden input for form submission -->
                            <input type="hidden" name="leader_id" id="leader_id" value="<?= $leader ? $leader['id'] : '' ?>"
                                required>
                        </div>
                    </div>
                </div>

                <!-- Members Cards -->
                <div>
                    <label class="block text-sm text-slate-600 font-semibold mb-2">Members</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
                        <!-- Current Members as cards except leader -->
                        <?php foreach ($members as $member): ?>
                            <div
                                class="flex items-center justify-between bg-slate-50 border border-slate-200 rounded-lg px-4 py-2 shadow-sm">
                                <div class="flex items-center space-x-3 flex-1">
                                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($member['username']) ?>&background=64748b&color=fff"
                                        class="w-8 h-8 rounded-full">
                                    <div>
                                        <span
                                            class="block font-medium text-slate-700"><?= htmlspecialchars($member['username']) ?></span>
                                        <span class="block text-xs text-slate-500">Member</span>
                                    </div>
                                </div>
                                <a href="?team_id=<?= $team_id ?>&action=remove_member&member_id=<?= $member['id'] ?>"
                                    onclick="return confirm('Are you sure you want to remove this member?')"
                                    class="ml-2 p-1.5 text-red-600 hover:bg-red-100 rounded transition" title="Remove member">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
                                    </svg>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($members) === 0): ?>
                            <span class="text-slate-400 italic col-span-2 sm:col-span-3">No other members in team.</span>
                        <?php endif; ?>
                    </div>

                    <!-- Add Member Section - Searchable Dropdown -->
                    <?php if (count($available_users) > 0): ?>
                        <div class="mt-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
                            <label class="block text-sm text-slate-600 font-semibold mb-2">Add Member</label>
                            <div class="relative">
                                <!-- Searchable Dropdown Container -->
                                <div class="relative">
                                    <input type="text" id="member_search_dropdown"
                                        placeholder="Search by name, username, role, or ID..." autocomplete="off"
                                        class="w-full border px-4 py-2 pl-10 pr-10 rounded focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent">
                                    <svg xmlns="http://www.w3.org/2000/svg"
                                        class="h-5 w-5 absolute left-3 top-1/2 transform -translate-y-1/2 text-slate-400"
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                    <button type="button" id="add_member_btn"
                                        class="absolute right-2 top-1/2 transform -translate-y-1/2 p-1.5 bg-emerald-600 text-white hover:bg-emerald-700 rounded transition opacity-0 pointer-events-none"
                                        title="Add selected member">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 4v16m8-8H4" />
                                        </svg>
                                    </button>
                                </div>

                                <!-- Dropdown Results -->
                                <div id="member_dropdown_results"
                                    class="hidden absolute z-10 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                    <!-- Results will be populated by JavaScript -->
                                </div>

                                <!-- Hidden select for storing selected value -->
                                <select id="add_member_select" class="hidden">
                                    <option value="">-- Select user to add --</option>
                                    <?php foreach ($available_users as $user): ?>
                                        <option value="<?= $user['id'] ?>"
                                            data-username="<?= htmlspecialchars(strtolower($user['username'])) ?>"
                                            data-firstname="<?= htmlspecialchars(strtolower($user['first_name'] ?? '')) ?>"
                                            data-lastname="<?= htmlspecialchars(strtolower($user['last_name'] ?? '')) ?>"
                                            data-role="<?= htmlspecialchars(strtolower($user['role'] ?? '')) ?>"
                                            data-userid="<?= $user['id'] ?>"
                                            data-display-text="<?= htmlspecialchars($user['username'] . (!empty($user['first_name']) || !empty($user['last_name']) ? ' (' . trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) . ')' : '') . ' - ' . ($user['role'] ?? 'N/A') . ' - ID: ' . $user['id']) ?>">
                                            <?= htmlspecialchars($user['username']) ?>
                                            <?php if (!empty($user['first_name']) || !empty($user['last_name'])): ?>
                                                (<?= htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?>)
                                            <?php endif; ?>
                                            - <?= htmlspecialchars($user['role'] ?? 'N/A') ?> - ID: <?= $user['id'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mt-4 p-4 bg-slate-50 border border-slate-200 rounded-lg text-center text-slate-500 text-sm">
                            All available users are already in the team.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex items-center justify-between mt-8">
                    <a href="view_teams.php" class="px-5 py-2 bg-slate-200 hover:bg-slate-300 rounded-lg">← Back</a>
                    <button type="submit"
                        class="px-7 py-3 bg-indigo-600 text-white hover:bg-indigo-700 font-semibold rounded-lg transition"
                        <?= (!$leader) ? 'disabled style="opacity:.6;pointer-events:none;"' : '' ?>>
                        Save Changes
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </main>
    <script>
        (function () {
            const memberSearchInput = document.getElementById('member_search_dropdown');
            const dropdownResults = document.getElementById('member_dropdown_results');
            const addMemberSelect = document.getElementById('add_member_select');
            const addMemberBtn = document.getElementById('add_member_btn');
            let selectedMemberId = null;
            let selectedDisplayText = '';

            if (!memberSearchInput || !dropdownResults || !addMemberSelect) return;

            // Store all original options with all searchable data
            const allOptions = Array.from(addMemberSelect.querySelectorAll('option')).map(opt => ({
                value: opt.value,
                text: opt.textContent,
                displayText: opt.getAttribute('data-display-text') || opt.textContent,
                username: opt.getAttribute('data-username') || '',
                firstname: opt.getAttribute('data-firstname') || '',
                lastname: opt.getAttribute('data-lastname') || '',
                role: opt.getAttribute('data-role') || '',
                userid: opt.getAttribute('data-userid') || ''
            })).filter(opt => opt.value !== ''); // Remove placeholder

            // Filter and display results
            function filterAndDisplayResults(searchTerm, showAll = false) {
                const term = searchTerm.toLowerCase().trim();
                dropdownResults.innerHTML = '';

                if (term === '' && !showAll) {
                    dropdownResults.classList.add('hidden');
                    selectedMemberId = null;
                    selectedDisplayText = '';
                    updateAddButton();
                    return;
                }

                const filtered = showAll && term === ''
                    ? allOptions
                    : allOptions.filter(opt => {
                        // Search by ID
                        const matchesId = String(opt.userid).includes(term);

                        // Search by username
                        const matchesUsername = opt.username.includes(term);

                        // Search by first name
                        const matchesFirstname = opt.firstname.includes(term);

                        // Search by last name
                        const matchesLastname = opt.lastname.includes(term);

                        // Search by role
                        const matchesRole = opt.role.includes(term);

                        // Search by full name (first + last)
                        const fullName = (opt.firstname + ' ' + opt.lastname).trim();
                        const matchesFullName = fullName.includes(term);

                        return matchesId || matchesUsername || matchesFirstname || matchesLastname || matchesRole || matchesFullName;
                    });

                if (filtered.length === 0) {
                    dropdownResults.innerHTML = '<div class="px-4 py-3 text-slate-500 text-sm">No users found</div>';
                    dropdownResults.classList.remove('hidden');
                    selectedMemberId = null;
                    selectedDisplayText = '';
                    updateAddButton();
                    return;
                }

                filtered.forEach(opt => {
                    const item = document.createElement('div');
                    item.className = 'px-4 py-3 hover:bg-emerald-50 cursor-pointer border-b border-slate-100 last:border-b-0 transition';
                    item.innerHTML = `
                        <div class="font-medium text-slate-800">${opt.displayText}</div>
                    `;
                    item.addEventListener('click', function () {
                        selectedMemberId = opt.value;
                        selectedDisplayText = opt.displayText;
                        memberSearchInput.value = opt.displayText;
                        dropdownResults.classList.add('hidden');
                        updateAddButton();
                    });
                    dropdownResults.appendChild(item);
                });

                dropdownResults.classList.remove('hidden');
            }

            // Update add button visibility and state
            function updateAddButton() {
                if (selectedMemberId) {
                    addMemberBtn.classList.remove('opacity-0', 'pointer-events-none');
                    addMemberBtn.classList.add('opacity-100');
                } else {
                    addMemberBtn.classList.add('opacity-0', 'pointer-events-none');
                    addMemberBtn.classList.remove('opacity-100');
                }
            }

            // Handle search input
            memberSearchInput.addEventListener('input', function () {
                // Clear selection if input is cleared
                if (this.value.trim() === '') {
                    selectedMemberId = null;
                    selectedDisplayText = '';
                    updateAddButton();
                }
                filterAndDisplayResults(this.value);
            });

            // Handle focus - show all results if empty
            memberSearchInput.addEventListener('focus', function () {
                if (this.value.trim() === '') {
                    // Show all options when focused and empty
                    filterAndDisplayResults('', true);
                } else {
                    filterAndDisplayResults(this.value);
                }
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                const container = memberSearchInput.closest('.relative');
                if (container && !container.contains(e.target)) {
                    dropdownResults.classList.add('hidden');
                    // Clear selection if input is cleared
                    if (memberSearchInput.value.trim() === '') {
                        selectedMemberId = null;
                        selectedDisplayText = '';
                        updateAddButton();
                    }
                }
            });

            // Handle add member button click
            if (addMemberBtn) {
                addMemberBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (selectedMemberId) {
                        window.location.href = '?team_id=<?= $team_id ?>&action=add_member&member_id=' + selectedMemberId;
                    } else {
                        alert('Please select a user to add.');
                    }
                });
            }

            // Handle Enter key to add member
            memberSearchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && selectedMemberId) {
                    e.preventDefault();
                    window.location.href = '?team_id=<?= $team_id ?>&action=add_member&member_id=' + selectedMemberId;
                }
            });
        })();

        // Leader Search AJAX Functionality
        (function () {
            const leaderSearchInput = document.getElementById('leader_search');
            const leaderDropdownResults = document.getElementById('leader_dropdown_results');
            const leaderIdInput = document.getElementById('leader_id');
            const clearLeaderBtn = document.getElementById('clear_leader_btn');
            let searchTimeout = null;
            let selectedLeaderId = leaderIdInput ? leaderIdInput.value : null;
            let selectedLeaderDisplay = leaderSearchInput ? leaderSearchInput.value : '';

            if (!leaderSearchInput || !leaderDropdownResults || !leaderIdInput) return;

            // Function to perform AJAX search
            function searchLeaders(searchTerm) {
                // Clear previous timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }

                // Debounce the search
                searchTimeout = setTimeout(function () {
                    const term = searchTerm.trim();

                    // If search is cleared, hide dropdown
                    if (term === '') {
                        leaderDropdownResults.classList.add('hidden');
                        // If input is cleared, also clear the selection
                        if (leaderSearchInput.value.trim() === '') {
                            selectedLeaderId = null;
                            selectedLeaderDisplay = '';
                            leaderIdInput.value = '';
                            updateClearButton();
                        }
                        return;
                    }

                    // Show loading state
                    leaderDropdownResults.innerHTML = '<div class="px-4 py-3 text-slate-500 text-sm">Searching...</div>';
                    leaderDropdownResults.classList.remove('hidden');

                    // Perform AJAX request
                    const url = '?ajax=search_leaders&q=' + encodeURIComponent(term) + '&team_id=<?= $team_id ?>';
                    fetch(url)
                        .then(response => response.json())
                        .then(data => {
                            displayLeaderResults(data);
                        })
                        .catch(error => {
                            console.error('Error searching leaders:', error);
                            leaderDropdownResults.innerHTML = '<div class="px-4 py-3 text-red-500 text-sm">Error searching leaders. Please try again.</div>';
                        });
                }, 300); // 300ms debounce
            }

            // Function to display search results
            function displayLeaderResults(leaders) {
                leaderDropdownResults.innerHTML = '';

                if (leaders.length === 0) {
                    leaderDropdownResults.innerHTML = '<div class="px-4 py-3 text-slate-500 text-sm">No team leaders found</div>';
                    leaderDropdownResults.classList.remove('hidden');
                    return;
                }

                leaders.forEach(leader => {
                    const item = document.createElement('div');
                    item.className = 'px-4 py-3 hover:bg-indigo-50 cursor-pointer border-b border-slate-100 last:border-b-0 transition';
                    item.innerHTML = `
                        <div class="font-medium text-slate-800">${leader.display_text}</div>
                    `;
                    item.addEventListener('click', function () {
                        selectedLeaderId = leader.id;
                        selectedLeaderDisplay = leader.display_text;
                        leaderSearchInput.value = leader.display_text;
                        leaderIdInput.value = leader.id;
                        leaderDropdownResults.classList.add('hidden');
                        updateClearButton();
                    });
                    leaderDropdownResults.appendChild(item);
                });

                leaderDropdownResults.classList.remove('hidden');
            }

            // Update clear button visibility
            function updateClearButton() {
                if (clearLeaderBtn) {
                    if (selectedLeaderId) {
                        clearLeaderBtn.classList.remove('opacity-0', 'pointer-events-none');
                        clearLeaderBtn.classList.add('opacity-100');
                    } else {
                        clearLeaderBtn.classList.add('opacity-0', 'pointer-events-none');
                        clearLeaderBtn.classList.remove('opacity-100');
                    }
                }
            }

            // Handle search input
            leaderSearchInput.addEventListener('input', function () {
                const term = this.value.trim();
                // If user is typing and clearing, perform search
                if (term.length > 0) {
                    searchLeaders(term);
                } else {
                    // If cleared, clear selection
                    selectedLeaderId = null;
                    selectedLeaderDisplay = '';
                    leaderIdInput.value = '';
                    leaderDropdownResults.classList.add('hidden');
                    updateClearButton();
                }
            });

            // Handle focus - show initial results if empty
            leaderSearchInput.addEventListener('focus', function () {
                if (this.value.trim() === '' || this.value === selectedLeaderDisplay) {
                    // Show all leaders when focused and empty
                    const url = '?ajax=search_leaders&q=&team_id=<?= $team_id ?>';
                    leaderDropdownResults.innerHTML = '<div class="px-4 py-3 text-slate-500 text-sm">Loading...</div>';
                    leaderDropdownResults.classList.remove('hidden');

                    fetch(url)
                        .then(response => response.json())
                        .then(data => {
                            displayLeaderResults(data);
                        })
                        .catch(error => {
                            console.error('Error loading leaders:', error);
                            leaderDropdownResults.innerHTML = '<div class="px-4 py-3 text-red-500 text-sm">Error loading leaders. Please try again.</div>';
                        });
                } else if (this.value.trim().length > 0) {
                    // If there's a value, perform search
                    searchLeaders(this.value);
                }
            });

            // Handle clear button
            if (clearLeaderBtn) {
                clearLeaderBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    leaderSearchInput.value = '';
                    selectedLeaderId = null;
                    selectedLeaderDisplay = '';
                    leaderIdInput.value = '';
                    leaderDropdownResults.classList.add('hidden');
                    updateClearButton();
                });
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                const container = leaderSearchInput.closest('.relative');
                if (container && !container.contains(e.target)) {
                    leaderDropdownResults.classList.add('hidden');
                    // Restore selected value if input was cleared
                    if (leaderSearchInput.value.trim() === '' && selectedLeaderDisplay) {
                        leaderSearchInput.value = selectedLeaderDisplay;
                    }
                } else if (container && container.contains(e.target) && e.target === clearLeaderBtn) {
                    // If clicking clear button, dropdown should close
                    leaderDropdownResults.classList.add('hidden');
                }
            });

            // Prevent form submission if no leader is selected
            const form = leaderSearchInput.closest('form');
            if (form) {
                form.addEventListener('submit', function (e) {
                    if (!leaderIdInput.value || leaderIdInput.value === '') {
                        e.preventDefault();
                        alert('Please select a team leader.');
                        leaderSearchInput.focus();
                    }
                });
            }

            // Initialize clear button state
            updateClearButton();
        })();
    </script>
</body>

</html>