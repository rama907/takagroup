<?php
require_once 'config.php';

// Hanya direktur, wakil direktur, dan manager yang bisa mengakses halaman ini
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;

// Ambil daftar semua Talent aktif
$active_talents = getAllActiveTalents();

// --- A. Handle Aksi (Pembayaran Massal) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_transactions_paid') {
    $transaction_ids = $_POST['transaction_ids'] ?? [];
    $total_amount_paid = (int)($_POST['total_amount_to_pay'] ?? 0);
    
    // Preserve filters for redirect
    $redirect_date = $_POST['redirect_date'] ?? date('Y-m-d');
    $redirect_talent = $_POST['redirect_talent'] ?? '';

    if (empty($transaction_ids)) {
        $error = "Pilih minimal satu transaksi untuk dibayarkan.";
    } else {
        $conn->begin_transaction();
        try {
            
            $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
            // Tipe parameter: i (paid_by_employee_id) diikuti oleh i...i (transaction_ids)
            $types = 'i' . str_repeat('i', count($transaction_ids)); 
            $params = array_merge([$user['id']], $transaction_ids);
            
            // Menggunakan kolom paid_by_employee_id dan paid_at
            $sql = "UPDATE sales_table_room SET talent_share_status = 'Paid', paid_by_employee_id = ?, paid_at = NOW() WHERE id IN ($placeholders) AND talent_share_status = 'Pending'";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query pembayaran: " . $conn->error);
            }
            
            $stmt->bind_param($types, ...$params);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $conn->commit();
                $success = "Berhasil menandai **{$stmt->affected_rows} transaksi** Talent sebagai Dibayar. Total dibayarkan: **" . formatRupiah($total_amount_paid) . "**.";
            } else {
                throw new Exception("Gagal menandai transaksi. Mungkin sudah dibayarkan atau transaksi tidak ditemukan.");
            }
            $stmt->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Gagal memproses pembayaran: " . $e->getMessage();
        }
    }
    // Redirect untuk membersihkan POST dan menampilkan pesan
    $redirect_url = "talent-payroll-recap-weekly.php?msg=" . urlencode($success ?? $error) . "&type=" . urlencode(isset($success) ? 'success' : 'error');
    if (!empty($redirect_date)) $redirect_url .= "&daily_date=" . urlencode($redirect_date);
    if (!empty($redirect_talent)) $redirect_url .= "&talent_name=" . urlencode($redirect_talent);
    // Tambahkan hash untuk mengarahkan ke tab pembayaran harian setelah pembayaran
    header("Location: " . $redirect_url . "#daily-payment-tab");
    exit;
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $success = (isset($_GET['type']) && $_GET['type'] === 'success') ? htmlspecialchars($_GET['msg']) : $success;
    $error = (isset($_GET['type']) && $_GET['type'] === 'error') ? htmlspecialchars($_GET['msg']) : $error;
}


// --- B. Ambil Data untuk Pembayaran Per Transaksi (Daily/Real-time) ---
$today_date = date('Y-m-d');
$selected_daily_date = $_GET['daily_date'] ?? $today_date;
$selected_talent_name = $_GET['talent_name'] ?? '';

$pending_payments_log = [];
$total_pending_all_on_date = 0;

if (!empty($selected_talent_name) && !empty($selected_daily_date)) {
    $stmt_pending_log = $conn->prepare("
        SELECT str.id, str.input_time, str.base_package, str.total_net_revenue, str.talent_share, str.elysium_share, e.name as input_by_name
        FROM sales_table_room str
        JOIN employees e ON str.employee_id = e.id
        WHERE str.talent_name = ? AND DATE(str.sale_date) = ? AND str.talent_share_status = 'Pending'
        ORDER BY str.input_time DESC
    ");
    if ($stmt_pending_log) {
        $stmt_pending_log->bind_param("ss", $selected_talent_name, $selected_daily_date);
        $stmt_pending_log->execute();
        $pending_payments_log = $stmt_pending_log->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_pending_log->close();

        // Hitung Total yang Pending
        $total_pending_all_on_date = array_sum(array_column($pending_payments_log, 'talent_share'));
    }
}


// --- C. Ambil Data untuk Visual Hints (Semua Tanggal dengan Sales) ---
$dates_with_talent_sales = [];
$stmt_dates = $conn->query("
    SELECT DATE(sale_date) as sale_date, SUM(talent_share) as total_share
    FROM sales_table_room
    WHERE talent_share > 0
    GROUP BY DATE(sale_date)
");

if ($stmt_dates) {
    while ($row = $stmt_dates->fetch_assoc()) {
        if ($row['total_share'] > 0) {
            $dates_with_talent_sales[$row['sale_date']] = (int)$row['total_share']; 
        }
    }
    $stmt_dates->close();
}
$dates_with_talent_sales_json = json_encode($dates_with_talent_sales);


// --- D. Ambil Riwayat Rekap Mingguan (Historical Data) ---
$talent_recap_history = $conn->query("
    SELECT twsr.*, e.name as employee_name
    FROM talent_weekly_salary_recap twsr
    JOIN employees e ON twsr.employee_id = e.id
    ORDER BY twsr.week_start DESC, twsr.employee_name ASC
")->fetch_all(MYSQLI_ASSOC);


// --- E. Ambil Riwayat Keseluruhan Transaksi Talent ---
$all_talent_logs = [];
$filter_talent_history = $_GET['filter_talent_history'] ?? '';

$where_clause_history = "WHERE str.talent_share > 0 AND str.talent_name IS NOT NULL";
$params_history = [];
$types_history = '';

if (!empty($filter_talent_history)) {
    $where_clause_history .= " AND str.talent_name = ?";
    $params_history[] = $filter_talent_history;
    $types_history .= 's';
}

$sql_history = "
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
    {$where_clause_history}
    ORDER BY str.input_time DESC
    LIMIT 100
";

$stmt_history = $conn->prepare($sql_history);

if (!empty($params_history)) {
    $stmt_history->bind_param($types_history, ...$params_history);
}
$stmt_history->execute();
$all_talent_logs = $stmt_history->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_history->close();


// Helper functions
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
    <title>Rekap & Pembayaran Gaji Talent - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>
        .page-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .tab-content-area {
            display: none;
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
        }
        .tab-content-area.active {
            display: block;
        }
        .tab-button {
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md) var(--radius-md) 0 0;
            background: var(--bg-secondary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .tab-button.active {
            background: var(--bg-card);
            color: var(--primary-color);
            border-bottom-color: var(--bg-card);
        }

        .daily-payment-grid {
            display: grid;
            grid-template-columns: 3fr 1fr;
            gap: 20px;
        }
        @media (max-width: 1024px) {
            .daily-payment-grid {
                grid-template-columns: 1fr;
            }
        }
        .transaction-log-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: var(--spacing-md);
            margin-bottom: var(--spacing-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .transaction-info {
            flex-grow: 1;
        }
        .transaction-share {
            font-weight: 700;
            color: var(--primary-color);
            flex-shrink: 0;
            min-width: 100px;
            text-align: right;
        }
        .status-badge.paid { background-color: var(--success-light); color: var(--success-color); }
        .status-badge.pending { background-color: var(--warning-light); color: var(--warning-color); }

        .date-picker-hint-container {
            margin-top: 15px;
            padding: 10px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            max-height: 250px;
            overflow-y: auto;
        }
        .date-picker-hint-item {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            padding: 4px 0;
            border-bottom: 1px dotted var(--border-light);
        }
        .has-income { color: var(--primary-color); font-weight: 600; }
        .no-income { color: var(--text-muted); }

        .daily-filter-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            align-self: flex-start;
        }
        
        .transaction-checkbox {
            /* Gaya dasar untuk checkbox */
            width: 18px; 
            height: 18px;
            cursor: pointer;
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
                    <span class="page-icon">🧾</span>
                    Rekap & Pembayaran Gaji Talent
                </h1>
                <p>Kelola pembayaran Talent per transaksi dan lihat riwayat gaji mingguan.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <div class="page-tabs">
                <button class="tab-button active" onclick="showTab('daily-payment-tab', this)">Pembayaran Harian (Pending)</button>
                <button class="tab-button" onclick="showTab('weekly-recap-tab', this)">Rekap Mingguan (Historical)</button>
                <button class="tab-button" onclick="showTab('history-tab', this)">Riwayat Transaksi Keseluruhan</button>
            </div>

            <div id="daily-payment-tab" class="tab-content-area active">
                <div class="daily-payment-grid">
                    
                    <div class="card" style="grid-column: 1 / -1; margin-top: 0;">
                        <div class="card-header">
                             <h3>Pembayaran Transaksi Pending (Pilih Transaksi)</h3>
                        </div>
                        <div class="card-content">
                            <form method="GET" class="filter-control-container" style="align-items: center; margin-bottom: 20px;">
                                <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                                    <label for="talent_name_filter">Pilih Talent</label>
                                    <select name="talent_name" id="talent_name_filter" class="form-select" required>
                                        <option value="">-- Pilih Talent --</option>
                                        <?php foreach ($active_talents as $talent): ?>
                                            <option value="<?= htmlspecialchars($talent['name']) ?>" <?= $selected_talent_name === $talent['name'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($talent['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0; min-width: 150px;">
                                    <label for="daily_date_filter">Pilih Tanggal</label>
                                    <input type="date" name="daily_date" id="daily_date_filter" class="form-input" 
                                           value="<?= htmlspecialchars($selected_daily_date) ?>" required>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm" style="align-self: flex-end;">Filter</button>
                                <?php if (!empty($selected_talent_name) || $selected_daily_date !== $today_date): ?>
                                <a href="talent-payroll-recap-weekly.php" class="btn btn-secondary btn-sm" style="align-self: flex-end;">Reset Filter</a>
                                <?php endif; ?>
                            </form>

                            <?php if (!empty($selected_talent_name) && !empty($selected_daily_date)): ?>
                                <div class="daily-payment-grid">
                                    <div class="daily-payment-list">
                                        <form method="POST" id="payment-form">
                                            <input type="hidden" name="action" value="mark_transactions_paid">
                                            <input type="hidden" name="total_amount_to_pay" id="total_amount_to_pay" value="0">
                                            <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($selected_daily_date) ?>">
                                            <input type="hidden" name="redirect_talent" value="<?= htmlspecialchars($selected_talent_name) ?>">

                                            <h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-top: var(--spacing-lg);">
                                                Transaksi Pending Pembayaran Talent **<?= htmlspecialchars($selected_talent_name) ?>**
                                            </h4>
                                            
                                            <div class="info-message" id="payment-summary" style="margin-bottom: var(--spacing-md);">
                                                Total Share Pending **Hari Ini**: **<?= formatRupiah($total_pending_all_on_date) ?>**
                                            </div>
                                            
                                            <?php if (empty($pending_payments_log)): ?>
                                                <div class="no-data">Tidak ada transaksi pending untuk Talent ini pada tanggal yang dipilih.</div>
                                            <?php else: ?>
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                                    <label>
                                                        <input type="checkbox" id="select-all-transactions" class="transaction-checkbox"> Pilih Semua Transaksi
                                                    </label>
                                                </div>
                                                <div class="requests-list">
                                                    <?php foreach ($pending_payments_log as $log): ?>
                                                    <div class="transaction-log-item">
                                                        <label style="display: flex; gap: 10px; align-items: center; cursor: pointer;">
                                                            <input type="checkbox" name="transaction_ids[]" value="<?= $log['id'] ?>" class="transaction-checkbox" data-share="<?= $log['talent_share'] ?>">
                                                            <div class="transaction-info">
                                                                <small style="display: block; color: var(--text-muted);"><?= date('H:i:s', strtotime($log['input_time'])) ?> (ID: <?= $log['id'] ?>)</small>
                                                                <strong><?= htmlspecialchars(getPackageDisplayName($log['base_package'])) ?></strong> 
                                                                <small style="display: block; color: var(--text-secondary);">Input oleh: <?= htmlspecialchars($log['input_by_name']) ?></small>
                                                            </div>
                                                        </label>
                                                        <span class="transaction-share">
                                                            <?= formatRupiah($log['talent_share']) ?>
                                                        </span>
                                                    </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                
                                                <div style="text-align: right; margin-top: 20px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                                                    <strong style="font-size: 1.2rem;">Total Dibayarkan: <span id="selected_total_display"><?= formatRupiah(0) ?></span></strong>
                                                    <button type="submit" class="btn btn-success btn-lg" id="pay-selected-btn" disabled style="margin-top: 10px;"
                                                        onclick="return confirm('Yakin ingin membayarkan gaji untuk transaksi yang dipilih? Total: ' + document.getElementById('selected_total_display').textContent)">
                                                        Bayar Sekarang
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </form>
                                    </div>

                                    <div class="daily-filter-box">
                                        <h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-bottom: var(--spacing-lg);">
                                            Tanggal dengan Pemasukan Talent
                                        </h4>
                                        <div class="date-picker-hint-container">
                                            <?php if (empty($dates_with_talent_sales)): ?>
                                                <div class="no-income no-data" style="border: none; padding: 10px 0;">Tidak ada transaksi Talent yang tercatat.</div>
                                            <?php else: ?>
                                                <?php 
                                                krsort($dates_with_talent_sales);
                                                foreach ($dates_with_talent_sales as $date => $share): 
                                                ?>
                                                    <div class="date-picker-hint-item <?= $share > 0 ? 'has-income' : 'no-income' ?>">
                                                        <span style="font-weight: bold;"><?= date('d/m/Y', strtotime($date)) ?></span>
                                                        <span><?= formatRupiah($share) ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div id="weekly-recap-tab" class="tab-content-area">
                <div class="card full-width" style="margin-top: var(--spacing-xl);">
                    <div class="card-header">
                        <h3>Rekap Mingguan (Historical)</h3>
                        <form method="POST" action="talent-payroll-recap.php" style="display: inline;" onsubmit="return confirm('Yakin ingin menjalankan Rekap Gaji Talent Mingguan sekarang? Ini akan memproses data penjualan (share) yang BELUM dibayar dari Sel-Senin.')">
                            <input type="hidden" name="action" value="recap_now">
                            <button type="submit" class="btn btn-warning btn-sm">
                                <span class="btn-icon">⏱️</span> Jalankan Rekap Mingguan
                            </button>
                        </form>
                    </div>
                    <div class="card-content">
                        <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                            **Catatan:** Bagian ini adalah riwayat rekap mingguan lama yang disimpan (Historical Data) saat tombol **Jalankan Rekap Mingguan** diklik.
                        </div>
                        <?php if (empty($talent_recap_history)): ?>
                            <div class="no-data">Belum ada riwayat rekap mingguan yang tercatat.</div>
                        <?php else: ?>
                            <div class="responsive-table-container">
                                <table class="activities-table-improved">
                                    <thead>
                                        <tr>
                                            <th>Nama Talent</th>
                                            <th>Periode</th>
                                            <th>Total Share Saat Rekap</th>
                                            <th>Status Pembayaran</th>
                                            <th>Tanggal Rekap</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($talent_recap_history as $recap): ?>
                                        <tr>
                                            <td data-label="Nama Talent"><strong><?= htmlspecialchars($recap['employee_name']) ?></strong></td>
                                            <td data-label="Periode"><?= date('d/m', strtotime($recap['week_start'])) ?> - <?= date('d/m/Y', strtotime($recap['week_end'])) ?></td>
                                            <td data-label="Total Share Saat Rekap"><strong><?= formatRupiah($recap['total_talent_share_accumulated']) ?></strong></td>
                                            <td data-label="Status Pembayaran">
                                                <span class="status-badge <?= strtolower($recap['payment_status']) ?>"><?= htmlspecialchars($recap['payment_status']) ?></span>
                                            </td>
                                            <td data-label="Tanggal Rekap"><?= date('d/m/Y H:i', strtotime($recap['backup_date'])) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="history-tab" class="tab-content-area">
                <div class="card full-width" style="margin-top: var(--spacing-xl);">
                    <div class="card-header">
                        <h3>Riwayat Transaksi Keseluruhan (<?= count($all_talent_logs) ?> Entri Terbaru)</h3>
                    </div>
                    <div class="card-content">
                        <form method="GET" class="filter-control-container" style="align-items: center; margin-bottom: 20px;">
                            <input type="hidden" name="tab" value="history-tab">
                            <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
                                <label for="filter_talent_history">Filter Nama Talent</label>
                                <select name="filter_talent_history" id="filter_talent_history" class="form-select">
                                    <option value="">-- Semua Talent --</option>
                                    <?php foreach ($active_talents as $talent): ?>
                                        <option value="<?= htmlspecialchars($talent['name']) ?>" <?= $filter_talent_history === $talent['name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($talent['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm" style="align-self: flex-end;">Terapkan Filter</button>
                            <?php if (!empty($filter_talent_history)): ?>
                            <a href="talent-payroll-recap-weekly.php?tab=history-tab" class="btn btn-secondary btn-sm" style="align-self: flex-end;">Reset Filter</a>
                            <?php endif; ?>
                        </form>

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
            </div>
        </main>
    </div>
    
    <script>
        // Data untuk date picker hints (digunakan di JS)
        const datesWithSales = <?= $dates_with_talent_sales_json ?>;
        
        const formatRupiahJS = (amount) => {
            const number = Math.round(amount);
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            }).format(number).replace('IDR', 'Rp');
        }

        function updateSelectedTotal() {
            let total = 0;
            let anyChecked = false;
            // Hanya ambil checkbox di tab pembayaran harian
            const liveCheckboxes = document.querySelectorAll('#daily-payment-tab .transaction-checkbox[name="transaction_ids[]"]');
            
            liveCheckboxes.forEach(checkbox => {
                // Pastikan checkbox adalah input dan memiliki attribute data-share
                if (checkbox.checked && checkbox.dataset.share) {
                    total += parseFloat(checkbox.dataset.share);
                    anyChecked = true;
                }
            });
            document.getElementById('selected_total_display').textContent = formatRupiahJS(total);
            document.getElementById('total_amount_to_pay').value = total;
            document.getElementById('pay-selected-btn').disabled = !anyChecked;
            
            // Update select-all state
            const allBoxes = Array.from(liveCheckboxes);
            const allChecked = allBoxes.length > 0 && allBoxes.every(cb => cb.checked);
            const selectAllCheckbox = document.getElementById('select-all-transactions');
            // Pastikan selectAllCheckbox ada dan hanya update jika bukan klik dari selectAllCheckbox itu sendiri
            if (selectAllCheckbox && liveCheckboxes.length > 0) {
                 selectAllCheckbox.checked = allChecked;
            } else if (selectAllCheckbox) {
                 selectAllCheckbox.checked = false;
            }
        }

        function initPaymentLogic() {
            // Checkboxes individual change listener (selector spesifik untuk yang bisa dicentang)
            document.querySelectorAll('#daily-payment-tab .transaction-checkbox[name="transaction_ids[]"]').forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedTotal);
            });

            // Select All listener
            const selectAllCheckbox = document.getElementById('select-all-transactions');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    document.querySelectorAll('#daily-payment-tab .transaction-checkbox[name="transaction_ids[]"]').forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    // Panggil updateTotal setelah semua status diubah
                    updateSelectedTotal();
                });
            }
            
            // Highlight the date input based on the filtered date sales data
            const dateInput = document.getElementById('daily_date_filter');
            if (dateInput) {
                const highlightDateInput = (dateString) => {
                    const hasSales = datesWithSales[dateString] > 0;
                    const styleInput = document.getElementById('daily_date_filter');
                    
                    if (hasSales) {
                         styleInput.style.backgroundColor = 'var(--primary-light)';
                         styleInput.style.borderColor = 'var(--primary-color)';
                    } else {
                         styleInput.style.backgroundColor = 'var(--bg-card)';
                         styleInput.style.borderColor = 'var(--border-color)';
                    }
                }
                
                highlightDateInput(dateInput.value);
                dateInput.addEventListener('change', function() {
                     highlightDateInput(this.value);
                });
            }
            
            // Set initial state
            updateSelectedTotal();
        }
        
        function showTab(tabId, clickedButton) {
            // Hide all contents
            document.querySelectorAll('.tab-content-area').forEach(tab => {
                tab.classList.remove('active');
            });
            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });

            // Show selected content and set active button
            document.getElementById(tabId).classList.add('active');
            if (clickedButton) {
                clickedButton.classList.add('active');
            }
            
            // Update URL hash to remember tab state
            window.history.pushState(null, '', `#${tabId}`);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize tab based on URL hash or default
            const hash = window.location.hash.substring(1);
            const defaultTab = 'daily-payment-tab';
            
            let initialTab = defaultTab;
            if (hash && document.getElementById(hash)) {
                initialTab = hash;
            } else if (document.querySelector('[name="filter_talent_history"]')?.value) {
                 // Prioritas filter riwayat
                 initialTab = 'history-tab';
            }


            const initialButton = document.querySelector(`.page-tabs button[onclick*="${initialTab}"]`);
            if (initialButton) {
                showTab(initialTab, initialButton);
            } else {
                showTab(defaultTab, document.querySelector(`.page-tabs button[onclick*="${defaultTab}"]`));
            }


            // Initialize payment and visual logic
            initPaymentLogic();
        });
    </script>
</body>
</html>