<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$agency_id = (int) $_POST['agency_id'];
$user_id = (int) $_POST['user_id']; // The representative who gets paid
$amount = (float) $_POST['amount'];
$points_redeemed = (int) $_POST['points_redeemed'];
$rep_points_to_subtract = (int) $_POST['rep_points_to_subtract'];
$rep_for_rep_points_to_subtract = (int) $_POST['rep_for_rep_points_to_subtract'];
$remarks = $_POST['remarks'] ?? 'Agency bonus payment';


if ($agency_id <= 0 || $user_id <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

$mysqli->begin_transaction();
try {
    // 1. Insert a record into the payments table for the representative
    $stmt = $mysqli->prepare("INSERT INTO payments (user_id, payment_type, points_redeemed, amount, remarks) VALUES (?, 'agency', ?, ?, ?)");
    $stmt->bind_param('iids', $user_id, $points_redeemed, $amount, $remarks);
    $stmt->execute();
    $stmt->close();

    // 2. Subtract the redeemed points from the agency_points summary table
    $stmt = $mysqli->prepare("
        UPDATE agency_points 
        SET 
            total_rep_points = total_rep_points - ?,
            total_representative_points = total_representative_points - ?
        WHERE agency_id = ?
    ");
    $stmt->bind_param('iii', $rep_points_to_subtract, $rep_for_rep_points_to_subtract, $agency_id);
    $stmt->execute();
    $stmt->close();

    $mysqli->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>