<?php
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = (int) $_POST['user_id'];
    $points = (int) $_POST['points'];
    $year = (int) $_POST['year'];
    $month = (int) $_POST['month'];
    $amount = $points * 0.05;

    // Check if already exists
    $exists = $mysqli->query("SELECT payment_id FROM payments WHERE user_id=$user_id AND year=$year AND month=$month");
    if ($exists->num_rows > 0) {
        $mysqli->query("UPDATE payments SET status='paid', amount_paid=$amount, paid_at=NOW() 
                        WHERE user_id=$user_id AND year=$year AND month=$month");
    } else {
        $stmt = $mysqli->prepare("INSERT INTO payments (user_id, year, month, points_earned, amount_paid, status, paid_at) 
                                  VALUES (?, ?, ?, ?, ?, 'paid', NOW())");
        $stmt->bind_param("iiiid", $user_id, $year, $month, $points, $amount);
        $stmt->execute();
    }
    echo "success";
}
?>