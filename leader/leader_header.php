<?php
// Ensure the user is logged in and is an admin
if (!isset($_SESSION)) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'representative') {
    header('Location: /ref/login.php');
    exit;
}
?>
<header class="bg-blue-600 text-white p-4 shadow-md">
    <!-- 
        - flex-col sm:flex-row: Stack layout on mobile, row layout on small screens and up.
        - gap-4 sm:gap-0: Adds spacing between title and buttons when stacked on mobile.
    -->
    <div class="max-w-6xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-4 sm:gap-0">
        <!-- Left: Title -->
        <h1 class="text-2xl font-bold tracking-wide">
            Team Leader Dashboard
        </h1>

        <!-- Right: Buttons -->
        <!-- 
            - flex-col sm:flex-row: Stacks buttons on mobile, row on small screens and up.
            - gap-3 sm:space-x-3: Uses 'gap' for mobile stacking, 'space-x' for desktop row.
            - w-full sm:w-auto: Makes the button container full-width on mobile, auto-width on desktop.
        -->
        <div class="flex flex-col sm:flex-row gap-3 sm:space-x-3 w-full sm:w-auto">
            <a href="/ref/leader/leader_dashboard.php"
                class="bg-white text-indigo-700 px-4 py-2 rounded-lg font-medium hover:bg-indigo-50 transition text-center w-full sm:w-auto">
                ← Back to Dashboard
            </a>
            <a href="/ref/logout.php"
                class="bg-red-500 px-4 py-2 rounded-lg font-medium hover:bg-red-600 transition text-center w-full sm:w-auto">
                Logout
            </a>
        </div>
    </div>
</header>