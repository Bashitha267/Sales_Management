<?php
session_start();
require '../config.php';

if (!isset($_SESSION['user_id'])) {
    die("Please log in.");
}

$leaderId = (int) ($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if ($leaderId <= 0 || $role !== 'representative') {
    die("Unauthorized access.");
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void
{
    $bindValues = [$types];
    foreach ($params as $key => $value) {
        $bindValues[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

// --- Fetch agencies owned by this representative ---
$agencies = [];
$agencyStmt = $mysqli->prepare("SELECT id, agency_name FROM agencies WHERE representative_id = ? ORDER BY agency_name");
if ($agencyStmt) {
    $agencyStmt->bind_param('i', $leaderId);
    $agencyStmt->execute();
    $agencyResult = $agencyStmt->get_result();
    while ($row = $agencyResult->fetch_assoc()) {
        $agencies[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'agency_name' => $row['agency_name'] ?? ''
        ];
    }
    $agencyStmt->close();
}

// --- Determine selected agency ---
$selectedAgencyId = isset($_GET['agency_id']) ? (int) $_GET['agency_id'] : 0;
if ($selectedAgencyId <= 0 || !array_key_exists($selectedAgencyId, $agencies)) {
    $selectedAgencyId = !empty($agencies) ? array_key_first($agencies) : 0;
}

// --- AJAX: Sale detail items (drill-down) ---
if (isset($_GET['sale_detail']) && is_numeric($_GET['sale_detail'])) {
    $saleId = (int) $_GET['sale_detail'];

    $checkStmt = $mysqli->prepare("
        SELECT 1
        FROM sales s
        INNER JOIN agency_reps ar ON ar.rep_user_id = s.rep_user_id
        INNER JOIN agencies ag ON ag.id = ar.agency_id
        WHERE s.id = ? AND ag.representative_id = ?
    ");
    if ($checkStmt) {
        $checkStmt->bind_param('ii', $saleId, $leaderId);
        $checkStmt->execute();
        $belongs = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();
    } else {
        $belongs = false;
    }

    if (!$belongs) {
        echo "<div class='text-red-500 p-4'>Sale not found.</div>";
        exit;
    }

    $detailStmt = $mysqli->prepare("
        SELECT i.item_code,
               i.item_name,
               si.quantity,
               i.representative_points,
               (si.quantity * i.representative_points) AS total_points
        FROM sale_items si
        INNER JOIN items i ON i.id = si.item_id
        WHERE si.sale_id = ?
        ORDER BY i.item_name
    ");

    if ($detailStmt) {
        $detailStmt->bind_param('i', $saleId);
        $detailStmt->execute();
        $detailResult = $detailStmt->get_result();

        if ($detailResult && $detailResult->num_rows > 0) {
            $totalPoints = 0;
            echo "<table class='min-w-full text-sm'>";
            echo "<thead><tr class='bg-slate-100 text-slate-700'>";
            echo "<th class='px-3 py-2 text-left font-semibold'>Item Code</th>";
            echo "<th class='px-3 py-2 text-left font-semibold'>Item</th>";
            echo "<th class='px-3 py-2 text-right font-semibold'>Qty</th>";
            echo "<th class='px-3 py-2 text-right font-semibold'>Points (ea)</th>";
            echo "<th class='px-3 py-2 text-right font-semibold'>Total Points</th>";
            echo "</tr></thead><tbody class='divide-y divide-slate-200'>";
            while ($row = $detailResult->fetch_assoc()) {
                $qty = (int) ($row['quantity'] ?? 0);
                $pointsEach = (int) ($row['representative_points'] ?? 0);
                $lineTotal = (int) ($row['total_points'] ?? ($qty * $pointsEach));
                $totalPoints += $lineTotal;

                echo "<tr>";
                echo "<td class='px-3 py-2 font-mono'>" . h($row['item_code'] ?? 'N/A') . "</td>";
                echo "<td class='px-3 py-2'>" . h($row['item_name'] ?? ($row['item_code'] ?? 'Unknown')) . "</td>";
                echo "<td class='px-3 py-2 text-right'>{$qty}</td>";
                echo "<td class='px-3 py-2 text-right'>{$pointsEach}</td>";
                echo "<td class='px-3 py-2 text-right font-semibold text-blue-700'>{$lineTotal}</td>";
                echo "</tr>";
            }
            echo "</tbody>";
            echo "<tfoot><tr class='bg-slate-50 font-semibold'>";
            echo "<td colspan='4' class='px-3 py-2 text-right text-slate-600'>Total</td>";
            echo "<td class='px-3 py-2 text-right text-blue-700'>{$totalPoints}</td>";
            echo "</tr></tfoot></table>";
        } else {
            echo "<div class='p-4 text-slate-500'>No items recorded for this sale.</div>";
        }
        $detailStmt->close();
    } else {
        echo "<div class='text-red-500 p-4'>Unable to fetch sale details.</div>";
    }
    exit;
}

// --- AJAX: Rep drill-down (list of sales for a rep) ---
if (isset($_GET['rep_detail']) && is_numeric($_GET['rep_detail'])) {
    $repId = (int) $_GET['rep_detail'];
    $agencyIdParam = isset($_GET['agency_id']) ? (int) $_GET['agency_id'] : 0;
    $yearParam = $_GET['year'] ?? 'all';
    $monthParam = $_GET['month'] ?? 'all';
    $weekParam = $_GET['week'] ?? 'all';

    $yearFilter = ($yearParam !== 'all' && $yearParam !== '') ? (int) $yearParam : null;
    $monthFilter = ($monthParam !== 'all' && $monthParam !== '') ? (int) $monthParam : null;
    $weekFilter = ($weekParam !== 'all' && $weekParam !== '') ? (int) $weekParam : null;

    if ($yearFilter === null) {
        $monthFilter = null;
        $weekFilter = null;
    } elseif ($monthFilter === null) {
        $weekFilter = null;
    }

    $membershipStmt = $mysqli->prepare("
        SELECT 1
        FROM agency_reps ar
        INNER JOIN agencies ag ON ag.id = ar.agency_id
        WHERE ar.rep_user_id = ? AND ar.agency_id = ? AND ag.representative_id = ?
    ");

    $authorized = false;
    if ($membershipStmt) {
        $membershipStmt->bind_param('iii', $repId, $agencyIdParam, $leaderId);
        $membershipStmt->execute();
        $authorized = $membershipStmt->get_result()->num_rows > 0;
        $membershipStmt->close();
    }

    if (!$authorized) {
        echo "<div class='text-red-500 p-4'>Representative not found for this agency.</div>";
        exit;
    }

    $salesQuery = "
        SELECT s.id,
               s.sale_date,
               COALESCE(SUM(si.quantity), 0) AS total_qty,
               COALESCE(SUM(si.quantity * i.representative_points), 0) AS total_points,
               COALESCE(s.total_amount, 0) AS total_amount
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN items i ON i.id = si.item_id
        WHERE s.rep_user_id = ?
    ";

    $types = 'i';
    $params = [$repId];

    if ($yearFilter !== null) {
        $salesQuery .= " AND YEAR(s.sale_date) = ?";
        $types .= 'i';
        $params[] = $yearFilter;
    }
    if ($monthFilter !== null) {
        $salesQuery .= " AND MONTH(s.sale_date) = ?";
        $types .= 'i';
        $params[] = $monthFilter;
    }
    if ($weekFilter !== null) {
        $dayStart = ($weekFilter - 1) * 7 + 1;
        $dayEnd = min($weekFilter * 7, 31);
        $salesQuery .= " AND DAY(s.sale_date) BETWEEN ? AND ?";
        $types .= 'ii';
        $params[] = $dayStart;
        $params[] = $dayEnd;
    }

    $salesQuery .= " GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC";

    $salesStmt = $mysqli->prepare($salesQuery);

    if ($salesStmt) {
        bindParams($salesStmt, $types, $params);
        $salesStmt->execute();
        $salesResult = $salesStmt->get_result();

        if ($salesResult && $salesResult->num_rows > 0) {
            echo "<table class='min-w-full divide-y divide-slate-200 text-sm'>";
            echo "<thead class='bg-slate-50 text-slate-700'>";
            echo "<tr>";
            echo "<th class='px-4 py-3 text-left font-semibold uppercase tracking-wide'>Sale ID</th>";
            echo "<th class='px-4 py-3 text-left font-semibold uppercase tracking-wide'>Date</th>";
            echo "<th class='px-4 py-3 text-right font-semibold uppercase tracking-wide'>Qty</th>";
            echo "<th class='px-4 py-3 text-right font-semibold uppercase tracking-wide'>Points</th>";
            echo "<th class='px-4 py-3 text-right font-semibold uppercase tracking-wide'>Amount</th>";
            echo "<th class='px-4 py-3 text-center font-semibold uppercase tracking-wide'>Items</th>";
            echo "</tr></thead><tbody class='divide-y divide-slate-100'>";

            while ($sale = $salesResult->fetch_assoc()) {
                $saleId = (int) ($sale['id'] ?? 0);
                $saleDate = $sale['sale_date'] ?? '';
                $qty = (int) ($sale['total_qty'] ?? 0);
                $points = (int) ($sale['total_points'] ?? 0);
                $amount = isset($sale['total_amount']) ? (float) $sale['total_amount'] : 0.0;

                echo "<tr>";
                echo "<td class='px-4 py-3 font-mono'>" . h((string) $saleId) . "</td>";
                echo "<td class='px-4 py-3'>" . h($saleDate ? date('Y-m-d', strtotime($saleDate)) : '—') . "</td>";
                echo "<td class='px-4 py-3 text-right'>" . number_format($qty) . "</td>";
                echo "<td class='px-4 py-3 text-right font-semibold text-indigo-600'>" . number_format($points) . "</td>";
                echo "<td class='px-4 py-3 text-right'>" . ($amount > 0 ? number_format($amount, 2) : '—') . "</td>";
                echo "<td class='px-4 py-3 text-center'>";
                echo "<button data-sale='{$saleId}' class='inline-flex items-center gap-2 px-3 py-1 rounded-md bg-blue-500 text-white text-xs font-semibold hover:bg-blue-600 view-sale-items'>View</button>";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<div class='p-4 text-slate-500'>No sales recorded for the selected period.</div>";
        }

        $salesStmt->close();
    } else {
        echo "<div class='p-4 text-red-500'>Unable to fetch sales for this representative.</div>";
    }

    exit;
}

// --- If no agencies, skip rest of processing ---
if ($selectedAgencyId === 0) {
    include 'leader_header.php';
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Team Sales</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>

    <body class="bg-slate-100 min-h-screen">
        <div class="max-w-4xl mx-auto mt-16 bg-white rounded-xl shadow p-8 text-center text-slate-500">
            You do not have any agencies assigned yet. Create an agency to begin tracking team sales.
        </div>
    </body>

    </html>
    <?php
    exit;
}

// --- Filters (year / month / week) ---
$yearParam = $_GET['year'] ?? 'all';
$monthParam = $_GET['month'] ?? 'all';
$weekParam = $_GET['week'] ?? 'all';

$selectedYear = ($yearParam !== 'all' && $yearParam !== '') ? (int) $yearParam : null;
$selectedMonth = ($monthParam !== 'all' && $monthParam !== '') ? (int) $monthParam : null;
$selectedWeek = ($weekParam !== 'all' && $weekParam !== '') ? (int) $weekParam : null;

if ($selectedYear === null) {
    $selectedMonth = null;
    $selectedWeek = null;
} elseif ($selectedMonth === null) {
    $selectedWeek = null;
}

// --- Available years ---
$availableYears = [];
$yearStmt = $mysqli->prepare("
    SELECT DISTINCT YEAR(s.sale_date) AS year_value
    FROM sales s
    INNER JOIN agency_reps ar ON ar.rep_user_id = s.rep_user_id
    WHERE ar.agency_id = ?
    ORDER BY year_value DESC
");
if ($yearStmt) {
    $yearStmt->bind_param('i', $selectedAgencyId);
    $yearStmt->execute();
    $yearResult = $yearStmt->get_result();
    while ($row = $yearResult->fetch_assoc()) {
        if (!empty($row['year_value'])) {
            $availableYears[] = (int) $row['year_value'];
        }
    }
    $yearStmt->close();
}

// --- Available months ---
$availableMonths = [];
if (!empty($availableYears)) {
    $monthQuery = "
        SELECT DISTINCT MONTH(s.sale_date) AS month_value
        FROM sales s
        INNER JOIN agency_reps ar ON ar.rep_user_id = s.rep_user_id
        WHERE ar.agency_id = ?
    ";
    $types = 'i';
    $params = [$selectedAgencyId];

    if ($selectedYear !== null) {
        $monthQuery .= " AND YEAR(s.sale_date) = ?";
        $types .= 'i';
        $params[] = $selectedYear;
    }

    $monthQuery .= " ORDER BY month_value";

    $monthStmt = $mysqli->prepare($monthQuery);
    if ($monthStmt) {
        bindParams($monthStmt, $types, $params);
        $monthStmt->execute();
        $monthResult = $monthStmt->get_result();
        while ($row = $monthResult->fetch_assoc()) {
            if (!empty($row['month_value'])) {
                $availableMonths[] = (int) $row['month_value'];
            }
        }
        $monthStmt->close();
    }
}

$monthSelectDisabled = ($selectedYear === null);
$weekSelectDisabled = ($selectedYear === null || $selectedMonth === null);

// --- Representative summary for the selected agency ---
$summaryQuery = "
    SELECT u.id AS rep_id,
           u.first_name,
           u.last_name,
           u.username,
           COALESCE(COUNT(DISTINCT s.id), 0) AS sales_count,
           COALESCE(SUM(si.quantity), 0) AS total_qty,
           COALESCE(SUM(si.quantity * i.representative_points), 0) AS total_points,
           MAX(s.sale_date) AS last_sale
    FROM agency_reps ar
    INNER JOIN users u ON u.id = ar.rep_user_id
    LEFT JOIN sales s ON s.rep_user_id = u.id
    LEFT JOIN sale_items si ON si.sale_id = s.id
    LEFT JOIN items i ON i.id = si.item_id
    WHERE ar.agency_id = ?
";
$summaryTypes = 'i';
$summaryParams = [$selectedAgencyId];

if ($selectedYear !== null) {
    $summaryQuery .= " AND YEAR(s.sale_date) = ?";
    $summaryTypes .= 'i';
    $summaryParams[] = $selectedYear;
}
if ($selectedMonth !== null) {
    $summaryQuery .= " AND MONTH(s.sale_date) = ?";
    $summaryTypes .= 'i';
    $summaryParams[] = $selectedMonth;
}
if ($selectedWeek !== null) {
    $dayStart = ($selectedWeek - 1) * 7 + 1;
    $dayEnd = min($selectedWeek * 7, 31);
    $summaryQuery .= " AND DAY(s.sale_date) BETWEEN ? AND ?";
    $summaryTypes .= 'ii';
    $summaryParams[] = $dayStart;
    $summaryParams[] = $dayEnd;
}

$summaryQuery .= "
    GROUP BY u.id, u.first_name, u.last_name, u.username
    ORDER BY total_points DESC, u.first_name, u.last_name
";

$repSummaries = [];
$summaryStmt = $mysqli->prepare($summaryQuery);
if ($summaryStmt) {
    bindParams($summaryStmt, $summaryTypes, $summaryParams);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();
    while ($row = $summaryResult->fetch_assoc()) {
        $repSummaries[] = [
            'rep_id' => (int) ($row['rep_id'] ?? 0),
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'username' => $row['username'] ?? '',
            'sales_count' => (int) ($row['sales_count'] ?? 0),
            'total_qty' => (int) ($row['total_qty'] ?? 0),
            'total_points' => (int) ($row['total_points'] ?? 0),
            'last_sale' => $row['last_sale'] ?? null
        ];
    }
    $summaryStmt->close();
}

$totalReps = count($repSummaries);
$totalAgencyPoints = array_reduce($repSummaries, static fn($carry, $rep) => $carry + ($rep['total_points'] ?? 0), 0);
$totalSalesCount = array_reduce($repSummaries, static fn($carry, $rep) => $carry + ($rep['sales_count'] ?? 0), 0);
$totalQuantity = array_reduce($repSummaries, static fn($carry, $rep) => $carry + ($rep['total_qty'] ?? 0), 0);

$selectedAgency = $agencies[$selectedAgencyId]['agency_name'] ?? 'Selected Agency';
$filtersActive = ($selectedYear !== null) || ($selectedMonth !== null) || ($selectedWeek !== null);

include 'leader_header.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Sales Overview</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto mt-8 bg-white rounded-xl shadow-lg p-6 sm:p-10 space-y-10">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-3xl font-bold text-blue-700">Agency Performance</h1>
                <p class="text-sm text-slate-500 mt-1">Track points earned by your representatives using item
                    <span class="font-semibold text-blue-600">representative_points</span>.
                </p>
            </div>
            <div class="text-right">
                <span class="text-xs uppercase tracking-wide text-slate-500 font-semibold block">Agency</span>
                <span class="text-lg font-semibold text-slate-900"><?= h($selectedAgency) ?></span>
            </div>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="bg-blue-50 border border-blue-100 rounded-lg p-4">
                <p class="text-[11px] uppercase tracking-wider text-blue-500 font-semibold">Representatives</p>
                <p class="text-2xl font-bold text-blue-900 mt-2"><?= number_format($totalReps) ?></p>
            </div>
            <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4">
                <p class="text-[11px] uppercase tracking-wider text-indigo-500 font-semibold">Total Points (Leader)</p>
                <p class="text-2xl font-bold text-indigo-900 mt-2"><?= number_format($totalAgencyPoints) ?></p>
            </div>
            <div class="bg-violet-50 border border-violet-100 rounded-lg p-4">
                <p class="text-[11px] uppercase tracking-wider text-violet-500 font-semibold">Sales Logged</p>
                <p class="text-2xl font-bold text-violet-900 mt-2"><?= number_format($totalSalesCount) ?></p>
            </div>
            <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                <p class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Total Quantity</p>
                <p class="text-2xl font-bold text-slate-900 mt-2"><?= number_format($totalQuantity) ?></p>
            </div>
        </section>

        <section class="bg-slate-50 border border-slate-200 rounded-xl p-5 space-y-4">
            <h2 class="text-xl font-semibold text-slate-900">Filters</h2>
            <form method="get" id="team-filters" class="grid gap-4 md:grid-cols-4">
                <div>
                    <label class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Agency</label>
                    <select id="filter-agency" name="agency_id"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                        <?php foreach ($agencies as $agency): ?>
                            <option value="<?= (int) $agency['id'] ?>" <?= $agency['id'] === $selectedAgencyId ? 'selected' : '' ?>>
                                <?= h($agency['agency_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Year</label>
                    <select id="filter-year" name="year"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                        <option value="all" <?= $selectedYear === null ? 'selected' : '' ?>>All years</option>
                        <?php foreach ($availableYears as $yearOption): ?>
                            <option value="<?= (int) $yearOption ?>" <?= ($selectedYear === (int) $yearOption) ? 'selected' : '' ?>>
                                <?= (int) $yearOption ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Month</label>
                    <select id="filter-month" name="month"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 <?= $monthSelectDisabled ? 'opacity-60 cursor-not-allowed' : '' ?>"
                        <?= $monthSelectDisabled ? 'disabled' : '' ?>>
                        <option value="all" <?= $selectedMonth === null ? 'selected' : '' ?>>All months</option>
                        <?php foreach ($availableMonths as $monthOption):
                            $monthObj = DateTime::createFromFormat('!m', (string) $monthOption);
                            $monthLabel = $monthObj ? $monthObj->format('F') : $monthOption; ?>
                            <option value="<?= (int) $monthOption ?>" <?= ($selectedMonth === (int) $monthOption) ? 'selected' : '' ?>>
                                <?= h($monthLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Week</label>
                    <select id="filter-week" name="week"
                        class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 <?= $weekSelectDisabled ? 'opacity-60 cursor-not-allowed' : '' ?>"
                        <?= $weekSelectDisabled ? 'disabled' : '' ?>>
                        <option value="all" <?= $selectedWeek === null ? 'selected' : '' ?>>All weeks</option>
                        <?php for ($w = 1; $w <= 5; $w++):
                            $rangeStart = ($w - 1) * 7 + 1;
                            $rangeEnd = min($w * 7, 31); ?>
                            <option value="<?= $w ?>" <?= ($selectedWeek === $w) ? 'selected' : '' ?>>
                                Week <?= $w ?> (<?= $rangeStart ?>-<?= $rangeEnd ?>)
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </form>
        </section>

        <section class="space-y-4">
            <header class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h2 class="text-2xl font-semibold text-slate-900">Representative Performance</h2>
                    <p class="text-sm text-slate-500">
                        Points reflect total `representative_points` awarded for each sale item.
                    </p>
                </div>
                <div class="text-sm text-slate-500">
                    <?= $filtersActive ? 'Filters active' : 'Showing all time' ?>
                </div>
            </header>

            <?php if (empty($repSummaries)): ?>
                <div class="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500">
                    <?= $filtersActive ? 'No representatives match the selected filters.' : 'No representative sales recorded yet.' ?>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto bg-white border border-slate-200 rounded-xl">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                    Representative</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                    Username</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                    Sales</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                    Quantity</th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                    Leader Points</th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                    Last Sale</th>
                                <th
                                    class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase tracking-wide">
                                    Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($repSummaries as $rep): ?>
                                <?php
                                $fullName = trim($rep['first_name'] . ' ' . $rep['last_name']);
                                $lastSale = $rep['last_sale'] ? date('Y-m-d', strtotime($rep['last_sale'])) : '—';
                                ?>
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-slate-900"><?= h($fullName) ?></td>
                                    <td class="px-4 py-3 text-slate-600">@<?= h($rep['username']) ?></td>
                                    <td class="px-4 py-3 text-right"><?= number_format($rep['sales_count']) ?></td>
                                    <td class="px-4 py-3 text-right"><?= number_format($rep['total_qty']) ?></td>
                                    <td class="px-4 py-3 text-right font-semibold text-blue-600">
                                        <?= number_format($rep['total_points']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-slate-600"><?= h($lastSale) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <button
                                            class="inline-flex items-center justify-center rounded-md bg-blue-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-600 rep-detail"
                                            data-rep="<?= (int) $rep['rep_id'] ?>">
                                            View Sales
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <div class="text-center">
            <a href="leader_dashboard.php" class="text-blue-600 hover:underline text-sm">&larr; Back to Dashboard</a>
        </div>
    </div>

    <!-- Rep Sales Modal -->
    <div id="repSalesModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 transition-opacity px-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full max-h-[85vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                <h3 class="text-lg font-semibold text-slate-900" id="repSalesTitle">Representative Sales</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600 text-2xl font-bold"
                    id="closeRepSales">&times;</button>
            </div>
            <div id="repSalesContent" class="p-6 text-sm text-slate-700">
                Loading sales...
            </div>
        </div>
    </div>

    <!-- Sale Items Modal -->
    <div id="saleItemsModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 transition-opacity px-4">
        <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[80vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
                <h3 class="text-lg font-semibold text-slate-900">Sale Items</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600 text-2xl font-bold"
                    id="closeSaleItems">&times;</button>
            </div>
            <div id="saleItemsContent" class="p-6 text-sm text-slate-700">
                Loading items...
            </div>
        </div>
    </div>

    <script>
        const filterAgency = document.getElementById('filter-agency');
        const filterYear = document.getElementById('filter-year');
        const filterMonth = document.getElementById('filter-month');
        const filterWeek = document.getElementById('filter-week');

        function setSelectDisabled(selectEl, disabled) {
            if (!selectEl) {
                return;
            }
            selectEl.disabled = disabled;
            selectEl.classList.toggle('opacity-60', disabled);
            selectEl.classList.toggle('cursor-not-allowed', disabled);
        }

        function applyFilters() {
            if (!filterAgency || !filterYear || !filterMonth || !filterWeek) {
                return;
            }

            const params = new URLSearchParams();
            params.set('agency_id', filterAgency.value);

            const yearVal = filterYear.value;
            params.set('year', yearVal);

            if (yearVal === 'all') {
                params.set('month', 'all');
                params.set('week', 'all');
            } else {
                const monthVal = filterMonth.value;
                params.set('month', monthVal);

                if (monthVal === 'all') {
                    params.set('week', 'all');
                } else {
                    params.set('week', filterWeek.value);
                }
            }

            window.location.href = `view_team_sales.php?${params.toString()}`;
        }

        function syncFilterStates(trigger = null) {
            if (!filterAgency || !filterYear || !filterMonth || !filterWeek) {
                return;
            }

            const yearVal = filterYear.value;
            if (yearVal === 'all') {
                setSelectDisabled(filterMonth, true);
                filterMonth.value = 'all';
                setSelectDisabled(filterWeek, true);
                filterWeek.value = 'all';
            } else {
                setSelectDisabled(filterMonth, false);

                if (filterMonth.value === 'all') {
                    setSelectDisabled(filterWeek, true);
                    filterWeek.value = 'all';
                } else {
                    setSelectDisabled(filterWeek, false);
                }
            }

            if (trigger !== 'init') {
                applyFilters();
            }
        }

        if (filterAgency && filterYear && filterMonth && filterWeek) {
            syncFilterStates('init');

            filterAgency.addEventListener('change', () => applyFilters());

            filterYear.addEventListener('change', () => {
                syncFilterStates();
            });

            filterMonth.addEventListener('change', () => {
                if (filterMonth.value === 'all') {
                    setSelectDisabled(filterWeek, true);
                    filterWeek.value = 'all';
                } else {
                    setSelectDisabled(filterWeek, false);
                }
                applyFilters();
            });

            filterWeek.addEventListener('change', () => applyFilters());
        }

        const repModal = document.getElementById('repSalesModal');
        const saleModal = document.getElementById('saleItemsModal');
        const repContent = document.getElementById('repSalesContent');
        const saleContent = document.getElementById('saleItemsContent');
        const closeRepBtn = document.getElementById('closeRepSales');
        const closeSaleBtn = document.getElementById('closeSaleItems');
        const repTitle = document.getElementById('repSalesTitle');
        const agencyId = <?= (int) $selectedAgencyId ?>;
        const selectedYear = <?= $selectedYear !== null ? (int) $selectedYear : 'null' ?>;
        const selectedMonth = <?= $selectedMonth !== null ? (int) $selectedMonth : 'null' ?>;
        const selectedWeek = <?= $selectedWeek !== null ? (int) $selectedWeek : 'null' ?>;

        function openModal(modal) {
            modal.classList.remove('hidden');
        }

        function closeModal(modal) {
            modal.classList.add('hidden');
        }

        closeRepBtn.addEventListener('click', () => closeModal(repModal));
        closeSaleBtn.addEventListener('click', () => closeModal(saleModal));

        document.addEventListener('click', (event) => {
            if (event.target.classList.contains('rep-detail')) {
                const repId = event.target.getAttribute('data-rep');
                repTitle.textContent = 'Representative Sales';
                repContent.innerHTML = 'Loading sales...';
                openModal(repModal);

                const yearParam = selectedYear !== null ? selectedYear : 'all';
                const monthParam = selectedMonth !== null ? selectedMonth : 'all';
                const weekParam = selectedWeek !== null ? selectedWeek : 'all';

                fetch(`view_team_sales.php?rep_detail=${repId}&agency_id=${agencyId}&year=${yearParam}&month=${monthParam}&week=${weekParam}`)
                    .then(res => res.text())
                    .then(html => {
                        repContent.innerHTML = html;
                    })
                    .catch(() => {
                        repContent.innerHTML = "<div class='text-red-500'>Unable to load sales.</div>";
                    });
            }

            if (event.target.classList.contains('view-sale-items')) {
                const saleId = event.target.getAttribute('data-sale');
                saleContent.innerHTML = 'Loading items...';
                openModal(saleModal);

                fetch(`view_team_sales.php?sale_detail=${saleId}`)
                    .then(res => res.text())
                    .then(html => {
                        saleContent.innerHTML = html;
                    })
                    .catch(() => {
                        saleContent.innerHTML = "<div class='text-red-500'>Unable to load sale items.</div>";
                    });
            }
        });

        repModal.addEventListener('click', (event) => {
            if (event.target === repModal) {
                closeModal(repModal);
            }
        });

        saleModal.addEventListener('click', (event) => {
            if (event.target === saleModal) {
                closeModal(saleModal);
            }
        });
    </script>
</body>

</html>