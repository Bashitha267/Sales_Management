<?php
require_once 'auth.php';
requireLogin();
require_once 'config.php';

// Security check - ensure user is admin
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

$message = '';
$message_type = '';

// --- AJAX: Get user details for modal ---
if (isset($_GET['user_details']) && is_numeric($_GET['user_details'])) {
    $user_id = (int) $_GET['user_details'];

    $stmt = $mysqli->prepare("
        SELECT 
            id, first_name, last_name, username, email, 
            contact_number, nic_number, address, city, 
            join_date, age, bank_account, bank_name, branch, account_holder, role, status
        FROM users 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        echo "<div class='p-4 sm:p-6 space-y-4'>";
        echo "<div class='grid grid-cols-1 md:grid-cols-2 gap-4'>";

        echo "<div><strong class='text-slate-600'>ID:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['id']) . "</span></div>";
        echo "<div><strong class='text-slate-600'>Username:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['username']) . "</span></div>";
        echo "<div><strong class='text-slate-600'>First Name:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['first_name'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Last Name:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['last_name'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Email:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['email'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Contact:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['contact_number'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>NIC Number:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['nic_number'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Address:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['address'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>City:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['city'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Join Date:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['join_date'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Age:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['age'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Role:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['role'] ?? 'N/A') . "</span></div>";
        echo "<div><strong class='text-slate-600'>Status:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['status'] ?? 'N/A') . "</span></div>";

        echo "</div>";

        if ($user['bank_account'] || $user['bank_name']) {
            echo "<div class='mt-4 pt-4 border-t border-slate-200'>";
            echo "<h4 class='font-semibold text-slate-700 mb-2'>Bank Details</h4>";
            echo "<div class='grid grid-cols-1 md:grid-cols-2 gap-4'>";
            echo "<div><strong class='text-slate-600'>Bank Name:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['bank_name'] ?? 'N/A') . "</span></div>";
            echo "<div><strong class='text-slate-600'>Account Number:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['bank_account'] ?? 'N/A') . "</span></div>";
            echo "<div><strong class='text-slate-600'>Branch:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['branch'] ?? 'N/A') . "</span></div>";
            echo "<div><strong class='text-slate-600'>Account Holder:</strong> <span class='text-slate-800'>" . htmlspecialchars($user['account_holder'] ?? 'N/A') . "</span></div>";
            echo "</div></div>";
        }

        echo "</div>";
    } else {
        echo "<div class='text-red-500 p-4'>User not found.</div>";
    }
    exit;
}

// --- POST: Activate Inactive Representative ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activate_rep_id'])) {
    $user_id = (int) $_POST['activate_rep_id'];

    if ($user_id > 0) {
        $stmt = $mysqli->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'representative' AND status = 'inactive'");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $message = "Representative activated successfully.";
            $message_type = 'success';
        } else {
            $message = "Could not activate representative. User may not exist or already be active.";
            $message_type = 'error';
        }
        $stmt->close();
    }
}

// --- POST: Upgrade Rep to Representative ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade_rep_id'])) {
    $user_id = (int) $_POST['upgrade_rep_id'];

    if ($user_id > 0) {
        $mysqli->begin_transaction();
        try {
            // Update role from 'rep' to 'representative'
            $stmt = $mysqli->prepare("UPDATE users SET role = 'representative' WHERE id = ? AND role = 'rep'");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $mysqli->commit();
                $message = "User has been successfully upgraded to Representative.";
                $message_type = 'success';
            } else {
                $mysqli->rollback();
                $message = "Could not upgrade user. User may not exist or already be a representative.";
                $message_type = 'error';
            }
            $stmt->close();
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error upgrading user: " . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- DATA FETCH: Get inactive representatives with sale count > 10 ---
$inactive_reps = [];
$sql_inactive = "SELECT 
                    u.id, u.username, u.first_name, u.last_name, u.email, u.contact_number,
                    COUNT(s.id) AS sale_count
                 FROM users u
                 LEFT JOIN sales s ON u.id = s.rep_user_id
                 WHERE u.role = 'representative' 
                   AND u.status = 'inactive'
                 GROUP BY u.id
                 HAVING sale_count > 10
                 ORDER BY sale_count DESC, u.username ASC";

$result_inactive = $mysqli->query($sql_inactive);
if ($result_inactive) {
    while ($row = $result_inactive->fetch_assoc()) {
        $inactive_reps[] = $row;
    }
}

// --- DATA FETCH: Get reps with sale count > 5 ---
$reps_to_upgrade = [];
$sql_reps = "SELECT 
                u.id, u.username, u.first_name, u.last_name, u.email, u.contact_number,
                COUNT(s.id) AS sale_count
             FROM users u
             LEFT JOIN sales s ON u.id = s.rep_user_id
             WHERE u.role = 'rep'
             GROUP BY u.id
             HAVING sale_count > 5
             ORDER BY sale_count DESC, u.username ASC";

$result_reps = $mysqli->query($sql_reps);
if ($result_reps) {
    while ($row = $result_reps->fetch_assoc()) {
        $reps_to_upgrade[] = $row;
    }
}

// Include admin header
include 'admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upgrade Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>

    <style>
        /* On screens smaller than 768px (Tailwind's 'md' breakpoint) */
        @media (max-width: 767px) {
            .responsive-table thead {
                display: none;
            }

            .responsive-table tbody tr {
                display: block;
                border-bottom: 2px solid #e5e7eb;
                padding: 1rem 0.5rem;
            }

            .responsive-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.75rem 0.5rem;
                border: none;
                text-align: right;
            }

            .responsive-table tbody td:before {
                content: attr(data-label);
                font-weight: 600;
                text-align: left;
                padding-right: 1rem;
                color: #4b5563;
            }

            .responsive-table .action-cell {
                display: block;
                padding-top: 1rem;
            }

            .responsive-table .action-cell:before {
                display: none;
            }
        }

        /* Success message animation */
        .success-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideInRight 0.3s ease-out, fadeOut 0.3s ease-in 2.7s forwards;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }
    </style>
</head>

<body class="bg-gray-50">

    <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 space-y-12">

        <?php if ($message && $message_type === 'success'): ?>
            <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg" role="alert">
                <span class="font-medium">Success!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($message && $message_type === 'error'): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded-lg" role="alert">
                <span class="font-medium">Error!</span> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Success Toast (hidden by default) -->
        <div id="successToast"
            class="success-toast <?= (isset($_POST['activate_rep_id']) && $message_type === 'success') ? '' : 'hidden' ?>">
            <div class="bg-green-500 text-white px-6 py-4 rounded-lg shadow-lg flex items-center gap-3">
                <svg data-feather="check-circle" class="w-5 h-5"></svg>
                <span class="font-medium">Representative activated successfully</span>
            </div>
        </div>

        <!-- Inactive Representatives Section -->
        <div>
            <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3 mb-8">
                Inactive Representatives (Sales > 10)
            </h1>
            <div class="bg-white rounded-lg shadow-md overflow-x-auto">
                <?php if (empty($inactive_reps)): ?>
                    <div class="p-10 text-center text-gray-500">
                        <svg data-feather="check-circle" class="w-12 h-12 mx-auto text-green-400 mb-4"
                            stroke-width="1.5"></svg>
                        <h3 class="text-lg font-medium">All Caught Up!</h3>
                        <p class="text-sm">There are no inactive representatives with more than 10 sales.</p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200 responsive-table">
                        <thead class="bg-gray-50 hidden md:table-header-group">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sale Count</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 md:divide-y-0">
                            <?php foreach ($inactive_reps as $rep): ?>
                                <tr class="block md:table-row">
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap font-mono text-sm text-gray-800 block md:table-cell"
                                        data-label="User ID">
                                        #<?= htmlspecialchars($rep['id']) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm font-medium text-gray-900 block md:table-cell"
                                        data-label="Username">
                                        <?= htmlspecialchars($rep['username']) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                        data-label="Name">
                                        <?= htmlspecialchars(trim(($rep['first_name'] ?? '') . ' ' . ($rep['last_name'] ?? '')) ?: 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                        data-label="Email">
                                        <?= htmlspecialchars($rep['email'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                        data-label="Contact">
                                        <?= htmlspecialchars($rep['contact_number'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm font-semibold text-blue-600 text-right block md:table-cell"
                                        data-label="Sale Count">
                                        <?= htmlspecialchars($rep['sale_count']) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-right text-sm font-medium block md:table-cell action-cell"
                                        data-label="Action">
                                        <form method="POST" action="upgrade_user.php" class="inline w-full md:w-auto">
                                            <input type="hidden" name="activate_rep_id" value="<?= (int) $rep['id'] ?>">
                                            <button type="submit"
                                                class="inline-flex items-center justify-center rounded-md h-10 bg-green-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-600 w-full md:w-auto">
                                                <span data-feather="check" class="w-4 h-4 mr-1.5 hidden md:inline"></span>
                                                Make Active
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reps to Upgrade Section -->
        <div>
            <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3 mb-8">
                Reps Eligible for Upgrade (Sales > 5)
            </h1>
            <div class="bg-white rounded-lg shadow-md overflow-x-auto">
                <?php if (empty($reps_to_upgrade)): ?>
                    <div class="p-10 text-center text-gray-500">
                        <svg data-feather="check-circle" class="w-12 h-12 mx-auto text-green-400 mb-4"
                            stroke-width="1.5"></svg>
                        <h3 class="text-lg font-medium">All Caught Up!</h3>
                        <p class="text-sm">There are no reps with more than 5 sales to upgrade.</p>
                    </div>
                <?php else: ?>
                    <table class="min-w-full divide-y divide-gray-200 responsive-table">
                        <thead class="bg-gray-50 hidden md:table-header-group">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Contact</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Sale Count</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 md:divide-y-0">
                            <?php foreach ($reps_to_upgrade as $rep): ?>
                                <tr class="block md:table-row">
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap font-mono text-sm text-gray-800 block md:table-cell"
                                        data-label="User ID">
                                        #<?= htmlspecialchars($rep['id']) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm font-medium text-gray-900 block md:table-cell"
                                        data-label="Username">
                                        <?= htmlspecialchars($rep['username']) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                        data-label="Name">
                                        <?= htmlspecialchars(trim(($rep['first_name'] ?? '') . ' ' . ($rep['last_name'] ?? '')) ?: 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                        data-label="Email">
                                        <?= htmlspecialchars($rep['email'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm text-gray-600 block md:table-cell"
                                        data-label="Contact">
                                        <?= htmlspecialchars($rep['contact_number'] ?? 'N/A') ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-sm font-semibold text-blue-600 text-right block md:table-cell"
                                        data-label="Sale Count">
                                        <?= htmlspecialchars($rep['sale_count']) ?>
                                    </td>
                                    <td class="px-6 py-3 md:py-4 whitespace-nowrap text-right text-sm font-medium block md:table-cell action-cell"
                                        data-label="Action">
                                        <button type="button" onclick="showUserDetails(<?= (int) $rep['id'] ?>)"
                                            class="inline-flex items-center justify-center rounded-md h-10 bg-blue-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-600 w-full md:w-auto">
                                            <span data-feather="user-plus" class="w-4 h-4 mr-1.5 hidden md:inline"></span>
                                            Make Representative
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- User Details Modal -->
    <div id="userDetailModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60 transition-opacity p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl m-4 max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center p-4 border-b flex-shrink-0">
                <h3 class="text-lg font-semibold text-slate-800">User Details</h3>
                <button onclick="closeUserDetails()"
                    class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>
            <div class="overflow-y-auto" id="userModalContent">Loading...</div>
            <div class="flex justify-end gap-3 p-4 border-t flex-shrink-0">
                <button onclick="closeUserDetails()"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200 transition">
                    Cancel
                </button>
                <form method="POST" action="upgrade_user.php" id="upgradeForm" class="inline">
                    <input type="hidden" name="upgrade_rep_id" id="upgradeUserId" value="">
                    <button type="submit"
                        class="px-4 py-2 text-sm font-medium text-white bg-green-500 rounded-md hover:bg-green-600 transition">
                        Confirm Upgrade
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        feather.replace();

        // Show success toast on page load if activation was successful
        <?php if (isset($_POST['activate_rep_id']) && $message_type === 'success'): ?>
            window.addEventListener('DOMContentLoaded', function () {
                const toast = document.getElementById('successToast');
                if (toast && !toast.classList.contains('hidden')) {
                    setTimeout(() => {
                        toast.classList.add('hidden');
                    }, 3000);
                }
            });
        <?php endif; ?>

        // Show success toast
        function showSuccessToast() {
            const toast = document.getElementById('successToast');
            toast.classList.remove('hidden');
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 3000);
        }

        // User details modal functions
        let currentUserId = null;

        function showUserDetails(userId) {
            currentUserId = userId;
            const modal = document.getElementById('userDetailModal');
            const content = document.getElementById('userModalContent');
            const formInput = document.getElementById('upgradeUserId');

            formInput.value = userId;
            content.innerHTML = '<div class="p-6">Loading...</div>';
            modal.classList.remove('hidden');

            fetch(`upgrade_user.php?user_details=${userId}`)
                .then(res => res.text())
                .then(html => {
                    content.innerHTML = html;
                    feather.replace();
                })
                .catch(() => {
                    content.innerHTML = '<div class="text-red-500 p-6">Failed to load user details.</div>';
                });
        }

        function closeUserDetails() {
            document.getElementById('userDetailModal').classList.add('hidden');
            currentUserId = null;
        }

        // Close modal on outside click
        document.getElementById('userDetailModal').addEventListener('click', function (e) {
            if (e.target === this) {
                closeUserDetails();
            }
        });
    </script>
</body>

</html>