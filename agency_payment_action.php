<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$agency_1_id = (int) $_POST['agency_1_id'];
$agency_2_id = (int) $_POST['agency_2_id'];
$user_id = (int) $_POST['user_id']; // The representative who gets paid
$agency_1_points = (int) $_POST['agency_1_points']; // Total points in agency 1 (for reference)
$agency_2_points = (int) $_POST['agency_2_points']; // Total points in agency 2 (for reference)
$amount = (float) $_POST['amount'];
$payment_points = (int) $_POST['payment_points']; // 10000 points (for payment record)
$remarks = $_POST['remarks'] ?? 'Agency bonus payment';

if ($agency_1_id <= 0 || $agency_2_id <= 0 || $user_id <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data provided. All values must be positive.']);
    exit();
}

$mysqli->begin_transaction();
try {
    // 1. First, get the actual total points from points_ledger_group_points for each agency
    // IMPORTANT: Only get points from points_ledger_group_points (agency bonus points)
    $stmt_get_ag1 = $mysqli->prepare("
        SELECT COALESCE(SUM(pl.points), 0) AS total_points
        FROM points_ledger_group_points pl
        INNER JOIN sales s ON pl.sale_id = s.id
        WHERE pl.agency_id = ? 
          AND pl.representative_id = ? 
          AND pl.redeemed = 0 
          AND s.sale_type = 'full'
          AND s.sale_approved = 1
    ");
    $stmt_get_ag1->bind_param('ii', $agency_1_id, $user_id);
    $stmt_get_ag1->execute();
    $ag1_result = $stmt_get_ag1->get_result()->fetch_assoc();
    $actual_ag1_points = (int) ($ag1_result['total_points'] ?? 0);
    $stmt_get_ag1->close();

    $stmt_get_ag2 = $mysqli->prepare("
        SELECT COALESCE(SUM(pl.points), 0) AS total_points
        FROM points_ledger_group_points pl
        INNER JOIN sales s ON pl.sale_id = s.id
        WHERE pl.agency_id = ? 
          AND pl.representative_id = ? 
          AND pl.redeemed = 0 
          AND s.sale_type = 'full'
          AND s.sale_approved = 1
    ");
    $stmt_get_ag2->bind_param('ii', $agency_2_id, $user_id);
    $stmt_get_ag2->execute();
    $ag2_result = $stmt_get_ag2->get_result()->fetch_assoc();
    $actual_ag2_points = (int) ($ag2_result['total_points'] ?? 0);
    $stmt_get_ag2->close();

    // 2. Mark agency points as redeemed in points_ledger_group_points for Agency 1
    // Only redeem points_ledger_group_points records (agency bonus points)
    $stmt_redeem_ag1 = $mysqli->prepare("
        UPDATE points_ledger_group_points pl
        INNER JOIN sales s ON pl.sale_id = s.id
        SET pl.redeemed = 1
        WHERE pl.agency_id = ? 
          AND pl.representative_id = ? 
          AND pl.redeemed = 0 
          AND s.sale_type = 'full'
          AND s.sale_approved = 1
    ");
    $stmt_redeem_ag1->bind_param('ii', $agency_1_id, $user_id);
    $stmt_redeem_ag1->execute();
    $stmt_redeem_ag1->close();

    // 3. Mark agency points as redeemed in points_ledger_group_points for Agency 2
    $stmt_redeem_ag2 = $mysqli->prepare("
        UPDATE points_ledger_group_points pl
        INNER JOIN sales s ON pl.sale_id = s.id
        SET pl.redeemed = 1
        WHERE pl.agency_id = ? 
          AND pl.representative_id = ? 
          AND pl.redeemed = 0 
          AND s.sale_type = 'full'
          AND s.sale_approved = 1
    ");
    $stmt_redeem_ag2->bind_param('ii', $agency_2_id, $user_id);
    $stmt_redeem_ag2->execute();
    $stmt_redeem_ag2->close();

    // 6. Insert a record into the payments table for the representative
    // Payment is recorded as 10,000 points (5,000 from each agency) for Rs. 1,000
    // All points from both agencies are redeemed, but payment is fixed at Rs. 1,000
    $stmt_pay = $mysqli->prepare("INSERT INTO payments (user_id, payment_type, points_redeemed, amount, remarks) VALUES (?, 'agency', ?, ?, ?)");
    $stmt_pay->bind_param('iids', $user_id, $payment_points, $amount, $remarks);
    $stmt_pay->execute();
    $stmt_pay->close();

    $mysqli->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Payment processed successfully. All points redeemed from both agencies.',
        'agency_1_redeemed' => $actual_ag1_points,
        'agency_2_redeemed' => $actual_ag2_points,
        'payment_amount' => $amount,
        'payment_points' => $payment_points
    ]);

} catch (Exception $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>