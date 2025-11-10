<?php
// Ref Profile Page - Show current user's profile info (read-only view for reps, editable for admins/team leaders)

require_once 'auth.php';
requireLogin();
require_once 'config.php';

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

// Only allow rep, admin, or team leader
$allowed_roles = ['rep', 'representative', 'admin'];
if (!$user_id || !in_array($user_role, $allowed_roles)) {
    header('Location: /ref/login.php');
    exit;
}

// If admin/team leader, can view anyone's profile by ?id= param
$view_id = ($user_role === 'admin' || $user_role === 'representative') && isset($_GET['id'])
    ? (int) $_GET['id']
    : (int) $user_id;

// Fetch user info
$stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i', $view_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    // Not found or deleted
    echo "<div class='text-red-600 font-bold p-6'>User not found.</div>";
    exit;
}
$user = $result->fetch_assoc();
$stmt->close();

$is_self = ($user_id == $view_id);

// Only allow edit if self or admin/team leader viewing
$can_edit = (
    ($is_self && $user_role === 'rep')
    || $user_role === 'admin'
    || $user_role === 'team leader'
);

// --- Handle POST update (EDIT) ---
$errors = [];
$updated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    // Collect & validate input
    $fields = [
        'first_name',
        'last_name',
        'contact_number',
        'email',
        'nic_number',
        'address',
        'city',
        'bank_account',
        'bank_name',
        'branch',
        'account_holder',
        'age'
    ];
    $updates = [];
    foreach ($fields as $f) {
        $updates[$f] = trim($_POST[$f] ?? '');
    }

    // Simple validation
    if ($updates['first_name'] === '')
        $errors[] = "First name is required.";
    if ($updates['last_name'] === '')
        $errors[] = "Last name is required.";
    if ($updates['email'] === '')
        $errors[] = "Email is required.";
    if ($updates['nic_number'] === '')
        $errors[] = "NIC number is required.";
    if ($updates['age'] !== '' && !ctype_digit($updates['age']))
        $errors[] = "Age must be a number.";
    // Optional: validate email format, etc.

    // Uniqueness checks
    if (empty($errors)) {
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE (email=? OR nic_number=?) AND id!=?");
        $stmt->bind_param('ssi', $updates['email'], $updates['nic_number'], $view_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Email or NIC number is already in use by another user.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        // Run update query
        $query = "UPDATE users SET first_name=?, last_name=?, contact_number=?, email=?, nic_number=?, address=?, city=?, bank_account=?, bank_name=?, branch=?, account_holder=?, age=? WHERE id=?";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param(
            'ssssssssssssi',
            $updates['first_name'],
            $updates['last_name'],
            $updates['contact_number'],
            $updates['email'],
            $updates['nic_number'],
            $updates['address'],
            $updates['city'],
            $updates['bank_account'],
            $updates['bank_name'],
            $updates['branch'],
            $updates['account_holder'],
            $updates['age'],
            $view_id
        );
        if ($stmt->execute()) {
            $updated = true;
            // Refresh user data
            $stmt->close();
            $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param('i', $view_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
        } else {
            $errors[] = "Database update failed. (" . htmlspecialchars($stmt->error) . ")";
        }
        $stmt->close();
    }
}

// --- Header (role-based) ---
if ($user_role === 'admin') {
    include 'admin_header.php';
} else if ($user_role === 'rep') {
    include 'refs/refs_header.php';
} else if ($user_role === 'representative') {
    include 'leader/leader_header.php';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body>
    <div class="max-w-2xl mx-auto mt-10 bg-white rounded-xl shadow p-6">
        <h2 class="text-2xl font-bold mb-4 text-blue-700 flex items-center gap-2">

            <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>'s Profile
        </h2>
        <?php if ($updated): ?>
            <div class="bg-green-100 text-green-700 px-4 py-2 rounded mb-4">Profile updated successfully.</div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4">
                <ul class="list-disc list-inside text-sm">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($can_edit): ?>
            <form method="post" class="space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-medium mb-1">First Name *</label>
                        <input type="text" name="first_name" class="w-full border rounded px-3 py-2" required
                            value="<?= htmlspecialchars($user['first_name']) ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Last Name *</label>
                        <input type="text" name="last_name" class="w-full border rounded px-3 py-2" required
                            value="<?= htmlspecialchars($user['last_name']) ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Contact Number</label>
                        <input type="text" name="contact_number" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Email *</label>
                        <input type="email" name="email" class="w-full border rounded px-3 py-2" required
                            value="<?= htmlspecialchars($user['email']) ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">NIC Number *</label>
                        <input type="text" name="nic_number" class="w-full border rounded px-3 py-2" required
                            value="<?= htmlspecialchars($user['nic_number']) ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Address</label>
                        <input type="text" name="address" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['address'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">City</label>
                        <input type="text" name="city" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['city'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Age</label>
                        <input type="number" min="0" name="age" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['age'] ?? '') ?>">
                    </div>
                </div>
                <div class="font-semibold text-blue-700 mt-2">Bank Details</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-medium mb-1">Bank Account No</label>
                        <input type="text" name="bank_account" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['bank_account'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Bank Name</label>
                        <input type="text" name="bank_name" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['bank_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Branch</label>
                        <input type="text" name="branch" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['branch'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Account Holder</label>
                        <input type="text" name="account_holder" class="w-full border rounded px-3 py-2"
                            value="<?= htmlspecialchars($user['account_holder'] ?? '') ?>">
                    </div>
                </div>

                <div class="mt-4 flex flex-col sm:flex-row gap-4">
                    <button type="submit"
                        class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition font-semibold">
                        Save Changes
                    </button>
                    <a href="<?= ($user_role === 'admin') ? '/ref/admin_dashboard.php' : '/ref/refs/ref_dashboard.php' ?>"
                        class="px-6 py-2 bg-gray-100 text-slate-700 rounded hover:bg-gray-200 transition text-center font-semibold">
                        Back to Dashboard
                    </a>
                </div>
            </form>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                <div>
                    <span class="font-semibold">First Name:</span>
                    <span><?= htmlspecialchars($user['first_name']) ?></span>
                </div>
                <div>
                    <span class="font-semibold">Last Name:</span>
                    <span><?= htmlspecialchars($user['last_name']) ?></span>
                </div>
                <div>
                    <span class="font-semibold">Username:</span>
                    <span><?= htmlspecialchars($user['username']) ?></span>
                </div>
                <div>
                    <span class="font-semibold">Role:</span>
                    <span><?= htmlspecialchars($user['role']) ?></span>
                </div>
                <div>
                    <span class="font-semibold">Contact Number:</span>
                    <span><?= htmlspecialchars($user['contact_number'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">Email:</span>
                    <span><?= htmlspecialchars($user['email']) ?></span>
                </div>
                <div>
                    <span class="font-semibold">NIC Number:</span>
                    <span><?= htmlspecialchars($user['nic_number']) ?></span>
                </div>
                <div>
                    <span class="font-semibold">Address:</span>
                    <span><?= htmlspecialchars($user['address'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">City:</span>
                    <span><?= htmlspecialchars($user['city'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">Age:</span>
                    <span><?= htmlspecialchars($user['age'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">Join Date:</span>
                    <span><?= htmlspecialchars($user['join_date'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">Created At:</span>
                    <span><?= htmlspecialchars($user['created_at'] ?? '-') ?></span>
                </div>
                <div class="col-span-2 font-semibold text-blue-700 mt-2">Bank Details</div>
                <div>
                    <span class="font-semibold">Bank Account No:</span>
                    <span><?= htmlspecialchars($user['bank_account'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">Bank Name:</span>
                    <span><?= htmlspecialchars($user['bank_name'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">Branch:</span>
                    <span><?= htmlspecialchars($user['branch'] ?? '-') ?></span>
                </div>
                <div>
                    <span class="font-semibold">Account Holder:</span>
                    <span><?= htmlspecialchars($user['account_holder'] ?? '-') ?></span>
                </div>
            </div>

        <?php endif; ?>

    </div>
</body>