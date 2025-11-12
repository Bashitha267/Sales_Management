<?php
require_once '../config.php';
session_start();

$token = $_GET['token'] ?? '';
$message = [
    'state' => 'info',
    'title' => 'Join Agency',
    'body' => 'Fill in your details to register as a new representative.',
    'details' => 'All fields marked with * are required.',
    'action' => null
];

$formData = [
    'first_name' => '',
    'last_name' => '',
    'nic_number' => '',
    'email' => '',
    'contact_number' => '',
    'age' => ''
];

$formErrors = [];
$showSuccess = false;
$user_id = null;

if ($token) {
    // Check if invite exists
    $stmt = $mysqli->prepare("SELECT id, representative_id, rep_user_id, status FROM agency_invites WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $invite = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invite) {
        $message = [
            'state' => 'error',
            'title' => 'Invalid Invitation',
            'body' => 'No invitation found with this link.',
            'details' => 'Please check the link or contact your representative.',
            'action' => null
        ];
    } elseif ($invite['status'] !== 'pending') {
        $message = [
            'state' => 'error',
            'title' => 'Invitation Already Processed',
            'body' => 'This invitation has already been used or rejected.',
            'details' => 'Please contact your representative for a new invitation.',
            'action' => null
        ];
    } else {
        $invite_id = $invite['id'];
        $representative_id = $invite['representative_id'];

        // Process form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_registration'])) {
            // Get form data
            $formData['first_name'] = trim($_POST['first_name'] ?? '');
            $formData['last_name'] = trim($_POST['last_name'] ?? '');
            $formData['nic_number'] = trim($_POST['nic_number'] ?? '');
            $formData['email'] = trim($_POST['email'] ?? '');
            $formData['contact_number'] = trim($_POST['contact_number'] ?? '');
            $formData['age'] = trim($_POST['age'] ?? '');

            // Validate required fields
            if (empty($formData['first_name'])) {
                $formErrors['first_name'] = 'First name is required.';
            }
            if (empty($formData['last_name'])) {
                $formErrors['last_name'] = 'Last name is required.';
            }
            if (empty($formData['nic_number'])) {
                $formErrors['nic_number'] = 'NIC number is required.';
            }
            if (empty($formData['email'])) {
                $formErrors['email'] = 'Email is required.';
            } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
                $formErrors['email'] = 'Invalid email address.';
            }
            if (!empty($formData['age']) && (!is_numeric($formData['age']) || intval($formData['age']) < 0 || intval($formData['age']) > 150)) {
                $formErrors['age'] = 'Age must be a valid number between 0 and 150.';
            }

            // Check if email or NIC already exists
            if (empty($formErrors)) {
                $checkStmt = $mysqli->prepare("SELECT id FROM users WHERE email = ? OR nic_number = ?");
                $checkStmt->bind_param("ss", $formData['email'], $formData['nic_number']);
                $checkStmt->execute();
                $existing = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                if ($existing) {
                    $formErrors['general'] = 'Email or NIC number already exists in the system.';
                }
            }

            // If no errors, create user account and link to invite
            if (empty($formErrors)) {
                $mysqli->begin_transaction();
                try {
                    // Create user account with temporary username (will be set by representative later)
                    // Password will be set by representative in add_new_reps.php
                    // For now, create user with role 'rep' and a temporary password (user won't be able to login until password is set)
                    $temp_username = 'temp_' . bin2hex(random_bytes(4));
                    $temp_password = bin2hex(random_bytes(16)); // Random password, user won't use this
                    $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
                    $role = 'rep';

                    // Convert empty strings to NULL for optional fields
                    $contact_number = empty($formData['contact_number']) ? null : $formData['contact_number'];
                    $age = empty($formData['age']) ? null : intval($formData['age']);

                    // Insert user
                    $insertStmt = $mysqli->prepare("INSERT INTO users (first_name, last_name, username, password, role, email, nic_number, contact_number, age) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insertStmt->bind_param(
                        "ssssssssi",
                        $formData['first_name'],
                        $formData['last_name'],
                        $temp_username,
                        $hashed_password,
                        $role,
                        $formData['email'],
                        $formData['nic_number'],
                        $contact_number,
                        $age
                    );

                    if (!$insertStmt->execute()) {
                        throw new Exception('Failed to create user account: ' . $insertStmt->error);
                    }

                    $user_id = $mysqli->insert_id;
                    $insertStmt->close();

                    // Update invite with rep_user_id
                    $updateStmt = $mysqli->prepare("UPDATE agency_invites SET rep_user_id = ? WHERE id = ?");
                    $updateStmt->bind_param("ii", $user_id, $invite_id);
                    if (!$updateStmt->execute()) {
                        throw new Exception('Failed to link user to invite: ' . $updateStmt->error);
                    }
                    $updateStmt->close();

                    $mysqli->commit();

                    // Show success message
                    $showSuccess = true;
                    $message = [
                        'state' => 'success',
                        'title' => 'Welcome to Our System!',
                        'body' => 'Your registration has been successfully submitted.',
                        'details' => "Waiting for approval from representative.",
                        'action' => null
                    ];
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $formErrors['general'] = 'An error occurred: ' . htmlspecialchars($e->getMessage());
                }
            }
        }
    }
} else {
    $message = [
        'state' => 'error',
        'title' => 'No Invitation Token',
        'body' => 'Please use a valid invitation link.',
        'details' => 'Contact your representative for an invitation link.',
        'action' => null
    ];
}

$stateClasses = [
    'success' => 'border-l-4 border-green-500 bg-green-50 text-green-900',
    'info' => 'border-l-4 border-blue-500 bg-blue-50 text-blue-900',
    'warning' => 'border-l-4 border-amber-500 bg-amber-50 text-amber-900',
    'error' => 'border-l-4 border-red-500 bg-red-50 text-red-900'
];

$stateIcons = [
    'success' => '<svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.2l-5-5 1.4-1.4L9 13.4l9.6-9.6L20 5.2z"/></svg>',
    'info' => '<svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 15h-1v-6h2v6h-1zm0-8h-1V7h2v2h-1z"/></svg>',
    'error' => '<svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.59 13.58L15.58 16.59 12 13l-3.59 3.59-1.41-1.41L10.59 12 7 8.41l1.41-1.41L12 10.59l3.59-3.59 1.41 1.41L13.41 12l3.18 3.18z"/></svg>'
];

$currentState = $message['state'];
$cardClasses = $stateClasses[$currentState] ?? $stateClasses['info'];
$iconMarkup = $stateIcons[$currentState] ?? $stateIcons['info'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Agency</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-2xl w-full space-y-6">
        <!-- Message Card -->
        <div class="bg-white shadow-lg rounded-2xl p-8">
            <div class="flex items-start gap-4 <?php echo $cardClasses; ?> p-4 mb-6 rounded">
                <div class="flex-shrink-0"><?php echo $iconMarkup; ?></div>
                <div>
                    <h2 class="text-2xl font-bold"><?php echo htmlspecialchars($message['title']); ?></h2>
                    <p class="mt-1"><?php echo htmlspecialchars($message['body']); ?></p>
                    <?php if (!empty($message['details'])): ?>
                        <p class="mt-2 text-sm border-l-2 border-gray-200 pl-3">
                            <?php echo htmlspecialchars($message['details']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($formErrors['general'])): ?>
                <div class="mb-4 p-3 bg-red-100 text-red-700 rounded">
                    <?php echo htmlspecialchars($formErrors['general']); ?>
                </div>
            <?php endif; ?>

            <!-- Success Message -->
            <?php if ($showSuccess): ?>
                <div class="mt-6 text-center">
                    <p class="text-lg font-semibold text-green-700 mb-2">
                        <?php echo htmlspecialchars($message['body']); ?>
                    </p>
                    <?php if ($user_id): ?>
                        <p class="text-base text-gray-700 mb-4">
                            <strong>Your User ID:</strong> <?php echo htmlspecialchars((string) $user_id); ?>
                        </p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-600">
                        <?php echo htmlspecialchars($message['details']); ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Registration Form -->
            <?php if (!$showSuccess && $token && isset($invite) && $invite && $invite['status'] === 'pending'): ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="submit_registration" value="1">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                First Name <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="first_name"
                                value="<?php echo htmlspecialchars($formData['first_name']); ?>"
                                class="w-full rounded-lg border-gray-300 p-3 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                                required>
                            <?php if (!empty($formErrors['first_name'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?php echo htmlspecialchars($formErrors['first_name']); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Last Name <span class="text-red-500">*</span>
                            </label>
                            <input type="text" name="last_name"
                                value="<?php echo htmlspecialchars($formData['last_name']); ?>"
                                class="w-full rounded-lg border-gray-300 p-3 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                                required>
                            <?php if (!empty($formErrors['last_name'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?php echo htmlspecialchars($formErrors['last_name']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            NIC Number <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="nic_number"
                            value="<?php echo htmlspecialchars($formData['nic_number']); ?>"
                            class="w-full rounded-lg border-gray-300 p-3 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                            maxlength="20" required>
                        <?php if (!empty($formErrors['nic_number'])): ?>
                            <p class="mt-1 text-sm text-red-600"><?php echo htmlspecialchars($formErrors['nic_number']); ?></p>
                        <?php endif; ?>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Email <span class="text-red-500">*</span>
                        </label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>"
                            class="w-full rounded-lg border-gray-300 p-3 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                            maxlength="100" required>
                        <?php if (!empty($formErrors['email'])): ?>
                            <p class="mt-1 text-sm text-red-600"><?php echo htmlspecialchars($formErrors['email']); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Contact Number
                            </label>
                            <input type="text" name="contact_number"
                                value="<?php echo htmlspecialchars($formData['contact_number']); ?>"
                                class="w-full rounded-lg border-gray-300 p-3 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                                maxlength="20">
                            <?php if (!empty($formErrors['contact_number'])): ?>
                                <p class="mt-1 text-sm text-red-600">
                                    <?php echo htmlspecialchars($formErrors['contact_number']); ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Age
                            </label>
                            <input type="number" name="age" value="<?php echo htmlspecialchars($formData['age']); ?>"
                                class="w-full rounded-lg border-gray-300 p-3 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200"
                                min="0" max="150">
                            <?php if (!empty($formErrors['age'])): ?>
                                <p class="mt-1 text-sm text-red-600"><?php echo htmlspecialchars($formErrors['age']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition">
                            Submit Registration
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>