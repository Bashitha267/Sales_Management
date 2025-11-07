<?php
session_start();

// --- SECURITY CHECK (Admin only) ---
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: /ref/login.php");
    exit();
}

require_once '../config.php'; // $mysqli connection

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $team_name = trim($_POST['team_name']);
    $leader_id = $_POST['leader_id'];
    $member_ids = $_POST['member_ids'] ?? [];

    if (empty($team_name) || empty($leader_id)) {
        $message = "Please provide a team name and select a team leader.";
        $message_type = "error";
    } else {
        $mysqli->begin_transaction();
        try {
            // Insert team
            $stmt = $mysqli->prepare("INSERT INTO teams (team_name, leader_id) VALUES (?, ?)");
            $stmt->bind_param("si", $team_name, $leader_id);
            $stmt->execute();
            $team_id = $mysqli->insert_id;
            $stmt->close();

            // Insert members
            if (!empty($member_ids)) {
                $stmt = $mysqli->prepare("INSERT INTO team_members (team_id, member_id) VALUES (?, ?)");
                foreach ($member_ids as $mid) {
                    $stmt->bind_param("ii", $team_id, $mid);
                    $stmt->execute();
                }
                $stmt->close();
            }

            $mysqli->commit();
            $message = "✅ Team created successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error creating team: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// --- Fetch all Team Leaders ---
$leaders = $mysqli->query("SELECT id, first_name, last_name FROM users WHERE role = 'team leader' ORDER BY first_name");

// --- Fetch only 'rep' users as potential members ---
$users = $mysqli->query("
    SELECT id, first_name, last_name, role 
    FROM users 
    WHERE role = 'rep' 
    ORDER BY first_name
");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Team</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/tom-select/dist/css/tom-select.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tom-select/dist/js/tom-select.complete.min.js"></script>
</head>

<body class="bg-slate-100 min-h-screen flex items-center justify-center py-12 px-4">

    <!-- Toast Container -->
    <?php if ($message): ?>
        <div id="toast" class="fixed top-5 right-5 px-6 py-4 rounded-lg shadow-lg text-white text-base font-medium z-50 transition-opacity duration-500
            <?= $message_type === 'success' ? 'bg-green-600' : 'bg-red-600' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <script>
            setTimeout(() => {
                const toast = document.getElementById('toast');
                if (toast) toast.style.opacity = '0';
                setTimeout(() => toast?.remove(), 600);
            }, 3000);
        </script>
    <?php endif; ?>

    <div class="max-w-3xl w-full bg-white p-8 rounded-xl shadow-lg">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-3xl font-bold text-slate-800">Create New Team</h1>
            <a href="/ref/admin_dashboard.php" class="text-indigo-600 font-medium hover:text-indigo-800 transition">←
                Back to Dashboard</a>
        </div>

        <form action="" method="POST" class="space-y-6">
            <!-- Team Name -->
            <div>
                <label for="team_name" class="block text-sm font-medium text-slate-700 mb-1">Team Name <span
                        class="text-red-500">*</span></label>
                <input type="text" id="team_name" name="team_name" required
                    class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
            </div>

            <!-- Team Leader -->
            <div>
                <label for="leader_id" class="block text-sm font-medium text-slate-700 mb-1">Select Team Leader <span
                        class="text-red-500">*</span></label>
                <select id="leader_id" name="leader_id" required
                    class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm">
                    <option value="" disabled selected>Select a leader...</option>
                    <?php while ($l = $leaders->fetch_assoc()): ?>
                        <option value="<?= $l['id'] ?>">
                            <?= htmlspecialchars($l['first_name'] . ' ' . $l['last_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Members -->
            <div>
                <label for="member_ids" class="block text-sm font-medium text-slate-700 mb-1">Add Team Members</label>
                <select id="member_ids" name="member_ids[]" multiple
                    class="w-full border border-slate-300 rounded-md shadow-sm">
                    <?php while ($u = $users->fetch_assoc()): ?>
                        <option value="<?= $u['id'] ?>">
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <p class="text-sm text-slate-500 mt-1">Search and select multiple members.</p>
            </div>

            <!-- Submit -->
            <div class="pt-4">
                <button type="submit"
                    class="w-full bg-indigo-600 text-white px-6 py-3 rounded-md text-lg font-medium hover:bg-indigo-700 transition">
                    Create Team
                </button>
            </div>
        </form>
    </div>

    <script>
        // Enable searchable multi-select using Tom Select
        new TomSelect("#member_ids", {
            plugins: ['remove_button'],
            create: false,
            sortField: { field: "text", direction: "asc" }
        });
    </script>

</body>

</html>