<?php
// Session and role check
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

// Variables for messages
$success = "";
$error = "";

// Process the form when submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get POST data
    $item_code = trim($_POST['item_code'] ?? '');
    $item_name = trim($_POST['item_name'] ?? '');
    $points_leader = trim($_POST['points_leader'] ?? '');
    $points_rep = trim($_POST['points_rep'] ?? '');
    $price = trim($_POST['price'] ?? '');

    // Basic validation
    if ($item_code === '' || $item_name === '' || $points_leader === '' || $points_rep === '' || $price === '') {
        $error = "Please fill in all required fields.";
    } elseif (!ctype_digit($points_leader) || !ctype_digit($points_rep)) {
        $error = "Points must be whole numbers.";
    } elseif (!is_numeric($price) || floatval($price) < 0) {
        $error = "Price must be a valid number greater than or equal to 0.";
    } else {
        // Database insert
        require_once("../config.php"); // Make sure this opens a $mysqli MySQLi connection

        $stmt = $mysqli->prepare("INSERT INTO items (item_code, item_name, points_leader, points_rep, price) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("ssiid", $item_code, $item_name, $points_leader, $points_rep, $price);
            if ($stmt->execute()) {
                $success = "Item successfully created!";
                // Reset values for fresh form
                $item_code = $item_name = $points_leader = $points_rep = $price = '';
            } else {
                if ($mysqli->errno == 1062) { // Duplicate entry
                    $error = "Item code already exists.";
                } else {
                    $error = "Database error: " . $mysqli->error;
                }
            }
            $stmt->close();
        } else {
            $error = "Database error: " . $mysqli->error;
        }
        $mysqli->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Item - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

    <!-- Include the admin header for session and role checks/UI -->
    <?php include_once("../admin_header.php"); ?>

    <div
        class="max-w-xl mx-4 sm:mx-6 md:mx-auto mt-4 sm:mt-6 md:mt-8 lg:mt-10 bg-white shadow-lg rounded-lg p-4 sm:p-6 md:p-8">
        <h2 class="text-xl sm:text-2xl md:text-3xl font-bold mb-4 sm:mb-5 md:mb-6 text-slate-700 flex items-center">
            <img src="https://img.icons8.com/fluency/48/add-item.png" class="w-6 h-6 sm:w-7 sm:h-7 md:w-8 md:h-8 mr-2"
                alt="Item" />
            <span class="text-lg sm:text-xl md:text-2xl">Create New Item</span>
        </h2>
        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-800 px-3 sm:px-4 py-2 rounded mb-3 sm:mb-4 text-sm sm:text-base">
                <?= $success ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 px-3 sm:px-4 py-2 rounded mb-3 sm:mb-4 text-sm sm:text-base"><?= $error ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4 sm:space-y-5">
            <div>
                <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="item_code">Item Code<span
                        class="text-red-500">*</span></label>
                <input type="text" id="item_code" name="item_code" maxlength="20"
                    value="<?= htmlspecialchars($item_code ?? '') ?>" required
                    class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
            </div>
            <div>
                <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="item_name">Item Name<span
                        class="text-red-500">*</span></label>
                <input type="text" id="item_name" name="item_name" maxlength="100"
                    value="<?= htmlspecialchars($item_name ?? '') ?>" required
                    class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
            </div>
            <div>
                <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="points_leader">Points for
                    Leader<span class="text-red-500">*</span></label>
                <input type="number" id="points_leader" name="points_leader" min="0"
                    value="<?= htmlspecialchars($points_leader ?? '') ?>" required
                    class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
            </div>
            <div>
                <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="points_rep">Points for
                    Rep<span class="text-red-500">*</span></label>
                <input type="number" id="points_rep" name="points_rep" min="0"
                    value="<?= htmlspecialchars($points_rep ?? '') ?>" required
                    class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
            </div>
            <div>
                <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="price">Price<span
                        class="text-red-500">*</span></label>
                <input type="number" id="price" name="price" min="0" step="0.01"
                    value="<?= htmlspecialchars($price ?? '') ?>" required
                    class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
            </div>
            <div class="pt-3 sm:pt-4 md:pt-5">
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-semibold py-2.5 sm:py-2 md:py-3 rounded transition text-sm sm:text-base">
                    Create Item
                </button>
            </div>
        </form>
    </div>

</body>

</html>