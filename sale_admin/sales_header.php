<?php
// --- Session Start ---
// Start session only if it hasn't been started yet
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Security Check ---
// Redirects to login if user is not logged in or is not a sale_admin or admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || ($_SESSION['role'] !== 'sale_admin' && $_SESSION['role'] !== 'admin')) {
    header('Location: /ref/login.php');
    exit;
}

// --- Get User Details from Session ---
// All user information is stored in session during login (see auth.php)
$sale_admin_user_id = (int) $_SESSION['user_id'];
$sale_admin_username = isset($_SESSION['username']) ? htmlspecialchars((string) $_SESSION['username']) : 'Sale Admin';
$sale_admin_first_name = isset($_SESSION['first_name']) ? (string) $_SESSION['first_name'] : '';
$sale_admin_last_name = isset($_SESSION['last_name']) ? (string) $_SESSION['last_name'] : '';

// Set display name, falling back to username if name not found
$full_name = trim($sale_admin_first_name . ' ' . $sale_admin_last_name);
$sale_admin_display_name = !empty($full_name)
    ? htmlspecialchars($full_name)
    : $sale_admin_username;
$sale_admin_display_id = $sale_admin_user_id;
?>

<nav class="bg-teal-600 text-white px-4 sm:px-6 py-3 shadow-md">
    <div class="max-w-6xl mx-auto flex flex-row justify-between items-center">

        <a href="/ref/sale_admin/sale_dashboard.php" class="flex items-center gap-3 group">
            <img src="/ref/public/logo.jpg" alt="Solenation Logo"
                class="h-10 w-10 rounded-full border-2 border-teal-400 object-cover">
            <h1 class="text-xl font-semibold group-hover:text-teal-100">Solenation </h1>
        </a>

        <div x-data="{ open: false }" @click.away="open = false" class="relative w-auto">

            <button @click="open = !open"
                class="flex items-center justify-center w-auto hover:text-teal-100 transition duration-150 bg-teal-500 bg-opacity-0 hover:bg-opacity-50 p-2 rounded-full">
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
                    <p class="text-sm font-semibold text-gray-900 truncate" title="<?= $sale_admin_display_name ?>">
                        <?= $sale_admin_display_name ?>
                    </p>
                    <p class="text-xs text-gray-500">
                        Sale Admin ID: <?= $sale_admin_display_id ?>
                    </p>
                </div>

                <div class="py-1">
                    <a href="/ref/profile.php" class="flex items-center w-full px-4 py-2 text-sm hover:bg-gray-100">
                        <img src="https://img.icons8.com/ios-glyphs/90/1A1A1A/user-male-circle.png" alt="User"
                            class="w-4 h-4 mr-2" />
                        Profile
                    </a>
                    <a href="/ref/sale_admin/sale_dashboard.php"
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