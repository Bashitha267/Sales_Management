<?php
require_once '../auth.php';
requireLogin();

// Only admin can access
if ($_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

require_once '../config.php';

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: view_users.php');
    exit;
}
$id = intval($_GET['id']);

// Fetch user
$stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("❌ User not found.");
}
$user = $result->fetch_assoc();

// --- UPDATE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete'])) {
    $fields = [
        'first_name',
        'last_name',
        'username',
        'email',
        'role',
        'contact_number',
        'nic_number',
        'address',
        'city',
        'join_date',
        'age',
        'bank_account',
        'bank_name',
        'branch',
        'account_holder'
    ];

    $updates = [];
    $values = [];

    foreach ($fields as $field) {
        $updates[] = "$field = ?";
        $values[] = trim($_POST[$field] ?? '');
    }

    $new_password = trim($_POST['new_password']);
    if ($new_password !== '') {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $updates[] = "password = ?";
        $values[] = $hashed;
    }

    $values[] = $id;
    $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param(str_repeat('s', count($values) - 1) . 'i', ...$values);
    $stmt->execute();

    header('Location: view_users.php');
    exit;
}

// --- DELETE LOGIC ---
if (isset($_POST['delete'])) {
    $stmt = $mysqli->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    header('Location: view_users.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <!-- THIS IS THE FIX: The Viewport meta tag -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User Profile</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen">
    <?php include '../admin_header.php'; ?>

    <div class="max-w-4xl mx-auto bg-white shadow-lg rounded-2xl p-4 sm:p-8 border border-gray-200 mt-8 mb-12">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 text-center">Edit User Profile</h1>

        <form method="POST" class="flex flex-col gap-6">

            <!-- 👤 User Details -->
            <div>
                <h2 class="text-lg sm:text-xl font-semibold text-gray-800 border-b pb-2 mb-4">👤 User Details</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>"
                            required
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>" required
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                        <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                        <select name="role"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="representative" <?= $user['role'] === 'representative' ? 'selected' : '' ?>>
                                Representative</option>
                            <option value="rep" <?= $user['role'] === 'rep' ? 'selected' : '' ?>>Ref</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                        <input type="text" name="contact_number"
                            value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">NIC Number</label>
                        <input type="text" name="nic_number" value="<?= htmlspecialchars($user['nic_number']) ?>"
                            required
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                        <input type="text" name="city" value="<?= htmlspecialchars($user['city'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Age</label>
                        <input type="number" name="age" value="<?= htmlspecialchars($user['age'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Join Date</label>
                        <input type="date" name="join_date" value="<?= htmlspecialchars($user['join_date'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div class="col-span-1 sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                        <input type="text" name="address" value="<?= htmlspecialchars($user['address'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>

                    <div class="col-span-1 sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            New Password <span class="text-gray-500 text-xs">(leave blank to keep current)</span>
                        </label>
                        <input type="password" name="new_password" placeholder="Enter new password"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <!-- 🏦 Bank Details -->
            <div>
                <h2 class="text-lg sm:text-xl font-semibold text-gray-800 border-b pb-2 mb-4">🏦 Bank Details</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bank Account</label>
                        <input type="text" name="bank_account"
                            value="<?= htmlspecialchars($user['bank_account'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bank Name</label>
                        <input type="text" name="bank_name" value="<?= htmlspecialchars($user['bank_name'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Branch</label>
                        <input type="text" name="branch" value="<?= htmlspecialchars($user['branch'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Account Holder</label>
                        <input type="text" name="account_holder"
                            value="<?= htmlspecialchars($user['account_holder'] ?? '') ?>"
                            class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <!-- 🔘 Buttons -->
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4 pt-8">
                <button type="submit"
                    class="w-full sm:w-auto px-6 py-2 bg-indigo-600 text-white font-medium rounded-lg hover:bg-indigo-700 transition">
                    💾 Save Changes
                </button>

                <button type="submit" name="delete"
                    onclick="return confirm('⚠️ Are you sure you want to delete this user?')"
                    class="w-full sm:w-auto px-6 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition">
                    🗑 Delete User
                </button>
            </div>
        </form>
    </div>

    <!-- 
        MODAL SCRIPT
        This script replaces the 'confirm()' dialog which can be unreliable.
    -->
    <script>
        // Find all delete buttons
        const deleteButtons = document.querySelectorAll('button[name="delete"]');

        deleteButtons.forEach(button => {
            // Get the original onclick text
            const originalOnClick = button.getAttribute('onclick');

            // Clear the inline onclick to prevent it from firing
            button.setAttribute('onclick', '');

            // Add our own event listener
            button.addEventListener('click', function (event) {
                // Prevent the form from submitting immediately
                event.preventDefault();

                // Extract the confirmation message from the original attribute
                const message = originalOnClick.match(/confirm\('(.+)'\)/)[1] || 'Are you sure?';

                // Create and show the custom modal
                showCustomConfirm(message, () => {
                    // If user clicks "OK", submit the form
                    // We can do this by creating a hidden input field
                    // and then submitting the form.
                    const form = event.target.closest('form');

                    // Create a hidden input to signify deletion
                    const deleteInput = document.createElement('input');
                    deleteInput.type = 'hidden';
                    deleteInput.name = 'delete';
                    deleteInput.value = 'true';
                    form.appendChild(deleteInput);

                    // Now submit the form
                    form.submit();
                });
            });
        });

        function showCustomConfirm(message, onConfirm) {
            // Remove any existing modal
            const existingModal = document.getElementById('custom-confirm-modal');
            if (existingModal) {
                existingModal.remove();
            }

            // Create modal elements
            const modalOverlay = document.createElement('div');
            modalOverlay.id = 'custom-confirm-modal';
            modalOverlay.className = 'fixed inset-0 bg-gray-900 bg-opacity-60 flex items-center justify-center p-4 z-50';

            const modalBox = document.createElement('div');
            modalBox.className = 'bg-white rounded-2xl shadow-xl max-w-sm w-full p-6 mx-4';

            const modalTitle = document.createElement('h3');
            modalTitle.className = 'text-xl font-semibold text-gray-800 mb-4';
            modalTitle.textContent = 'Please Confirm';

            const modalMessage = document.createElement('p');
            modalMessage.className = 'text-gray-700 mb-6';
            modalMessage.textContent = message;

            const buttonGroup = document.createElement('div');
            buttonGroup.className = 'flex justify-end gap-3';

            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.className = 'px-5 py-2 bg-gray-200 text-gray-800 font-medium rounded-lg hover:bg-gray-300 transition';
            cancelButton.textContent = 'Cancel';

            const confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = 'px-5 py-2 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition';
            confirmButton.textContent = 'Delete';

            // Add elements to the page
            buttonGroup.appendChild(cancelButton);
            buttonGroup.appendChild(confirmButton);
            modalBox.appendChild(modalTitle);
            modalBox.appendChild(modalMessage);
            modalBox.appendChild(buttonGroup);
            modalOverlay.appendChild(modalBox);
            document.body.appendChild(modalOverlay);

            // Add event listeners
            const closeModal = () => modalOverlay.remove();

            cancelButton.addEventListener('click', closeModal);
            modalOverlay.addEventListener('click', (event) => {
                // Only close if clicking on the overlay itself
                if (event.target === modalOverlay) {
                    closeModal();
                }
            });

            confirmButton.addEventListener('click', () => {
                onConfirm(); // Run the confirmation callback (form submission)
                closeModal(); // Close the modal
            });
        }
    </script>
</body>

</html>