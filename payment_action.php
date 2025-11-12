<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = (int) ($_POST['user_id'] ?? 0);
$year = (int) ($_POST['year'] ?? 0);
$month = (int) ($_POST['month'] ?? 0);

if ($user_id <= 0 || $year <= 0 || $month <= 0 || $month > 12) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data.']);
    exit();
}

$start_date = sprintf('%04d-%02d-01', $year, $month);
$end_date = date('Y-m-t', strtotime($start_date));

$mysqli->begin_transaction();
try {
    // Recompute points on the server: FULL SALES only, unredeemed, within the month
    // MODIFIED: Joined with sales table
    $stmt = $mysqli->prepare("
        SELECT SUM(pl.points_rep) AS total_points
        FROM points_ledger pl
        INNER JOIN sales s ON pl.sale_id = s.id
        WHERE pl.rep_user_id = ?
          AND pl.redeemed = 0
          AND pl.sale_date BETWEEN ? AND ?
          AND s.sale_type = 'full'
    ");
    $stmt->bind_param('iss', $user_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $points_to_pay = (int) ($result['total_points'] ?? 0);
    if ($points_to_pay <= 0) {
        throw new Exception('No unredeemed points found for this month.');
    }

    $amount_to_pay = $points_to_pay * 0.1; // Rs per point
    $remarks = "Monthly payment for $points_to_pay points ($start_date to $end_date).";

    // Insert payment record using unified schema
    $stmtPay = $mysqli->prepare("
        INSERT INTO payments (user_id, payment_type, points_redeemed, amount, remarks, paid_date)
        VALUES (?, 'monthly', ?, ?, ?, NOW())
    ");
    $stmtPay->bind_param('iids', $user_id, $points_to_pay, $amount_to_pay, $remarks);
    $stmtPay->execute();
    $stmtPay->close();

    // Mark ledger rows as redeemed for that month
    // MODIFIED: Joined with sales table to only update 'full' sales
    $stmtUpd = $mysqli->prepare("
        UPDATE points_ledger pl
        INNER JOIN sales s ON pl.sale_id = s.id
        SET pl.redeemed = 1
        WHERE pl.rep_user_id = ?
          AND pl.redeemed = 0
          AND pl.sale_date BETWEEN ? AND ?
          AND s.sale_type = 'full'
    ");
    $stmtUpd->bind_param('iss', $user_id, $start_date, $end_date);
    $stmtUpd->execute();
    $stmtUpd->close();

    $mysqli->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $mysqli->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>