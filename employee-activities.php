<?php
require_once 'config.php';

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

// Pastikan fungsi formatDuration dan getRoleDisplayName ada di config.php atau di-include di tempat lain
if (!function_exists('formatDuration')) {
    function formatDuration($minutes) {
        if ($minutes < 0) return "0j 0m"; // Tangani durasi negatif dengan baik
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return "{$hours}j {$remainingMinutes}m";
    }
}

if (!function_exists('getRoleDisplayName')) {
    function getRoleDisplayName($role) {
        $roles = [
            'ceo' => 'CEO',
            'direktur' => 'Direktur',
            'wakil_direktur' => 'Wakil Direktur',
            'manager' => 'Manager',
            'barista' => 'Barista',
            'waiters' => 'Waiters',
            'guard' => 'Guard',
            'karyawan' => 'Karyawan',
            'magang' => 'Magang',
            // Tambahkan peran lain jika ada
        ];
        return $roles[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

// Dapatkan ringkasan aktivitas karyawan (mengambil data keseluruhan)
$stmt = $conn->query("
    SELECT
        e.id,
        e.name,
        e.role,
        e.is_on_duty,
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

if ($stmt === false) {
    die("Gagal menjalankan query: " . $conn->error);
}

$employee_activities = $stmt->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Hitung total penjualan
$total_sake = array_sum(array_column($employee_activities, 'paket_sake'));
$total_anggur_merah = array_sum(array_column($employee_activities, 'paket_anggur_merah'));
$total_tuak = array_sum(array_column($employee_activities, 'paket_tuak'));
$total_soju = array_sum(array_column($employee_activities, 'paket_soju'));
$total_spicy_1 = array_sum(array_column($employee_activities, 'paket_spicy_1'));
$total_spicy_2 = array_sum(array_column($employee_activities, 'paket_spicy_2'));
$total_spicy_3 = array_sum(array_column($employee_activities, 'paket_spicy_3'));

$total_paket_terjual_keseluruhan = $total_sake + $total_anggur_merah + $total_tuak + $total_soju + $total_spicy_1 + $total_spicy_2 + $total_spicy_3;

// === START EXPORT LOGIC ===
if (isset($_GET['export']) && $_GET['export'] == 'spreadsheet') {
    $export_stmt = $conn->query("
        SELECT
            e.id,
            e.name,
            e.role,
            e.is_on_duty,
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
    if ($export_stmt === false) {
        die("Gagal menjalankan query ekspor: " . $conn->error);
    }
    $export_data = $export_stmt->fetch_all(MYSQLI_ASSOC);
    $export_stmt->close();

    // Set header untuk unduhan CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="aktivitas_anggota_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Tambahkan UTF-8 BOM untuk kompatibilitas Excel (penting untuk karakter non-ASCII)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Definisikan CSV headers (ramah pengguna)
    $headers = [
        'Nama',
        'Jabatan',
        'Status On Duty',
        'Total Jam Kerja Keseluruhan',
        'Sake', 
        'Anggur Merah',
        'Tuak',
        'Soju',
        'Spicy 1', 
        'Spicy 2', 
        'Spicy 3'
    ];
    fputcsv($output, $headers);

    // Tulis baris data
    foreach ($export_data as $row) {
        $data_row = [
            htmlspecialchars_decode($row['name']), // Dekode entitas HTML jika ada
            getRoleDisplayName($row['role']),
            $row['is_on_duty'] ? 'On Duty' : 'Off Duty',
            formatDuration($row['total_duty_minutes']),
            $row['paket_sake'], 
            $row['paket_anggur_merah'],
            $row['paket_tuak'],
            $row['paket_soju'],
            $row['paket_spicy_1'], 
            $row['paket_spicy_2'], 
            $row['paket_spicy_3']
        ];
        fputcsv($output, $data_row);
    }

    fclose($output);
    exit; // Hentikan eksekusi lebih lanjut setelah mengirim file
}
// === AKHIR LOGIKA EKSPOR ===
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitas Anggota - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📊</span>
                    Aktivitas Anggota
                </h1>
                <p>Ringkasan aktivitas dan performa semua anggota</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="employee-activities.php?export=spreadsheet" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span>
                        Unduh Data Spreadsheet
                    </a>
                </div>
            </div>

            <div class="summary-stats-container">
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--info-color);">⏰</div>
                    <div class="summary-content">
                        <h4>Total Jam Kerja Keseluruhan</h4>
                        <p class="summary-value">
                            <?= formatDuration(array_sum(array_column($employee_activities, 'total_duty_minutes'))) ?>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--primary-color);">💰</div>
                    <div class="summary-content">
                        <h4>Total Penjualan Keseluruhan</h4>
                        <p class="summary-value">
                            <?= $total_paket_terjual_keseluruhan ?>
                        </p>
                        <p class="stat-breakdown" style="font-size: 0.9em; margin-top: 0.5rem; text-align: left;">
                            <span>Sake: <strong><?= $total_sake ?></strong></span>
                            <span>Anggur Merah: <strong><?= $total_anggur_merah ?></strong></span>
                            <span>Tuak: <strong><?= $total_tuak ?></strong></span>
                            <span>Soju: <strong><?= $total_soju ?></strong></span>
                            <span>Spicy 1: <strong><?= $total_spicy_1 ?></strong></span>
                            <span>Spicy 2: <strong><?= $total_spicy_2 ?></strong></span>
                            <span>Spicy 3: <strong><?= $total_spicy_3 ?></strong></span>
                        </p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="color: var(--success-color);">✅</div>
                    <div class="summary-content">
                        <h4>Anggota Aktif On Duty</h4>
                        <p class="summary-value">
                            <?= count(array_filter($employee_activities, function($emp) { return $emp['is_on_duty']; })) ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3>Ringkasan Aktivitas per Anggota</h3>
                </div>
                <div class="card-content">
                    <div class="responsive-table-container">
                        <table class="activities-table-improved">
                            <thead>
                                <tr>
                                    <th>Nama</th>
                                    <th>Jabatan</th>
                                    <th>Status</th>
                                    <th>Total Jam Kerja</th>
                                    <th>Sake</th>
                                    <th>Anggur Merah</th>
                                    <th>Tuak</th>
                                    <th>Soju</th>
                                    <th>Spicy 1</th>
                                    <th>Spicy 2</th>
                                    <th>Spicy 3</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employee_activities)): ?>
                                    <tr>
                                        <td colspan="11" class="no-data">Belum ada data aktivitas anggota.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employee_activities as $activity): ?>
                                    <tr>
                                        <td data-label="Nama">
                                            <div class="employee-name-cell">
                                                <span class="employee-avatar-small">
                                                    <?= strtoupper(substr(htmlspecialchars($activity['name']), 0, 1)) ?>
                                                </span>
                                                <span><?= htmlspecialchars($activity['name']) ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Jabatan">
                                            <span class="role-badge role-<?= htmlspecialchars($activity['role']) ?>">
                                                <?= htmlspecialchars(getRoleDisplayName($activity['role'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <div class="status-cell">
                                                <span class="status-indicator <?= $activity['is_on_duty'] ? 'on-duty' : 'off-duty' ?>"></span>
                                                <span><?= $activity['is_on_duty'] ? 'On Duty' : 'Off Duty' ?></span>
                                            </div>
                                        </td>
                                        <td data-label="Total Jam Kerja">
                                            <strong><?= formatDuration($activity['total_duty_minutes']) ?></strong>
                                        </td>
                                        <td data-label="Sake"><?= $activity['paket_sake'] ?></td>
                                        <td data-label="Anggur Merah"><?= $activity['paket_anggur_merah'] ?></td>
                                        <td data-label="Tuak"><?= $activity['paket_tuak'] ?></td>
                                        <td data-label="Soju"><?= $activity['paket_soju'] ?></td>
                                        <td data-label="Spicy 1"><?= $activity['paket_spicy_1'] ?></td>
                                        <td data-label="Spicy 2"><?= $activity['paket_spicy_2'] ?></td>
                                        <td data-label="Spicy 3"><?= $activity['paket_spicy_3'] ?></td>
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
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Animasi untuk kartu ringkasan
            const summaryCards = document.querySelectorAll('.summary-card');
            summaryCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
                card.classList.add('fade-in');
            });

            // Animasi untuk baris tabel
            const tableRows = document.querySelectorAll('.activities-table-improved tbody tr');
            tableRows.forEach((row, index) => {
                row.style.animationDelay = `${(summaryCards.length * 0.1) + (index * 0.05)}s`; // Sedikit tunda setelah kartu
                row.classList.add('fade-in');
            });
        });
    </script>
</body>
</html>