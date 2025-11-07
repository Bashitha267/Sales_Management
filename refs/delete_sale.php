<?php
session_start();
include '../config.php';

// 1. Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Authentication required.");
}

// 2. Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Sale ID.");
}

$sale_id = (int) $_GET['id'];
$ref_id = (int) $_SESSION['user_id'];

// 3. Security Check: Verify this user OWNS this sale before deleting
$sql_check = "SELECT sale_id FROM sales_log WHERE sale_id = ? AND ref_id = ?";
$stmt_check = $mysqli->prepare($sql_check);
$stmt_check->bind_param("ii", $sale_id, $ref_id);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

if ($result_check->num_rows == 1) {
    // 4. User is owner. Proceed with deletion (in a transaction)
    $mysqli->begin_transaction();
    try {
        // Delete sale details first
        $stmt_del_details = $mysqli->prepare("DELETE FROM sale_details WHERE sale_id = ?");
        $stmt_del_details->bind_param("i", $sale_id);
        $stmt_del_details->execute();

        // Delete main sale log
        $stmt_del_log = $mysqli->prepare("DELETE FROM sales_log WHERE sale_id = ?");
        $stmt_del_log->bind_param("i", $sale_id);
        $stmt_del_log->execute();

        // Commit changes
        $mysqli->commit();

    } catch (mysqli_sql_exception $exception) {
        $mysqli->rollback();
        die("Error deleting sale: " . $exception->getMessage());
    }

} else {
    // User does not own this sale or it doesn't exist
    die("Error: You do not have permission to delete this sale.");
}

// 5. Redirect back to the sales list
header("Location: view_sales.php?deleted=true"); // Added a param for success
exit;
?>