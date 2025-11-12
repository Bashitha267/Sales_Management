<?php
require_once '../auth.php';
requireLogin();
include '../config.php';

$representative_id = $_SESSION['user_id'] ?? 0;
$role = $_SESSION['role'] ?? '';

if ($representative_id <= 0 || $role !== 'representative') {
    header("Location: leader_dashboard.php");
    exit();
}

function set_flash_and_redirect(string $message, string $type = 'success'): void
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: manage_team.php");
    exit();
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? '';
$generated_invite_link = $_SESSION['generated_invite_link'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type'], $_SESSION['generated_invite_link']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'remove_member') {
        $agency_id = intval($_POST['agency_id'] ?? 0);
        $member_id = intval($_POST['member_id'] ?? 0);

        if ($agency_id <= 0 || $member_id <= 0) {
            set_flash_and_redirect('Invalid representative selection.', 'error');
        }

        $checkAgency = $mysqli->prepare("SELECT 1 FROM agencies WHERE id = ? AND representative_id = ?");
        $checkAgency->bind_param("ii", $agency_id, $representative_id);
        $checkAgency->execute();
        $ownsAgency = $checkAgency->get_result()->num_rows > 0;
        $checkAgency->close();

        if (!$ownsAgency) {
            set_flash_and_redirect('You do not have permission to manage that agency.', 'error');
        }

        $delete = $mysqli->prepare("DELETE FROM agency_reps WHERE agency_id = ? AND rep_user_id = ?");
        $delete->bind_param("ii", $agency_id, $member_id);
        $delete->execute();

        if ($delete->affected_rows > 0) {
            $delete->close();
            set_flash_and_redirect('Representative removed from the agency.', 'success');
        }

        $delete->close();
        set_flash_and_redirect('Representative was not found in that agency.', 'error');
    } elseif ($action === 'generate_link') {
        // Generate link with only representative_id (no agency selection needed)
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            set_flash_and_redirect('Unable to generate invite token. Please try again.', 'error');
        }

        // Insert invite with representative_id only (agency_id will be NULL, set later in add_new_reps.php)
        // Note: agency_id is nullable and will be set to NULL by default
        $insert = $mysqli->prepare("INSERT INTO agency_invites (representative_id, token) VALUES (?, ?)");
        if (!$insert) {
            set_flash_and_redirect('Failed to prepare invite creation. Please try again.', 'error');
        }

        $insert->bind_param("is", $representative_id, $token);
        if ($insert->execute()) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $currentDir = rtrim(dirname($scriptName), '/\\');
            $baseDir = $currentDir === '' || $currentDir === '.' ? '' : rtrim(dirname($currentDir), '/\\');
            $invitePath = rtrim($baseDir, '/\\') . '/refs/join_agency.php';
            $invitePath = '/' . ltrim(preg_replace('#/+#', '/', $invitePath), '/');
            $invite_link = sprintf('%s://%s%s?token=%s', $scheme, $host, $invitePath, $token);
            $_SESSION['generated_invite_link'] = $invite_link;
            $insert->close();
            set_flash_and_redirect('Invite link generated successfully.', 'success');
        }

        $error = $insert->error;
        $insert->close();
        set_flash_and_redirect('Failed to generate invite link. ' . (!empty($error) ? 'Error: ' . $error : 'Please try again.'), 'error');
    }
}

// --- Fetch Agencies ---
$agencies = [];
$agencyStmt = $mysqli->prepare("SELECT id, agency_name FROM agencies WHERE representative_id = ? ORDER BY agency_name");
$agencyStmt->bind_param("i", $representative_id);
$agencyStmt->execute();
$agencyResult = $agencyStmt->get_result();
while ($row = $agencyResult->fetch_assoc()) {
    $row['members'] = [];
    $agencies[$row['id']] = $row;
}
$agencyStmt->close();

// Ensure the representative always has Agency 1 and Agency 2
$expectedNames = ['Agency 1', 'Agency 2'];
if (count($agencies) < 2) {
    $existingNames = array_map(static fn($agency) => $agency['agency_name'], $agencies);
    foreach ($expectedNames as $expectedName) {
        if (!in_array($expectedName, $existingNames, true)) {
            $insertAgency = $mysqli->prepare("INSERT INTO agencies (representative_id, agency_name) VALUES (?, ?)");
            $insertAgency->bind_param("is", $representative_id, $expectedName);
            $insertAgency->execute();
            $newId = $insertAgency->insert_id;
            $agencies[$newId] = [
                'id' => $newId,
                'agency_name' => $expectedName,
                'members' => [],
            ];
            $insertAgency->close();
        }
    }
    // Re-fetch agencies to keep ordering consistent after potential inserts
    $agencies = [];
    $agencyStmt = $mysqli->prepare("SELECT id, agency_name FROM agencies WHERE representative_id = ? ORDER BY agency_name");
    $agencyStmt->bind_param("i", $representative_id);
    $agencyStmt->execute();
    $agencyResult = $agencyStmt->get_result();
    while ($row = $agencyResult->fetch_assoc()) {
        $row['members'] = [];
        $agencies[$row['id']] = $row;
    }
    $agencyStmt->close();
}

// --- Fetch Members for each Agency ---
if (!empty($agencies)) {
    $memberStmt = $mysqli->prepare("
        SELECT ar.agency_id,
               u.id,
               u.first_name,
               u.last_name,
               u.username,
               u.email
        FROM agency_reps ar
        INNER JOIN users u ON u.id = ar.rep_user_id
        WHERE ar.agency_id = ?
        ORDER BY u.first_name, u.last_name
    ");

    foreach ($agencies as &$agency) {
        $agencyId = $agency['id'];
        $memberStmt->bind_param("i", $agencyId);
        $memberStmt->execute();
        $agency['members'] = $memberStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    unset($agency);
    $memberStmt->close();
}

// --- Fetch Pending Requests ---
$pendingRequests = [];
$requestStmt = $mysqli->prepare("
    SELECT ai.id,
           ai.agency_id,
           ai.rep_user_id,
           ai.created_at,
           ag.agency_name,
           u.first_name,
           u.last_name,
           u.username,
           u.email,
           u.nic_number,
           u.contact_number,
           u.age
    FROM agency_invites ai
    LEFT JOIN agencies ag ON ag.id = ai.agency_id
    LEFT JOIN users u ON u.id = ai.rep_user_id
    WHERE ai.representative_id = ?
      AND ai.status = 'pending'
      AND ai.rep_user_id IS NOT NULL
    ORDER BY ai.created_at DESC
");
$requestStmt->bind_param("i", $representative_id);
$requestStmt->execute();
$pendingRequests = $requestStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$requestStmt->close();

// $mysqli->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Agencies</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <?php include 'leader_header.php'; ?>

    <main class="max-w-5xl mx-auto py-10 px-4 space-y-10">
        <header class="flex items-center justify-between flex-wrap gap-4">
            <h1 class="text-3xl font-bold text-slate-900 flex items-center gap-3">
                <span data-feather="users" class="text-indigo-600"></span>
                Manage Your Agencies
            </h1>

        </header>

        <?php if (!empty($flash_message)): ?>
            <?php
            $flashStyles = $flash_type === 'error'
                ? 'bg-red-100 text-red-800 border border-red-200'
                : 'bg-green-100 text-green-800 border border-green-200';
            ?>
            <div class="p-4 rounded-md <?= $flashStyles ?>">
                <?= h($flash_message) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($generated_invite_link)): ?>
            <div
                class="p-4 rounded-md border border-indigo-200 bg-indigo-50 text-sm text-indigo-900 flex flex-col gap-1 relative">
                <span class="font-semibold flex items-center gap-2 pr-24">
                    Invite Link
                </span>
                <a href="<?= h($generated_invite_link) ?>" target="_blank"
                    class="break-all text-indigo-700 hover:text-indigo-900">
                    <?= h($generated_invite_link) ?>
                </a>
                <span class="text-xs text-slate-500">Share this link with reps to let them request access.</span>
                <button type="button"
                    class="flex items-center gap-1 px-2 py-1 rounded-md bg-indigo-100 text-indigo-700 hover:bg-indigo-200 transition text-xs font-medium copy-invite-link absolute bottom-4 right-4"
                    data-copy="<?= h($generated_invite_link) ?>" aria-label="Copy invite link">
                    <span data-feather="copy"></span>
                    Copy
                </button>
            </div>
        <?php endif; ?>

        <section class="bg-white rounded-xl shadow-sm border border-slate-200">
            <div class="p-6 border-b border-slate-200 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-slate-900 flex items-center gap-2">
                        <span data-feather="help-circle" class="text-indigo-600"></span>
                        Pending Requests
                    </h2>
                    <p class="text-sm text-slate-500">
                        New reps who registered using your invite links appear here. Click on a request to complete
                        their setup (set username, password, and assign agency).
                    </p>
                </div>
                <form method="post" class="flex items-center gap-2">
                    <input type="hidden" name="action" value="generate_link">
                    <button type="submit"
                        class="inline-flex items-center justify-center px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition">
                        Generate invite link
                    </button>
                </form>
            </div>

            <?php if (empty($pendingRequests)): ?>
                <div class="p-6 text-slate-500 text-sm">
                    No pending requests right now. Share an invite link to let reps request access.
                </div>
            <?php else: ?>
                <ul class="divide-y divide-slate-200">
                    <?php foreach ($pendingRequests as $request): ?>
                        <li class="p-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                            <div class="flex-1">
                                <p class="text-base font-semibold text-slate-900">
                                    <?= h(trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''))) ?>
                                    <?php if (!empty($request['username'])): ?>
                                        <span class="text-sm text-slate-500">@<?= h($request['username']) ?></span>
                                    <?php endif; ?>
                                </p>
                                <?php if (!empty($request['email'])): ?>
                                    <p class="text-sm text-slate-500"><?= h($request['email']) ?></p>
                                <?php endif; ?>
                                <p class="text-sm text-slate-600 mt-1">
                                    <?php if (!empty($request['agency_name'])): ?>
                                        Agency: <?= h($request['agency_name']) ?> •
                                    <?php else: ?>
                                        Agency: Not assigned yet •
                                    <?php endif; ?>
                                    <?php if (!empty($request['created_at'])): ?>
                                        <?= h(date('M d, Y g:i A', strtotime($request['created_at']))) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if (empty($request['username'])): ?>
                                    <p class="text-xs text-amber-600 mt-1 font-medium">
                                        ⚠️ Setup required: Username and password not set
                                    </p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="add_new_reps.php?id=<?= $request['id'] ?>"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 transition">
                                    <span data-feather="user-plus"></span>
                                    Complete Setup
                                </a>
                                <a href="approve_invite.php?id=<?= $request['id'] ?>&action=reject"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-red-100 text-red-700 text-sm font-medium hover:bg-red-200 transition">
                                    <span data-feather="x"></span>
                                    Decline
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="space-y-6">
            <div class="flex items-center justify-between flex-wrap gap-3">
                <h2 class="text-2xl font-semibold text-slate-900">Your Agencies</h2>
                <p class="text-sm text-slate-500">
                    Each rep can only belong to one of your agencies at a time.
                </p>
            </div>

            <?php if (empty($agencies)): ?>
                <div class="bg-white border border-slate-200 rounded-xl p-6 text-center text-slate-500">
                    Agencies not found. Please contact an administrator.
                </div>
            <?php else: ?>
                <div class="grid gap-6 md:grid-cols-2">
                    <?php foreach ($agencies as $agency): ?>
                        <article class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                            <header class="p-6 border-b border-slate-200 bg-slate-50">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-lg font-semibold text-indigo-700"><?= h($agency['agency_name']) ?></h3>
                                    <span class="text-xs uppercase tracking-wide text-slate-500">
                                        ID: <?= h((string) $agency['id']) ?>
                                    </span>
                                </div>
                                <p class="text-sm text-slate-500 mt-1">
                                    <?= count($agency['members']) ?> member<?= count($agency['members']) === 1 ? '' : 's' ?>
                                </p>
                            </header>

                            <div class="p-6 space-y-4">
                                <?php if (empty($agency['members'])): ?>
                                    <p class="text-sm text-slate-500">
                                        No reps assigned yet. Approve a pending request to place them here.
                                    </p>
                                <?php else: ?>
                                    <ul class="space-y-3">
                                        <?php foreach ($agency['members'] as $member): ?>
                                            <li
                                                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border border-slate-200 rounded-md px-4 py-3">
                                                <div>
                                                    <p class="font-semibold text-slate-900">
                                                        <?= h(trim($member['first_name'] . ' ' . $member['last_name'])) ?>
                                                    </p>
                                                    <p class="text-sm text-slate-500">
                                                        @<?= h($member['username']) ?>
                                                        <?php if (!empty($member['email'])): ?>
                                                            • <?= h($member['email']) ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                                <form method="post" class="flex items-center gap-2">
                                                    <input type="hidden" name="action" value="remove_member">
                                                    <input type="hidden" name="agency_id" value="<?= h((string) $agency['id']) ?>">
                                                    <input type="hidden" name="member_id" value="<?= h((string) $member['id']) ?>">

                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
        feather.replace();

        document.querySelectorAll('.copy-invite-link').forEach((button) => {
            button.addEventListener('click', async () => {
                const value = button.getAttribute('data-copy') ?? '';

                try {
                    await navigator.clipboard.writeText(value);
                    button.textContent = 'Copied!';
                    setTimeout(() => {
                        button.innerHTML = '<span data-feather="copy"></span>Copy';
                        feather.replace();
                    }, 1500);
                } catch (error) {
                    console.error('Failed to copy invite link', error);
                    button.textContent = 'Try again';
                    setTimeout(() => {
                        button.innerHTML = '<span data-feather="copy"></span>Copy';
                        feather.replace();
                    }, 1500);
                }
            });
        });
    </script>
</body>

</html>