<?php
require_once '../auth.php';
requireLogin();
include '../config.php';

$representative_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? '';

if ($representative_id <= 0 || $role !== 'representative') {
    header("Location: /ref/leader/manage_team.php");
    exit();
}

function flash_and_redirect(string $message, string $type = 'success'): void
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: /ref/leader/manage_team.php");
    exit();
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$invite_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$success = '';
$error = '';

if ($invite_id <= 0) {
    flash_and_redirect('Invalid invite request.', 'error');
}

// Fetch invite and user details
$inviteStmt = $mysqli->prepare("
    SELECT ai.id, ai.representative_id, ai.rep_user_id, ai.agency_id, ai.status
    FROM agency_invites ai
    WHERE ai.id = ? AND ai.representative_id = ?
");
$inviteStmt->bind_param("ii", $invite_id, $representative_id);
$inviteStmt->execute();
$invite = $inviteStmt->get_result()->fetch_assoc();
$inviteStmt->close();

if (!$invite) {
    flash_and_redirect('Invite not found or no longer available.', 'error');
}

if ($invite['status'] !== 'pending') {
    flash_and_redirect('This invite has already been processed.', 'error');
}

$rep_user_id = intval($invite['rep_user_id'] ?? 0);
if ($rep_user_id <= 0) {
    flash_and_redirect('User not found. Please ensure the rep has completed registration.', 'error');
}

// Fetch user details
$userStmt = $mysqli->prepare("
    SELECT id, first_name, last_name, username, email, nic_number, contact_number, age, role
    FROM users
    WHERE id = ? AND role = 'rep'
");
$userStmt->bind_param("i", $rep_user_id);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    flash_and_redirect('User not found or is not a rep.', 'error');
}

// Fetch agencies for this representative
$agencies = [];
$agencyStmt = $mysqli->prepare("SELECT id, agency_name FROM agencies WHERE representative_id = ? ORDER BY agency_name");
$agencyStmt->bind_param("i", $representative_id);
$agencyStmt->execute();
$agencyResult = $agencyStmt->get_result();
while ($row = $agencyResult->fetch_assoc()) {
    $agencies[] = $row;
}
$agencyStmt->close();

if (empty($agencies)) {
    flash_and_redirect('No agencies found. Please create an agency first.', 'error');
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_setup'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $agency_id = intval($_POST['agency_id'] ?? 0);

    // Validation - only check username, password, and agency
    if (empty($username)) {
        $error = 'Username is required.';
    } elseif (empty($password)) {
        $error = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($agency_id <= 0) {
        $error = 'Please select an agency.';
    } else {
        // Check if username already exists (excluding current user)
        $checkStmt = $mysqli->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $checkStmt->bind_param("si", $username, $rep_user_id);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if ($existing) {
            $error = 'Username already exists. Please choose a different username.';
        } else {
            // Check if agency belongs to representative
            $checkAgency = $mysqli->prepare("SELECT 1 FROM agencies WHERE id = ? AND representative_id = ?");
            $checkAgency->bind_param("ii", $agency_id, $representative_id);
            $checkAgency->execute();
            $ownsAgency = $checkAgency->get_result()->num_rows > 0;
            $checkAgency->close();

            if (!$ownsAgency) {
                $error = 'You do not have permission to assign reps to that agency.';
            } else {
                // Check if rep is already in any agency (rep_user_id is UNIQUE in agency_reps)
                // A rep can only belong to ONE agency total (across all representatives)
                $checkExisting = $mysqli->prepare("
                    SELECT agency_id, representative_id 
                    FROM agency_reps 
                    WHERE rep_user_id = ?
                ");
                $checkExisting->bind_param("i", $rep_user_id);
                $checkExisting->execute();
                $existingRelation = $checkExisting->get_result()->fetch_assoc();
                $checkExisting->close();

                // If rep is already in an agency, verify it's for this representative
                if ($existingRelation) {
                    $existing_agency_id = intval($existingRelation['agency_id']);
                    $existing_representative_id = intval($existingRelation['representative_id']);

                    // If rep belongs to a different representative's agency, show error and stop
                    if ($existing_representative_id !== $representative_id) {
                        $error = 'This rep is already assigned to another representative\'s agency. Remove them from that agency first if you need to move them.';
                    }
                    // If rep belongs to this representative (same or different agency), allow update
                    // This will be handled by UPDATE below
                }

                // Only proceed if no error
                if (empty($error)) {

                    // Start transaction
                    $mysqli->begin_transaction();
                    try {
                        // Hash password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                        // Update only username and password in users table
                        // Agency assignment is stored in agency_reps table, NOT in users table
                        $updateStmt = $mysqli->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                        $updateStmt->bind_param("ssi", $username, $hashed_password, $rep_user_id);

                        if (!$updateStmt->execute()) {
                            throw new Exception('Failed to update user: ' . $updateStmt->error);
                        }
                        $updateStmt->close();

                        // Update invite with agency_id
                        $updateInvite = $mysqli->prepare("UPDATE agency_invites SET agency_id = ? WHERE id = ?");
                        $updateInvite->bind_param("ii", $agency_id, $invite_id);
                        if (!$updateInvite->execute()) {
                            throw new Exception('Failed to update invite: ' . $updateInvite->error);
                        }
                        $updateInvite->close();

                        // Handle agency assignment in agency_reps table (NOT in users table)
                        if (!$existingRelation) {
                            // Rep is not in any agency yet - insert new record
                            $insertRep = $mysqli->prepare("INSERT INTO agency_reps (rep_user_id, representative_id, agency_id) VALUES (?, ?, ?)");
                            $insertRep->bind_param("iii", $rep_user_id, $representative_id, $agency_id);
                            if (!$insertRep->execute()) {
                                throw new Exception('Failed to add rep to agency: ' . $insertRep->error);
                            }
                            $insertRep->close();
                        } else {
                            // Rep is already in an agency - update to new agency (if different)
                            // Since rep_user_id is UNIQUE, we use UPDATE with WHERE rep_user_id
                            $updateRep = $mysqli->prepare("UPDATE agency_reps SET agency_id = ?, representative_id = ? WHERE rep_user_id = ?");
                            $updateRep->bind_param("iii", $agency_id, $representative_id, $rep_user_id);
                            if (!$updateRep->execute()) {
                                throw new Exception('Failed to update agency relation: ' . $updateRep->error);
                            }
                            $updateRep->close();
                        }

                        // Mark invite as accepted
                        $updateStatus = $mysqli->prepare("UPDATE agency_invites SET status = 'accepted' WHERE id = ?");
                        $updateStatus->bind_param("i", $invite_id);
                        if (!$updateStatus->execute()) {
                            throw new Exception('Failed to update invite status: ' . $updateStatus->error);
                        }
                        $updateStatus->close();

                        $mysqli->commit();
                        flash_and_redirect('Rep setup completed successfully. The rep has been added to the agency.', 'success');
                    } catch (Exception $e) {
                        $mysqli->rollback();
                        $error = 'An error occurred: ' . htmlspecialchars($e->getMessage());
                    }
                }
            }
        }
    }
}

// Set form data from user (only editable fields)
$formData = [
    'username' => $user['username'] ?? '',
    'agency_id' => $invite['agency_id'] ?? 0
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Rep Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <?php include 'leader_header.php'; ?>

    <main class="max-w-4xl mx-auto py-10 px-4">
        <header class="mb-6">
            <h1 class="text-3xl font-bold text-slate-900">Complete Rep Setup</h1>
            <p class="text-sm text-slate-500 mt-2">Set username, password, and assign agency for the new rep.</p>
        </header>

        <?php if ($success): ?>
            <div class="mb-4 p-4 bg-green-100 text-green-800 border border-green-200 rounded">
                <?= h($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-4 p-4 bg-red-100 text-red-800 border border-red-200 rounded">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="complete_setup" value="1">

                <!-- Personal Information Section (Read-Only) -->
                <div class="border-b border-slate-200 pb-6">
                    <h3 class="text-lg font-semibold text-slate-700 mb-4">Personal Information</h3>
                    <p class="text-sm text-slate-500 mb-4">Personal information is set during registration and cannot be
                        changed here.</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="first_name">
                                First Name
                            </label>
                            <input type="text" id="first_name" name="first_name" maxlength="100"
                                value="<?= h($user['first_name'] ?? '') ?>" disabled readonly
                                class="w-full border rounded px-3 py-2 text-base bg-slate-100 text-slate-600 cursor-not-allowed" />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="last_name">
                                Last Name
                            </label>
                            <input type="text" id="last_name" name="last_name" maxlength="100"
                                value="<?= h($user['last_name'] ?? '') ?>" disabled readonly
                                class="w-full border rounded px-3 py-2 text-base bg-slate-100 text-slate-600 cursor-not-allowed" />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="nic_number">
                                NIC Number
                            </label>
                            <input type="text" id="nic_number" name="nic_number" maxlength="20"
                                value="<?= h($user['nic_number'] ?? '') ?>" disabled readonly
                                class="w-full border rounded px-3 py-2 text-base bg-slate-100 text-slate-600 cursor-not-allowed" />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="email">
                                Email
                            </label>
                            <input type="email" id="email" name="email" maxlength="100"
                                value="<?= h($user['email'] ?? '') ?>" disabled readonly
                                class="w-full border rounded px-3 py-2 text-base bg-slate-100 text-slate-600 cursor-not-allowed" />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="contact_number">
                                Contact Number
                            </label>
                            <input type="text" id="contact_number" name="contact_number" maxlength="20"
                                value="<?= h($user['contact_number'] ?? '') ?>" disabled readonly
                                class="w-full border rounded px-3 py-2 text-base bg-slate-100 text-slate-600 cursor-not-allowed" />
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="age">
                                Age
                            </label>
                            <input type="number" id="age" name="age" min="0" max="150"
                                value="<?= h($user['age'] ?? '') ?>" disabled readonly
                                class="w-full border rounded px-3 py-2 text-base bg-slate-100 text-slate-600 cursor-not-allowed" />
                        </div>
                    </div>
                </div>

                <!-- Account Setup Section -->
                <div class="border-b border-slate-200 pb-6">
                    <h3 class="text-lg font-semibold text-slate-700 mb-4">Account Setup</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="username">
                                Username <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="username" name="username" maxlength="20"
                                value="<?= h($formData['username']) ?>" required
                                class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                            <p class="text-xs text-slate-500 mt-1">Choose a unique username for login.</p>
                        </div>

                        <div>
                            <label class="block font-medium text-slate-700 mb-1 text-sm" for="password">
                                Password <span class="text-red-500">*</span>
                            </label>
                            <input type="password" id="password" name="password" required
                                class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                            <p class="text-xs text-slate-500 mt-1">Minimum 6 characters.</p>
                        </div>
                    </div>
                </div>

                <!-- Agency Assignment Section -->
                <div>
                    <h3 class="text-lg font-semibold text-slate-700 mb-4">Agency Assignment</h3>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm" for="agency_id">
                            Agency <span class="text-red-500">*</span>
                        </label>
                        <select id="agency_id" name="agency_id" required
                            class="w-full border rounded px-3 py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none">
                            <option value="">Select Agency</option>
                            <?php foreach ($agencies as $agency): ?>
                                <option value="<?= h((string) $agency['id']) ?>" <?= $formData['agency_id'] == $agency['id'] ? 'selected' : '' ?>>
                                    <?= h($agency['agency_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Role (Display Only) -->
                <div>
                    <label class="block font-medium text-slate-700 mb-1 text-sm">
                        Role
                    </label>
                    <input type="text" value="Rep" disabled
                        class="w-full border rounded px-3 py-2 text-base bg-slate-100 text-slate-600" />
                    <p class="text-xs text-slate-500 mt-1">Role is fixed as 'Rep' and cannot be changed.</p>
                </div>

                <!-- Submit Button -->
                <div class="pt-4">
                    <button type="submit"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 px-6 rounded transition">
                        Complete Setup
                    </button>
                </div>
            </form>
        </div>
    </main>
</body>

</html>