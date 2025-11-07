<?php
session_start(); // Must be at the very top

// --- ADMIN-ONLY SECURITY CHECK ---
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
    header("Location: /ref/login.php");
    exit();
}

$message = '';
$message_type = '';

// --- FORM PROCESSING ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- 1. DATABASE CONNECTION ---
    require_once '../config.php'; // Provides $mysqli

    // --- 2. GET FORM DATA ---
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];
    $contact_number = $_POST['contact_number'];
    $email = $_POST['email'];
    $nic_number = $_POST['nic_number'];
    $address = $_POST['address'];
    $city = $_POST['city'];
    $join_date = $_POST['join_date'];
    $age = $_POST['age'];

    // Optional bank details
    $bank_account = $_POST['bank_account'] ?? null;
    $bank_name = $_POST['bank_name'] ?? null;
    $branch = $_POST['branch'] ?? null;
    $account_holder = $_POST['account_holder'] ?? null;

    // --- 3. VALIDATE REQUIRED FIELDS ---
    if (empty($first_name) || empty($last_name) || empty($username) || empty($password) || empty($role) || empty($email) || empty($nic_number)) {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    } else {
        // --- 4. HASH PASSWORD & EXECUTE SQL ---
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        try {
            $sql = "INSERT INTO users 
                    (first_name, last_name, username, password, role, contact_number, email, nic_number, address, city, join_date, age, bank_account, bank_name, branch, account_holder) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param(
                "ssssssssssisssss",
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

            if ($stmt->execute()) {
                $message = "New user created successfully!";
                $message_type = "success";
            }

            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) {
                $message = "Error: Username, Email, or NIC Number already exists.";
                $message_type = "error";
            } else {
                $message = "An error occurred. Please try again.";
                $message_type = "error";
                error_log("User Creation Error: " . $e->getMessage());
            }
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
    <title>Add New User</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest/dist/lucide.min.js" defer></script>
</head>

<body class="bg-slate-100 min-h-screen flex flex-col">

    <!-- 🧭 HEADER -->
    <?php include '../admin_header.php'; ?>

    <!-- Toast Container -->
    <div id="toast-container" class="fixed top-4 right-4 z-50 space-y-3 w-full max-w-sm"></div>

    <!-- Main Content -->
    <main class="flex-grow flex items-center justify-center py-12 px-4">
        <div class="max-w-3xl w-full bg-white p-8 md:p-12 rounded-xl shadow-lg">
            <div class="flex items-center space-x-3 mb-8">
                <h1 class="text-3xl font-bold text-slate-800">Add New User</h1>
            </div>

            <form action="add_new_user.php" method="POST" class="space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Basic Info -->
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-slate-700 mb-1">First Name <span
                                class="text-red-500">*</span></label>
                        <input type="text" id="first_name" name="first_name" required
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="last_name" class="block text-sm font-medium text-slate-700 mb-1">Last Name <span
                                class="text-red-500">*</span></label>
                        <input type="text" id="last_name" name="last_name" required
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="username" class="block text-sm font-medium text-slate-700 mb-1">Username <span
                                class="text-red-500">*</span></label>
                        <input type="text" id="username" name="username" required
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="role" class="block text-sm font-medium text-slate-700 mb-1">Role <span
                                class="text-red-500">*</span></label>
                        <select id="role" name="role" required
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="" disabled selected>Select a role...</option>
                            <option value="admin">Admin</option>
                            <option value="rep">Rep</option>
                            <option value="team leader">Team Leader</option>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Password <span
                                class="text-red-500">*</span></label>
                        <input type="password" id="password" name="password" required
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email <span
                                class="text-red-500">*</span></label>
                        <input type="email" id="email" name="email" required
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="contact_number" class="block text-sm font-medium text-slate-700 mb-1">Contact
                            Number</label>
                        <input type="text" id="contact_number" name="contact_number"
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="nic_number" class="block text-sm font-medium text-slate-700 mb-1">NIC Number <span
                                class="text-red-500">*</span></label>
                        <input type="text" id="nic_number" name="nic_number" required
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="age" class="block text-sm font-medium text-slate-700 mb-1">Age</label>
                        <input type="number" id="age" name="age"
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="md:col-span-2">
                        <label for="address" class="block text-sm font-medium text-slate-700 mb-1">Address</label>
                        <input type="text" id="address" name="address"
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="city" class="block text-sm font-medium text-slate-700 mb-1">City</label>
                        <input type="text" id="city" name="city"
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div>
                        <label for="join_date" class="block text-sm font-medium text-slate-700 mb-1">Join Date</label>
                        <input type="date" id="join_date" name="join_date"
                            class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <!-- 🏦 Bank Details Section -->
                <div class="mt-10 border-t border-slate-200 pt-6">
                    <h2 class="text-xl font-semibold text-slate-800 mb-4">Bank Details (Optional)</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="bank_account" class="block text-sm font-medium text-slate-700 mb-1">Bank Account
                                Number</label>
                            <input type="text" id="bank_account" name="bank_account"
                                class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="bank_name" class="block text-sm font-medium text-slate-700 mb-1">Bank
                                Name</label>
                            <input type="text" id="bank_name" name="bank_name"
                                class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="branch" class="block text-sm font-medium text-slate-700 mb-1">Branch</label>
                            <input type="text" id="branch" name="branch"
                                class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label for="account_holder" class="block text-sm font-medium text-slate-700 mb-1">Account
                                Holder Name</label>
                            <input type="text" id="account_holder" name="account_holder"
                                class="w-full px-4 py-2 border border-slate-300 rounded-md shadow-sm focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="pt-4">
                    <button type="submit"
                        class="w-full flex justify-center items-center space-x-2 bg-indigo-600 text-white px-6 py-3 rounded-md shadow-md text-lg font-medium hover:bg-indigo-700 transition">
                        <i data-lucide="check-circle" class="w-5 h-5"></i>
                        <span>Create User</span>
                    </button>
                </div>

            </form>
        </div>
    </main>

    <!-- Toast JS -->
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const message = <?php echo json_encode($message); ?>;
            const messageType = <?php echo json_encode($message_type); ?>;
            if (!message) return;

            const toastContainer = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = 'p-4 rounded-md shadow-lg max-w-sm transition-all duration-300 transform opacity-0 translate-x-full flex items-center space-x-3';

            let typeStyles = '';
            if (messageType === 'success') typeStyles = ' bg-green-100 border border-green-300 text-green-800';
            else if (messageType === 'error') typeStyles = ' bg-red-100 border border-red-300 text-red-800';
            else typeStyles = ' bg-gray-100 border border-gray-300 text-gray-800';

            toast.className += typeStyles;
            toast.innerHTML = `<span>${message}</span>`;
            toastContainer.appendChild(toast);

            setTimeout(() => toast.classList.remove('opacity-0', 'translate-x-full'), 100);
            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-x-full');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        });
    </script>
</body>

</html>