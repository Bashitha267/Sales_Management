<?php
// leader_report.php - Monthly Agency Sales Report
include '../config.php';
include 'leader_header.php';

$representative_id = $_SESSION['user_id'] ?? null;
if (!$representative_id) {
    header("Location: ../login.php");
    exit;
}

// --- Get Years from sales in points_ledger_rep and points_ledger_group_points for this representative's agencies ---
$year_options = [];
// Get years from points_ledger_group_points (agency group points)
$sql_years_group = "
    SELECT DISTINCT YEAR(pl.sale_date) as year
    FROM points_ledger_group_points pl
    INNER JOIN agencies a ON pl.agency_id = a.id
    INNER JOIN sales s ON pl.sale_id = s.id
    WHERE a.representative_id = ?
    AND pl.representative_id = ?
    AND s.sale_type = 'full'
    AND s.sale_approved = 1
    ORDER BY year DESC
";
$stmt = $mysqli->prepare($sql_years_group);
$stmt->bind_param("ii", $representative_id, $representative_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if ($row['year'] && !in_array($row['year'], $year_options)) {
        $year_options[] = $row['year'];
    }
}
$stmt->close();

// Get years from points_ledger_rep (rep points for agencies)
$sql_years_rep = "
    SELECT DISTINCT YEAR(pl.sale_date) as year
    FROM points_ledger_rep pl
    INNER JOIN agencies a ON pl.agency_id = a.id
    INNER JOIN sales s ON pl.sale_id = s.id
    WHERE a.representative_id = ?
    AND s.sale_type = 'full'
    AND s.sale_approved = 1
    ORDER BY year DESC
";
$stmt = $mysqli->prepare($sql_years_rep);
$stmt->bind_param("i", $representative_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if ($row['year'] && !in_array($row['year'], $year_options)) {
        $year_options[] = $row['year'];
    }
}
$stmt->close();

// Also check direct sales by representative (where rep_user_id = representative_id but agency_id not in their agencies)
$sql_years_direct_rep = "
    SELECT DISTINCT YEAR(pl.sale_date) as year
    FROM points_ledger_rep pl
    INNER JOIN sales s ON pl.sale_id = s.id
    WHERE pl.rep_user_id = ? 
    AND pl.agency_id NOT IN (SELECT id FROM agencies WHERE representative_id = ?)
    AND s.sale_type = 'full'
    AND s.sale_approved = 1
    ORDER BY year DESC
";
$stmt = $mysqli->prepare($sql_years_direct_rep);
$stmt->bind_param("ii", $representative_id, $representative_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if ($row['year'] && !in_array($row['year'], $year_options)) {
        $year_options[] = $row['year'];
    }
}
$stmt->close();

$sql_years_direct_group = "
    SELECT DISTINCT YEAR(pl.sale_date) as year
    FROM points_ledger_group_points pl
    INNER JOIN sales s ON pl.sale_id = s.id
    WHERE pl.representative_id = ? 
    AND pl.agency_id NOT IN (SELECT id FROM agencies WHERE representative_id = ?)
    AND s.sale_type = 'full'
    AND s.sale_approved = 1
    ORDER BY year DESC
";
$stmt = $mysqli->prepare($sql_years_direct_group);
$stmt->bind_param("ii", $representative_id, $representative_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if ($row['year'] && !in_array($row['year'], $year_options)) {
        $year_options[] = $row['year'];
    }
}
$stmt->close();

if (empty($year_options)) {
    $year_options[] = date('Y');
}

$selected_year = $_GET['year'] ?? $year_options[0];

// --- Get agencies for this representative ---
$agencies = [];
$sql_agencies = "
    SELECT id, agency_name
    FROM agencies
    WHERE representative_id = ?
    ORDER BY agency_name
";
$stmt = $mysqli->prepare($sql_agencies);
$stmt->bind_param("i", $representative_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $agencies[$row['id']] = $row['agency_name'];
}
$stmt->close();

// Get agency IDs
$agency_ids = array_keys($agencies);
$agency_1_id = null;
$agency_2_id = null;
foreach ($agencies as $id => $name) {
    if (stripos($name, 'agency 1') !== false || stripos($name, '1') !== false) {
        $agency_1_id = $id;
    } elseif (stripos($name, 'agency 2') !== false || stripos($name, '2') !== false) {
        $agency_2_id = $id;
    }
}
// If not found by name, assign first two
if ($agency_1_id === null && count($agency_ids) > 0) {
    $agency_1_id = $agency_ids[0];
}
if ($agency_2_id === null && count($agency_ids) > 1) {
    $agency_2_id = $agency_ids[1];
}

// --- Month names ---
$month_names = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

// --- Get monthly data for each agency ---
$monthly_data = [];

for ($month = 1; $month <= 12; $month++) {
    $monthly_data[$month] = [
        'agency_1' => [
            'total_sales' => 0,
            'total_qty' => 0,
            'total_points' => 0,
            'total_representative_points' => 0
        ],
        'agency_2' => [
            'total_sales' => 0,
            'total_qty' => 0,
            'total_points' => 0,
            'total_representative_points' => 0
        ],
        'total' => [
            'total_sales' => 0,
            'total_qty' => 0,
            'total_points' => 0,
            'total_representative_points' => 0
        ]
    ];

    // Query for Agency 1 - get data from points_ledger_rep and points_ledger_group_points
    if ($agency_1_id) {
        // Get distinct sale IDs from both tables for this agency in this month/year
        // Then get sales count, qty, and points
        $sql_agency1_sales = "
            SELECT COUNT(DISTINCT sale_id) AS total_sales
            FROM (
                SELECT pl_rep.sale_id
                FROM points_ledger_rep pl_rep
                INNER JOIN sales s ON pl_rep.sale_id = s.id
                WHERE pl_rep.agency_id = ?
                  AND YEAR(pl_rep.sale_date) = ?
                  AND MONTH(pl_rep.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
                UNION
                SELECT pl_group.sale_id
                FROM points_ledger_group_points pl_group
                INNER JOIN sales s ON pl_group.sale_id = s.id
                WHERE pl_group.agency_id = ?
                  AND pl_group.representative_id = ?
                  AND YEAR(pl_group.sale_date) = ?
                  AND MONTH(pl_group.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ) AS combined_sales
        ";
        $stmt = $mysqli->prepare($sql_agency1_sales);
        $stmt->bind_param("iiiiiii", $agency_1_id, $selected_year, $month, $agency_1_id, $representative_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_1']['total_sales'] = (int) $row['total_sales'];
        }
        $stmt->close();

        // Get total qty from sale_items for sales in this agency
        $sql_agency1_qty = "
            SELECT COALESCE(SUM(si.quantity), 0) AS total_qty
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.id
            WHERE si.sale_id IN (
                SELECT sale_id FROM (
                    SELECT pl_rep.sale_id
                    FROM points_ledger_rep pl_rep
                    WHERE pl_rep.agency_id = ?
                      AND YEAR(pl_rep.sale_date) = ?
                      AND MONTH(pl_rep.sale_date) = ?
                    UNION
                    SELECT pl_group.sale_id
                    FROM points_ledger_group_points pl_group
                    WHERE pl_group.agency_id = ?
                      AND pl_group.representative_id = ?
                      AND YEAR(pl_group.sale_date) = ?
                      AND MONTH(pl_group.sale_date) = ?
                ) AS combined_sales
            )
            AND s.sale_type = 'full'
            AND s.sale_approved = 1
        ";
        $stmt = $mysqli->prepare($sql_agency1_qty);
        $stmt->bind_param("iiiiiii", $agency_1_id, $selected_year, $month, $agency_1_id, $representative_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_1']['total_qty'] = (int) $row['total_qty'];
        }
        $stmt->close();

        // Get points from points_ledger_rep (rep points - direct sales points)
        $sql_agency1_points_rep = "
            SELECT COALESCE(SUM(pl.points), 0) AS total_points
            FROM points_ledger_rep pl
            INNER JOIN sales s ON pl.sale_id = s.id
            WHERE pl.agency_id = ?
              AND YEAR(pl.sale_date) = ?
              AND MONTH(pl.sale_date) = ?
              AND s.sale_type = 'full'
              AND s.sale_approved = 1
        ";
        $stmt = $mysqli->prepare($sql_agency1_points_rep);
        $stmt->bind_param("iii", $agency_1_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_1']['total_points'] = (int) $row['total_points'];
        }
        $stmt->close();

        // Get points from points_ledger_group_points (representative points - agency group points)
        $sql_agency1_points_group = "
            SELECT COALESCE(SUM(pl.points), 0) AS total_representative_points
            FROM points_ledger_group_points pl
            INNER JOIN sales s ON pl.sale_id = s.id
            WHERE pl.agency_id = ?
              AND pl.representative_id = ?
              AND YEAR(pl.sale_date) = ?
              AND MONTH(pl.sale_date) = ?
              AND s.sale_type = 'full'
              AND s.sale_approved = 1
        ";
        $stmt = $mysqli->prepare($sql_agency1_points_group);
        $stmt->bind_param("iiii", $agency_1_id, $representative_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_1']['total_representative_points'] = (int) $row['total_representative_points'];
        }
        $stmt->close();
    }

    // Query for Agency 2 - get data from points_ledger_rep and points_ledger_group_points
    if ($agency_2_id) {
        // Get distinct sale IDs from both tables for this agency in this month/year
        $sql_agency2_sales = "
            SELECT COUNT(DISTINCT sale_id) AS total_sales
            FROM (
                SELECT pl_rep.sale_id
                FROM points_ledger_rep pl_rep
                INNER JOIN sales s ON pl_rep.sale_id = s.id
                WHERE pl_rep.agency_id = ?
                  AND YEAR(pl_rep.sale_date) = ?
                  AND MONTH(pl_rep.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
                UNION
                SELECT pl_group.sale_id
                FROM points_ledger_group_points pl_group
                INNER JOIN sales s ON pl_group.sale_id = s.id
                WHERE pl_group.agency_id = ?
                  AND pl_group.representative_id = ?
                  AND YEAR(pl_group.sale_date) = ?
                  AND MONTH(pl_group.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ) AS combined_sales
        ";
        $stmt = $mysqli->prepare($sql_agency2_sales);
        $stmt->bind_param("iiiiiii", $agency_2_id, $selected_year, $month, $agency_2_id, $representative_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_2']['total_sales'] = (int) $row['total_sales'];
        }
        $stmt->close();

        // Get total qty from sale_items for sales in this agency
        $sql_agency2_qty = "
            SELECT COALESCE(SUM(si.quantity), 0) AS total_qty
            FROM sale_items si
            INNER JOIN sales s ON si.sale_id = s.id
            WHERE si.sale_id IN (
                SELECT sale_id FROM (
                    SELECT pl_rep.sale_id
                    FROM points_ledger_rep pl_rep
                    WHERE pl_rep.agency_id = ?
                      AND YEAR(pl_rep.sale_date) = ?
                      AND MONTH(pl_rep.sale_date) = ?
                    UNION
                    SELECT pl_group.sale_id
                    FROM points_ledger_group_points pl_group
                    WHERE pl_group.agency_id = ?
                      AND pl_group.representative_id = ?
                      AND YEAR(pl_group.sale_date) = ?
                      AND MONTH(pl_group.sale_date) = ?
                ) AS combined_sales
            )
            AND s.sale_type = 'full'
            AND s.sale_approved = 1
        ";
        $stmt = $mysqli->prepare($sql_agency2_qty);
        $stmt->bind_param("iiiiiii", $agency_2_id, $selected_year, $month, $agency_2_id, $representative_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_2']['total_qty'] = (int) $row['total_qty'];
        }
        $stmt->close();

        // Get points from points_ledger_rep (rep points - direct sales points)
        $sql_agency2_points_rep = "
            SELECT COALESCE(SUM(pl.points), 0) AS total_points
            FROM points_ledger_rep pl
            INNER JOIN sales s ON pl.sale_id = s.id
            WHERE pl.agency_id = ?
              AND YEAR(pl.sale_date) = ?
              AND MONTH(pl.sale_date) = ?
              AND s.sale_type = 'full'
              AND s.sale_approved = 1
        ";
        $stmt = $mysqli->prepare($sql_agency2_points_rep);
        $stmt->bind_param("iii", $agency_2_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_2']['total_points'] = (int) $row['total_points'];
        }
        $stmt->close();

        // Get points from points_ledger_group_points (representative points - agency group points)
        $sql_agency2_points_group = "
            SELECT COALESCE(SUM(pl.points), 0) AS total_representative_points
            FROM points_ledger_group_points pl
            INNER JOIN sales s ON pl.sale_id = s.id
            WHERE pl.agency_id = ?
              AND pl.representative_id = ?
              AND YEAR(pl.sale_date) = ?
              AND MONTH(pl.sale_date) = ?
              AND s.sale_type = 'full'
              AND s.sale_approved = 1
        ";
        $stmt = $mysqli->prepare($sql_agency2_points_group);
        $stmt->bind_param("iiii", $agency_2_id, $representative_id, $selected_year, $month);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $monthly_data[$month]['agency_2']['total_representative_points'] = (int) $row['total_representative_points'];
        }
        $stmt->close();
    }

    // Calculate totals (sum of both agencies)
    $monthly_data[$month]['total']['total_sales'] =
        $monthly_data[$month]['agency_1']['total_sales'] +
        $monthly_data[$month]['agency_2']['total_sales'];
    $monthly_data[$month]['total']['total_qty'] =
        $monthly_data[$month]['agency_1']['total_qty'] +
        $monthly_data[$month]['agency_2']['total_qty'];
    $monthly_data[$month]['total']['total_points'] =
        $monthly_data[$month]['agency_1']['total_points'] +
        $monthly_data[$month]['agency_2']['total_points'];
    $monthly_data[$month]['total']['total_representative_points'] =
        $monthly_data[$month]['agency_1']['total_representative_points'] +
        $monthly_data[$month]['agency_2']['total_representative_points'];

    // Also include direct sales by the representative (where rep_user_id = representative_id)
    // These are sales made directly by the representative, not through assigned reps
    // Only count if agency_id is NOT in their agencies (to avoid double counting)
    // Direct sales only have points in points_ledger_rep, not points_ledger_group_points
    if ($agency_1_id || $agency_2_id) {
        $agency_1_param = $agency_1_id ?? 0;
        $agency_2_param = $agency_2_id ?? 0;

        // Build the query based on available agencies
        if ($agency_1_id && $agency_2_id) {
            // Get direct sales from points_ledger_rep where rep_user_id = representative_id
            // and agency_id is NOT in their agencies (direct sales)
            $sql_direct_sales = "
                SELECT COUNT(DISTINCT pl.sale_id) AS total_sales
                FROM points_ledger_rep pl
                INNER JOIN sales s ON pl.sale_id = s.id
                WHERE pl.rep_user_id = ?
                  AND pl.agency_id NOT IN (?, ?)
                  AND YEAR(pl.sale_date) = ?
                  AND MONTH(pl.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_sales);
            $stmt->bind_param("iiiii", $representative_id, $agency_1_param, $agency_2_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $direct_sales_count = (int) $row['total_sales'];
                $monthly_data[$month]['total']['total_sales'] += $direct_sales_count;
            }
            $stmt->close();

            // Get qty for direct sales
            $sql_direct_qty = "
                SELECT COALESCE(SUM(si.quantity), 0) AS total_qty
                FROM sale_items si
                INNER JOIN sales s ON si.sale_id = s.id
                WHERE si.sale_id IN (
                    SELECT pl.sale_id
                    FROM points_ledger_rep pl
                    WHERE pl.rep_user_id = ?
                      AND pl.agency_id NOT IN (?, ?)
                      AND YEAR(pl.sale_date) = ?
                      AND MONTH(pl.sale_date) = ?
                )
                AND s.sale_type = 'full'
                AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_qty);
            $stmt->bind_param("iiiii", $representative_id, $agency_1_param, $agency_2_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_qty'] += (int) $row['total_qty'];
            }
            $stmt->close();

            // Get points from points_ledger_rep for direct sales
            $sql_direct_points = "
                SELECT COALESCE(SUM(pl.points), 0) AS total_points
                FROM points_ledger_rep pl
                INNER JOIN sales s ON pl.sale_id = s.id
                WHERE pl.rep_user_id = ?
                  AND pl.agency_id NOT IN (?, ?)
                  AND YEAR(pl.sale_date) = ?
                  AND MONTH(pl.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_points);
            $stmt->bind_param("iiiii", $representative_id, $agency_1_param, $agency_2_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_points'] += (int) $row['total_points'];
            }
            $stmt->close();
            // Direct sales don't have points_representative (no agency group points)
        } elseif ($agency_1_id) {
            // Get direct sales from points_ledger_rep
            $sql_direct_sales = "
                SELECT COUNT(DISTINCT pl.sale_id) AS total_sales
                FROM points_ledger_rep pl
                INNER JOIN sales s ON pl.sale_id = s.id
                WHERE pl.rep_user_id = ?
                  AND pl.agency_id != ?
                  AND YEAR(pl.sale_date) = ?
                  AND MONTH(pl.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_sales);
            $stmt->bind_param("iiii", $representative_id, $agency_1_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_sales'] += (int) $row['total_sales'];
            }
            $stmt->close();

            // Get qty for direct sales
            $sql_direct_qty = "
                SELECT COALESCE(SUM(si.quantity), 0) AS total_qty
                FROM sale_items si
                INNER JOIN sales s ON si.sale_id = s.id
                WHERE si.sale_id IN (
                    SELECT pl.sale_id
                    FROM points_ledger_rep pl
                    WHERE pl.rep_user_id = ?
                      AND pl.agency_id != ?
                      AND YEAR(pl.sale_date) = ?
                      AND MONTH(pl.sale_date) = ?
                )
                AND s.sale_type = 'full'
                AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_qty);
            $stmt->bind_param("iiii", $representative_id, $agency_1_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_qty'] += (int) $row['total_qty'];
            }
            $stmt->close();

            // Get points from points_ledger_rep for direct sales
            $sql_direct_points = "
                SELECT COALESCE(SUM(pl.points), 0) AS total_points
                FROM points_ledger_rep pl
                INNER JOIN sales s ON pl.sale_id = s.id
                WHERE pl.rep_user_id = ?
                  AND pl.agency_id != ?
                  AND YEAR(pl.sale_date) = ?
                  AND MONTH(pl.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_points);
            $stmt->bind_param("iiii", $representative_id, $agency_1_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_points'] += (int) $row['total_points'];
            }
            $stmt->close();
        } else {
            // Get direct sales from points_ledger_rep
            $sql_direct_sales = "
                SELECT COUNT(DISTINCT pl.sale_id) AS total_sales
                FROM points_ledger_rep pl
                INNER JOIN sales s ON pl.sale_id = s.id
                WHERE pl.rep_user_id = ?
                  AND pl.agency_id != ?
                  AND YEAR(pl.sale_date) = ?
                  AND MONTH(pl.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_sales);
            $stmt->bind_param("iiii", $representative_id, $agency_2_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_sales'] += (int) $row['total_sales'];
            }
            $stmt->close();

            // Get qty for direct sales
            $sql_direct_qty = "
                SELECT COALESCE(SUM(si.quantity), 0) AS total_qty
                FROM sale_items si
                INNER JOIN sales s ON si.sale_id = s.id
                WHERE si.sale_id IN (
                    SELECT pl.sale_id
                    FROM points_ledger_rep pl
                    WHERE pl.rep_user_id = ?
                      AND pl.agency_id != ?
                      AND YEAR(pl.sale_date) = ?
                      AND MONTH(pl.sale_date) = ?
                )
                AND s.sale_type = 'full'
                AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_qty);
            $stmt->bind_param("iiii", $representative_id, $agency_2_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_qty'] += (int) $row['total_qty'];
            }
            $stmt->close();

            // Get points from points_ledger_rep for direct sales
            $sql_direct_points = "
                SELECT COALESCE(SUM(pl.points), 0) AS total_points
                FROM points_ledger_rep pl
                INNER JOIN sales s ON pl.sale_id = s.id
                WHERE pl.rep_user_id = ?
                  AND pl.agency_id != ?
                  AND YEAR(pl.sale_date) = ?
                  AND MONTH(pl.sale_date) = ?
                  AND s.sale_type = 'full'
                  AND s.sale_approved = 1
            ";
            $stmt = $mysqli->prepare($sql_direct_points);
            $stmt->bind_param("iiii", $representative_id, $agency_2_param, $selected_year, $month);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $monthly_data[$month]['total']['total_points'] += (int) $row['total_points'];
            }
            $stmt->close();
        }
    }
}

// Calculate year totals
$year_totals = [
    'agency_1' => ['total_sales' => 0, 'total_qty' => 0, 'total_points' => 0, 'total_representative_points' => 0],
    'agency_2' => ['total_sales' => 0, 'total_qty' => 0, 'total_points' => 0, 'total_representative_points' => 0],
    'total' => ['total_sales' => 0, 'total_qty' => 0, 'total_points' => 0, 'total_representative_points' => 0]
];

foreach ($monthly_data as $month => $data) {
    foreach (['agency_1', 'agency_2', 'total'] as $key) {
        $year_totals[$key]['total_sales'] += $data[$key]['total_sales'];
        $year_totals[$key]['total_qty'] += $data[$key]['total_qty'];
        $year_totals[$key]['total_points'] += $data[$key]['total_points'];
        $year_totals[$key]['total_representative_points'] += $data[$key]['total_representative_points'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Agency Sales Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 sm:py-6 lg:py-10">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
            <h1 class="text-xl sm:text-2xl font-bold text-blue-800">Monthly Agency Sales Report</h1>
            <h1 class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 text-sm sm:text-base">
                <?php
                // Set to Colombo, Sri Lanka. For Chennai, use 'Asia/Kolkata'
                date_default_timezone_set('Asia/Colombo');
                $current_datetime = date('F j, Y, g:i a');
                ?>
                <span><?= htmlspecialchars($current_datetime) ?></span>
            </h1>
        </div>

        <!-- Year Filter -->
        <form method="get" class="mb-6 sm:mb-8 flex flex-col sm:flex-row gap-2 sm:gap-3 items-start sm:items-center">
            <label for="year" class="font-medium text-sm sm:text-base">Select Year:</label>
            <select name="year" id="year" class="border p-2 rounded text-sm sm:text-base w-full sm:w-auto">
                <?php foreach ($year_options as $y): ?>
                    <option value="<?= htmlspecialchars($y) ?>" <?= $y == $selected_year ? 'selected' : '' ?>>
                        <?= htmlspecialchars($y) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit"
                class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 text-sm sm:text-base w-full sm:w-auto">
                Filter
            </button>
        </form>

        <?php if (empty($agencies)): ?>
            <div class="bg-white p-6 sm:p-8 rounded shadow text-center text-gray-600">
                You don't have any agencies assigned currently.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto -mx-4 sm:mx-0">
                <div class="inline-block min-w-full align-middle">
                    <div class="overflow-hidden shadow ring-1 ring-black ring-opacity-5 md:rounded-lg">
                        <table class="min-w-full divide-y divide-gray-300 text-xs sm:text-sm">
                            <thead class="bg-blue-50">
                                <!-- First header row with main column groups -->
                                <tr>
                                    <th rowspan="2" scope="col"
                                        class="sticky left-0 bg-blue-50 z-10 px-2 py-3 sm:px-4 sm:py-3.5 text-left text-xs sm:text-sm font-semibold text-blue-700 border-r border-gray-300">
                                        Month
                                    </th>
                                    <th colspan="4" scope="colgroup"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs sm:text-sm font-semibold text-blue-700 border-r border-gray-300 border-b border-gray-300">
                                        <?= htmlspecialchars($agencies[$agency_1_id] ?? 'Agency 1') ?>
                                    </th>
                                    <th colspan="4" scope="colgroup"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs sm:text-sm font-semibold text-blue-700 border-r border-gray-300 border-b border-gray-300">
                                        <?= htmlspecialchars($agencies[$agency_2_id] ?? 'Agency 2') ?>
                                    </th>
                                    <th rowspan="2" scope="col"
                                        class="px-2 py-3 sm:px-4 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-blue-700 bg-blue-100 border-r border-gray-300">
                                        Total Sales
                                    </th>
                                    <th rowspan="2" scope="col"
                                        class="px-2 py-3 sm:px-4 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-blue-700 bg-blue-100 border-r border-gray-300">
                                        Total Qty
                                    </th>
                                    <th rowspan="2" scope="col"
                                        class="px-2 py-3 sm:px-4 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-blue-700 bg-blue-100 border-r border-gray-300">
                                        Total Direct Sales Points
                                    </th>
                                    <th rowspan="2" scope="col"
                                        class="px-2 py-3 sm:px-4 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-blue-700 bg-blue-100">
                                        Total Points for representative
                                    </th>
                                </tr>
                                <!-- Second header row with sub-columns -->
                                <tr>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Sales
                                    </th>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Qty
                                    </th>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Direct Sales Points
                                    </th>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Points for representative
                                    </th>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Sales
                                    </th>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Qty
                                    </th>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Direct Sales Points
                                    </th>
                                    <th scope="col"
                                        class="px-2 py-2 sm:px-3 sm:py-2.5 text-center text-xs font-medium text-blue-600 border-r border-gray-300">
                                        Points for representative
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <?php foreach ($monthly_data as $month => $data): ?>
                                    <tr class="hover:bg-yellow-50 even:bg-gray-50">
                                        <td
                                            class="sticky left-0 bg-white z-10 whitespace-nowrap px-2 py-3 sm:px-4 sm:py-3.5 text-xs sm:text-sm font-semibold text-gray-900 border-r border-gray-300">
                                            <?= htmlspecialchars($month_names[$month]) ?>
                                        </td>
                                        <!-- Agency 1 columns -->
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-gray-900 border-r border-gray-300">
                                            <?= number_format($data['agency_1']['total_sales']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm text-gray-600 border-r border-gray-300">
                                            <?= number_format($data['agency_1']['total_qty']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-medium text-blue-600 border-r border-gray-300">
                                            <?= number_format($data['agency_1']['total_points']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-medium text-green-600 border-r border-gray-300">
                                            <?= number_format($data['agency_1']['total_representative_points']) ?>
                                        </td>
                                        <!-- Agency 2 columns -->
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-gray-900 border-r border-gray-300">
                                            <?= number_format($data['agency_2']['total_sales']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm text-gray-600 border-r border-gray-300">
                                            <?= number_format($data['agency_2']['total_qty']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-medium text-blue-600 border-r border-gray-300">
                                            <?= number_format($data['agency_2']['total_points']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-medium text-green-600 border-r border-gray-300">
                                            <?= number_format($data['agency_2']['total_representative_points']) ?>
                                        </td>
                                        <!-- Total columns -->
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-gray-900 bg-blue-50 border-r border-gray-300">
                                            <?= number_format($data['total']['total_sales']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-gray-900 bg-blue-50 border-r border-gray-300">
                                            <?= number_format($data['total']['total_qty']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-blue-600 bg-blue-50 border-r border-gray-300">
                                            <?= number_format($data['total']['total_points']) ?>
                                        </td>
                                        <td
                                            class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-semibold text-green-600 bg-blue-50">
                                            <?= number_format($data['total']['total_representative_points']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Year Total Row -->
                                <tr class="bg-blue-100 font-bold border-t-2 border-blue-300">
                                    <td
                                        class="sticky left-0 bg-blue-100 z-10 whitespace-nowrap px-2 py-3 sm:px-4 sm:py-3.5 text-xs sm:text-sm font-bold text-blue-900 border-r border-gray-300">
                                        Year Total
                                    </td>
                                    <!-- Agency 1 totals -->
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-900 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_1']['total_sales']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-900 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_1']['total_qty']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-700 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_1']['total_points']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-green-700 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_1']['total_representative_points']) ?>
                                    </td>
                                    <!-- Agency 2 totals -->
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-900 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_2']['total_sales']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-900 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_2']['total_qty']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-700 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_2']['total_points']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-green-700 border-r border-gray-300">
                                        <?= number_format($year_totals['agency_2']['total_representative_points']) ?>
                                    </td>
                                    <!-- Total columns -->
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-900 bg-blue-200 border-r border-gray-300">
                                        <?= number_format($year_totals['total']['total_sales']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-900 bg-blue-200 border-r border-gray-300">
                                        <?= number_format($year_totals['total']['total_qty']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-blue-700 bg-blue-200 border-r border-gray-300">
                                        <?= number_format($year_totals['total']['total_points']) ?>
                                    </td>
                                    <td
                                        class="px-2 py-3 sm:px-3 sm:py-3.5 text-center text-xs sm:text-sm font-bold text-green-700 bg-blue-200">
                                        <?= number_format($year_totals['total']['total_representative_points']) ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>

</html>