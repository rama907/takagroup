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

// --- A. Handle Aksi (Assign/Unassign Talent & Pembayaran Massal) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'] ?? '';
    $employee_id = (int)($_POST['employee_id'] ?? 0);

    if ($employee_id <= 0 && $action !== 'mark_transactions_paid') {
        $error = "ID anggota tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            
            if ($action === 'mark_transactions_paid') {
                $transaction_ids = $_POST['transaction_ids'] ?? [];
                $total_amount_paid = (int)($_POST['total_amount_to_pay'] ?? 0);
                
                if (empty($transaction_ids)) {
                    throw new Exception("Pilih minimal satu transaksi untuk dibayarkan.");
                }

                $placeholders = implode(',', array_fill(0, count($transaction_ids), '?'));
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
            } else {
                // Logic Assign/Unassign
                $employee_name = getEmployeeNameById($employee_id);

                if ($action === 'assign_talent') {
                    $stmt = $conn->prepare("
                        INSERT INTO talent_assignments (employee_id, is_talent, assigned_by)
                        VALUES (?, TRUE, ?)
                        ON DUPLICATE KEY UPDATE is_talent = TRUE, assigned_by = VALUES(assigned_by)
                    ");
                    if (!$stmt) { throw new Exception("Gagal menyiapkan query assign: " . $conn->error); }
                    $stmt->bind_param("ii", $employee_id, $user['id']);
                    $stmt->execute();
                    $success = "Anggota **{$employee_name}** berhasil ditandai sebagai Talent.";
    
                } elseif ($action === 'unassign_talent') {
                    $stmt = $conn->prepare("DELETE FROM talent_assignments WHERE employee_id = ?");
                    if (!$stmt) { throw new Exception("Gagal menyiapkan query unassign: " . $conn->error); }
                    $stmt->bind_param("i", $employee_id);
                    $stmt->execute();
                    $success = "Tanda Talent berhasil dihapus dari **{$employee_name}**.";
                }
    
                $conn->commit();
            }

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Gagal memproses aksi: " . $e->getMessage();
        }
    }
    // Redirect untuk membersihkan POST dan menampilkan pesan
    $redirect_url = "talent-management.php?msg=" . urlencode($success ?? $error) . "&type=" . urlencode(isset($success) ? 'success' : 'error');
    // Pertahankan filter setelah redirect
    if (isset($_GET['daily_date'])) $redirect_url .= "&daily_date=" . urlencode($_GET['daily_date']);
    if (isset($_GET['talent_name'])) $redirect_url .= "&talent_name=" . urlencode($_GET['talent_name']);
    header("Location: " . $redirect_url . "#daily-recap-section");
    exit;
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $success = (isset($_GET['type']) && $_GET['type'] === 'success') ? htmlspecialchars($_GET['msg']) : $success;
    $error = (isset($_GET['type']) && $_GET['type'] === 'error') ? htmlspecialchars($_GET['msg']) : $error;
}


// --- B. Ambil Data untuk Tampilan Utama (Assign/Unassign) ---

// 1. Ambil semua Talent aktif
$active_talents = $conn->query("
    SELECT e.id, e.name, e.role, ta.assigned_at, u.name as assigned_by_name
    FROM employees e
    JOIN talent_assignments ta ON e.id = ta.employee_id
    LEFT JOIN employees u ON ta.assigned_by = u.id
    WHERE e.status = 'active' AND ta.is_talent = TRUE
    ORDER BY e.name
")->fetch_all(MYSQLI_ASSOC);

// 2. Ambil semua karyawan yang BUKAN Talent
$non_talents = $conn->query("
    SELECT e.id, e.name, e.role
    FROM employees e
    LEFT JOIN talent_assignments ta ON e.id = ta.employee_id
    WHERE e.status = 'active' AND (ta.is_talent IS NULL OR ta.is_talent = FALSE)
    ORDER BY e.name
")->fetch_all(MYSQLI_ASSOC);


// --- C. Ambil Data untuk Pembayaran Per Transaksi ---
$today_date = date('Y-m-d');
$selected_daily_date = $_GET['daily_date'] ?? $today_date;
$selected_talent_name = $_GET['talent_name'] ?? '';

// 2. Ambil Log Pending Payment
$pending_payments_log = [];
$total_pending_all = 0;

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
        $total_pending_all = array_sum(array_column($pending_payments_log, 'talent_share'));
    }
}

// 3. Ambil Semua Tanggal dengan Gaji (untuk visualisasi date picker hints)
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
            // Simpan tanggal dan total share untuk Talent manapun
            $dates_with_talent_sales[$row['sale_date']] = (int)$row['total_share']; 
        }
    }
    $stmt_dates->close();
}
$dates_with_talent_sales_json = json_encode($dates_with_talent_sales);


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
    <title>Manajemen Talent - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>
        .management-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: var(--spacing-xl);
        }
        @media (min-width: 1024px) {
            .management-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        .assign-card .card-content {
            max-height: 400px;
            overflow-y: auto;
        }
        .employee-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--spacing-sm) 0;
            border-bottom: 1px dashed var(--border-light);
        }
        .employee-list-item:last-child {
            border-bottom: none;
        }
        .employee-list-item span {
            font-size: 0.9rem;
        }
        .status-badge.paid { background-color: var(--success-light); color: var(--success-color); }
        .status-badge.pending { background-color: var(--warning-light); color: var(--warning-color); }

        /* Gaya untuk Daily Recap */
        .date-picker-hint-container {
            margin-top: 15px;
            padding: 10px;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            max-height: 150px;
            overflow-y: auto;
        }
        .date-picker-hint-item {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            padding: 4px 0;
            border-bottom: 1px dotted var(--border-light);
        }
        .date-picker-hint-item:last-child { border-bottom: none; }
        .has-income { color: var(--primary-color); font-weight: 600; }
        .no-income { color: var(--text-muted); }

        .daily-date-hints {
            /* Pastikan kolom hints tetap di grid */
            grid-column: 2 / 3;
        }
        @media (max-width: 1023px) {
            .daily-date-hints {
                grid-column: 1 / -1; /* Penuh di mobile */
            }
        }
        .daily-summary-data {
             grid-column: 1 / 2;
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
                    <span class="page-icon">🎭</span>
                    Manajemen Talent
                </h1>
                <p>Kelola daftar Talent (Angel/Demon) dan pembayaran gaji berbasis share mereka.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="management-grid">
                
                <div class="card assign-card">
                    <div class="card-header">
                        <h3>Anggota yang BUKAN Talent (Assign)</h3>
                        <span class="member-count"><?= count($non_talents) ?> Orang</span>
                    </div>
                    <div class="card-content">
                        <?php if (empty($non_talents)): ?>
                            <div class="no-data">Semua anggota sudah terdaftar sebagai Talent!</div>
                        <?php else: ?>
                            <?php foreach ($non_talents as $emp): ?>
                                <div class="employee-list-item">
                                    <span>
                                        <strong><?= htmlspecialchars($emp['name']) ?></strong> 
                                        (<small><?= getRoleDisplayName($emp['role']) ?></small>)
                                    </span>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="assign_talent">
                                        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Tandai Talent</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card assign-card">
                    <div class="card-header">
                        <h3>Daftar Talent Aktif (<?= count($active_talents) ?>)</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($active_talents)): ?>
                            <div class="no-data">Belum ada Talent yang terdaftar.</div>
                        <?php else: ?>
                            <?php foreach ($active_talents as $talent): ?>
                                <div class="employee-list-item">
                                    <span>
                                        <strong><?= htmlspecialchars($talent['name']) ?></strong> 
                                        (<small>Sejak: <?= date('d/m/Y', strtotime($talent['assigned_at'])) ?></small>)
                                    </span>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus tanda Talent dari <?= htmlspecialchars($talent['name']) ?>?')">
                                        <input type="hidden" name="action" value="unassign_talent">
                                        <input type="hidden" name="employee_id" value="<?= $talent['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Hapus Tanda</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card full-width" style="margin-top: var(--spacing-2xl);" id="daily-recap-section">
                <div class="card-header">
                    <h3>Pembayaran Gaji Talent Per Transaksi</h3>
                    <a href="talent-transaction-history.php" class="btn btn-info btn-sm">Lihat Semua Riwayat</a>
                </div>
                <div class="card-content">
                    <form method="GET" class="filter-control-container" id="daily-recap-form" style="align-items: center; margin-bottom: 20px;">
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
                        <a href="talent-management.php" class="btn btn-secondary btn-sm" style="align-self: flex-end;">Reset Filter</a>
                        <?php endif; ?>
                    </form>

                    <?php if (!empty($selected_talent_name)): ?>
                        <form method="POST" id="payment-form">
                            <input type="hidden" name="action" value="mark_transactions_paid">
                            <input type="hidden" name="total_amount_to_pay" id="total_amount_to_pay" value="0">

                            <h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem; margin-top: var(--spacing-lg);">
                                Transaksi Pending Pembayaran Talent **<?= htmlspecialchars($selected_talent_name) ?>**
                            </h4>
                            
                            <div class="info-message" id="payment-summary" style="margin-bottom: var(--spacing-md);">
                                Total Share Pending untuk **<?= date('d/m/Y', strtotime($selected_daily_date)) ?>**: **<?= formatRupiah($total_pending_all) ?>**
                            </div>
                            
                            <?php if (empty($pending_payments_log)): ?>
                                <div class="no-data">Tidak ada transaksi pending untuk Talent ini pada tanggal yang dipilih.</div>
                            <?php else: ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <label>
                                        <input type="checkbox" id="select-all-transactions"> Pilih Semua
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
                                    <strong style="font-size: 1.2rem;">Total Dipilih: <span id="selected_total_display"><?= formatRupiah(0) ?></span></strong>
                                    <button type="submit" class="btn btn-success btn-lg" id="pay-selected-btn" disabled style="margin-top: 10px;"
                                        onclick="return confirm('Yakin ingin membayarkan gaji untuk transaksi yang dipilih? Aksi ini akan mencatat pembayaran.')">
                                        Bayar Transaksi Dipilih
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                    <div class="info-message">
                        Silakan pilih **Nama Talent** dan **Tanggal** untuk melihat riwayat gaji harian real-time.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </main>
    </div>
    
    <script>
        // Data untuk date picker hints (digunakan di JS)
        const datesWithSales = <?= $dates_with_talent_sales_json ?>;
        const dateInput = document.getElementById('daily_date_filter');
        const selectAllCheckbox = document.getElementById('select-all-transactions');
        const transactionCheckboxes = document.querySelectorAll('.transaction-checkbox');
        const selectedTotalDisplay = document.getElementById('selected_total_display');
        const totalAmountToPayInput = document.getElementById('total_amount_to_pay');
        const paySelectedBtn = document.getElementById('pay-selected-btn');
        
        const currencyToNumber = (rupiah) => {
             return parseInt(rupiah.replace(/[^0-9]/g, '')) || 0;
        }

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
            const liveCheckboxes = document.querySelectorAll('.transaction-checkbox');
            
            liveCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    total += parseInt(checkbox.dataset.share);
                    anyChecked = true;
                }
            });
            selectedTotalDisplay.textContent = formatRupiahJS(total);
            totalAmountToPayInput.value = total;
            paySelectedBtn.disabled = !anyChecked;
            
            // Update select-all state
            const allBoxes = Array.from(liveCheckboxes);
            const allChecked = allBoxes.length > 0 && allBoxes.every(cb => cb.checked);
            if (selectAllCheckbox) {
                 selectAllCheckbox.checked = allChecked;
            }
        }

        function initPaymentLogic() {
            // Checkboxes individual change listener
            document.querySelectorAll('.transaction-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedTotal);
            });

            // Select All listener
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    document.querySelectorAll('.transaction-checkbox').forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                    updateSelectedTotal();
                });
            }
            
            // Initial call to set color based on current filtered date
            if (dateInput) {
                const highlightDateInput = (dateString) => {
                    const hasSales = datesWithSales[dateString] > 0;
                    if (hasSales) {
                         dateInput.style.backgroundColor = 'var(--primary-light)';
                         dateInput.style.borderColor = 'var(--primary-color)';
                    } else {
                         dateInput.style.backgroundColor = 'var(--bg-card)';
                         dateInput.style.borderColor = 'var(--border-color)';
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

        document.addEventListener('DOMContentLoaded', initPaymentLogic);
    </script>
</body>
</html>