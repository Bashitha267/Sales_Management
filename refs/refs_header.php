<?php
// Ensure the user is logged in and is an admin
if (!isset($_SESSION)) {
    session_start();
}

// 1. Check user role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'rep') {
    header('Location: /ref/login.php');
    exit;
}

// 2. Include database connection (with path fix)
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    // Uses __DIR__ . '/../config.php' to correctly go up one directory
    require_once __DIR__ . '/../config.php';
}

// 3. Set up variables
$repId = $_SESSION['user_id'] ?? null;
$currentYear = (int) date('Y');
$currentMonth = (int) date('n');
$daysInMonth = (int) date('t');
$currentMonthLabel = date('F Y');
$currentDay = (int) date('j'); // Get current day for highlighting

// 4. Define week ranges
$rangeDefinitions = [
    ['start' => 1, 'end' => 7],
    ['start' => 8, 'end' => 14],
    ['start' => 15, 'end' => 21],
    ['start' => 22, 'end' => 31], // Your original was 22-31
];

// 5. Initialize weekly summaries and find current week number
$weeklySummaries = [];
$currentWeekNum = 0; // This will hold the week number (1-4)
foreach ($rangeDefinitions as $idx => $range) {
    $rangeEnd = min($range['end'], $daysInMonth);
    $weekNum = $idx + 1;

    $weeklySummaries[] = [
        'week' => $weekNum,
        'start' => $range['start'],
        'end' => $rangeEnd,
        'label' => $range['start'] . ' - ' . $rangeEnd,
        'total' => 0,
    ];

    // Check if the current day falls into this week
    if ($currentDay >= $range['start'] && $currentDay <= $rangeEnd) {
        $currentWeekNum = $weekNum;
    }
}

// 6. Calculate points
$totalFullSalePoints = 0;

if ($repId && isset($mysqli) && $mysqli instanceof mysqli) {
    // This query gets the total points for *each* approved 'full' sale
    $pointsQuery = "
        SELECT 
            s.sale_date,
            COALESCE(SUM(si.quantity * COALESCE(i.rep_points, i.representative_points, 0)), 0) AS sale_points
        FROM sales s
        LEFT JOIN sale_items si ON s.id = si.sale_id
        LEFT JOIN items i ON si.item_id = i.id
        WHERE s.rep_user_id = ?
          AND s.sale_type = 'full'
          AND s.admin_approved = 1  -- <-- IMPORTANT: Make sure you only count approved sales
          AND YEAR(s.sale_date) = ?
          AND MONTH(s.sale_date) = ?
        GROUP BY s.id, s.sale_date
    ";

    $stmt = $mysqli->prepare($pointsQuery);
    if ($stmt) {
        $stmt->bind_param('iii', $repId, $currentYear, $currentMonth);
        $stmt->execute();
        $result = $stmt->get_result();

        // Loop through each sale and add its points to the correct week
        while ($row = $result->fetch_assoc()) {
            $salePoints = (int) ($row['sale_points'] ?? 0);
            $totalFullSalePoints += $salePoints;

            $saleDay = 0;
            if (!empty($row['sale_date'])) {
                $saleDay = (int) date('j', strtotime($row['sale_date']));
            }

            // Find which week this sale belongs to
            if ($saleDay > 0) {
                foreach ($weeklySummaries as $index => $summary) {
                    if ($saleDay >= $summary['start'] && $saleDay <= $summary['end']) {
                        $weeklySummaries[$index]['total'] += $salePoints;
                        break; // Found the week, stop this inner loop
                    }
                }
            }
        }
        $stmt->close();
    } else {
        // Handle query preparation error
        error_log("MySQLi prepare error: " . $mysqli->error);
    }
}
?>
<header class="bg-blue-600 text-white p-4 shadow-md">
    <div class="max-w-6xl mx-auto space-y-4">
        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 sm:gap-0">

            <div class="text-center sm:text-left">
                <h1 class="text-2xl font-bold tracking-wide">
                    Ref Dashboard
                </h1>
                <div class="mt-1 text-md text-blue-100/80 flex flex-col sm:flex-row sm:items-center sm:gap-4">
                    <span>Rep ID: <?= htmlspecialchars((string) ($repId ?? 'N/A')) ?></span>

                </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 sm:space-x-3 w-full sm:w-auto">
                <div class=" gap-3 text-center text-sm">

                    <?php foreach ($weeklySummaries as $summary): ?>
                        <?php
                        // Check if this is the current week
                        $isCurrentWeek = ($summary['week'] === $currentWeekNum);

                        // Set styles based on whether it's the current week
                        $weekClasses = $isCurrentWeek
                            ? "bg-white text-blue-700 border-white/20" // Highlighted style
                            : "bg-blue-500/20 border-white/20 text-white hidden"; // Default style
                    
                        $labelClasses = $isCurrentWeek
                            ? "text-blue-500 " // Highlighted label
                            : "text-blue-100/80 hidden"; // Default label
                        ?>

                        <div
                            class="<?= $weekClasses ?> rounded-lg px-3 py-2 transition-all flex flex-row items-center gap-3 ">
                            <div class="text-md uppercase tracking-wide font-semibold <?= $labelClasses ?>">
                                Weekly points:
                            </div>
                            <div class="text-lg font-semibold mr-1">
                                <?= number_format((int) $summary['total']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div>
                <a href="/ref/refs/ref_dashboard.php"
                    class="bg-white text-indigo-700 px-4 py-2 rounded-lg font-medium hover:bg-indigo-50 transition text-center w-full sm:w-auto">
                    ← Back to Dashboard
                </a>

                <a href="/ref/logout.php"
                    class="bg-red-500 px-4 py-2 rounded-lg font-medium hover:bg-red-600 transition text-center w-full sm:w-auto">
                    Logout
                </a>
            </div>
        </div>


    </div>
</header>