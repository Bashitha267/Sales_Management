<?php
include 'config.php';

$cur_year = isset($_GET['year']) ? intval($_GET['year']) : (int) date('Y');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// --- Get users ---
$user_q = $mysqli->query("
    SELECT id, first_name, last_name, role
    FROM users
    WHERE role IN ('rep', 'team leader')
    ORDER BY role DESC, first_name
");
$users = [];
while ($u = $user_q->fetch_assoc()) {
    if (
        $search === '' || stripos($u['first_name'], $search) !== false ||
        stripos($u['last_name'], $search) !== false || $u['id'] == $search
    ) {
        $users[$u['id']] = [
            'name' => $u['first_name'] . ' ' . $u['last_name'],
            'role' => $u['role']
        ];
    }
}
$user_q->close();

if (empty($users)) {
    echo "<div class='text-center text-gray-600 mt-10 text-lg'>No users found.</div>";
    exit();
}

// --- Get items points ---
$item_points = [];
$res = $mysqli->query("SELECT item_code, points_leader, points_rep FROM items");
while ($i = $res->fetch_assoc()) {
    $item_points[$i['item_code']] = [
        'leader' => (int) $i['points_leader'],
        'rep' => (int) $i['points_rep']
    ];
}

// --- Get teams + members ---
$teams = [];
$tq = $mysqli->query("SELECT team_id, leader_id FROM teams");
while ($t = $tq->fetch_assoc())
    $teams[$t['team_id']] = $t['leader_id'];
$members = [];
$mq = $mysqli->query("SELECT team_id, member_id FROM team_members");
while ($m = $mq->fetch_assoc()) {
    $leader = $teams[$m['team_id']] ?? null;
    if ($leader)
        $members[$leader][] = $m['member_id'];
}
$pay_status = [];
$pq = $mysqli->query("SELECT user_id, month, status FROM payments WHERE year=$cur_year");
while ($p = $pq->fetch_assoc()) {
    $pay_status[$p['user_id']][$p['month']] = $p['status'];
}
// --- Months ---
$months = [
    1 => 'Jan',
    2 => 'Feb',
    3 => 'Mar',
    4 => 'Apr',
    5 => 'May',
    6 => 'Jun',
    7 => 'Jul',
    8 => 'Aug',
    9 => 'Sep',
    10 => 'Oct',
    11 => 'Nov',
    12 => 'Dec'
];

// --- Calculate data ---
$data = [];

foreach (array_keys($users) as $uid) {
    // Own sales
    $stmt = $mysqli->prepare("
        SELECT MONTH(sl.sale_date) AS m, sd.item_code, SUM(sd.qty) AS qty
        FROM sales_log sl
        JOIN sale_details sd ON sl.sale_id = sd.sale_id
        WHERE sl.ref_id=? AND YEAR(sl.sale_date)=?
        GROUP BY m, sd.item_code
    ");
    $stmt->bind_param('ii', $uid, $cur_year);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $m = (int) $r['m'];
        $points = ($item_points[$r['item_code']]['rep'] ?? 0) * (int) $r['qty'];
        $data[$uid][$m] = ($data[$uid][$m] ?? 0) + $points;
    }
    $stmt->close();

    // Team leader points
    if ($users[$uid]['role'] === 'team leader' && !empty($members[$uid])) {
        $ids = implode(',', array_map('intval', $members[$uid]));
        $sql = "
            SELECT MONTH(sl.sale_date) AS m, sd.item_code, SUM(sd.qty) AS qty
            FROM sales_log sl
            JOIN sale_details sd ON sl.sale_id = sd.sale_id
            WHERE sl.ref_id IN ($ids) AND YEAR(sl.sale_date)=$cur_year
            GROUP BY m, sd.item_code
        ";
        $r2 = $mysqli->query($sql);
        while ($r = $r2->fetch_assoc()) {
            $m = (int) $r['m'];
            $points = ($item_points[$r['item_code']]['leader'] ?? 0) * (int) $r['qty'];
            $data[$uid][$m] = ($data[$uid][$m] ?? 0) + $points;
        }
    }
}
?>

<!-- TABLE -->
<div class="overflow-x-auto bg-white shadow rounded-lg">
    <table class="min-w-full border-collapse text-sm">
        <thead class="bg-blue-100 text-blue-900">
            <tr>
                <th class="px-4 py-3 text-left">User</th>
                <?php foreach ($months as $mnum => $mname): ?>
                    <th class="px-3 py-3 text-center"><?= $mname ?></th>
                <?php endforeach; ?>
                <!-- <th class="px-4 py-3 text-center">Payment Status</th> -->
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            <?php foreach ($users as $uid => $u): ?>
                <tr class="<?= $u['role'] === 'team leader' ? 'bg-blue-50 font-semibold' : '' ?>">
                    <td class="px-4 py-3 text-gray-800">
                        <?= htmlspecialchars($u['name']) ?><br>
                        <span class="text-xs text-gray-500">(<?= $u['role'] ?>)</span>
                    </td>

                    <?php
                    $user_paid_all = true; // assume paid, check inside
                    foreach ($months as $mnum => $mname):
                        $points = $data[$uid][$mnum] ?? 0;
                        $status = $pay_status[$uid][$mnum] ?? 'pending';

                        if ($points > 0 && $status !== 'paid') {
                            $user_paid_all = false;
                        }

                        // apply background colors
                        $bg_class = '';
                        if ($points > 0) {
                            $bg_class = $status === 'paid' ? 'bg-green-200' : 'bg-red-200';
                        }
                        ?>
                        <td class="px-3 py-3 text-center <?= $bg_class ?> rounded">
                            <div class="font-medium"><?= number_format($points) ?></div>
                            <?php if ($points > 0): ?>
                                <?php if ($status === 'paid'): ?>
                                    <span class="text-green-700 text-xs font-semibold">Paid</span>
                                <?php else: ?>
                                    <span class="text-red-700 text-xs font-semibold">Pending</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>

                </tr>
            <?php endforeach; ?>
        </tbody>


    </table>
</div>