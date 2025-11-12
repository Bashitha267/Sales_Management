<?php
// Session and role check
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

require_once '../config.php';

// Variables for messages
$success = "";
$error = "";

// Process the form when submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get POST data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $nic_number = trim($_POST['nic_number'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $join_date = trim($_POST['join_date'] ?? '');
    $age = trim($_POST['age'] ?? '');
    $bank_account = trim($_POST['bank_account'] ?? '');
    $bank_name = trim($_POST['bank_name'] ?? '');
    $branch = trim($_POST['branch'] ?? '');
    $account_holder = trim($_POST['account_holder'] ?? '');

    // Basic validation
    if ($first_name === '' || $last_name === '' || $username === '' || $password === '' || $role === '' || $email === '' || $nic_number === '') {
        $error = "Please fill in all required fields.";
    } elseif (!in_array($role, ['admin', 'rep', 'representative', 'sale_admin'])) {
        $error = "Invalid role selected.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif ($age !== '' && (!is_numeric($age) || intval($age) < 0 || intval($age) > 150)) {
        $error = "Age must be a valid number between 0 and 150.";
    } else {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Start transaction
        $mysqli->begin_transaction();

        try {
            // Prepare the SQL statement
            $stmt = $mysqli->prepare("INSERT INTO users (first_name, last_name, username, password, role, contact_number, email, nic_number, address, city, join_date, age, bank_account, bank_name, branch, account_holder) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmt) {
                throw new Exception("Database error: " . htmlspecialchars($mysqli->error));
            }

            // Convert empty strings to NULL for optional fields
            $join_date = $join_date === '' ? null : $join_date;
            $age = $age === '' ? null : intval($age);
            $contact_number = $contact_number === '' ? null : $contact_number;
            $address = $address === '' ? null : $address;
            $city = $city === '' ? null : $city;
            $bank_account = $bank_account === '' ? null : $bank_account;
            $bank_name = $bank_name === '' ? null : $bank_name;
            $branch = $branch === '' ? null : $branch;
            $account_holder = $account_holder === '' ? null : $account_holder;

            $stmt->bind_param(
                "sssssssssssissss",
                $first_name,
                $last_name,
                $username,
                $hashed_password,
                $role,
                $contact_number,
                $email,
                $nic_number,
                $address,
                $city,
                $join_date,
                $age,
                $bank_account,
                $bank_name,
                $branch,
                $account_holder
            );

            if (!$stmt->execute()) {
                // Check for specific error codes
                if ($mysqli->errno == 1062) { // Duplicate entry
                    throw new Exception("Username, email, or NIC number already exists.");
                } else {
                    throw new Exception("Database error: " . htmlspecialchars($mysqli->error));
                }
            }

            $new_user_id = $mysqli->insert_id;
            $stmt->close();

            // If role is 'representative', create agency 1 and agency 2
            if ($role === 'representative' && $new_user_id) {
                $agency_stmt = $mysqli->prepare("INSERT INTO agencies (representative_id, agency_name) VALUES (?, ?)");
                if (!$agency_stmt) {
                    throw new Exception("Database error: " . htmlspecialchars($mysqli->error));
                }

                // Insert agency 1
                $agency_name_1 = 'agency 1';
                $agency_stmt->bind_param("is", $new_user_id, $agency_name_1);
                if (!$agency_stmt->execute()) {
                    throw new Exception("Failed to create agency 1: " . htmlspecialchars($mysqli->error));
                }

                // Insert agency 2
                $agency_name_2 = 'agency 2';
                $agency_stmt->bind_param("is", $new_user_id, $agency_name_2);
                if (!$agency_stmt->execute()) {
                    throw new Exception("Failed to create agency 2: " . htmlspecialchars($mysqli->error));
                }

                $agency_stmt->close();
            }

            // Commit transaction
            $mysqli->commit();
            $success = "User successfully created!";
            // Reset form values
            $_POST = array();
        } catch (Exception $e) {
            // Rollback transaction on error
            $mysqli->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New User - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

    <!-- Include the admin header for session and role checks/UI -->
    <?php include_once("../admin_header.php"); ?>

    <div
        class="max-w-4xl mx-4 sm:mx-6 md:mx-auto mt-4 sm:mt-6 md:mt-8 lg:mt-10 bg-white shadow-lg rounded-lg p-4 sm:p-6 md:p-8 mb-8">
        <h2 class="text-xl sm:text-2xl md:text-3xl font-bold mb-4 sm:mb-5 md:mb-6 text-slate-700 flex items-center">
            <img src="https://img.icons8.com/fluency/48/add-user-male.png"
                class="w-6 h-6 sm:w-7 sm:h-7 md:w-8 md:h-8 mr-2" alt="User" />
            <span class="text-lg sm:text-xl md:text-2xl">Add New User</span>
        </h2>

        <?php if ($success): ?>
            <div id="successMessage"
                class="bg-green-300 text-green-800 px-3 sm:px-4 py-2 rounded mb-3 sm:mb-4 text-sm sm:text-base border border-green-500">
                <?= htmlspecialchars($success) ?>
            </div>
            <script>
                setTimeout(function () {
                    const successMsg = document.getElementById('successMessage');
                    if (successMsg) {
                        successMsg.style.opacity = '0';
                        successMsg.style.transition = 'opacity 0.5s';
                        setTimeout(function () {
                            successMsg.remove();
                        }, 500);
                    }
                }, 3000);
            </script>
        <?php endif; ?>

        <?php if ($error): ?>
            <div
                class="bg-red-100 text-red-700 px-3 sm:px-4 py-2 rounded mb-3 sm:mb-4 text-sm sm:text-base border border-red-500">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4 sm:space-y-5">
            <!-- Personal Information Section -->
            <div class="border-b border-slate-200 pb-4 mb-4">
                <h3 class="text-lg font-semibold text-slate-700 mb-4">Personal Information</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="first_name">
                            First Name<span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="first_name" name="first_name" maxlength="100"
                            value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="last_name">
                            Last Name<span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="last_name" name="last_name" maxlength="100"
                            value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="username">
                            Username<span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="username" name="username" maxlength="20"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="password">
                            Default Password<span class="text-red-500">*</span>
                        </label>
                        <input type="password" id="password" name="password" required
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="email">
                            Email<span class="text-red-500">*</span>
                        </label>
                        <input type="email" id="email" name="email" maxlength="100"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="nic_number">
                            NIC Number<span class="text-red-500">*</span>
                        </label>
                        <input type="text" id="nic_number" name="nic_number" maxlength="20"
                            value="<?= htmlspecialchars($_POST['nic_number'] ?? '') ?>" required
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="contact_number">
                            Contact Number
                        </label>
                        <input type="text" id="contact_number" name="contact_number" maxlength="20"
                            value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>"
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="age">
                            Age
                        </label>
                        <input type="number" id="age" name="age" min="0" max="150"
                            value="<?= htmlspecialchars($_POST['age'] ?? '') ?>"
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="role">
                            Role<span class="text-red-500">*</span>
                        </label>
                        <select id="role" name="role" required
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none">
                            <option value="">Select Role</option>
                            <option value="admin" <?= (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                            <option value="rep" <?= (isset($_POST['role']) && $_POST['role'] === 'rep') ? 'selected' : '' ?>>Rep</option>
                            <option value="representative" <?= (isset($_POST['role']) && $_POST['role'] === 'representative') ? 'selected' : '' ?>>Representative</option>
                            <option value="sale_admin" <?= (isset($_POST['role']) && $_POST['role'] === 'sale_admin') ? 'selected' : '' ?>>Sale Admin</option>
                        </select>
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="join_date">
                            Join Date
                        </label>
                        <input type="date" id="join_date" name="join_date"
                            value="<?= htmlspecialchars($_POST['join_date'] ?? '') ?>"
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="address">
                        Address
                    </label>
                    <textarea id="address" name="address" rows="2" maxlength="255"
                        class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                </div>

                <div class="mt-4">
                    <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="city">
                        City
                    </label>
                    <input type="text" id="city" name="city" maxlength="100"
                        value="<?= htmlspecialchars($_POST['city'] ?? '') ?>"
                        class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                </div>
            </div>

            <!-- Bank Information Section -->
            <div class="border-b border-slate-200 pb-4 mb-4">
                <h3 class="text-lg font-semibold text-slate-700 mb-4">Bank Information</h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="bank_name">
                            Bank Name
                        </label>
                        <input type="text" id="bank_name" name="bank_name" maxlength="100"
                            value="<?= htmlspecialchars($_POST['bank_name'] ?? '') ?>"
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="branch">
                            Branch
                        </label>
                        <input type="text" id="branch" name="branch" maxlength="100"
                            value="<?= htmlspecialchars($_POST['branch'] ?? '') ?>"
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="account_holder">
                            Account Holder Name
                        </label>
                        <input type="text" id="account_holder" name="account_holder" maxlength="100"
                            value="<?= htmlspecialchars($_POST['account_holder'] ?? '') ?>"
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-700 mb-1 text-sm sm:text-base" for="bank_account">
                            Bank Account Number
                        </label>
                        <input type="text" id="bank_account" name="bank_account" maxlength="50"
                            value="<?= htmlspecialchars($_POST['bank_account'] ?? '') ?>"
                            class="w-full border rounded px-3 sm:px-4 py-2.5 sm:py-2 text-base focus:ring focus:ring-indigo-200 focus:outline-none" />
                    </div>
                </div>
            </div>

            <div class="pt-3 sm:pt-4 md:pt-5">
                <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-semibold py-2.5 sm:py-2 md:py-3 rounded transition text-sm sm:text-base">
                    Add User
                </button>
            </div>
        </form>
    </div>

</body>

</html>