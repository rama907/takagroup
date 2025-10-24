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

// --- NEW SALARY LOGIC AND CONSTANTS ---

/**
 * Membulatkan total menit duty ke jam terdekat.
 * 2 jam 29 menit -> 2 jam.
 * 2 jam 30 menit -> 3 jam.
 */
function roundToNearestHour($minutes) {
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
    'chef' => 0, 
];

// Konstanta perhitungan (dalam jam)
$MIN_DUTY_FULL_PAY_HOURS = 10;
$MIN_DUTY_40_CUT_HOURS = 8;
$MIN_DUTY_50_CUT_HOURS = 0; // Sebenarnya sudah dicakup oleh MIN_DUTY_40_CUT_HOURS
// --- END NEW SALARY LOGIC AND CONSTANTS ---


// Ambil data anggota spesifik (yang sedang login) menggunakan subquery untuk agregasi
$stmt = $conn->prepare("
    SELECT e.id, e.name, e.role, e.is_paid,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes,
           COALESCE(sales_summary.paket_sake, 0) as paket_sake,
           COALESCE(sales_summary.paket_anggur_merah, 0) as paket_anggur_merah,
           COALESCE(sales_summary.paket_tuak, 0) as paket_tuak,
           COALESCE(sales_summary.paket_soju, 0) as paket_soju,
           COALESCE(sales_summary.paket_spicy_1, 0) as paket_spicy_1,
           COALESCE(sales_summary.paket_spicy_2, 0) as paket_spicy_2,
           COALESCE(sales_summary.paket_spicy_3, 0) as paket_spicy_3,
           COALESCE(sales_summary.paket_vip_person, 0) as paket_vip_person, /* NEW */
           COALESCE(sales_summary.paket_special_30min, 0) as paket_special_30min /* NEW */
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
            SUM(paket_spicy_3) as paket_spicy_3,
            SUM(paket_vip_person) as paket_vip_person,
            SUM(paket_special_30min) as paket_special_30min
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
        'is_paid' => false,
        'total_duty_minutes' => 0,
        'paket_sake' => 0,
        'paket_anggur_merah' => 0,
        'paket_tuak' => 0,
        'paket_soju' => 0,
        'paket_spicy_1' => 0,
        'paket_spicy_2' => 0,
        'paket_spicy_3' => 0,
        'paket_vip_person' => 0,
        'paket_special_30min' => 0,
    ];
}

$employee_role_summary = $employee_data_summary['role'];
$is_paid_status = (bool)($employee_data_summary['is_paid'] ?? false); 
$total_duty_minutes_summary = $employee_data_summary['total_duty_minutes'];
$total_penjualan_paket_summary = ($employee_data_summary['paket_sake'] ?? 0) + 
                                 ($employee_data_summary['paket_anggur_merah'] ?? 0) + 
                                 ($employee_data_summary['paket_tuak'] ?? 0) + 
                                 ($employee_data_summary['paket_soju'] ?? 0) + 
                                 ($employee_data_summary['paket_spicy_1'] ?? 0) + 
                                 ($employee_data_summary['paket_spicy_2'] ?? 0) + 
                                 ($employee_data_summary['paket_spicy_3'] ?? 0) +
                                 ($employee_data_summary['paket_vip_person'] ?? 0) +
                                 ($employee_data_summary['paket_special_30min'] ?? 0);

$gaji_pokok_base_summary = 0; 
$total_gajian_summary = 0; 
$is_cut = false;
$cut_percentage_display = 0;


// --- LOGIKA PERHITUNGAN GAJI BARU (Diulangi di sini untuk Ringkasan) ---

// 1. Hitung Jam Kerja yang Dibulatkan
$rounded_duty_hours = roundToNearestHour($total_duty_minutes_summary);

if (isset($hourly_rates[$employee_role_summary])) {
    $hourly_rate = $hourly_rates[$employee_role_summary];

    // Gaji Pokok (Base Pay) berdasarkan jam yang dibulatkan
    $gaji_pokok_base_summary = $rounded_duty_hours * $hourly_rate;

    // 1. Peran Khusus (Chef)
    if (in_array($employee_role_summary, ['chef'])) {
        $gaji_pokok_summary = 0;
        $total_gajian_summary = 0;
    }
    // 2. Peran Senior (CEO, Direktur, Wakil Direktur)
    elseif (in_array($employee_role_summary, ['ceo', 'direktur', 'wakil_direktur'])) {
        $total_gajian_summary = $gaji_pokok_base_summary;
    }
    // 3. Perhitungan Gaji Operasional dengan Potongan
    else {
        if ($rounded_duty_hours >= $MIN_DUTY_FULL_PAY_HOURS) {
            // Full Pay (>= 10 jam)
            $total_gajian_summary = $gaji_pokok_base_summary;
        } elseif ($rounded_duty_hours >= $MIN_DUTY_40_CUT_HOURS) {
            // Potongan 40% (8 jam <= Duty < 10 jam)
            $cut_percentage_display = 40;
            $total_gajian_summary = $gaji_pokok_base_summary * 0.60; // Pay 60%
            $is_cut = true;
        } else {
            // Potongan 50% (< 8 jam)
            $cut_percentage_display = 50;
            $total_gajian_summary = $gaji_pokok_base_summary * 0.50; // Pay 50%
            $is_cut = true;
        }
    }
}
// --- AKHIR LOGIKA PERHITUNGAN GAJI BARU ---

$gaji_pokok_summary = $gaji_pokok_base_summary;

// Fungsi format mata uang
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}

// Helper function untuk format durasi (untuk display)
function formatDurationDisplay($minutes) {
    if ($minutes < 0) return "0 jam 0 menit";
    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    return "{$hours}j {$remainingMinutes}m";
}
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        .summary-item.payment-status .value { 
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 0.05em;
        }
        
        /* New warning colors for my-payslip */
        .note-red {
             color: var(--danger-color);
             border-color: var(--danger-color);
             background-color: var(--danger-light);
        }
        .note-orange {
             color: #fd7e14;
             border-color: #fd7e14;
             background-color: #fff4e6;
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

            <?php if (in_array($employee_role_summary, ['chef'])): ?>
                <div class="error-message">
                    <strong>Penting:</strong> Jabatan Anda (**<?= getRoleDisplayName($employee_role_summary) ?>**) tidak menerima gaji operasional per jam. Total gaji ditampilkan sebagai nol.
                </div>
            <?php elseif ($rounded_duty_hours < $MIN_DUTY_40_CUT_HOURS): ?>
                <div class="error-message">
                    <strong>Penting:</strong> Jam kerja yang dibulatkan (**<?= $rounded_duty_hours ?> jam**) di bawah minimum <?= $MIN_DUTY_40_CUT_HOURS ?> jam. Gaji Anda dikenakan **potongan 50%**.
                </div>
            <?php elseif ($rounded_duty_hours < $MIN_DUTY_FULL_PAY_HOURS): ?>
                <div class="warning-message">
                    <strong>Peringatan:</strong> Jam kerja yang dibulatkan (**<?= $rounded_duty_hours ?> jam**) di bawah <?= $MIN_DUTY_FULL_PAY_HOURS ?> jam, sehingga gaji dikenakan **potongan 40%**.
                </div>
            <?php endif; ?>

            <div class="summary-section">
                <div class="summary-item">
                    <div class="label">Total Jam Duty (Asli)</div>
                    <div class="value"><?= formatDurationDisplay($total_duty_minutes_summary) ?></div>
                </div>
                <div class="summary-item" style="border: 2px solid var(--primary-color);">
                    <div class="label">Total Jam Duty (Bulat)</div>
                    <div class="value" style="color: var(--primary-color);">
                        <?= $rounded_duty_hours ?> jam
                    </div>
                </div>
                <div class="summary-item">
                    <div class="label">Total Penjualan</div>
                    <div class="value">
                        <?= $total_penjualan_paket_summary ?> Paket
                    </div>
                </div>
                
                <div class="summary-item payment-status">
                    <div class="label">Status Pembayaran</div>
                    <div class="value" style="color: <?= $is_paid_status ? 'var(--success-color)' : 'var(--warning-color)' ?>;">
                        <?= $is_paid_status ? 'SUDAH DIBAYARKAN' : 'BELUM DIBAYARKAN' ?>
                    </div>
                </div>
                
                <div class="summary-item total-gaji" style="grid-column: 1 / -1; max-width: 400px; margin: 10px auto;">
                    <div class="label">Total Gaji Bersih</div>
                    <div class="value"><?= formatRupiah($total_gajian_summary) ?></div>
                </div>
            </div>

            <div class="payslip-action-card">
                <span class="icon">⬇️</span>
                <h2>Siap Mengunduh Slip Gaji Anda?</h2>
                <p>Klik tombol di bawah ini untuk melihat detail lengkap dan mencetak slip gaji Anda. Pastikan Anda sudah melihat perhitungan potongan di atas.</p>
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