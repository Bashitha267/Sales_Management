<?php
// Ensure the user is logged in
// Start session only if it hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Check user role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'representative') {
    header('Location: /ref/login.php');
    exit;
}

// 2. Include database connection
require_once __DIR__ . '/../config.php';

// --- Get Leader Details ---
$leader_user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$leader_username = isset($_SESSION['username']) ? htmlspecialchars((string) $_SESSION['username']) : 'Leader';

// --- Fetch Leader's full name ---
$leader_details = null;
if ($leader_user_id > 0 && isset($mysqli)) {
    // Added 'id' to the SELECT query
    $stmt = $mysqli->prepare("SELECT id, first_name, last_name FROM users WHERE id = ?");
    $stmt->bind_param("i", $leader_user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // *** BUG FIX ***
    // The original code fetched into '$header_user' but tried to read from '$user'.
    // This now correctly assigns to '$leader_details' and is used below.
    $leader_details = $result->fetch_assoc();

    $stmt->close();
}

// Set display name, falling back to username if name not found
$leader_display_name = $leader_details ? htmlspecialchars($leader_details['first_name'] . ' ' . $leader_details['last_name']) : $leader_username;
$leader_display_id = $leader_details ? htmlspecialchars($leader_details['id']) : $leader_user_id;


// --- Logout Logic (Standardized) ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /ref/login.php'); // Adjust path to your login page
    exit;
}
?>

<nav class="bg-blue-600 text-white px-4 sm:px-6 py-3 shadow-md">
    <div class="max-w-6xl mx-auto flex flex-row justify-between items-center">

        <a href="/ref/leader/leader_dashboard.php" class="flex items-center gap-3 group">
            <img src="/ref/public/logo.jpg" alt="Solenation Logo"
                class="h-10 w-10 rounded-full border-2 border-blue-400 object-cover">
            <h1 class="text-xl font-semibold group-hover:text-blue-100">Team Leader Dashboard</h1>
        </a>

        <div x-data="{ open: false }" @click.away="open = false" class="relative w-auto">

            <button @click="open = !open"
                class="flex items-center justify-center w-auto hover:text-blue-100 transition duration-150 bg-blue-500 bg-opacity-0 hover:bg-opacity-50 p-2 rounded-full">
                <img src="https://img.icons8.com/ios-glyphs/30/FFFFFF/user--v1.png" alt="User" class="w-5 h-5" />
            </button>

            <div x-show="open" x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 transform scale-95"
                x-transition:enter-end="opacity-100 transform scale-100"
                x-transition:leave="transition ease-in duration-75"
                x-transition:leave-start="opacity-100 transform scale-100"
                x-transition:leave-end="opacity-0 transform scale-95"
                class="absolute right-0 mt-2 w-56 bg-white text-gray-700 rounded-md shadow-lg border z-50"
                style="display: none;">

                <div class="px-4 py-3 border-b">
                    <p class="text-sm font-semibold text-gray-900 truncate" title="<?= $leader_display_name ?>">
                        <?= $leader_display_name ?>
                    </p>
                    <p class="text-xs text-gray-500">
                        Leader ID: <?= $leader_display_id ?>
                    </p>
                </div>

                <div class="py-1">
                    <a href="/ref/leader/leader_dashboard.php"
                        class="flex items-center w-full px-4 py-2 text-sm hover:bg-gray-100">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                            </path>
                        </svg>
                        Dashboard
                    </a>
                </div>

                <div class="py-1 border-t">
                    <a href="/ref/logout.php"
                        class="flex items-center w-full px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                        <img src="https://img.icons8.com/ios/100/FA5252/exit--v1.png" alt="Logout"
                            class="w-4 h-4 mr-2" />
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>