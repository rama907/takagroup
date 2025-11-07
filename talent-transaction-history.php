<?php
require_once 'config.php';

// Hanya direktur, wakil direktur, dan manager yang bisa mengakses halaman ini
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$selected_talent_name = $_GET['talent_name'] ?? '';

// Ambil daftar semua Talent aktif untuk filter
$active_talents = getAllActiveTalents();

// Query untuk mengambil semua transaksi Talent
$where_clause = "WHERE str.talent_share > 0 AND str.talent_name IS NOT NULL";
$params = [];
$types = '';

if (!empty($selected_talent_name)) {
    $where_clause .= " AND str.talent_name = ?";
    $params[] = $selected_talent_name;
    $types .= 's';
}

$sql = "
    SELECT 
        str.id, 
        str.input_time, 
        str.base_package, 
        str.total_net_revenue, 
        str.talent_share, 
        str.talent_share_status, 
        str.paid_at,
        str.talent_name,
        e.name AS input_by_name,
        pbe.name AS paid_by_name
    FROM sales_table_room str
    JOIN employees e ON str.employee_id = e.id
    LEFT JOIN employees pbe ON str.paid_by_employee_id = pbe.id
    {$where_clause}
    ORDER BY str.input_time DESC
";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$all_talent_logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}
function getPackageDisplayName($key) {
    $map = [
        'regular_table' => 'Regular Table',
        'vip_table' => 'VIP Table',
        'vvip_table' => 'VVIP Table',
        'vvip_room_only' => 'VVIP Room Only',
        'vvip_room_angel_demon' => 'VVIP Room + Angel',
        'svip_room_angel_demon' => 'SVIP Room + Angel',
    ];
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Transaksi Talent - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>
        .status-badge.paid { background-color: var(--success-light); color: var(--success-color); }
        .status-badge.pending { background-color: var(--warning-light); color: var(--warning-color); }
        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            margin-bottom: 2rem;
            padding: 1rem;
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">💸</span>
                    Riwayat Transaksi Talent
                </h1>
                <p>Mencakup semua transaksi Talent (Dibayar & Pending) dari penjualan Table & Room.</p>
            </div>

            <form method="GET" class="filter-form">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="talent_name_filter">Filter Nama Talent</label>
                    <select name="talent_name" id="talent_name_filter" class="form-select">
                        <option value="">-- Semua Talent --</option>
                        <?php foreach ($active_talents as $talent): ?>
                            <option value="<?= htmlspecialchars($talent['name']) ?>" <?= $selected_talent_name === $talent['name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($talent['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Terapkan Filter</button>
                <?php if (!empty($selected_talent_name)): ?>
                <a href="talent-transaction-history.php" class="btn btn-secondary btn-sm">Reset Filter</a>
                <?php endif; ?>
            </form>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Daftar Transaksi (<?= count($all_talent_logs) ?> Entri)</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($all_talent_logs)): ?>
                        <div class="no-data">Tidak ada transaksi Talent yang ditemukan.</div>
                    <?php else: ?>
                        <div class="responsive-table-container">
                            <table class="activities-table-improved">
                                <thead>
                                    <tr>
                                        <th>Tanggal & Waktu Input</th>
                                        <th>Nama Talent</th>
                                        <th>Penjualan (Paket Dasar)</th>
                                        <th>Total Share Talent</th>
                                        <th>Status Bayar</th>
                                        <th>Tanggal Dibayar</th>
                                        <th>Input Oleh</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_talent_logs as $log): ?>
                                    <tr>
                                        <td data-label="Tanggal & Waktu Input">
                                            <?= date('d/m/Y H:i:s', strtotime($log['input_time'])) ?>
                                        </td>
                                        <td data-label="Nama Talent">
                                            <strong><?= htmlspecialchars($log['talent_name']) ?></strong>
                                        </td>
                                        <td data-label="Penjualan (Paket Dasar)">
                                            <?= htmlspecialchars(getPackageDisplayName($log['base_package'])) ?>
                                        </td>
                                        <td data-label="Total Share">
                                            <strong><?= formatRupiah($log['talent_share']) ?></strong>
                                        </td>
                                        <td data-label="Status Bayar">
                                            <span class="status-badge <?= strtolower($log['talent_share_status']) ?>">
                                                <?= htmlspecialchars($log['talent_share_status']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Tanggal Dibayar">
                                            <?php if ($log['talent_share_status'] === 'Paid'): ?>
                                                <?= date('d/m/Y H:i', strtotime($log['paid_at'])) ?>
                                                <br><small>(Oleh: <?= htmlspecialchars($log['paid_by_name'] ?? 'Admin') ?>)</small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Input Oleh">
                                            <?= htmlspecialchars($log['input_by_name']) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>