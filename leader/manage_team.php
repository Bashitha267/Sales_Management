<?php
require_once '../auth.php';
requireLogin();
include '../config.php';

$leader_id = $_SESSION['user_id'] ?? 0;

// === AJAX: Search for Reps ===
if (isset($_GET['search_reps'])) {
    header('Content-Type: application/json');
    $term = '%' . $_GET['search_reps'] . '%';
    $sql = "SELECT id, first_name, last_name, username 
            FROM users 
            WHERE role='rep' 
            AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ?)
            LIMIT 10";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("sss", $term, $term, $term);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc())
        $out[] = $r;
    echo json_encode($out);
    exit;
}

// === AJAX: Add or Remove Members ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'An error occurred.'];

    // --- Action: Add Member ---
    if ($_POST['action'] === 'add_member') {
        $team_id = intval($_POST['team_id'] ?? 0);
        $member_id = intval($_POST['member_id'] ?? 0);

        // *** BUG FIX: Validate that a member was actually selected ***
        if ($team_id <= 0 || $member_id <= 0) {
            $response['message'] = 'Please select a valid team and member.';
            echo json_encode($response);
            exit;
        }

        // Security: Check if leader owns this team
        $check = $mysqli->prepare("SELECT 1 FROM teams WHERE team_id=? AND leader_id=?");
        $check->bind_param("ii", $team_id, $leader_id);
        $check->execute();
        if ($check->get_result()->num_rows == 0) {
            $response['message'] = 'Permission denied.';
            echo json_encode($response);
            exit;
        }
        $check->close();

        // Check if member already exists in this team
        $exists = $mysqli->prepare("SELECT 1 FROM team_members WHERE team_id=? AND member_id=?");
        $exists->bind_param("ii", $team_id, $member_id);
        $exists->execute();
        if ($exists->get_result()->num_rows > 0) {
            $response['message'] = 'This member is already in that team.';
            echo json_encode($response);
            exit;
        }
        $exists->close();

        // All checks passed, add the member
        $insert = $mysqli->prepare("INSERT INTO team_members (team_id, member_id) VALUES (?, ?)");
        $insert->bind_param("ii", $team_id, $member_id);
        if ($insert->execute()) {
            // Get new member's details to send back to the page
            $stmt = $mysqli->prepare("SELECT id, first_name, last_name, username FROM users WHERE id=?");
            $stmt->bind_param("i", $member_id);
            $stmt->execute();
            $new_member = $stmt->get_result()->fetch_assoc();

            $response['success'] = true;
            $response['message'] = 'Member added successfully!';
            $response['new_member'] = $new_member;
            $response['team_id'] = $team_id; // Tell JS which card to update
        } else {
            $response['message'] = 'Database error adding member.';
        }
        $insert->close();
    }

    // --- Action: Remove Member (BUG FIX: Added missing logic) ---
    if ($_POST['action'] === 'remove_member') {
        $team_id = intval($_POST['rm_team_id'] ?? 0);
        $member_id = intval($_POST['rm_member_id'] ?? 0);

        if ($team_id <= 0 || $member_id <= 0) {
            $response['message'] = 'Invalid data provided.';
            echo json_encode($response);
            exit;
        }

        // Security: Check if leader owns the team this member is in
        $check = $mysqli->prepare("SELECT 1 FROM teams WHERE team_id=? AND leader_id=?");
        $check->bind_param("ii", $team_id, $leader_id);
        $check->execute();
        if ($check->get_result()->num_rows == 0) {
            $response['message'] = 'Permission denied.';
            echo json_encode($response);
            exit;
        }
        $check->close();

        // Remove member from the team
        $delete = $mysqli->prepare("DELETE FROM team_members WHERE team_id=? AND member_id=?");
        $delete->bind_param("ii", $team_id, $member_id);
        if ($delete->execute() && $delete->affected_rows > 0) {
            $response['success'] = true;
            $response['message'] = 'Member removed successfully.';
            $response['team_id'] = $team_id;
            $response['member_id'] = $member_id; // Tell JS which <li> to remove
        } else {
            $response['message'] = 'Error removing member or member not found.';
        }
        $delete->close();
    }

    echo json_encode($response);
    exit;
}
// === End of AJAX Handlers ===


// --- Main Page Load Logic ---

// Fetch all teams under this leader
$teams = [];
$stmt = $mysqli->prepare("SELECT team_id, team_name FROM teams WHERE leader_id = ?");
$stmt->bind_param("i", $leader_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $teams[] = $row;
}
$stmt->close();

// Helper function to fetch members for each team
function fetch_team_members($mysqli, $team_id)
{
    $stmt = $mysqli->prepare("
        SELECT u.id, u.first_name, u.last_name, u.username
        FROM team_members tm
        JOIN users u ON tm.member_id = u.id
        WHERE tm.team_id = ?
        ORDER BY u.first_name, u.last_name");
    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Teams</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        /* Simple transition for dynamically added/removed items */
        .member-item {
            transition: all 0.3s ease;
            max-height: 100px;
            overflow: hidden;
        }

        .member-item.removing {
            opacity: 0;
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
            border-width: 0;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">
    <?php include 'leader_header.php' ?>
    <div id="notification" class="fixed top-5 right-5 z-50 ">
    </div>


    <div class="max-w-5xl mx-auto py-10 px-4">
        <h1 class="text-3xl font-bold text-gray-800 mb-8 flex items-center justify-start gap-3">
            <span data-feather="users" class="text-blue-600"></span>
            Manage Your Teams
        </h1>

        <?php if (empty($teams)): ?>
            <div class="text-center text-gray-600 bg-white p-8 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-2">No Teams Found</h2>
                <p>You are not leading any teams yet. Go to the admin panel to create a team.</p>
            </div>
        <?php else: ?>

            <div class="bg-white rounded-lg shadow-md p-6 mb-10 border-l-4 border-blue-600">
                <h2 class="text-xl font-semibold text-gray-800 mb-5 flex items-center gap-2">
                    <span data-feather="user-plus" class="text-blue-600"></span>
                    Add Member to Team
                </h2>

                <form id="addMemberForm" method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label for="teamSelect" class="block text-sm font-medium text-gray-700 mb-1">1. Select Team</label>
                        <select name="team_id" id="teamSelect" required
                            class="w-full border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">-- Select a Team --</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= $t['team_id'] ?>"><?= htmlspecialchars($t['team_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="relative">
                        <label for="repSearch" class="block text-sm font-medium text-gray-700 mb-1">2. Find Rep</label>
                        <input type="text" id="repSearch" placeholder="Search by name or username..."
                            class="w-full border-gray-300 rounded-md shadow-sm p-2 focus:ring-blue-500 focus:border-blue-500"
                            autocomplete="off">
                        <div id="repResults"
                            class="absolute top-full left-0 w-full bg-white border border-gray-300 rounded-b-md shadow-lg hidden z-10 max-h-60 overflow-y-auto">
                        </div>
                        <input type="hidden" name="member_id" id="selectedRepId">
                        <input type="hidden" name="action" value="add_member">
                    </div>

                    <button type="submit" name="add_member"
                        class="bg-blue-600 text-white px-5 py-2 rounded-md shadow-sm hover:bg-blue-700 transition w-full md:w-auto h-10">
                        Add Member
                    </button>
                </form>
            </div>

            <h2 class="text-2xl font-semibold text-gray-800 mb-6">Your Teams</h2>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">

                <?php foreach ($teams as $t):
                    $members = fetch_team_members($mysqli, $t['team_id']); ?>

                    <div class="bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow overflow-hidden"
                        id="team-card-<?= $t['team_id'] ?>">
                        <div class="p-5 border-b border-gray-200">
                            <div class="flex justify-between items-center">
                                <h3 class="text-lg font-semibold text-blue-800"><?= htmlspecialchars($t['team_name']) ?></h3>
                                <span class="text-sm font-mono text-gray-400">ID: <?= $t['team_id'] ?></span>
                            </div>
                            <p class="text-sm text-gray-500">
                                <span data-feather="users" class="w-4 h-4 inline-block -mt-1"></span>
                                <span id="member-count-<?= $t['team_id'] ?>"><?= count($members) ?></span> Members
                            </p>
                        </div>

                        <div class="p-5 bg-gray-50">
                            <h4 class="text-sm font-semibold text-gray-700 mb-3">Members</h4>

                            <p id="no-members-msg-<?= $t['team_id'] ?>"
                                class="text-gray-500 text-sm <?= empty($members) ? '' : 'hidden' ?>">
                                No members in this team yet.
                            </p>

                            <ul id="member-list-<?= $t['team_id'] ?>" class="text-gray-800 text-sm space-y-2">
                                <?php foreach ($members as $m): ?>
                                    <li class="member-item flex justify-between items-center border-b border-gray-200 py-2"
                                        id="member-<?= $m['id'] ?>-in-team-<?= $t['team_id'] ?>">

                                        <span class="flex flex-col">
                                            <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                            <span class="text-xs text-gray-500">@<?= htmlspecialchars($m['username']) ?></span>
                                        </span>

                                        <form method="post" class="remove-member-form">
                                            <input type="hidden" name="rm_team_id" value="<?= $t['team_id'] ?>">
                                            <input type="hidden" name="rm_member_id" value="<?= $m['id'] ?>">
                                            <input type="hidden" name="action" value="remove_member">
                                            <button type="submit" title="Remove member"
                                                class="text-red-500 hover:text-red-700 p-1 rounded-full hover:bg-red-100">
                                                <span data-feather="x" class="w-4 h-4"></span>
                                            </button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

    <script>
        feather.replace();

        // --- Notification Function ---
        const notificationArea = document.getElementById('notification');
        function showNotification(message, isSuccess = true) {
            const bgColor = isSuccess ? 'bg-green-600' : 'bg-red-600';
            const notification = document.createElement('div');
            notification.className = `p-4 ${bgColor} text-white rounded-md shadow-lg mb-2 transition-all transform translate-x-full`;
            notification.innerText = message;

            notificationArea.appendChild(notification);

            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 10);

            // Animate out
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // --- AJAX Rep Search ---
        const searchBox = document.getElementById('repSearch');
        const resultsBox = document.getElementById('repResults');
        const hiddenInput = document.getElementById('selectedRepId');

        searchBox.addEventListener('input', function () {
            const q = this.value.trim();
            hiddenInput.value = ''; // Clear selection on new input
            if (q.length < 2) {
                resultsBox.classList.add('hidden');
                return;
            }

            fetch('?search_reps=' + encodeURIComponent(q))
                .then(res => res.json())
                .then(data => {
                    if (data.length === 0) {
                        resultsBox.innerHTML = "<div class='p-3 text-gray-500'>No matches found</div>";
                    } else {
                        resultsBox.innerHTML = data.map(u =>
                            `<div class='p-3 hover:bg-blue-50 cursor-pointer' 
                                data-id="${u.id}" 
                                data-name="${u.first_name} ${u.last_name} (@${u.username})">
                                ${u.first_name} ${u.last_name} <span class='text-gray-500 text-sm'>(${u.username})</span>
                            </div>`
                        ).join('');
                    }
                    resultsBox.classList.remove('hidden');
                });
        });

        // Click handler for search results
        resultsBox.addEventListener('click', function (e) {
            const div = e.target.closest('div[data-id]');
            if (div) {
                selectRep(div.dataset.id, div.dataset.name);
            }
        });

        function selectRep(id, name) {
            searchBox.value = name;
            hiddenInput.value = id;
            resultsBox.classList.add('hidden');
        }

        // Close results if clicking outside
        document.addEventListener('click', function (e) {
            if (!searchBox.contains(e.target) && !resultsBox.contains(e.target)) {
                resultsBox.classList.add('hidden');
            }
        });

        // --- AJAX Form Handling (Add & Remove) ---
        document.addEventListener('submit', function (e) {

            // --- Handle Add Member ---
            if (e.target.id === 'addMemberForm') {
                e.preventDefault();
                const form = e.target;
                const formData = new FormData(form);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        showNotification(data.message, data.success);
                        if (data.success) {
                            // Reset the form
                            form.reset();
                            hiddenInput.value = '';

                            // Add new member to the list
                            addMemberToCard(data.team_id, data.new_member);
                        }
                    })
                    .catch(err => console.error('Error:', err));
            }

            // --- Handle Remove Member ---
            if (e.target.classList.contains('remove-member-form')) {
                e.preventDefault();
                if (!confirm('Are you sure you want to remove this member?')) {
                    return;
                }

                const form = e.target;
                const formData = new FormData(form);

                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        showNotification(data.message, data.success);
                        if (data.success) {
                            // Remove member from the list
                            removeMemberFromCard(data.team_id, data.member_id);
                        }
                    })
                    .catch(err => console.error('Error:', err));
            }
        });

        // --- DOM Update Functions ---
        function addMemberToCard(team_id, member) {
            const memberList = document.getElementById(`member-list-${team_id}`);
            const noMsg = document.getElementById(`no-members-msg-${team_id}`);
            const countSpan = document.getElementById(`member-count-${team_id}`);

            if (!memberList) return;

            // Hide the "no members" message
            if (noMsg) noMsg.classList.add('hidden');

            // Create new member list item
            const li = document.createElement('li');
            li.className = 'member-item flex justify-between items-center border-b border-gray-200 py-2';
            li.id = `member-${member.id}-in-team-${team_id}`;
            li.innerHTML = `
                <span class="flex flex-col">
                    ${escapeHTML(member.first_name + ' ' + member.last_name)}
                    <span class="text-xs text-gray-500">@${escapeHTML(member.username)}</span>
                </span>
                <form method="post" class="remove-member-form">
                    <input type="hidden" name="rm_team_id" value="${team_id}">
                    <input type="hidden" name="rm_member_id" value="${member.id}">
                    <input type="hidden" name="action" value="remove_member">
                    <button type="submit" title="Remove member" class="text-red-500 hover:text-red-700 p-1 rounded-full hover:bg-red-100">
                        <span data-feather="x" class="w-4 h-4"></span>
                    </button>
                </form>
            `;

            memberList.appendChild(li);
            feather.replace(); // Redraw the new 'x' icon

            // Update count
            countSpan.innerText = memberList.children.length;
        }

        function removeMemberFromCard(team_id, member_id) {
            const memberItem = document.getElementById(`member-${member_id}-in-team-${team_id}`);
            const memberList = document.getElementById(`member-list-${team_id}`);
            const noMsg = document.getElementById(`no-members-msg-${team_id}`);
            const countSpan = document.getElementById(`member-count-${team_id}`);

            if (memberItem) {
                // Animate out
                memberItem.classList.add('removing');
                setTimeout(() => {
                    memberItem.remove();

                    // Check if list is now empty
                    if (memberList.children.length === 0) {
                        if (noMsg) noMsg.classList.remove('hidden');
                    }
                    // Update count
                    countSpan.innerText = memberList.children.length;
                }, 300);
            }
        }

        function escapeHTML(str) {
            return str.replace(/[&<>"']/g, function (m) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[m];
            });
        }

    </script>
</body>

</html>