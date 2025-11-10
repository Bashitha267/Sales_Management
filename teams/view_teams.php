<?php
// Connect to DB
require_once '../config.php';
require_once '../auth.php';
requireLogin();

// Admin check
if ($_SESSION['role'] !== 'admin') {
    header('Location: /ref/login.php');
    exit;
}

// --- POST HANDLER (Deletes) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $response = ['success' => false];

    // Handle Rep from Agency removal
    if (isset($data['action']) && $data['action'] === 'remove_rep') {
        $rep_id = (int) ($data['rep_id'] ?? 0);
        $rep_id_from = (int) ($data['representative_id'] ?? 0);

        if ($rep_id > 0 && $rep_id_from > 0) {
            $stmt = $mysqli->prepare("DELETE FROM agency_reps WHERE rep_user_id = ? AND representative_id = ?");
            $stmt->bind_param('ii', $rep_id, $rep_id_from);
            if ($stmt->execute()) {
                $response['success'] = true;
            }
            $stmt->close();
        }
    }

    // Handle Full Representative deletion
    if (isset($data['action']) && $data['action'] === 'delete_representative') {
        $rep_id = (int) ($data['id'] ?? 0);

        if ($rep_id > 0) {
            $mysqli->begin_transaction();
            try {
                // 1. Delete all their agency assignments
                $stmt1 = $mysqli->prepare("DELETE FROM agency_reps WHERE representative_id = ?");
                $stmt1->bind_param('i', $rep_id);
                $stmt1->execute();
                $stmt1->close();

                // 2. Delete the representative user
                $stmt2 = $mysqli->prepare("DELETE FROM users WHERE id = ? AND role = 'representative'");
                $stmt2->bind_param('i', $rep_id);
                $stmt2->execute();
                $stmt2->close();

                $mysqli->commit();
                $response['success'] = true;
            } catch (mysqli_sql_exception $exception) {
                $mysqli->rollback();
                $response['error'] = 'Database error: ' . $exception->getMessage();
            }
        }
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// --- GET HANDLER (Page load and Modal data) ---
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {

    $representative_id = (int) ($_GET['representative_id'] ?? 0);
    $agency_id = (int) ($_GET['agency_id'] ?? 0);

    if ($representative_id === 0 || $agency_id === 0) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid parameters.']);
        exit;
    }

    // This is the main query to get reps and their monthly points
    $sql = "
        SELECT 
            u.id AS rep_id,
            u.username AS rep_username,
            CONCAT(u.first_name, ' ', u.last_name) AS rep_full_name,
            COALESCE(monthly_points.total_rep_points, 0) AS total_rep_points,
            COALESCE(monthly_points.total_rep_agency_points, 0) AS total_rep_agency_points
        FROM 
            agency_reps ar
        JOIN 
            users u ON ar.rep_user_id = u.id
        LEFT JOIN (
            -- Subquery to calculate points for all reps for the current month
            SELECT
                s.rep_user_id,
                SUM(i.rep_points * si.quantity) AS total_rep_points,
                SUM(i.representative_points * si.quantity) AS total_rep_agency_points
            FROM 
                sales s
            JOIN 
                sale_items si ON s.id = si.sale_id
            JOIN 
                items i ON si.item_id = i.id
            WHERE 
                s.sale_type = 'full'
                AND s.admin_approved = 1
                -- Filter for the current month and year
                AND MONTH(s.sale_date) = MONTH(CURRENT_DATE())
                AND YEAR(s.sale_date) = YEAR(CURRENT_DATE())
            GROUP BY
                s.rep_user_id
        ) AS monthly_points ON ar.rep_user_id = monthly_points.rep_user_id
        WHERE 
            ar.representative_id = ?
            AND ar.agency_id = ?
            AND u.role = 'rep'
        ORDER BY
            u.first_name, u.last_name;
    ";

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $representative_id, $agency_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate the total agency points from all reps
    $total_agency_points = 0;
    foreach ($data as $row) {
        $total_agency_points += $row['total_rep_agency_points'];
    }

    // Return a structured response with reps list and the total
    $response = [
        'reps' => $data,
        'total_agency_points' => $total_agency_points
    ];

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// --- NORMAL PAGE LOAD ---

// Get new statistics
$total_representatives = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'representative'")->fetch_assoc()['c'];
$total_reps = $mysqli->query("SELECT COUNT(*) AS c FROM users WHERE role = 'rep'")->fetch_assoc()['c'];
$total_assignments = $mysqli->query("SELECT COUNT(*) AS c FROM agency_reps")->fetch_assoc()['c'];

// Get all Representatives to display as cards
$sql = "
    SELECT 
        id, 
        CONCAT(first_name, ' ', last_name) AS full_name,
        username
    FROM users 
    WHERE role = 'representative'
    ORDER BY first_name, last_name
";

$stmt = $mysqli->prepare($sql);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Teams - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

    <?php include '../admin_header.php'; ?>

    <main class="p-4 sm:p-10">
        <div class="max-w-6xl mx-auto">

            <div class="mb-8">
                <h2 class="text-3xl font-bold text-slate-800 flex items-center gap-3 mb-2">
                    <img src="https://img.icons8.com/fluency-systems-regular/48/4f46e5/groups.png" class="w-8 h-8" />
                    Agencies Overview
                </h2>
                <p class="text-slate-600 mt-2">Manage and view all representatives and their agencies.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                <div class="bg-indigo-100 text-indigo-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold mb-2">Total Representatives</h3>
                    <p class="text-3xl font-bold"><?= $total_representatives ?></p>
                </div>

                <div class="bg-emerald-100 text-emerald-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold mb-2">Total Reps</h3>
                    <p class="text-3xl font-bold"><?= $total_reps ?></p>
                </div>

                <div class="bg-amber-100 text-amber-800 p-6 rounded-xl shadow hover:shadow-md transition">
                    <h3 class="text-lg font-semibold mb-2">Total Agency Assignments</h3>
                    <p class="text-3xl font-bold"><?= $total_assignments ?></p>
                </div>
            </div>

            <div class="mb-6">
                <input type="text" id="cardSearchInput" placeholder="Search representatives by name or username..."
                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-slate-700">
            </div>

            <div id="representativesGrid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <div
                            class="rep-card bg-white p-5 rounded-xl shadow hover:shadow-lg transition-all text-left flex flex-col group">
                            <div class="flex-grow">
                                <div class="flex items-center gap-3 mb-3">
                                    <span class="flex-shrink-0 bg-indigo-100 p-2 rounded-full">
                                        <img src="https://img.icons8.com/fluency-systems-regular/48/4f46e5/user-shield.png"
                                            class="w-6 h-6" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-lg font-bold text-slate-800 truncate group-hover:text-indigo-600 transition-colors"
                                            data-name>
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </p>
                                        <p class="text-sm text-slate-500" data-username>
                                            <?= htmlspecialchars($row['username']) ?></p>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-400 font-mono">ID: <?= $row['id'] ?></span>
                            </div>

                            <div class="flex justify-between items-center mt-4 pt-4 border-t border-slate-100">
                                <button class="view-agency-btn text-sm font-medium text-indigo-600 hover:text-indigo-800"
                                    data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['full_name']) ?>">
                                    View Agencies
                                </button>
                                <button class="delete-rep-btn text-sm font-medium text-red-500 hover:text-red-700"
                                    data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['full_name']) ?>">
                                    Delete
                                </button>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-span-full flex flex-col items-center justify-center text-gray-500 py-16">
                        <img src="https://img.icons8.com/fluency-systems-regular/64/9ca3af/groups.png"
                            class="w-16 h-16 mb-3 opacity-50" />
                        <p class="text-lg font-medium">No representatives found</p>
                        <p class="text-sm mt-1">No users with the 'representative' role exist yet.</p>
                    </div>
                <?php endif; ?>
                <div id="noResultsCard"
                    class="col-span-full hidden flex-col items-center justify-center text-gray-500 py-16">
                    <img src="https://img.icons8.com/fluency-systems-regular/64/9ca3af/groups.png"
                        class="w-16 h-16 mb-3 opacity-50" />
                    <p class="text-lg font-medium">No representatives found</p>
                    <p class="text-sm mt-1">Your search term returned no results.</p>
                </div>
            </div>
        </div>
    </main>

    <div id="agencyModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 hidden">
        <div class="bg-white rounded-lg shadow-lg max-w-4xl w-full p-6 sm:p-8 m-4 max-h-[90vh] flex flex-col">
            <div class="flex justify-between items-center pb-4 border-b">
                <div>
                    <h3 class="text-2xl font-bold text-slate-800" id="modalRepName">Representative Name</h3>
                    <p class="text-sm text-slate-500">Viewing agency reps and current monthly points ('Full' sales only)
                    </p>
                </div>
                <button id="closeModalBtn" class="text-gray-400 hover:text-gray-600 text-3xl">&times;</button>
            </div>

            <div class="flex border-b border-gray-200 mt-6">
                <button data-agency="1"
                    class="agency-tab-btn flex-1 py-3 px-4 text-center font-medium text-indigo-600 border-b-2 border-indigo-600">
                    Agency 1
                </button>
                <button data-agency="2"
                    class="agency-tab-btn flex-1 py-3 px-4 text-center font-medium text-slate-500 hover:text-slate-700">
                    Agency 2
                </button>
            </div>

            <div id="modalTableContainer" class="flex-grow overflow-y-auto mt-4">
                <div class="flex items-center justify-center p-12 text-slate-500">
                    <p>Loading data...</p>
                </div>
            </div>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('agencyModal');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const modalRepName = document.getElementById('modalRepName');
            const modalTableContainer = document.getElementById('modalTableContainer');
            const grid = document.getElementById('representativesGrid');
            const agencyTabs = document.querySelectorAll('.agency-tab-btn');
            const searchInput = document.getElementById('cardSearchInput');
            const noResultsCard = document.getElementById('noResultsCard');

            let currentRepresentativeId = null;
            let currentAgencyId = 1; // Default to agency 1

            // --- Card Search/Filter ---
            searchInput.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase().trim();
                const cards = grid.querySelectorAll('.rep-card');
                let visibleCards = 0;

                cards.forEach(card => {
                    const name = card.querySelector('[data-name]').textContent.toLowerCase();
                    const username = card.querySelector('[data-username]').textContent.toLowerCase();

                    if (name.includes(searchTerm) || username.includes(searchTerm)) {
                        card.classList.remove('hidden');
                        visibleCards++;
                    } else {
                        card.classList.add('hidden');
                    }
                });

                if (visibleCards === 0) {
                    noResultsCard.classList.remove('hidden');
                    noResultsCard.classList.add('flex');
                } else {
                    noResultsCard.classList.add('hidden');
                    noResultsCard.classList.remove('flex');
                }
            });

            // --- Modal Logic ---
            const openModal = (representativeId, representativeName) => {
                currentRepresentativeId = representativeId;
                modalRepName.textContent = representativeName;
                modal.classList.remove('hidden');

                // Default to Agency 1
                currentAgencyId = 1;
                loadAgencyData(currentAgencyId);
                updateTabStyles(currentAgencyId);
            };

            const closeModal = () => {
                modal.classList.add('hidden');
                currentRepresentativeId = null;
                modalTableContainer.innerHTML = '<div class="flex items-center justify-center p-12 text-slate-500"><p>Loading data...</p></div>';
            };

            const updateTabStyles = (activeAgencyId) => {
                agencyTabs.forEach(tab => {
                    if (tab.dataset.agency == activeAgencyId) {
                        tab.classList.add('text-indigo-600', 'border-indigo-600');
                        tab.classList.remove('text-slate-500', 'hover:text-slate-700');
                    } else {
                        tab.classList.remove('text-indigo-600', 'border-indigo-600');
                        tab.classList.add('text-slate-500', 'hover:text-slate-700');
                    }
                });
            };

            const loadAgencyData = async (agencyId) => {
                if (!currentRepresentativeId) return;
                currentAgencyId = agencyId; // Store the active agency
                modalTableContainer.innerHTML = '<div class="flex items-center justify-center p-12 text-slate-500"><p>Loading data...</p></div>';

                try {
                    const url = `?ajax=1&representative_id=${currentRepresentativeId}&agency_id=${agencyId}`;
                    const response = await fetch(url);
                    if (!response.ok) throw new Error('Network response was not ok');

                    const data = await response.json();

                    if (data.error) {
                        modalTableContainer.innerHTML = `<div class="p-12 text-center text-red-500">${data.error}</div>`;
                        return;
                    }

                    renderTable(data.reps, data.total_agency_points);

                } catch (error) {
                    console.error('Fetch error:', error);
                    modalTableContainer.innerHTML = '<div class="p-12 text-center text-red-500">Failed to load data. Please try again.</div>';
                }
            };

            const renderTable = (reps, totalAgencyPoints) => {

                // Card for the total points from all reps in this agency
                let totalHtml = `
                    <div class="bg-indigo-50 border border-indigo-200 text-indigo-800 p-4 rounded-lg mb-4">
                        <h4 class="font-semibold text-sm uppercase tracking-wider">Leader's Total Points (From Reps)</h4>
                        <p class="text-3xl font-bold">${parseInt(totalAgencyPoints)}</p>
                    </div>
                `;

                if (reps.length === 0) {
                    // Show total card, then the "no reps" message
                    modalTableContainer.innerHTML = totalHtml + `
                        <div class="text-center p-12 text-slate-500">
                            <p class="font-medium">No reps found for this agency.</p>
                        </div>`;
                    return;
                }

                let tableHtml = `
                    <div class="overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-slate-200">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Rep ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Username</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Rep Points (Month)</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                `;

                reps.forEach(rep => {
                    tableHtml += `
                        <tr class="hover:bg-slate-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">${rep.rep_id}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-700">${escapeHtml(rep.rep_full_name)}</td>
                            <td classs="px-6 py-4 whitespace-nowrap text-sm text-slate-500">${escapeHtml(rep.rep_username)}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-900 font-bold text-right">${parseInt(rep.total_rep_points)}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <button 
                                    class="remove-rep-btn text-red-500 hover:text-red-700 text-sm font-medium"
                                    data-rep-id="${rep.rep_id}"
                                    data-rep-name="${escapeHtml(rep.rep_full_name)}">
                                    Remove
                                </button>
                            </td>
                        </tr>
                    `;
                });

                tableHtml += `</tbody></table></div>`;

                modalTableContainer.innerHTML = totalHtml + tableHtml;
            };

            const escapeHtml = (unsafe) => {
                if (unsafe === null || unsafe === undefined) return '';
                return String(unsafe).replace(/[&<>"']/g, (match) => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[match]));
            };

            // --- Main Page Event Listener (for card buttons) ---
            grid.addEventListener('click', (e) => {
                const viewBtn = e.target.closest('.view-agency-btn');
                if (viewBtn) {
                    openModal(viewBtn.dataset.id, viewBtn.dataset.name);
                    return;
                }

                const deleteBtn = e.target.closest('.delete-rep-btn');
                if (deleteBtn) {
                    const repId = deleteBtn.dataset.id;
                    const repName = deleteBtn.dataset.name;
                    if (confirm(`Are you sure you want to PERMANENTLY DELETE ${repName} and all their agencies/reps? This cannot be undone.`)) {
                        deleteRepresentative(repId, deleteBtn.closest('.rep-card'));
                    }
                    return;
                }
            });

            // --- Modal Event Listener (for table buttons) ---
            modalTableContainer.addEventListener('click', (e) => {
                const removeBtn = e.target.closest('.remove-rep-btn');
                if (removeBtn) {
                    const repId = removeBtn.dataset.repId;
                    const repName = removeBtn.dataset.repName;

                    if (confirm(`Are you sure you want to remove ${repName} from this agency?`)) {
                        removeRepFromAgency(repId);
                    }
                }
            });

            // --- AJAX Delete Functions ---

            async function deleteRepresentative(repId, cardElement) {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'delete_representative',
                            id: repId
                        })
                    });
                    const data = await response.json();
                    if (data.success) {
                        cardElement.style.transition = 'opacity 0.3s';
                        cardElement.style.opacity = '0';
                        setTimeout(() => cardElement.remove(), 300);
                    } else {
                        alert('Error deleting representative: ' + (data.error || 'Unknown error'));
                    }
                } catch (error) {
                    alert('Request failed: ' + error);
                }
            }

            async function removeRepFromAgency(repId) {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'remove_rep',
                            rep_id: repId,
                            representative_id: currentRepresentativeId
                        })
                    });
                    const data = await response.json();
                    if (data.success) {
                        // Refresh the modal data to show the rep is gone
                        // AND to update the leader's total points
                        loadAgencyData(currentAgencyId);
                    } else {
                        alert('Error removing rep: ' + (data.error || 'Unknown error'));
                    }
                } catch (error) {
                    alert('Request failed: ' + error);
                }
            }

            // --- Modal Close Listeners ---
            closeModalBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', (e) => {
                if (e.target === modal) closeModal();
            });

            agencyTabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const agencyId = tab.dataset.agency;
                    updateTabStyles(agencyId);
                    loadAgencyData(agencyId);
                });
            });
        });
    </script>

</body>

</html>

<?php
// Close statements and connection
if (isset($stmt) && $stmt)
    $stmt->close();
$mysqli->close();
?>