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

// Only handle reject action now (accept is handled in add_new_reps.php)
if ($action === 'reject') {
    // If user exists, we can optionally delete the user account or just reject the invite
    $rep_user_id = intval($invite['rep_user_id'] ?? 0);

    $mysqli->begin_transaction();
    try {
        // Reject the invite
        $updateStmt = $mysqli->prepare("UPDATE agency_invites SET status = 'rejected' WHERE id = ?");
        $updateStmt->bind_param("i", $id);
        $updateStmt->execute();
        $updateStmt->close();

        // Optionally: If user was created but setup not completed, we could delete the user
        // For now, we'll just reject the invite and leave the user account
        // (The representative can complete setup later if needed)

        $mysqli->commit();
        flash_and_redirect('Invite rejected.', 'success');
    } catch (Exception $e) {
        $mysqli->rollback();
        flash_and_redirect('Error rejecting invite: ' . htmlspecialchars($e->getMessage()), 'error');
    }
} else {
    // Accept action should redirect to add_new_reps.php instead
    flash_and_redirect('Please use the "Complete Setup" button to approve this invite.', 'error');
}
