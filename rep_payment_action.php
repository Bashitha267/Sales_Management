<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// --- 1. GET AND VALIDATE POST DATA ---
$user_id = (int) $_POST['user_id'];
$start_date = $_POST['start_date'] ?? null;
$end_date = $_POST['end_date'] ?? null;

// Validate the inputs
if ($user_id <= 0 || !$start_date || !$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid data. User ID or date range is missing.']);
    exit();
}


// --- 2. PROCESS PAYMENT IN A TRANSACTION ---
$mysqli->begin_transaction();
try {
    // 1. RE-CALCULATE points and amount on the server. DO NOT TRUST CLIENT.
    // Query from points_ledger_rep table for rep points
    $stmt = $mysqli->prepare("
        SELECT SUM(pl.points) AS total_points 
        FROM points_ledger_rep pl
        INNER JOIN sales s ON pl.sale_id = s.id
        WHERE pl.rep_user_id = ? 
          AND pl.redeemed = 0 
          AND pl.sale_date BETWEEN ? AND ?
          AND s.sale_type = 'full'
          AND s.sale_approved = 1
    ");
    $stmt->bind_param('iss', $user_id, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $points_to_pay = (int) ($result['total_points'] ?? 0);

    // Check if there is anything to pay
    if ($points_to_pay <= 0) {
        throw new Exception("No unredeemed points found for this period. Payment may have already been processed.");
    }

    $amount_to_pay = $points_to_pay * 0.1;
    $remarks = "Weekly payment for $points_to_pay points (Period: $start_date to $end_date).";

    // 2. Insert a record into the payments table
    $stmt = $mysqli->prepare("INSERT INTO payments (user_id, payment_type, points_redeemed, amount, remarks) VALUES (?, 'weekly', ?, ?, ?)");
    $stmt->bind_param('iids', $user_id, $points_to_pay, $amount_to_pay, $remarks);
    $stmt->execute();
    $stmt->close();

    // 3. Mark all unredeemed points *in that period* as redeemed in points_ledger_rep
    $stmt = $mysqli->prepare("
        UPDATE points_ledger_rep pl
        INNER JOIN sales s ON pl.sale_id = s.id
        SET pl.redeemed = 1 
        WHERE pl.rep_user_id = ? 
          AND pl.redeemed = 0 
          AND pl.sale_date BETWEEN ? AND ?
          AND s.sale_type = 'full'
          AND s.sale_approved = 1
    ");
    $stmt->bind_param('iss', $user_id, $start_date, $end_date);
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