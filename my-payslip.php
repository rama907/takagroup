<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount(); // Untuk sidebar

// URL untuk generate payslip karyawan yang sedang login
$payslip_url = 'generate-payslip.php?employee_id=' . $user['id'];

// --- Duplikasi logika perhitungan gaji dari generate-payslip.php untuk tampilan ringkasan ---

// Definisi gaji pokok per jabatan
$base_salaries = [
    'direktur' => 1415000,
    'wakil_direktur' => 1215000,
    'manager' => 915000,
    'chef' => 815000,
    'karyawan' => 685000,
    'magang' => 615000,
];

// Definisi bonus lembur per jam per jabatan (untuk jam di atas 21 jam)
$overtime_hourly_bonus = [
    'direktur' => 35000,
    'wakil_direktur' => 35000,
    'manager' => 30000,
    'chef' => 25000,
    'karyawan' => 20000,
    'magang' => 15000,
];

$min_duty_hours_for_base_salary = 8; // Perubahan: Minimal jam kerja untuk mendapatkan gaji pokok
$min_duty_minutes_for_base_salary = $min_duty_hours_for_base_salary * 60;

$min_duty_hours_for_bonus = 21;
$min_duty_minutes_for_bonus = $min_duty_hours_for_bonus * 60;

$overtime_cap_hours = 15;
$overtime_cap_minutes = $overtime_cap_hours * 60;

$sales_bonus_threshold = 400;
$sales_bonus_amount = 800000;

$duty_21_hour_bonus = 1000000;

$performance_cut_off_threshold = 400;

// Ambil data anggota spesifik (yang sedang login) menggunakan subquery untuk agregasi
$stmt = $conn->prepare("
    SELECT e.id, e.name, e.role,
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
    WHERE e.id = ? AND e.status = 'active'
    GROUP BY e.id
");
if (!$stmt) {
    error_log("Error preparing payslip summary statement: " . $conn->error);
}
$stmt->bind_param("i", $user['id']);
$stmt->execute();
$employee_data_summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employee_data_summary) {
    $employee_data_summary = [
        'id' => $user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
        'total_duty_minutes' => 0,
        'paket_sake' => 0,
        'paket_anggur_merah' => 0,
        'paket_tuak' => 0,
        'paket_soju' => 0,
        'paket_spicy_1' => 0,
        'paket_spicy_2' => 0,
        'paket_spicy_3' => 0,
    ];
}

$employee_role_summary = $employee_data_summary['role'];
$total_duty_minutes_summary = $employee_data_summary['total_duty_minutes'];
$paket_sake_summary = $employee_data_summary['paket_sake'];
$paket_anggur_merah_summary = $employee_data_summary['paket_anggur_merah'];
$paket_tuak_summary = $employee_data_summary['paket_tuak'];
$paket_soju_summary = $employee_data_summary['paket_soju'];
$paket_spicy_1_summary = $employee_data_summary['paket_spicy_1'];
$paket_spicy_2_summary = $employee_data_summary['paket_spicy_2'];
$paket_spicy_3_summary = $employee_data_summary['paket_spicy_3'];

$overtime_minutes_summary = 0;
$overtime_hours_display_summary = 0;
$overtime_remaining_minutes_summary = 0;
$nominal_bonus_lembur_perjam_summary = 0;
$total_bonus_lembur_summary = 0;

// Perhitungan Gaji Pokok
$gaji_pokok_summary = 0;
if ($total_duty_minutes_summary >= $min_duty_minutes_for_base_salary && isset($base_salaries[$employee_role_summary])) {
    $gaji_pokok_summary = $base_salaries[$employee_role_summary];
}

// Perhitungan Bonus Jam Duty 21 Jam
$bonus_21_jam_summary = 0;
if ($total_duty_minutes_summary >= $min_duty_minutes_for_bonus) {
    $bonus_21_jam_summary = $duty_21_hour_bonus;
}

// Perhitungan Jam Lembur dan Bonus Lembur
if ($total_duty_minutes_summary > $min_duty_minutes_for_bonus) {
    $overtime_minutes_raw_summary = $total_duty_minutes_summary - $min_duty_minutes_for_bonus;
    $overtime_minutes_summary = min($overtime_minutes_raw_summary, $overtime_cap_minutes);
    
    $overtime_hours_display_summary = floor($overtime_minutes_summary / 60);
    $overtime_remaining_minutes_summary = $overtime_minutes_summary % 60;

    if (isset($overtime_hourly_bonus[$employee_role_summary])) {
        $nominal_bonus_lembur_perjam_summary = $overtime_hourly_bonus[$employee_role_summary];
        $total_bonus_lembur_summary = ($overtime_minutes_summary / 60) * $nominal_bonus_lembur_perjam_summary;
    }
}

// Perhitungan Bonus Penjualan
$total_penjualan_paket_summary = $paket_sake_summary + $paket_anggur_merah_summary + $paket_tuak_summary + $paket_soju_summary + $paket_spicy_1_summary + $paket_spicy_2_summary + $paket_spicy_3_summary;
$bonus_penjualan_summary = 0;
// Note: Logic for sales bonus based on sales threshold and employee role
if (in_array($employee_role_summary, ['karyawan', 'magang'])) {
    if ($total_penjualan_paket_summary >= $sales_bonus_threshold) {
        $bonus_penjualan_summary = $sales_bonus_amount;
    }
}

$is_bonus_cut_summary = false;
$performance_indicator_text = '';

if (in_array($employee_role_summary, ['karyawan', 'magang'])) {
    $performance_indicator_text = $total_penjualan_paket_summary . ' Paket';
    if ($total_penjualan_paket_summary < $performance_cut_off_threshold) {
        $bonus_21_jam_summary *= 0.5;
        $total_bonus_lembur_summary *= 0.5;
        $is_bonus_cut_summary = true;
    }
} elseif ($employee_role_summary === 'chef') {
    // Note: You removed masak products, so this part of the logic might need to be adjusted based on new products.
    // For now, I'll keep it simple by setting performance to 0.
    $total_masak_packages_summary = 0;
    $performance_indicator_text = '0 Masak';
    if ($total_masak_packages_summary < $performance_cut_off_threshold) {
        $bonus_21_jam_summary *= 0.5;
        $total_bonus_lembur_summary *= 0.5;
        $is_bonus_cut_summary = true;
    }
}

// Perhitungan Total Gaji
$total_gajian_summary = $gaji_pokok_summary + $bonus_21_jam_summary + $total_bonus_lembur_summary + $bonus_penjualan_summary;

// Hitung Total Nominal Bonus
$total_nominal_bonus_summary = $bonus_21_jam_summary + $total_bonus_lembur_summary + $bonus_penjualan_summary;

// Fungsi format mata uang
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}

// --- Akhir duplikasi logika perhitungan gaji ---
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slip Gaji Saya - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .payslip-action-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-2xl);
            text-align: center;
            margin-top: var(--spacing-xl);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--spacing-lg);
        }
        .payslip-action-card .icon {
            font-size: 4rem;
            color: var(--primary-color);
        }
        .payslip-action-card h2 {
            font-size: 1.8rem;
            color: var(--text-primary);
            margin-bottom: var(--spacing-md);
        }
        .payslip-action-card p {
            color: var(--text-secondary);
            margin-bottom: var(--spacing-lg);
            max-width: 500px;
        }

        /* Styles for the summary section */
        .summary-section {
            margin-top: var(--spacing-2xl);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }

        .summary-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }
        .summary-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .summary-item .label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .summary-item .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            letter-spacing: -0.025em;
            margin: 0;
        }
        .summary-item.total-gaji .value {
            color: var(--success-color);
            font-size: 1.8rem;
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
                    <span class="page-icon">📄</span>
                    Slip Gaji Saya
                </h1>
                <p>Lihat dan unduh slip gaji pribadi Anda.</p>
            </div>
            
            <?php if ($total_duty_minutes_summary < $min_duty_minutes_for_base_salary): ?>
                <div class="error-message">
                    <strong>Penting:</strong> Total jam kerja Anda (<?= formatDuration($total_duty_minutes_summary) ?>) belum mencapai minimal 8 jam untuk mendapatkan gaji pokok.
                </div>
            <?php endif; ?>

            <div class="summary-section">
                <div class="summary-item">
                    <div class="label">Total Jam Duty</div>
                    <div class="value"><?= formatDuration($total_duty_minutes_summary) ?></div>
                </div>
                <div class="summary-item">
                    <div class="label">Total Penjualan</div>
                    <div class="value">
                        <?= $total_penjualan_paket_summary ?> Paket
                    </div>
                </div>
                <div class="summary-item">
                    <div class="label">Jam Lembur</div>
                    <div class="value"><?= $overtime_hours_display_summary ?>j <?= $overtime_remaining_minutes_summary ?>m</div>
                </div>
                <div class="summary-item">
                    <div class="label">Nominal Total Bonus</div>
                    <div class="value"><?= formatRupiah($total_nominal_bonus_summary) ?></div>
                </div>
                <div class="summary-item total-gaji">
                    <div class="label">Total Gaji Keseluruhan</div>
                    <div class="value"><?= formatRupiah($total_gajian_summary) ?></div>
                </div>
            </div>

            <div class="payslip-action-card">
                <span class="icon">⬇️</span>
                <h2>Siap Mengunduh Slip Gaji Anda?</h2>
                <p>Klik tombol di bawah ini untuk melihat detail lengkap dan mencetak slip gaji Anda.</p>
                <a href="<?= $payslip_url ?>" target="_blank" class="btn btn-primary btn-lg">
                    <span class="btn-icon">👁️</span>
                    Lihat & Unduh Slip Gaji
                </a>
                <p style="font-size: 0.85em; color: var(--text-muted); margin-top: var(--spacing-md);">
                    Slip gaji Anda mencakup data akumulatif hingga saat ini.
                </p>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>