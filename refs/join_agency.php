<?php
require_once '../config.php';
session_start();

$token = $_GET['token'] ?? '';
$message = [
    'state' => 'info',
    'title' => 'Representative Verification Required',
    'body' => 'Enter your Representative ID to continue.',
    'details' => 'We will fetch your details to confirm the request before linking.',
    'action' => null
];

$repIdForm = [
    'visible' => true,
    'value' => '',
    'error' => ''
];

$userDetails = null;
$showConfirmButton = false;

if ($token) {
    $stmt = $mysqli->prepare("SELECT * FROM agency_invites WHERE token = ?");
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
        $repIdForm['visible'] = false;
    } else {
        $inviteId = $invite['id'];
        $existingRep = $invite['rep_user_id'];

        // --- If user is logged in, auto-link the invite ---
        if (isset($_SESSION['user_id'])) {
            $repId = (int) $_SESSION['user_id'];
            $updateStmt = $mysqli->prepare("UPDATE agency_invites SET rep_user_id = ? WHERE id = ?");
            $updateStmt->bind_param("ii", $repId, $inviteId);
            $updateStmt->execute();
            $updateStmt->close();

            $message = [
                'state' => 'success',
                'title' => 'Request Linked Automatically!',
                'body' => 'The request has been successfully linked to your account.',
                'details' => 'You can now go to the dashboard to manage your agency.',
                'action' => ['label' => 'Go to Dashboard', 'url' => '/ref/login.php']
            ];
            $repIdForm['visible'] = false;
        } else {
            // Step 1: Fetch user details manually via Rep ID (existing logic)
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rep_id']) && !isset($_POST['confirm'])) {
                $repIdForm['value'] = trim($_POST['rep_id']);
                if (!ctype_digit($repIdForm['value'])) {
                    $repIdForm['error'] = 'Representative ID must be numeric.';
                } else {
                    $repId = (int) $repIdForm['value'];
                    $userStmt = $mysqli->prepare("SELECT id, first_name, last_name, username, nic_number FROM users WHERE id = ?");
                    $userStmt->bind_param("i", $repId);
                    $userStmt->execute();
                    $userResult = $userStmt->get_result();
                    $userDetails = $userResult->fetch_assoc();
                    $userStmt->close();

                    if (!$userDetails) {
                        $repIdForm['error'] = 'Representative not found. Please check the ID.';
                    } else {
                        $showConfirmButton = true;
                        $repIdForm['visible'] = false;
                    }
                }
            }

            // Step 2: Confirm & Send request
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && isset($_POST['rep_id'])) {
                $repId = (int) $_POST['rep_id'];
                $updateStmt = $mysqli->prepare("UPDATE agency_invites SET rep_user_id = ? WHERE id = ?");
                $updateStmt->bind_param("ii", $repId, $inviteId);
                $updateStmt->execute();
                $updateStmt->close();

                $message = [
                    'state' => 'success',
                    'title' => 'Request Linked!',
                    'body' => 'The request has been successfully linked to your representative.',
                    'details' => 'You can now go to the dashboard to manage your agency.',
                    'action' => ['label' => 'Go to Dashboard', 'url' => '/ref/login.php']
                ];
                $userDetails = null;
                $repIdForm['visible'] = false;
                $showConfirmButton = false;
            }
        }
    }
}

$stateClasses = [
    'success' => 'border-l-4 border-violet-500 bg-violet-50 text-violet-900',
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
$action = $message['action'] ?? null;
$showAction = is_array($action) && !empty($action['url']) && !empty($action['label']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agency Invite</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-xl w-full space-y-6">

        <!-- Message Card -->
        <div class="bg-white shadow-lg rounded-2xl p-8">
            <div class="flex items-start gap-4 border-l-4 <?php echo $cardClasses; ?> p-4 mb-6">
                <div class="flex-shrink-0 text-violet-700"><?php echo $iconMarkup; ?></div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($message['title']); ?></h2>
                    <p class="mt-1 text-gray-600"><?php echo htmlspecialchars($message['body']); ?></p>
                    <?php if (!empty($message['details'])): ?>
                        <p class="mt-2 text-sm text-gray-500 border-l-2 border-gray-200 pl-3">
                            <?php echo htmlspecialchars($message['details']); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 1: Enter Rep ID -->
            <?php if ($repIdForm['visible']): ?>
                <form method="POST" class="space-y-4">
                    <label class="block text-sm font-medium text-gray-700">Representative ID</label>
                    <input type="text" name="rep_id" value="<?php echo htmlspecialchars($repIdForm['value']); ?>"
                        class="mt-1 block w-full rounded-lg border-gray-300 p-3 shadow-sm focus:border-violet-500 focus:ring focus:ring-violet-200 sm:text-sm"
                        placeholder="Enter your Representative ID" required>
                    <?php if (!empty($repIdForm['error'])): ?>
                        <p class="mt-2 text-sm text-red-600"><?php echo htmlspecialchars($repIdForm['error']); ?></p>
                    <?php endif; ?>
                    <button type="submit"
                        class="w-full bg-violet-600 hover:bg-violet-700 text-white font-semibold py-3 px-6 rounded-lg transition">
                        Fetch Details
                    </button>
                </form>
            <?php endif; ?>

            <!-- Step 2: Show User Details + Confirm -->
            <?php if ($userDetails && $showConfirmButton): ?>
                <div class="bg-gray-50 p-6 rounded-xl border border-gray-200 shadow-inner">
                    <h3 class="text-lg font-semibold mb-4">Representative Details</h3>
                    <ul class="text-gray-700 space-y-2">
                        <li><strong>Name:</strong>
                            <?php echo htmlspecialchars($userDetails['first_name'] . ' ' . $userDetails['last_name']); ?>
                        </li>
                        <li><strong>Username:</strong> <?php echo htmlspecialchars($userDetails['username']); ?></li>
                        <li><strong>NIC:</strong> <?php echo htmlspecialchars($userDetails['nic_number']); ?></li>
                    </ul>
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="rep_id" value="<?php echo (int) $userDetails['id']; ?>">
                        <button type="submit" name="confirm"
                            class="w-full bg-violet-600 hover:bg-violet-700 text-white font-semibold py-3 px-6 rounded-lg transition">
                            Confirm & Send Request
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Action Button -->
            <?php if ($showAction): ?>
                <div class="mt-6 text-center">
                    <a href="<?php echo htmlspecialchars($action['url']); ?>"
                        class="inline-block bg-violet-600 hover:bg-violet-700 text-white font-semibold py-3 px-6 rounded-lg transition">
                        <?php echo htmlspecialchars($action['label']); ?>
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>
</body>

</html>