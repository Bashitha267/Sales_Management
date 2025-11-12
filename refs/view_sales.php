<?php
session_start();
include '../config.php';

// --- Handle AJAX for sale details ---
if (isset($_GET['details']) && is_numeric($_GET['details'])) {
    if (!isset($_SESSION['user_id'])) {
        echo "<div class='text-red-500 p-2'>Authentication error.</div>";
        exit;
    }

    $sale_id = (int) $_GET['details'];
    $ref_id = (int) $_SESSION['user_id'];

    $sql = "SELECT i.item_code,
                   i.item_name,
                   si.quantity,
                   COALESCE(i.rep_points, i.representative_points, 0) AS rep_points,
                   COALESCE(i.price, 0) AS price,
                   s.sale_type,
                   CASE
                       WHEN s.sale_type = 'full' THEN si.quantity * COALESCE(i.rep_points, i.representative_points, 0)
                       ELSE 0
                   END AS item_points,
                   (si.quantity * COALESCE(i.price, 0)) AS line_total
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.id
            LEFT JOIN items i ON si.item_id = i.id
            WHERE si.sale_id = ? AND s.rep_user_id = ?";

    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        error_log("view_sales.php (details) prepare failed: " . $mysqli->error);
        echo "<div class='text-red-500 p-4'>Unable to load sale items right now.</div>";
        exit;
    }

    $stmt->bind_param("ii", $sale_id, $ref_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        error_log("view_sales.php (details) get_result failed: " . $stmt->error);
        echo "<div class='text-red-500 p-4'>Unable to read sale items.</div>";
        $stmt->close();
        exit;
    }

    if ($result && $result->num_rows > 0) {
        $total_points_modal = 0;
        $total_amount_modal = 0.0;
        echo "<table class='min-w-full text-sm'><thead><tr class='bg-slate-100'>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Item Code</th>";
        echo "<th class='px-3 py-2 text-left font-semibold text-slate-700'>Item</th>";
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Qty</th>";
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Points (ea)</th>";
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Total Points</th>";
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Price (ea)</th>";
        echo "<th class='px-3 py-2 text-right font-semibold text-slate-700'>Amount</th>";
        echo "</tr></thead><tbody class='divide-y divide-slate-200'>";

        while ($row = $result->fetch_assoc()) {
            $quantity = (int) ($row['quantity'] ?? 0);
            $baseRepPoints = (int) ($row['rep_points'] ?? 0);
            $itemPoints = (int) ($row['item_points'] ?? 0);
            $basePrice = (float) ($row['price'] ?? 0);
            $lineTotal = (float) ($row['line_total'] ?? 0);

            $total_points_modal += $itemPoints;
            $total_amount_modal += $lineTotal;
            echo "<tr>";
            echo "<td class='px-3 py-2 font-mono'>" . htmlspecialchars($row['item_code'] ?? 'N/A') . "</td>";
            echo "<td class='px-3 py-2'>" . htmlspecialchars($row['item_name'] ?? ($row['item_code'] ?? 'Unknown Item')) . "</td>";
            echo "<td class='px-3 py-2 text-right'>" . $quantity . "</td>";
            echo "<td class='px-3 py-2 text-right'>" . $baseRepPoints . "</td>";
            echo "<td class='px-3 py-2 text-right font-medium text-blue-600'>" . $itemPoints . "</td>";
            echo "<td class='px-3 py-2 text-right'>" . number_format($basePrice, 2) . "</td>";
            echo "<td class='px-3 py-2 text-right font-medium'>" . number_format($lineTotal, 2) . "</td>";
            echo "</tr>";
        }
        echo "</tbody>";
        echo "<tfoot>";
        echo "<tr class='bg-slate-50 font-bold border-t-2 border-slate-300'>";
        echo "<td colspan='4' class='px-3 py-2 text-right text-slate-800'>Total Points</td>";
        echo "<td class='px-3 py-2 text-right font-bold text-blue-600'>" . number_format($total_points_modal) . "</td>";
        echo "<td class='px-3 py-2 text-right text-slate-800'>Total Amount</td>";
        echo "<td class='px-3 py-2 text-right font-bold'>" . number_format($total_amount_modal, 2) . "</td>";
        echo "</tr>";
        echo "</tfoot></table>";
    } else {
        echo "<div class='text-slate-500 p-4'>No items found for this sale.</div>";
    }
    $stmt->close();
    exit;
}

// --- Handle "Make Full" requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['make_full'])) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please log in first.']);
        exit;
    }

    if (!is_numeric($_POST['make_full'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid sale reference.']);
        exit;
    }

    $saleId = (int) $_POST['make_full'];
    $refId = (int) $_SESSION['user_id'];

    $updateSql = "UPDATE sales 
                  SET admin_request = 1, admin_approved = 0 
                  WHERE id = ? AND rep_user_id = ? AND sale_type = 'half'";
    $updateStmt = $mysqli->prepare($updateSql);

    if (!$updateStmt) {
        echo json_encode(['success' => false, 'message' => 'Unable to process request.']);
        exit;
    }

    $updateStmt->bind_param('ii', $saleId, $refId);
    $updateStmt->execute();

    if ($updateStmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Your request was sent to the admin. Waiting for approval.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unable to update this sale. It may already be processed.']);
    }

    $updateStmt->close();
    exit;
}

// --- Main Page ---

$user_role = $_SESSION['role'] ?? null;
if ($user_role === 'representative') {
    include '../leader/leader_header.php';
} elseif ($user_role === 'rep') {
    include 'refs_header.php';
} else {
    header('Location: /ref/login.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo "Please log in.";
    exit;
}
$ref_id = (int) $_SESSION['user_id'];

$new_sale_link = 'add_sale.php';
$dashboard_link = ($user_role === 'representative') ? '../leader/leader_dashboard.php' : 'ref_dashboard.php';


function bindParams(mysqli_stmt $stmt, string $types, array $params): void
{
    $bindValues = [$types];
    foreach ($params as $key => $value) {
        $bindValues[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindValues);
}

// --- Filters ---
$yearParam = $_GET['year'] ?? 'all';
$monthParam = $_GET['month'] ?? 'all';
$weekParam = $_GET['week'] ?? 'all';
$saleTypeParam = $_GET['sale_type'] ?? 'all';

$selectedYear = ($yearParam !== 'all' && $yearParam !== '') ? (int) $yearParam : null;
$selectedMonth = ($monthParam !== 'all' && $monthParam !== '') ? (int) $monthParam : null;
$selectedWeek = ($weekParam !== 'all' && $weekParam !== '') ? (int) $weekParam : null;
$selectedSaleType = ($saleTypeParam === 'full' || $saleTypeParam === 'half') ? $saleTypeParam : null;
if ($selectedYear === null) {
    $selectedMonth = null;
    $selectedWeek = null;
} elseif ($selectedMonth === null) {
    $selectedWeek = null;
}

// --- Available Years/Months ---
$availableYears = [];
$yearStmt = $mysqli->prepare("SELECT DISTINCT YEAR(sale_date) AS year_value FROM sales WHERE rep_user_id = ? ORDER BY year_value DESC");
if ($yearStmt) {
    $yearStmt->bind_param('i', $ref_id);
    $yearStmt->execute();
    $yearResult = $yearStmt->get_result();
    while ($row = $yearResult->fetch_assoc()) {
        $availableYears[] = (int) $row['year_value'];
    }
    $yearStmt->close();
}

$availableMonths = [];
if (!empty($availableYears)) {
    $monthQuery = "SELECT DISTINCT MONTH(sale_date) AS month_value FROM sales WHERE rep_user_id = ?";
    $types = 'i';
    $params = [$ref_id];
    if ($selectedYear !== null) {
        $monthQuery .= " AND YEAR(sale_date)=?";
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
            $availableMonths[] = (int) $row['month_value'];
        }
        $monthStmt->close();
    }
}

// --- Sales Query ---
$sales = [];
$salesQuery = "SELECT s.id,
                      s.sale_date,
                      COALESCE(s.total_amount, COALESCE(SUM(si.quantity*i.price),0)) AS total_amount,
                      s.sale_type,
                      s.admin_request,
                      s.admin_approved,
                      s.sale_approved,
                      s.pending_edit_data, -- Need this to check status
                      COALESCE(SUM(si.quantity),0) AS total_qty,
                      COALESCE(SUM(CASE WHEN s.sale_type='full' AND s.sale_approved=1 THEN si.quantity*COALESCE(i.rep_points, i.representative_points, 0) ELSE 0 END),0) AS total_points,
                      COUNT(si.id) AS items_count,
                      COALESCE(MAX(pl_rep.redeemed), 0) AS redeemed
FROM sales s 
LEFT JOIN sale_items si ON s.id=si.sale_id 
LEFT JOIN items i ON si.item_id=i.id
LEFT JOIN points_ledger_rep pl_rep ON s.id = pl_rep.sale_id AND s.rep_user_id = pl_rep.rep_user_id
WHERE s.rep_user_id=?";
$queryTypes = 'i';
$queryParams = [$ref_id];

if ($selectedYear !== null) {
    $salesQuery .= " AND YEAR(s.sale_date)=?";
    $queryTypes .= 'i';
    $queryParams[] = $selectedYear;
}
if ($selectedMonth !== null) {
    $salesQuery .= " AND MONTH(s.sale_date)=?";
    $queryTypes .= 'i';
    $queryParams[] = $selectedMonth;
}

if ($selectedSaleType !== null) {
    $salesQuery .= " AND s.sale_type=?";
    $queryTypes .= 's';
    $queryParams[] = $selectedSaleType;
}

if ($selectedWeek !== null) {
    $dayStart = ($selectedWeek - 1) * 7 + 1;
    $dayEnd = min($selectedWeek * 7, 31);
    $salesQuery .= " AND DAY(s.sale_date) BETWEEN ? AND ?";
    $queryTypes .= 'ii';
    $queryParams[] = $dayStart;
    $queryParams[] = $dayEnd;
}

$salesQuery .= " GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC";

$salesStmt = $mysqli->prepare($salesQuery);
if ($salesStmt) {
    bindParams($salesStmt, $queryTypes, $queryParams);
    $salesStmt->execute();
    $salesResult = $salesStmt->get_result();
    while ($row = $salesResult->fetch_assoc()) {
        $sales[] = [
            'id' => (int) $row['id'],
            'sale_date' => $row['sale_date'],
            'sale_type' => $row['sale_type'],
            'admin_request' => (int) ($row['admin_request'] ?? 0),
            'admin_approved' => (int) ($row['admin_approved'] ?? 0),
            'sale_approved' => (int) ($row['sale_approved'] ?? 0),
            'pending_edit_data' => $row['pending_edit_data'], // Get the edit data
            'redeemed' => (int) ($row['redeemed'] ?? 0), // Check if sale is redeemed
            'total_amount' => isset($row['total_amount']) ? (float) $row['total_amount'] : null,
            'total_qty' => (int) ($row['total_qty'] ?? 0),
            'total_points' => (int) ($row['total_points'] ?? 0),
            'items_count' => (int) ($row['items_count'] ?? 0)
        ];
    }
    $salesStmt->close();
}

// --- Aggregates ---
// Calculate totals - points only from approved sales
$totalSalesCount = count($sales);
$totalQuantity = 0;
$totalPoints = 0;
$totalAmount = 0.0;
$weeklyBreakdown = [];
foreach ($sales as $sale) {
    $totalQuantity += $sale['total_qty'];
    // Only count points from approved sales
    if ($sale['sale_approved'] == 1) {
        $totalPoints += $sale['total_points'];
    }
    $totalAmount += $sale['total_amount'] ?? 0;
}
$selectedMonthLabel = $selectedMonth !== null ? DateTime::createFromFormat('!m', str_pad((string) $selectedMonth, 2, '0', STR_PAD_LEFT))->format('F') : null;
$monthSelectDisabled = ($selectedYear === null);
$weekSelectDisabled = ($selectedYear === null || $selectedMonth === null);
$filtersActive = ($selectedYear !== null) || ($selectedMonth !== null) || ($selectedWeek !== null) || ($selectedSaleType !== null);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">
    <div class="max-w-6xl mx-auto py-8 px-4 sm:px-6 lg:px-8 space-y-8">
        
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded-lg" role="alert">
                <span class="font-medium">Success!</span> <?= htmlspecialchars($_SESSION['flash_message']) ?>
            </div>
            <?php unset($_SESSION['flash_message']); ?>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-lg p-4 sm:p-8 space-y-8">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                <h2 class="text-2xl font-bold text-blue-700 flex items-center gap-3">
                    <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" stroke-width="2"
                        viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 7h18M3 12h18M3 17h18" stroke-linecap="round" />
                    </svg>
                    Sales Overview
                </h2>

                <a href="<?= htmlspecialchars($new_sale_link) ?>"
                    class="bg-blue-600 text-white px-5 py-2 rounded-lg hover:bg-blue-700 font-semibold text-base transition w-full sm:w-auto text-center">+
                    New Sale</a>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Total Sales</p>
                    <p class="text-2xl font-bold text-slate-900 mt-2"><?= number_format($totalSalesCount) ?></p>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Total Quantity</p>
                    <p class="text-2xl font-bold text-slate-900 mt-2"><?= number_format($totalQuantity) ?></p>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Points Earned</p>
                    <p class="text-2xl font-bold text-blue-700 mt-2"><?= number_format($totalPoints) ?></p>
                </div>
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4">
                    <p class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Total Amount</p>
                    <p class="text-2xl font-bold text-slate-900 mt-2">
                        <?= $totalAmount > 0 ? 'Rs. ' . number_format($totalAmount, 2) : '—' ?>
                    </p>
                </div>
            </div>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-5">
                <div class="grid gap-4 md:grid-cols-4">
                    <div>
                        <label for="filter-year"
                            class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Year</label>
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
                        <label for="filter-month"
                            class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Month</label>
                        <select id="filter-month" name="month"
                            class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 <?= $monthSelectDisabled ? 'opacity-60 cursor-not-allowed' : '' ?>"
                            <?= $monthSelectDisabled ? 'disabled' : '' ?>>
                            <option value="all" <?= $selectedMonth === null ? 'selected' : '' ?>>All months</option>
                            <?php foreach ($availableMonths as $monthOption):
                                $monthObj = DateTime::createFromFormat('!m', str_pad((string) $monthOption, 2, '0', STR_PAD_LEFT));
                                $monthLabel = $monthObj ? $monthObj->format('F') : $monthOption; ?>
                                <option value="<?= (int) $monthOption ?>" <?= ($selectedMonth === (int) $monthOption) ? 'selected' : '' ?>><?= htmlspecialchars($monthLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="filter-week"
                            class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Week</label>
                        <select id="filter-week" name="week"
                            class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200 <?= $weekSelectDisabled ? 'opacity-60 cursor-not-allowed' : '' ?>"
                            <?= $weekSelectDisabled ? 'disabled' : '' ?>>
                            <option value="all" <?= $selectedWeek === null ? 'selected' : '' ?>>All weeks</option>
                            <?php for ($w = 1; $w <= 5; $w++): ?>
                                <option value="<?= $w ?>" <?= ($selectedWeek === $w) ? 'selected' : '' ?>>Week <?= $w ?>
                                    (<?= (($w - 1) * 7 + 1) ?>-<?= min($w * 7, 31) ?>)</option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div>
                        <label for="filter-sale-type"
                            class="text-xs uppercase tracking-wide text-slate-500 font-semibold">Sale Type</label>
                        <select id="filter-sale-type" name="sale_type"
                            class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                            <option value="all" <?= $selectedSaleType === null ? 'selected' : '' ?>>All sales</option>
                            <option value="full" <?= $selectedSaleType === 'full' ? 'selected' : '' ?>>Full sales</option>
                            <option value="half" <?= $selectedSaleType === 'half' ? 'selected' : '' ?>>Half sales</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php if (empty($sales)): ?>
                <div class="py-12 text-slate-500 text-center text-sm">
                    <?= $filtersActive ? 'No sales match the selected filters.' : 'No sales recorded yet.' ?>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Sale ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Items</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Qty</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Points</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-slate-600 uppercase">Amount</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase">Approved</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold text-slate-600 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($sales as $sale): ?>
                                <?php
                                // --- LOGIC FOR BUTTONS ---
                                $isRedeemed = ($sale['redeemed'] === 1);
                                $isPendingEdit = ($sale['admin_request'] === 2 && $sale['admin_approved'] === 0 && !empty($sale['pending_edit_data']));
                                $isPendingFull = ($sale['sale_type'] === 'half' && $sale['admin_request'] === 1 && $sale['admin_approved'] === 0);
                                $isHalfSale = ($sale['sale_type'] === 'half' && !$isPendingFull);
                                $isEditable = (!$isRedeemed && !$isPendingEdit && !$isPendingFull);
                                ?>
                                <tr class="<?= $sale['sale_type'] === 'half' ? 'bg-amber-50' : '' ?>">
                                    <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars((string) $sale['id']) ?></td>
                                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars(date("Y-m-d", strtotime($sale['sale_date']))) ?></td>
                                    <td class="px-4 py-3 text-sm text-right"><?= number_format($sale['items_count']) ?></td>
                                    <td class="px-4 py-3 text-sm text-right"><?= number_format($sale['total_qty']) ?></td>
                                    <td class="px-4 py-3 text-sm font-semibold <?= $sale['sale_approved'] == 1 ? 'text-blue-600' : 'text-gray-400' ?> text-right">
                                        <?= $sale['sale_approved'] == 1 ? number_format($sale['total_points']) : '—' ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-right">
                                        <?= $sale['total_amount'] !== null ? 'Rs. ' . number_format($sale['total_amount'], 2) : '—' ?>
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <?php if ($sale['sale_approved'] == 1): ?>
                                            <span class="inline-flex items-center rounded-md bg-green-100 px-2 py-1 text-xs font-semibold text-green-800">Approved</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center rounded-md bg-red-100 px-2 py-1 text-xs font-semibold text-red-800">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center justify-center gap-2">
                                            <button onclick="showSaleDetails(<?= (int) $sale['id'] ?>)"
                                                class="inline-flex items-center justify-center rounded-md bg-blue-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-600">View</button>

                                            <?php if ($isEditable): ?>
                                                <a href="edit_sale.php?id=<?= (int) $sale['id'] ?>"
                                                    class="inline-flex items-center justify-center rounded-md bg-green-400 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-green-500">Edit</a>
                                            <?php endif; ?>

                                            <?php if ($isHalfSale): ?>
                                                <button type="button" onclick="makeSaleFull(<?= (int) $sale['id'] ?>)"
                                                    class="inline-flex items-center justify-center rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-amber-700">Make
                                                    Full</button>
                                            <?php endif; ?>
                                            
                                            <?php if ($isPendingFull): ?>
                                                <span class="inline-flex items-center rounded-md bg-slate-200 px-2 py-1 text-xs font-semibold text-slate-600">Pending Full</span>
                                            <?php endif; ?>
                                            
                                            <?php if ($isPendingEdit): ?>
                                                <span class="inline-flex items-center rounded-md bg-yellow-200 px-2 py-1 text-xs font-semibold text-yellow-800">Pending Edit</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="text-center mt-4">
                <a href="<?= htmlspecialchars($dashboard_link) ?>" class="text-blue-600 hover:underline text-sm">&larr; Back
                    to Dashboard</a>
            </div>
        </div>
    </div>

    <div id="saleDetailModal"
        class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60 transition-opacity p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl m-4 max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center p-4 border-b flex-shrink-0">
                <h3 class="text-lg font-semibold text-slate-800" id="modalTitle">Sale Details</h3>
                <button onclick="closeSaleDetails()"
                    class="text-slate-400 hover:text-slate-600 text-2xl font-bold">&times;</button>
            </div>
            <div class="overflow-y-auto" id="modalContent">Loading...</div>
        </div>
    </div>

    <script>
        const yearSelect = document.getElementById('filter-year');
        const monthSelect = document.getElementById('filter-month');
        const weekSelect = document.getElementById('filter-week');
        const saleTypeSelect = document.getElementById('filter-sale-type');

        function updateSales() {
            const year = yearSelect.value;
            const month = monthSelect.value;
            const week = weekSelect.value;
            const saleType = saleTypeSelect.value;
            let url = 'view_sales.php?year=' + encodeURIComponent(year)
                + '&month=' + encodeURIComponent(month)
                + '&week=' + encodeURIComponent(week)
                + '&sale_type=' + encodeURIComponent(saleType);
            window.location.href = url;
        }

        yearSelect.addEventListener('change', () => { monthSelect.disabled = false; updateSales(); });
        monthSelect.addEventListener('change', () => { weekSelect.disabled = false; updateSales(); });
        weekSelect.addEventListener('change', updateSales);
        saleTypeSelect.addEventListener('change', updateSales);

        function showSaleDetails(saleId) {
            const modal = document.getElementById('saleDetailModal');
            const content = document.getElementById('modalContent');
            content.innerHTML = '<div class="p-6">Loading...</div>';
            modal.classList.remove('hidden');

            fetch(`view_sales.php?details=${saleId}`)
                .then(res => res.text())
                .then(html => { content.innerHTML = `<div class="p-4 sm:p-6">${html}</div>`; })
                .catch(() => { content.innerHTML = '<div class="text-red-500 p-6">Failed to load details.</div>'; });
        }

        function closeSaleDetails() { document.getElementById('saleDetailModal').classList.add('hidden'); }

        function makeSaleFull(saleId) {
            if (!saleId) {
                alert('Sale reference missing.');
                return;
            }
            if (!confirm('Are you sure you want to request to make this sale full?')) {
                return;
            }

            fetch('view_sales.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({ make_full: saleId })
            })
                .then(res => res.json())
                .then(data => {
                    alert(data.message || 'Request processed.');
                    if (data.success) {
                        window.location.reload();
                    }
                })
                .catch(() => {
                    alert('Failed to send the request. Please try again later.');
                });
        }
    </script>
</body>

</html>