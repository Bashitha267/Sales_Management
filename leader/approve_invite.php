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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = strtolower($_GET['action'] ?? '');

if ($id <= 0 || !in_array($action, ['accept', 'reject'], true)) {
    flash_and_redirect('Invalid invite request.', 'error');
}

$inviteStmt = $mysqli->prepare("SELECT * FROM agency_invites WHERE id = ? AND representative_id = ?");
$inviteStmt->bind_param("ii", $id, $representative_id);
$inviteStmt->execute();
$invite = $inviteStmt->get_result()->fetch_assoc();
$inviteStmt->close();

if (!$invite) {
    flash_and_redirect('Invite not found or no longer available.', 'error');
}

if ($invite['status'] !== 'pending') {
    flash_and_redirect('This invite has already been processed.', 'error');
}

$agencyStmt = $mysqli->prepare("SELECT 1 FROM agencies WHERE id = ? AND representative_id = ?");
$agencyStmt->bind_param("ii", $invite['agency_id'], $representative_id);
$agencyStmt->execute();
$agencyExists = $agencyStmt->get_result()->num_rows > 0;
$agencyStmt->close();

if (!$agencyExists) {
    flash_and_redirect('You no longer manage the target agency.', 'error');
}

if ($action === 'reject') {
    $updateStmt = $mysqli->prepare("UPDATE agency_invites SET status = 'rejected' WHERE id = ?");
    $updateStmt->bind_param("i", $id);
    $updateStmt->execute();
    $updateStmt->close();
    $mysqli->close();
    flash_and_redirect('Invite rejected.', 'success');
}

$rep_user_id = intval($invite['rep_user_id'] ?? 0);
if ($rep_user_id <= 0) {
    flash_and_redirect('The rep must log in with the invite link before you can approve.', 'error');
}

$userStmt = $mysqli->prepare("SELECT role FROM users WHERE id = ?");
$userStmt->bind_param("i", $rep_user_id);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user || $user['role'] !== 'rep') {
    flash_and_redirect('Only reps can be added to your agency.', 'error');
}

$existingStmt = $mysqli->prepare("
    SELECT agency_id
    FROM agency_reps
    WHERE representative_id = ? AND rep_user_id = ?
    LIMIT 1
");
$existingStmt->bind_param("ii", $representative_id, $rep_user_id);
$existingStmt->execute();
$existingRelation = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

if ($existingRelation) {
    if (intval($existingRelation['agency_id']) === intval($invite['agency_id'])) {
        $updateStmt = $mysqli->prepare("UPDATE agency_invites SET status = 'accepted' WHERE id = ?");
        $updateStmt->bind_param("i", $id);
        $updateStmt->execute();
        $updateStmt->close();
        $mysqli->close();
        flash_and_redirect('That rep is already part of this agency.', 'success');
    }

    $updateStmt = $mysqli->prepare("UPDATE agency_invites SET status = 'rejected' WHERE id = ?");
    $updateStmt->bind_param("i", $id);
    $updateStmt->execute();
    $updateStmt->close();
    $mysqli->close();
    flash_and_redirect('Each rep can only belong to one of your agencies. Remove them first if you need to move them.', 'error');
}

$mysqli->begin_transaction();

try {
    $updateInvite = $mysqli->prepare("UPDATE agency_invites SET status = 'accepted' WHERE id = ?");
    $updateInvite->bind_param("i", $id);
    $updateInvite->execute();
    $updateInvite->close();

    $insertRep = $mysqli->prepare("INSERT INTO agency_reps (rep_user_id, representative_id, agency_id) VALUES (?, ?, ?)");
    $insertRep->bind_param("iii", $rep_user_id, $representative_id, $invite['agency_id']);
    $insertRep->execute();
    $insertRep->close();

    $mysqli->commit();
    $mysqli->close();
    flash_and_redirect('Invite approved. The rep is now part of the agency.', 'success');
} catch (Exception $e) {
    $mysqli->rollback();
    $mysqli->close();
    flash_and_redirect('Something went wrong while approving the invite.', 'error');
}
