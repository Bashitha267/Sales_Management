<?php
include 'config.php';

// Parameters
$cur_year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Utility: HTML escape
function h_admin(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// Drill-down: Sale items for a sale
if (isset($_GET['sale_detail']) && is_numeric($_GET['sale_detail'])) {
    $saleId = (int) $_GET['sale_detail'];

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
            // Responsive table for modal
            echo "<div class='overflow-x-auto'>";
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
                echo "<td class='px-3 py-2 font-mono'>" . h_admin($row['item_code'] ?? 'N/A') . "</td>";
                echo "<td class='px-3 py-2'>" . h_admin($row['item_name'] ?? ($row['item_code'] ?? 'Unknown')) . "</td>";
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
            echo "</div>";
        } else {
            echo "<div class='p-4 text-slate-500'>No items recorded for this sale.</div>";
        }
        $detailStmt->close();
    } else {
        echo "<div class='text-red-500 p-4'>Unable to fetch sale details.</div>";
    }
    exit;
}

// Drill-down: Rep sales list
if (isset($_GET['rep_detail']) && is_numeric($_GET['rep_detail'])) {
    $repId = (int) $_GET['rep_detail'];
    $yearParam = isset($_GET['year']) ? $_GET['year'] : 'all';
    $monthParam = isset($_GET['month']) ? $_GET['month'] : 'all';
    $scopeParam = isset($_GET['scope']) ? trim($_GET['scope']) : 'direct'; // 'direct' or agency id

    $yearFilter = ($yearParam !== 'all' && $yearParam !== '') ? (int) $yearParam : null;
    $monthFilter = ($monthParam !== 'all' && $monthParam !== '') ? (int) $monthParam : null;

    // Fetch rep username/display for labeling
    $repUsername = '';
    $repFullName = '';
    $repUserStmt = $mysqli->prepare("SELECT username, CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) AS full_name FROM users WHERE id = ?");
    if ($repUserStmt) {
        $repUserStmt->bind_param('i', $repId);
        $repUserStmt->execute();
        $repUserRes = $repUserStmt->get_result()->fetch_assoc();
        $repUsername = $repUserRes['username'] ?? '';
        $repFullName = trim($repUserRes['full_name'] ?? '');
        $repUserStmt->close();
    }

    // --- *** THIS IS THE FIX *** ---
    // Get the agencies this user is a REPRESENTATIVE for (not a member of)
    $agencies = [];
    $agStmt = $mysqli->prepare("
        SELECT a.id, a.agency_name
        FROM agencies a
        WHERE a.representative_id = ?
        ORDER BY a.agency_name
    ");
    // --- *** END OF FIX *** ---

    if ($agStmt) {
        $agStmt->bind_param('i', $repId);
        $agStmt->execute();
        $agRes = $agStmt->get_result();
        while ($row = $agRes->fetch_assoc()) {
            $agencies[(int) $row['id']] = $row['agency_name'] ?? ('Agency ' . (int) $row['id']);
        }
        $agStmt->close();
    }

    // Render filters header
    echo "<div class='mb-4 space-y-2'>";
    $repLabel = trim(($repFullName !== '' ? h_admin($repFullName) : '') . ($repUsername !== '' ? " <span class=\"text-slate-500\">(@" . h_admin($repUsername) . ")</span>" : ''));
    echo "<div class='text-slate-700 text-sm'>Representative: <span class='font-semibold'>" . ($repLabel !== '' ? $repLabel : "ID " . h_admin((string) $repId)) . "</span> • User ID: <span class='font-mono'>" . h_admin((string) $repId) . "</span></div>";

    // Mobile-first filters
    echo "<div class='flex flex-col sm:flex-row sm:items-center gap-3 text-sm'>";

    // Year select
    echo "<div class='flex items-center gap-2'><label class='text-slate-600 flex-shrink-0'>Year</label>";
    echo "<select id='repFilterYear' class='border rounded px-2 py-1 w-full sm:w-auto' data-rep='{$repId}'>";
    echo "<option value='all'" . ($yearFilter === null ? " selected" : "") . ">All</option>";
    foreach ($years as $y) {
        $selected = ($yearFilter !== null && (int) $y === (int) $yearFilter) ? " selected" : "";
        echo "<option value='{$y}'{$selected}>{$y}</option>";
    }
    echo "</select></div>";

    // Month select
    echo "<div class='flex items-center gap-2'><label class='text-slate-600 flex-shrink-0'>Month</label>";
    echo "<select id='repFilterMonth' class='border rounded px-2 py-1 w-full sm:w-auto'>";
    $monthNames = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];
    echo "<option value='all'" . ($monthFilter === null ? " selected" : "") . ">All</option>";
    for ($m = 1; $m <= 12; $m++) {
        $sel = ($monthFilter !== null && (int) $monthFilter === $m) ? " selected" : "";
        echo "<option value='{$m}'{$sel}>{$monthNames[$m]}</option>";
    }
    echo "</select></div>";

    // Scope select: Direct vs Agencies
    echo "<div class='flex items-center gap-2'><label class='text-slate-600 flex-shrink-0'>Scope</label>";
    echo "<select id='repFilterScope' class='border rounded px-2 py-1 w-full sm:w-auto'>";
    $isDirect = ($scopeParam === 'direct' || $scopeParam === '' || !is_numeric($scopeParam));
    echo "<option value='direct'" . ($isDirect ? " selected" : "") . ">Direct Sales</option>";
    foreach ($agencies as $aid => $aname) {
        $sel = (!$isDirect && is_numeric($scopeParam) && (int) $scopeParam === (int) $aid) ? " selected" : "";
        // Use the proper agency name from the DB
        echo "<option value='{$aid}'{$sel}>" . h_admin(ucfirst($aname)) . "</option>";
    }
    echo "</select></div>";

    echo "</div></div>";

    // Build sales query based on filters
    $salesQuery = "
        SELECT s.id,
               s.rep_user_id,
               s.sale_date,
               COALESCE(SUM(si.quantity), 0) AS total_qty,
               COALESCE(SUM(si.quantity * i.representative_points), 0) AS total_points,
               COALESCE(s.total_amount, 0) AS total_amount,
               u.username
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN items i ON i.id = si.item_id
        LEFT JOIN users u ON u.id = s.rep_user_id
        WHERE 1=1
    ";
    $types = '';
    $params = [];

    if ($isDirect) {
        // Scope is 'Direct Sales', so find sales by the representative themselves
        $salesQuery .= " AND s.rep_user_id = ?";
        $types .= 'i';
        $params[] = $repId;
    } else {
        // Scope is an Agency ID. Find all members of that agency.
        $memberIds = [];
        $memStmt = $mysqli->prepare("SELECT rep_user_id FROM agency_reps WHERE agency_id = ?");
        if ($memStmt) {
            $agencyId = (int) $scopeParam;
            $memStmt->bind_param('i', $agencyId);
            $memStmt->execute();
            $memRes = $memStmt->get_result();
            while ($mr = $memRes->fetch_assoc()) {
                $memberIds[] = (int) $mr['rep_user_id'];
            }
            $memStmt->close();
        }
        if (empty($memberIds)) {
            echo "<div class='p-4 text-slate-500'>No members found for the selected agency.</div>";
            exit;
        }
        // Build IN clause safely
        $inPlaceholders = implode(',', array_fill(0, count($memberIds), '?'));
        $salesQuery .= " AND s.rep_user_id IN ($inPlaceholders)";
        $types .= str_repeat('i', count($memberIds));
        foreach ($memberIds as $mid) {
            $params[] = $mid;
        }
    }

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
    $salesQuery .= " GROUP BY s.id ORDER BY s.sale_date DESC, s.id DESC";

    $salesStmt = $mysqli->prepare($salesQuery);
    if ($salesStmt) {
        // dynamic bind
        if ($types !== '') {
            $bindValues = [$types];
            foreach ($params as $key => $value) {
                $bindValues[] = &$params[$key];
            }
            call_user_func_array([$salesStmt, 'bind_param'], $bindValues);
        }

        $salesStmt->execute();
        $salesResult = $salesStmt->get_result();

        if ($salesResult && $salesResult->num_rows > 0) {
            $totalPointsSum = 0;
            // Responsive table for modal
            echo "<div class='overflow-x-auto'>";
            echo "<table class='min-w-full divide-y divide-slate-200 text-sm'>";
            echo "<thead class='bg-slate-50 text-slate-700'>";
            echo "<tr>";
            echo "<th class='px-4 py-3 text-left font-semibold uppercase tracking-wide'>Sale ID</th>";
            echo "<th class='px-4 py-3 text-left font-semibold uppercase tracking-wide'>Date</th>";
            // Only show User ID / Username if in agency scope
            if (!$isDirect) {
                echo "<th class='px-4 py-3 text-left font-semibold uppercase tracking-wide'>User ID</th>";
                echo "<th class='px-4 py-3 text-left font-semibold uppercase tracking-wide'>Username</th>";
            }
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
                $username = $sale['username'] ?? '';
                $totalPointsSum += $points;

                echo "<tr>";
                echo "<td class='px-4 py-3 font-mono'>" . h_admin((string) $saleId) . "</td>";
                echo "<td class='px-4 py-3'>" . h_admin($saleDate ? date('Y-m-d', strtotime($saleDate)) : '—') . "</td>";
                if (!$isDirect) {
                    echo "<td class='px-4 py-3 font-mono'>" . h_admin(isset($sale['rep_user_id']) ? (string) $sale['rep_user_id'] : '') . "</td>";
                    echo "<td class='px-4 py-3'>" . ($username !== '' ? h_admin($username) : '—') . "</td>";
                }
                echo "<td class='px-4 py-3 text-right'>" . number_format($qty) . "</td>";
                echo "<td class='px-4 py-3 text-right font-semibold text-indigo-600'>" . number_format($points) . "</td>";
                echo "<td class='px-4 py-3 text-right'>" . ($amount > 0 ? "Rs. " . number_format($amount, 2) : '—') . "</td>";
                echo "<td class='px-4 py-3 text-center'>";
                echo "<button data-sale='{$saleId}' class='inline-flex items-center gap-2 px-3 py-1 rounded-md bg-blue-500 text-white text-xs font-semibold hover:bg-blue-600 admin-view-sale-items'>View</button>";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody>";
            echo "<tfoot><tr class='bg-slate-50'>";
            $cols = $isDirect ? 3 : 5;
            echo "<td colspan='{$cols}' class='px-4 py-3 text-right font-semibold text-slate-700'>Total Points</td>";
            echo "<td class='px-4 py-3 text-right font-bold text-indigo-700'>" . number_format($totalPointsSum) . "</td>";
            echo "<td colspan='2'></td>";
            echo "</tr></tfoot>";
            echo "</table>";
            echo "</div>";
        } else {
            echo "<div class='p-4 text-slate-500'>No sales recorded for the selected filters.</div>";
        }
        $salesStmt->close();
    } else {
        echo "<div class='p-4 text-red-500'>Unable to fetch sales for this representative.</div>";
    }
    exit;
}

// --- Representatives list with summary (cards) ---
$users = [];
$userStmtSql = "
    SELECT id, first_name, last_name
    FROM users
    WHERE role = 'representative'
    ORDER BY first_name, last_name
";
$user_q = $mysqli->query($userStmtSql);
while ($u = $user_q->fetch_assoc()) {
    if (
        $search === '' || stripos($u['first_name'], $search) !== false ||
        stripos($u['last_name'], $search) !== false || (string) $u['id'] === $search
    ) {
        $users[(int) $u['id']] = [
            'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))
        ];
    }
}
$user_q->close();

if (empty($users)) {
    echo "<div class='text-center text-gray-600 mt-10 text-lg'>No representatives found.</div>";
    exit();
}

// Build summaries per rep
$summaries = [];
foreach (array_keys($users) as $repId) {
    // This query calculates the representative's DIRECT sales summary
    $query = "
        SELECT
            COALESCE(COUNT(DISTINCT s.id), 0) AS sales_count,
            COALESCE(SUM(si.quantity), 0) AS total_qty,
            COALESCE(SUM(si.quantity * i.representative_points), 0) AS total_points,
            MAX(s.sale_date) AS last_sale
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        LEFT JOIN items i ON i.id = si.item_id
        WHERE s.rep_user_id = ?
    ";
    $types = 'i';
    $params = [$repId];
    if (!empty($cur_year)) {
        $query .= " AND YEAR(s.sale_date) = ?";
        $types .= 'i';
        $params[] = $cur_year;
    }
    $stmt = $mysqli->prepare($query);
    if ($stmt) {
        $bindValues = [$types];
        foreach ($params as $k => $v) {
            $bindValues[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindValues);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $summaries[$repId] = [
            'sales_count' => (int) ($res['sales_count'] ?? 0),
            'total_qty' => (int) ($res['total_qty'] ?? 0),
            'total_points' => (int) ($res['total_points'] ?? 0),
            'last_sale' => $res['last_sale'] ?? null
        ];
        $stmt->close();
    } else {
        $summaries[$repId] = ['sales_count' => 0, 'total_qty' => 0, 'total_points' => 0, 'last_sale' => null];
    }
}
?>

<div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($users as $uid => $u): ?>
        <?php
        $sum = $summaries[$uid] ?? ['sales_count' => 0, 'total_qty' => 0, 'total_points' => 0, 'last_sale' => null];
        $lastSale = $sum['last_sale'] ? date('Y-m-d', strtotime($sum['last_sale'])) : '—';
        ?>
        <div class="bg-white border border-slate-200 rounded-xl shadow hover:shadow-md transition cursor-pointer rep-card"
            data-rep="<?= (int) $uid ?>">
            <div class="p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900"><?= h_admin($u['name']) ?></h3>
                    <span class="text-xs px-2 py-1 rounded bg-blue-50 text-blue-700 font-semibold">Representative</span>
                </div>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Sales</div>
                        <div class="text-xl font-bold text-slate-900"><?= number_format($sum['sales_count']) ?></div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Quantity</div>
                        <div class="text-xl font-bold text-slate-900"><?= number_format($sum['total_qty']) ?></div>
                    </div>
                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Points</div>
                        <div class="text-xl font-bold text-indigo-700"><?= number_format($sum['total_points']) ?></div>
                    </div>
                </div>
                <div class="text-xs text-slate-500">Last direct sale: <?= h_admin($lastSale) ?></div>
                <div class="pt-2">
                    <button
                        class="w-full inline-flex items-center justify-center rounded-md bg-blue-500 px-3 py-2 text-sm font-semibold text-white hover:bg-blue-600 view-rep-sales"
                        data-rep="<?= (int) $uid ?>">
                        View Sales
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>