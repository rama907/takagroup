<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// --- New Rounding Function ---
/**
 * Membulatkan total menit duty ke jam terdekat.
 * 2 jam 29 menit -> 2 jam.
 * 2 jam 30 menit -> 3 jam.
 */
function roundToNearestHour($minutes) {
    // PHP's round() function naturally handles X.5 up, which fits the 30-minute rule.
    return round($minutes / 60);
}

// Definisi gaji per jam baru (Rupiah per jam)
$hourly_rates = [
    'ceo' => 40000,          
    'direktur' => 40000,     
    'wakil_direktur' => 40000, 
    'manager' => 24400,
    'guard' => 19200,
    'barista' => 19200,
    'waiters' => 14000,
    'karyawan' => 14000,
    'magang' => 9600,
    'chef' => 0, // Asumsi Chef masih tidak digaji per jam
];

// Konstanta perhitungan (dalam jam)
$MIN_DUTY_FULL_PAY_HOURS = 10;
$MIN_DUTY_40_CUT_HOURS = 8; 

$success_message = null;
$error_message = null;

// --- Handle Payment Status Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $employee_id = (int)($_POST['employee_id'] ?? 0);

    $conn->begin_transaction();
    try {
        if ($action === 'reset_all_paid_status') {
             // Aksi ini tidak memerlukan employee_id, jadi proses langsung
            $stmt = $conn->prepare("UPDATE employees SET is_paid = FALSE WHERE status = 'active'");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query reset semua status pembayaran: " . $conn->error);
            }

            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $conn->commit();
                $success_message = "Semua status gaji anggota berhasil diubah menjadi **Belum Dibayar**.";
                sendDiscordNotification([
                    'admin_name' => $user['name']
                ], 'salary_unpaid_all');
            } else {
                throw new Exception("Gagal mereset semua status pembayaran. Mungkin tidak ada yang perlu direset.");
            }
            $stmt->close();
            
        } elseif ($action === 'delete_all_activity_data') {
            // Aksi menghapus semua data aktivitas (sales_data dan completed duty_logs) untuk semua anggota
            
            // Cek otorisasi lebih ketat untuk mass delete
            if (!hasRole(['ceo', 'direktur', 'wakil_direktur'])) {
                 throw new Exception("Anda tidak memiliki izin untuk menghapus semua data aktivitas.");
            }

            // 1. Hapus semua data penjualan (sales_data)
            $stmt_delete_sales = $conn->prepare("DELETE FROM sales_data");
            if (!$stmt_delete_sales) {
                throw new Exception("Gagal menyiapkan query hapus data penjualan massal: " . $conn->error);
            }
            $stmt_delete_sales->execute();
            $deleted_sales_count = $stmt_delete_sales->affected_rows;
            $stmt_delete_sales->close();

            // 2. Hapus semua log jam kerja dengan status 'completed' (duty_logs)
            $stmt_delete_duty = $conn->prepare("DELETE FROM duty_logs WHERE status = 'completed'");
            if (!$stmt_delete_duty) {
                throw new Exception("Gagal menyiapkan query hapus log jam kerja massal: " . $conn->error);
            }
            $stmt_delete_duty->execute();
            $deleted_duty_count = $stmt_delete_duty->affected_rows;
            $stmt_delete_duty->close();

            $conn->commit();
            $success_message = "Semua data aktivitas (**{$deleted_sales_count} penjualan** dan **{$deleted_duty_count} log duty selesai**) berhasil dihapus untuk **SEMUA** anggota.";

            sendDiscordNotification([
                'admin_name' => $user['name'],
                'deleted_sales' => $deleted_sales_count,
                'deleted_duty_logs' => $deleted_duty_count,
                'action_type' => 'mass_activity_delete'
            ], 'admin_system_action'); // Use a general admin action notification
        
        } else {
            // Aksi-aksi berikut memerlukan employee_id
            if ($employee_id <= 0) {
                throw new Exception("ID anggota tidak valid.");
            }
            $employee_name = getEmployeeNameById($employee_id);

            if ($action === 'mark_paid') {
                $new_status = TRUE;
                $status_text = 'Sudah Dibayar';

                $stmt = $conn->prepare("UPDATE employees SET is_paid = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query update status pembayaran: " . $conn->error);
                }
                $stmt->bind_param("ii", $new_status, $employee_id);

                if (!$stmt->execute() || $stmt->affected_rows === 0) {
                    throw new Exception("Gagal mengubah status pembayaran. Mungkin sudah dalam status yang sama atau ID tidak ditemukan.");
                }
                $stmt->close();

                $conn->commit();
                $success_message = "Status gaji **" . htmlspecialchars($employee_name) . "** berhasil diubah menjadi **{$status_text}**.";
                
                sendDiscordNotification([
                    'employee_name' => $employee_name,
                    'status' => $status_text,
                    'admin_name' => $user['name']
                ], 'salary_paid_single');
                
            } elseif ($action === 'mark_unpaid') {
                $new_status = FALSE;
                $status_text = 'Belum Dibayar';

                $stmt = $conn->prepare("UPDATE employees SET is_paid = ? WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Gagal menyiapkan query update status pembayaran: " . $conn->error);
                }
                $stmt->bind_param("ii", $new_status, $employee_id);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $conn->commit();
                    $success_message = "Status gaji **" . htmlspecialchars($employee_name) . "** berhasil diubah menjadi **{$status_text}**.";
                    sendDiscordNotification([
                        'employee_name' => $employee_name,
                        'status' => $status_text,
                        'admin_name' => $user['name']
                    ], 'salary_unpaid_single');
                } else {
                    throw new Exception("Gagal mengubah status pembayaran. Mungkin sudah dalam status yang sama atau ID tidak ditemukan.");
                }
                $stmt->close();
            } elseif ($action === 'delete_sales_data') {
                // Hapus data penjualan 
                $stmt_delete_sales = $conn->prepare("DELETE FROM sales_data WHERE employee_id = ?");
                if (!$stmt_delete_sales) {
                    throw new Exception("Gagal menyiapkan query hapus data penjualan: " . $conn->error);
                }
                $stmt_delete_sales->bind_param("i", $employee_id);
                $stmt_delete_sales->execute();
                $stmt_delete_sales->close();

                // Hapus log jam kerja dengan status 'completed'
                $stmt_delete_duty = $conn->prepare("DELETE FROM duty_logs WHERE employee_id = ? AND status = 'completed'");
                if (!$stmt_delete_duty) {
                    throw new Exception("Gagal menyiapkan query hapus log jam kerja: " . $conn->error);
                }
                $stmt_delete_duty->bind_param("i", $employee_id);
                $stmt_delete_duty->execute();
                $stmt_delete_duty->close();

                $conn->commit();
                $success_message = "Semua data penjualan dan jam kerja (completed) untuk **" . htmlspecialchars($employee_name) . "** telah berhasil dihapus.";
            }
        }

    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Terjadi kesalahan: " . $e->getMessage();
    }
    header("Location: salary-recap.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error'));
    exit;
}

if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}

$employees_data = [];
$total_payroll_expenditure = 0;

$stmt = $conn->query("
    SELECT e.id, e.name, e.role, e.is_on_duty, e.is_paid,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.paket_sake, 0) as paket_sake,
           COALESCE(sales_summary.paket_anggur_merah, 0) as paket_anggur_merah,
           COALESCE(sales_summary.paket_tuak, 0) as paket_tuak,
           COALESCE(sales_summary.paket_soju, 0) as paket_soju,
           COALESCE(sales_summary.paket_spicy_1, 0) as paket_spicy_1,
           COALESCE(sales_summary.paket_spicy_2, 0) as paket_spicy_2,
           COALESCE(sales_summary.paket_spicy_3, 0) as paket_spicy_3
    FROM employees e
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs
        WHERE status = 'completed'
        GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(paket_sake) as paket_sake,
            SUM(paket_anggur_merah) as paket_anggur_merah,
            SUM(paket_tuak) as paket_tuak,
            SUM(paket_soju) as paket_soju,
            SUM(paket_spicy_1) as paket_spicy_1,
            SUM(paket_spicy_2) as paket_spicy_2,
            SUM(paket_spicy_3) as paket_spicy_3
        FROM sales_data
        GROUP BY employee_id
    ) as sales_summary ON e.id = sales_summary.employee_id
    WHERE e.status = 'active'
    ORDER BY
        CASE e.role
            WHEN 'ceo' THEN 1
            WHEN 'direktur' THEN 2
            WHEN 'wakil_direktur' THEN 3
            WHEN 'manager' THEN 4
            WHEN 'barista' THEN 5
            WHEN 'waiters' THEN 6
            WHEN 'guard' THEN 7
            WHEN 'karyawan' THEN 8
            WHEN 'magang' THEN 9
        END,
        e.name
");
$employees_raw_data = $stmt->fetch_all(MYSQLI_ASSOC);

foreach ($employees_raw_data as $employee) {
    $employee_id = $employee['id'];
    $employee_role = $employee['role'];
    $total_duty_minutes = $employee['total_duty_minutes'];
    
    // 1. Hitung Jam Kerja yang Dibulatkan (Rounding to nearest hour)
    $rounded_duty_hours = roundToNearestHour($total_duty_minutes);
    
    // Total Penjualan untuk data export
    $total_sales_packages = ($employee['paket_sake'] ?? 0) + ($employee['paket_anggur_merah'] ?? 0) + ($employee['paket_tuak'] ?? 0) + ($employee['paket_soju'] ?? 0) + ($employee['paket_spicy_1'] ?? 0) + ($employee['paket_spicy_2'] ?? 0) + ($employee['paket_spicy_3'] ?? 0);

    $gaji_pokok = 0; // Gaji Pokok sebelum potongan (Base Pay)
    $total_gajian = 0; // Gaji Akhir yang Dibayarkan
    $cut_percentage = 0;
    $keterangan_gaji = 'N/A';
    $is_cut = false;

    // --- LOGIKA PERHITUNGAN GAJI BARU ---
    if (isset($hourly_rates[$employee_role])) {
        $hourly_rate = $hourly_rates[$employee_role];
        
        // Gaji Pokok (Base Pay) berdasarkan jam yang dibulatkan
        $base_salary = $rounded_duty_hours * $hourly_rate;
        $gaji_pokok = $base_salary; 

        // 1. Peran Khusus (Chef)
        if (in_array($employee_role, ['chef'])) {
            $keterangan_gaji = 'Tidak Digaji/Jabatan Khusus';
            $gaji_pokok = 0;
            $total_gajian = 0;
        }
        // 2. Peran Senior (CEO, Direktur, Wakil Direktur)
        elseif (in_array($employee_role, ['ceo', 'direktur', 'wakil_direktur'])) {
            $total_gajian = $gaji_pokok;
            $keterangan_gaji = 'Full Pay (Senior)';
        }
        // 3. Perhitungan Gaji Operasional dengan Potongan
        else {
            if ($rounded_duty_hours >= $MIN_DUTY_FULL_PAY_HOURS) {
                // Full Pay (>= 10 jam)
                $total_gajian = $gaji_pokok;
                $keterangan_gaji = 'Lulus Syarat (Full Pay)';
            } elseif ($rounded_duty_hours >= $MIN_DUTY_40_CUT_HOURS) {
                // Potongan 40% (8 jam <= Duty < 10 jam)
                $total_gajian = $gaji_pokok * 0.60; // Pay 60%
                $keterangan_gaji = 'Potongan 40% (Duty < 10j)';
                $is_cut = true;
            } else {
                // Potongan 50% (< 8 jam)
                $total_gajian = $gaji_pokok * 0.50; // Pay 50%
                $keterangan_gaji = 'Potongan 50% (Duty < 8j)';
                $is_cut = true;
            }
        }
    }
    // --- AKHIR LOGIKA PERHITUNGAN GAJI BARU ---

    
    $total_payroll_expenditure += $total_gajian;

    $employees_data[] = [
        'id' => $employee['id'],
        'name' => $employee['name'],
        'role' => $employee['role'],
        'is_paid' => (bool)$employee['is_paid'],
        'total_duty_minutes' => $total_duty_minutes,
        'total_duty_hours' => $total_duty_minutes / 60, // Total duty tidak dibulatkan
        'rounded_duty_hours' => $rounded_duty_hours,
        'total_sales_packages' => $total_sales_packages,
        // Komponen Bonus dihilangkan / diatur 0
        'overtime_hours_display' => 0, 
        'overtime_remaining_minutes' => 0,
        'gaji_pokok' => $gaji_pokok, 
        'bonus_penjualan' => 0, 
        'nominal_bonus_lembur_perjam' => 0,
        'total_bonus_lembur' => 0,
        'bonus_21_jam' => 0,
        'total_gajian' => $total_gajian,
        'is_cut' => $is_cut,
        'keterangan_gaji' => $keterangan_gaji
    ];
}

// === START EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rekap_gajian_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    $headers = [
        'Nama',
        'Jabatan',
        'Total Jam Duty (Jam Asli)',
        'Total Jam Duty (Menit)',
        'Total Jam Duty (Jam Dibulatkan)', // BARU
        'Total Penjualan Paket',
        'Gaji Pokok (Rp)',
        'Total Gaji Bersih (Rp)',
        'Keterangan Gaji',
        'Status Pembayaran'
    ];
    fputcsv($output, $headers);

    foreach ($employees_data as $row) {
        $data_row = [
            htmlspecialchars_decode($row['name']),
            getRoleDisplayName($row['role']),
            number_format($row['total_duty_minutes'] / 60, 2), // Total Jam Duty (Jam Asli)
            $row['total_duty_minutes'],
            $row['rounded_duty_hours'], // Jam Dibulatkan
            $row['total_sales_packages'],
            $row['gaji_pokok'],
            $row['total_gajian'],
            $row['keterangan_gaji'],
            $row['is_paid'] ? 'Sudah Dibayar' : 'Belum Dibayar'
        ];
        fputcsv($output, $data_row);
    }

    fclose($output);
    exit;
}
// === END EXPORT LOGIC ===
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Gaji - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .payslip-status {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .payslip-status.paid {
            background-color: var(--success-light);
            color: var(--success-color);
        }
        .payslip-status.unpaid {
            background-color: var(--warning-light);
            color: var(--warning-color);
        }
        .action-column {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
            align-items: flex-end;
        }
        .action-column .btn {
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        @media (max-width: 1024px) {
            .activities-table-improved th,
            .activities-table-improved td {
                padding: 0.6rem;
            }
            .action-column {
                align-items: stretch;
            }
        }
        @media (max-width: 768px) {
            .activities-table-improved td:before {
                width: 40%;
            }
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
                    Rekap Gaji
                </h1>
                <p>Ringkasan perhitungan gaji untuk semua anggota Warung Om Tante</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="salary-recap.php?export=spreadsheet" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span>
                        Unduh Rekap Spreadsheet
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin MERESET status pembayaran semua anggota menjadi Belum Dibayar? Tindakan ini tidak dapat dibatalkan untuk semua!')">
                        <input type="hidden" name="action" value="reset_all_paid_status">
                        <button type="submit" class="btn btn-warning">
                            <span class="btn-icon">🔄</span> Reset Semua Status Bayar
                        </button>
                    </form>
                    <?php if (hasRole(['ceo', 'direktur', 'wakil_direktur'])): // Batasi hanya untuk level Direktur ke atas ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ PERINGATAN KERAS! Yakin ingin menghapus SELURUH data penjualan dan jam kerja (completed) untuk SEMUA anggota? Tindakan ini TIDAK DAPAT DIBATALKAN.')">
                        <input type="hidden" name="action" value="delete_all_activity_data">
                        <button type="submit" class="btn btn-danger">
                            <span class="btn-icon">🗑️</span> Hapus Semua Data Aktivitas
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">💲</div>
                    <div class="summary-content">
                        <h4>Total Pengeluaran Gaji</h4>
                        <p class="summary-value">
                            <?= 'Rp ' . number_format($total_payroll_expenditure, 0, ',', '.') ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">👥</div>
                    <div class="summary-content">
                        <h4>Jumlah Anggota</h4>
                        <p class="summary-value">
                            <?= count($employees_data) ?> Orang
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">✅</div>
                    <div class="summary-content">
                        <h4>Anggota Aktif On Duty</h4>
                        <p class="summary-value">
                            <?= count(array_filter($employees_raw_data, function($emp) { return $emp['is_on_duty']; })) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--warning-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Rata-rata Gaji per Anggota</h4>
                        <p class="summary-value">
                            <?= count($employees_data) > 0 ? 'Rp ' . number_format($total_payroll_expenditure / count($employees_data), 0, ',', '.') : 'Rp 0' ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Detail Perhitungan Gaji</h3>
                </div>
                <div class="card-content">
                    <div class="responsive-table-container">
                        <table class="activities-table-improved">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jabatan</th>
                                    <th>Jam Duty (Asli)</th>
                                    <th>Jam Duty (Bulat)</th>
                                    <th>Penjualan</th>
                                    <th>Base Gaji (100%)</th>
                                    <th>Total Gaji (Net)</th>
                                    <th>Keterangan</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees_data)): ?>
                                    <tr>
                                        <td colspan="10" class="no-data">Belum ada data anggota atau aktivitas untuk rekap gaji.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employees_data as $employee): ?>
                                    <tr data-employee-id="<?= $employee['id'] ?>">
                                        <td data-label="Nama">
                                            <div class="employee-name-cell">
                                                <span class="employee-avatar-small">
                                                    <?= strtoupper(substr(htmlspecialchars($employee['name']), 0, 1)) ?>
                                                </span>
                                                <span><?= htmlspecialchars($employee['name']) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Jabatan">
                                            <span class="role-badge role-<?= htmlspecialchars($employee['role']) ?>">
                                                <?= htmlspecialchars(getRoleDisplayName($employee['role'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Jam Duty (Asli)">
                                            <small><?= number_format($employee['total_duty_minutes'] / 60, 2) ?> jam</small>
                                            <small>(<?= formatDuration($employee['total_duty_minutes']) ?>)</small>
                                        </td>
                                        <td data-label="Jam Duty (Bulat)">
                                            <strong><?= $employee['rounded_duty_hours'] ?> jam</strong>
                                        </td>
                                        <td data-label="Penjualan">
                                            <?= $employee['total_sales_packages'] . ' Paket' ?>
                                        </td>
                                        <td data-label="Base Gaji (100%)">
                                            <?= 'Rp ' . number_format($employee['gaji_pokok'], 0, ',', '.') ?>
                                        </td>
                                        <td data-label="Total Gaji">
                                            <strong><?= 'Rp ' . number_format($employee['total_gajian'], 0, ',', '.') ?></strong>
                                        </td>
                                        <td data-label="Keterangan">
                                            <?php if (in_array($employee['role'], ['chef'])): ?>
                                                <small style="display: block; color: var(--info-color); font-weight: 600;">Tidak Digaji</small>
                                            <?php elseif (in_array($employee['role'], ['ceo', 'direktur', 'wakil_direktur'])): ?>
                                                <small style="display: block; color: var(--success-color); font-weight: 600;">Senior (Full Pay)</small>
                                            <?php elseif ($employee['is_cut']): ?>
                                                <small style="display: block; color: var(--danger-color); font-weight: 600;"><?= $employee['keterangan_gaji'] ?></small>
                                            <?php else: ?>
                                                <small style="display: block; color: var(--success-color); font-weight: 600;">Lulus Syarat (Full Pay)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status">
                                            <span class="payslip-status <?= $employee['is_paid'] ? 'paid' : 'unpaid' ?>">
                                                <?= $employee['is_paid'] ? 'Sudah Dibayar' : 'Belum Dibayar' ?>
                                            </span>
                                        </td>
                                        <td data-label="Aksi" class="action-column">
                                            <?php if (!$employee['is_paid']): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menandai gaji <?= htmlspecialchars($employee['name']) ?> sebagai Sudah Dibayar? Status akan tersimpan.')">
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-sm">Tandai Dibayar</button>
                                            </form>
                                            <?php else: ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menandai gaji <?= htmlspecialchars($employee['name']) ?> sebagai Belum Dibayar? Status akan tersimpan.')">
                                                <input type="hidden" name="action" value="mark_unpaid">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button type="submit" class="btn btn-warning btn-sm">Batal Dibayar</button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display: inline; margin-top: 5px;" onsubmit="return confirm('Yakin ingin menghapus data penjualan dan jam kerja untuk <?= htmlspecialchars($employee['name']) ?>? Tindakan ini tidak dapat dibatalkan.')">
                                                <input type="hidden" name="action" value="delete_sales_data">
                                                <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Hapus Data Aktivitas</button>
                                            </form>
                                            <a href="generate-payslip.php?employee_id=<?= $employee['id'] ?>" target="_blank" class="btn btn-primary btn-sm">Unduh Slip Gaji</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>