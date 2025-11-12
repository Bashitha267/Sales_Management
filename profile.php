<?php
// --- SETUP ---
require_once 'auth.php';
requireLogin();
require_once 'config.php';

// --- CSRF Token ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- GET CURRENT USER ---
$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;

// Only allow logged-in users to see this page
$allowed_roles = ['rep', 'representative', 'admin'];
if (!$user_id || !in_array($user_role, $allowed_roles)) {
    header('Location: /ref/login.php');
    exit;
}

// --- GET PROFILE TO VIEW ---
// Admins or Team Leaders can view other profiles via ?id=
// Reps can only view their own profile
$view_id = ($user_role === 'admin' || $user_role === 'representative') && isset($_GET['id'])
    ? (int) $_GET['id']
    : (int) $user_id;

// --- PERMISSIONS ---
// NEW: Only admins can edit
$can_edit = ($user_role === 'admin');
// Are we in 'edit' mode?
$action = $_GET['action'] ?? 'view';

// --- DATA FETCHING ---
$stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i', $view_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    // A simple error. You can make this prettier by including headers/footers.
    echo "<div style='font-family: sans-serif; padding: 2rem; color: #b91c1c; font-weight: bold;'>Error: User not found.</div>";
    exit;
}
$user = $result->fetch_assoc();
$stmt->close();

/**
 * Helper function to safely print data or a default dash.
 */
function h(?string $value, string $default = '—'): string
{
    if ($value === null || $value === '') {
        return $default;
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// --- POST: HANDLE UPDATE (ADMINS ONLY) ---
$errors = [];
$updated = false;

// Only process form if it's POST and user is admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {

    // 1. CSRF Check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session token. Please try again.";
    } else {

        // 2. Collect & Validate Data
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

        // 3. Validation Rules
        if (empty($updates['first_name']))
            $errors[] = "First name is required.";
        if (empty($updates['last_name']))
            $errors[] = "Last name is required.";
        if (empty($updates['email'])) {
            $errors[] = "Email is required.";
        } elseif (!filter_var($updates['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        if (empty($updates['nic_number']))
            $errors[] = "NIC number is required.";
        if ($updates['age'] !== '' && (!ctype_digit($updates['age']) || (int) $updates['age'] < 0)) {
            $errors[] = "Age must be a valid positive number.";
        }

        // 4. Uniqueness Checks
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

        // 5. Database Update
        if (empty($errors)) {
            $query = "UPDATE users SET 
                first_name=?, last_name=?, contact_number=?, email=?, nic_number=?, 
                address=?, city=?, bank_account=?, bank_name=?, branch=?, 
                account_holder=?, age=? 
                WHERE id=?";

            $stmt = $mysqli->prepare($query);

            // Handle empty 'age' as NULL in the database
            $age_val = $updates['age'] === '' ? null : (int) $updates['age'];

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
                $age_val,
                $view_id
            );

            if ($stmt->execute()) {
                // Success: Redirect back to VIEW mode with a success flag
                header("Location: profile.php?id=$view_id&updated=true");
                exit;
            } else {
                $errors[] = "Database update failed: " . htmlspecialchars($stmt->error);
            }
            $stmt->close();
        }
    }
}

// Check for success flag from redirect
if (isset($_GET['updated']) && $_GET['updated'] === 'true') {
    $updated = true;
}

// --- INCLUDE HEADER (ROLE-BASED) ---
if ($user_role === 'admin') {
    // Use __DIR__ for robust include paths
    include __DIR__ . '/admin_header.php';
} else if ($user_role === 'rep') {
    include __DIR__ . '/refs/refs_header.php';
} else if ($user_role === 'representative') {
    include __DIR__ . '/leader/leader_header.php';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile - <?= h($user['first_name'] . ' ' . $user['last_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>

</head>

<body class="bg-slate-100 min-h-screen">

    <main class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">

        <?php
        // --- CHECK ACTION ---
        // If (action=edit) AND (user is admin), show the EDIT FORM
        if ($action === 'edit' && $can_edit):
            ?>

            <div class="bg-white shadow-xl rounded-lg overflow-hidden">
                <div class="px-4 py-5 sm:px-6 bg-slate-50 border-b border-slate-200">
                    <h2 class="text-2xl font-bold text-slate-900 flex items-center gap-3">
                        <i data-feather="edit" class="text-indigo-600"></i>
                        Edit User: <?= h($user['first_name'] . ' ' . $user['last_name']) ?>
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">Update the user's profile information.</p>
                </div>

                <form method="post" action="profile.php?id=<?= (int) $view_id ?>&action=edit">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

                    <div class="p-4 sm:p-6 space-y-8">

                        <?php if (!empty($errors)): ?>
                            <div class="bg-red-100 text-red-800 border border-red-200 p-4 rounded-md text-sm">
                                <p class="font-semibold mb-2">Please fix the following errors:</p>
                                <ul class="list-disc list-inside">
                                    <?php foreach ($errors as $e): ?>
                                        <li><?= h($e) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div>
                            <h3 class="text-lg font-semibold text-slate-800 border-b border-slate-200 pb-2 mb-4">Personal
                                Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-slate-700 mb-1">First Name
                                        *</label>
                                    <input type="text" id="first_name" name="first_name"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        required value="<?= h($user['first_name'], '') ?>">
                                </div>
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-slate-700 mb-1">Last Name
                                        *</label>
                                    <input type="text" id="last_name" name="last_name"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        required value="<?= h($user['last_name'], '') ?>">
                                </div>
                                <div>
                                    <label for="nic_number" class="block text-sm font-medium text-slate-700 mb-1">NIC Number
                                        *</label>
                                    <input type="text" id="nic_number" name="nic_number"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        required value="<?= h($user['nic_number'], '') ?>">
                                </div>
                                <div>
                                    <label for="age" class="block text-sm font-medium text-slate-700 mb-1">Age</label>
                                    <input type="number" id="age" name="age" min="0"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['age'], '') ?>">
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold text-slate-800 border-b border-slate-200 pb-2 mb-4">Contact
                                Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                                <div>
                                    <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email Address
                                        *</label>
                                    <input type="email" id="email" name="email"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        required value="<?= h($user['email'], '') ?>">
                                </div>
                                <div>
                                    <label for="contact_number"
                                        class="block text-sm font-medium text-slate-700 mb-1">Contact Number</label>
                                    <input type="tel" id="contact_number" name="contact_number"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['contact_number'], '') ?>">
                                </div>
                                <div class="md:col-span-2">
                                    <label for="address"
                                        class="block text-sm font-medium text-slate-700 mb-1">Address</label>
                                    <input type="text" id="address" name="address"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['address'], '') ?>">
                                </div>
                                <div>
                                    <label for="city" class="block text-sm font-medium text-slate-700 mb-1">City</label>
                                    <input type="text" id="city" name="city"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['city'], '') ?>">
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold text-slate-800 border-b border-slate-200 pb-2 mb-4">Bank
                                Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-5">
                                <div>
                                    <label for="bank_account" class="block text-sm font-medium text-slate-700 mb-1">Bank
                                        Account No</label>
                                    <input type="text" id="bank_account" name="bank_account"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['bank_account'], '') ?>">
                                </div>
                                <div>
                                    <label for="account_holder"
                                        class="block text-sm font-medium text-slate-700 mb-1">Account Holder Name</label>
                                    <input type="text" id="account_holder" name="account_holder"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['account_holder'], '') ?>">
                                </div>
                                <div>
                                    <label for="bank_name" class="block text-sm font-medium text-slate-700 mb-1">Bank
                                        Name</label>
                                    <input type="text" id="bank_name" name="bank_name"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['bank_name'], '') ?>">
                                </div>
                                <div>
                                    <label for="branch" class="block text-sm font-medium text-slate-700 mb-1">Branch</label>
                                    <input type="text" id="branch" name="branch"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-md shadow-sm placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                        value="<?= h($user['branch'], '') ?>">
                                </div>
                            </div>
                        </div>

                    </div>

                    <div
                        class="px-4 py-4 sm:px-6 bg-slate-50 border-t border-slate-200 flex items-center justify-end gap-3">
                        <a href="profile.php?id=<?= (int) $view_id ?>"
                            class="inline-flex justify-center py-2 px-4 border border-slate-300 rounded-md shadow-sm bg-white text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Cancel
                        </a>
                        <button type="submit"
                            class="inline-flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>


            <?php
            // --- ELSE, show the default READ-ONLY VIEW ---
        else:
            ?>

            <div class="bg-white shadow-xl rounded-lg overflow-hidden">

                <div class="px-4 py-5 sm:px-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h2 class="text-3xl font-bold text-slate-900">
                                <?= h($user['first_name'] . ' ' . $user['last_name']) ?>
                            </h2>
                            <p class="mt-1 text-sm text-slate-500 flex items-center gap-4 flex-wrap">
                                <span class="flex items-center gap-1.5">
                                    <i data-feather="user" class="w-4 h-4"></i>
                                    @<?= h($user['username']) ?>
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <i data-feather="shield" class="w-4 h-4"></i>
                                    Role: <?= h(ucfirst($user['role'])) ?>
                                </span>
                            </p>
                        </div>
                        <?php if ($can_edit): ?>
                            <div>
                                <a href="profile.php?id=<?= (int) $view_id ?>&action=edit"
                                    class="inline-flex items-center gap-2 w-full sm:w-auto justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    <i data-feather="edit-2" class="w-4 h-4"></i>
                                    Edit User
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($updated): ?>
                        <div class="mt-4 bg-green-100 text-green-800 border border-green-200 p-3 rounded-md text-sm">
                            Profile updated successfully.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="border-t border-slate-200">
                    <dl>
                        <div class="bg-slate-50 px-4 py-4 sm:px-6">
                            <h3 class="text-lg font-semibold text-slate-800">Personal Information</h3>
                        </div>
                        <div class="bg-white px-4 py-5 sm:px-6 divide-y divide-slate-200">
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Full Name</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0">
                                    <?= h($user['first_name'] . ' ' . $user['last_name']) ?>
                                </dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">NIC Number</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0"><?= h($user['nic_number']) ?>
                                </dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Age</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0"><?= h($user['age']) ?></dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Join Date</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0">
                                    <?= h($user['join_date'] ? date('F j, Y', strtotime($user['join_date'])) : null) ?>
                                </dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Member Since</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0">
                                    <?= h($user['created_at'] ? date('F j, Y, g:i a', strtotime($user['created_at'])) : null) ?>
                                </dd>
                            </div>
                        </div>

                        <div class="bg-slate-50 px-4 py-4 sm:px-6">
                            <h3 class="text-lg font-semibold text-slate-800">Contact Information</h3>
                        </div>
                        <div class="bg-white px-4 py-5 sm:px-6 divide-y divide-slate-200">
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Email Address</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0"><a
                                        href="mailto:<?= h($user['email'], '#') ?>"
                                        class="text-indigo-600 hover:text-indigo-800"><?= h($user['email']) ?></a></dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Contact Number</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0">
                                    <?= h($user['contact_number']) ?>
                                </dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Address</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0"><?= h($user['address']) ?>
                                </dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">City</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0"><?= h($user['city']) ?></dd>
                            </div>
                        </div>

                        <div class="bg-slate-50 px-4 py-4 sm:px-6">
                            <h3 class="text-lg font-semibold text-slate-800">Bank Details</h3>
                        </div>
                        <div class="bg-white px-4 py-5 sm:px-6 divide-y divide-slate-200">
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Bank Name</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0"><?= h($user['bank_name']) ?>
                                </dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Branch</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0"><?= h($user['branch']) ?></dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Account Holder</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0">
                                    <?= h($user['account_holder']) ?>
                                </dd>
                            </div>
                            <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:py-5">
                                <dt class="text-sm font-medium text-slate-500">Account Number</dt>
                                <dd class="mt-1 text-sm text-slate-900 sm:col-span-2 sm:mt-0">
                                    <?= h($user['bank_account']) ?>
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>

            </div>

            <?php
            // End the main if/else (action=edit)
        endif;
        ?>

    </main>

    <script>
        feather.replace();
    </script>
</body>

</html>
<?php
// Finally, close the database connection
$mysqli->close();
?>