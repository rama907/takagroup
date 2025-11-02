<?php
require_once 'config.php';

if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

// --- Definisi Logika Gaji & Konstanta (Dari salary-recap.php) ---
/**
 * Membulatkan total menit duty ke jam terdekat.
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

// --- Hitung Total Pengeluaran Gaji (Replikasi Logika salary-recap.php) ---
$total_payroll_expenditure = 0;
$employees_raw_data_payroll = $conn->query("
    SELECT e.id, e.name, e.role,
           COALESCE(duty_summary.total_duty_minutes, 0) as total_duty_minutes
    FROM employees e
    LEFT JOIN (
        SELECT
            employee_id,
            SUM(duration_minutes) as total_duty_minutes
        FROM duty_logs
        WHERE status = 'completed'
        GROUP BY employee_id
    ) as duty_summary ON e.id = duty_summary.employee_id
    WHERE e.status = 'active'
");

if ($employees_raw_data_payroll) {
    while ($employee = $employees_raw_data_payroll->fetch_assoc()) {
        $employee_role = $employee['role'];
        $total_duty_minutes = $employee['total_duty_minutes'];
        $rounded_duty_hours = roundToNearestHour($total_duty_minutes);
        
        $gaji_pokok = 0;
        $total_gajian = 0;

        if (isset($hourly_rates[$employee_role])) {
            $hourly_rate = $hourly_rates[$employee_role];
            $base_salary = $rounded_duty_hours * $hourly_rate;
            $gaji_pokok = $base_salary; 

            if (in_array($employee_role, ['chef'])) {
                $total_gajian = 0;
            } elseif (in_array($employee_role, ['ceo', 'direktur', 'wakil_direktur'])) {
                $total_gajian = $gaji_pokok;
            } else {
                if ($rounded_duty_hours >= $MIN_DUTY_FULL_PAY_HOURS) {
                    $total_gajian = $gaji_pokok;
                } elseif ($rounded_duty_hours >= $MIN_DUTY_40_CUT_HOURS) {
                    $total_gajian = $gaji_pokok * 0.60;
                } else {
                    $total_gajian = $gaji_pokok * 0.50;
                }
            }
        }
        $total_payroll_expenditure += $total_gajian;
    }
    $employees_raw_data_payroll->data_seek(0); // Reset pointer untuk menghindari error jika diulang
}


// Definisi harga per paket (UPDATED)
$price_sake = 20000;
$price_anggur_merah = 20000;
$price_tuak = 20000;
$price_soju = 20000;
$price_spicy_1 = 65000;
$price_azul_1 = 25000;
$price_azul_2 = 20000;

// Variabel Ruangan Dihapus (Diatur ke 0)
$price_vip_person = 0;
$price_special_30min = 0;

// Inisialisasi total income
$total_income_sake = 0;
$total_income_anggur_merah = 0;
$total_income_tuak = 0;
$total_income_soju = 0;
$total_income_spicy_1 = 0;
$total_income_azul_1 = 0;
$total_income_azul_2 = 0;

$overall_total_income = 0;

// Inisialisasi total paket/unit
$total_sake_packages = 0;
$total_anggur_merah_packages = 0;
$total_tuak_packages = 0;
$total_soju_packages = 0;
$total_spicy_1_packages = 0;
$total_azul_1_packages = 0;
$total_azul_2_packages = 0;


// Ambil total pemasukan dari sales_data (Paket Minum)
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(paket_sake), 0) as sum_sake,
        COALESCE(SUM(paket_anggur_merah), 0) as sum_anggur_merah,
        COALESCE(SUM(paket_tuak), 0) as sum_tuak,
        COALESCE(SUM(paket_soju), 0) as sum_soju,
        COALESCE(SUM(paket_spicy_1), 0) as sum_spicy_1,
        COALESCE(SUM(paket_spicy_2), 0) as sum_azul_1,
        COALESCE(SUM(paket_spicy_3), 0) as sum_azul_2
    FROM sales_data
");

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $total_sake_packages = $result['sum_sake'];
        $total_anggur_merah_packages = $result['sum_anggur_merah'];
        $total_tuak_packages = $result['sum_tuak'];
        $total_soju_packages = $result['sum_soju'];
        $total_spicy_1_packages = $result['sum_spicy_1'];
        $total_azul_1_packages = $result['sum_azul_1'];
        $total_azul_2_packages = $result['sum_azul_2'];

        $total_income_sake = $total_sake_packages * $price_sake;
        $total_income_anggur_merah = $total_anggur_merah_packages * $price_anggur_merah;
        $total_income_tuak = $total_tuak_packages * $price_tuak;
        $total_income_soju = $total_soju_packages * $price_soju;
        $total_income_spicy_1 = $total_spicy_1_packages * $price_spicy_1;
        $total_income_azul_1 = $total_azul_1_packages * $price_azul_1;
        $total_income_azul_2 = $total_azul_2_packages * $price_azul_2;

        // OVERALL TOTAL INCOME (Revenue from sales_data only)
        $overall_total_income = $total_income_sake + $total_income_anggur_merah + $total_income_tuak + $total_income_soju + $total_income_spicy_1 + $total_income_azul_1 + $total_income_azul_2;
    }
} else {
    die("Gagal menyiapkan query: " . $conn->error);
}

// --- Fetch Total Elysium Share (from sales_table_room) ---
$total_elysium_share = 0;
$stmt_share = $conn->query("SELECT COALESCE(SUM(elysium_share), 0) as total_share FROM sales_table_room");
if ($stmt_share) {
    $total_elysium_share = (float)$stmt_share->fetch_assoc()['total_share'];
    $stmt_share->close();
}


// --- Pemasukan Kotor (Gross Income) ---
// Gross Income = Total Revenue (Paket Minum) + Total Elysium Share (Table/Room)
$gross_income = $overall_total_income + $total_elysium_share;

// --- Pemasukan Bersih (Net Income) ---
// Net Income = Gross Income - Total Payroll Expenditure
$net_income = $gross_income - $total_payroll_expenditure;


// --- Data untuk Grafik Omset Mingguan (Senin-Minggu) ---
$daily_revenue_data = [];
$today = new DateTime();
$start_of_week = clone $today;
if ($start_of_week->format('N') != 1) { 
    $start_of_week->modify('last Monday');
}
$end_of_week = clone $start_of_week;
$end_of_week->modify('+6 days');

// Query gabungan untuk mendapatkan daily revenue dari sales_data dan daily elysium_share dari sales_table_room
$stmt_daily = $conn->prepare("
    SELECT
        sd.date,
        SUM(sd.paket_sake) as sum_sake_daily,
        SUM(sd.paket_anggur_merah) as sum_anggur_merah_daily,
        SUM(sd.paket_tuak) as sum_tuak_daily,
        SUM(sd.paket_soju) as sum_soju_daily,
        SUM(sd.paket_spicy_1) as sum_spicy_1_daily,
        SUM(sd.paket_spicy_2) as sum_azul_1_daily,
        SUM(sd.paket_spicy_3) as sum_azul_2_daily,
        COALESCE(SUM(str.elysium_share), 0) as sum_elysium_share_daily
    FROM sales_data sd
    LEFT JOIN sales_table_room str ON sd.date = DATE(str.sale_date)
    WHERE sd.date BETWEEN ? AND ?
    GROUP BY sd.date
    ORDER BY sd.date ASC
");
if ($stmt_daily) {
    $stmt_daily->bind_param("ss", $start_of_week->format('Y-m-d'), $end_of_week->format('Y-m-d'));
    $stmt_daily->execute();
    $daily_results = $stmt_daily->get_result();
    
    $chart_data_from_db = [];
    while ($row = $daily_results->fetch_assoc()) {
        $daily_sales_data_revenue = (float) ($row['sum_sake_daily'] * $price_sake) +
                                     (float) ($row['sum_anggur_merah_daily'] * $price_anggur_merah) +
                                     (float) ($row['sum_tuak_daily'] * $price_tuak) +
                                     (float) ($row['sum_soju_daily'] * $price_soju) +
                                     (float) ($row['sum_spicy_1_daily'] * $price_spicy_1) +
                                     (float) ($row['sum_azul_1_daily'] * $price_azul_1) + 
                                     (float) ($row['sum_azul_2_daily'] * $price_azul_2);
        
        // Daily Omset Grafik = Revenue (sales_data) + Elysium Share (sales_table_room)
        $chart_data_from_db[$row['date']] = $daily_sales_data_revenue + (float)$row['sum_elysium_share_daily'];
    }
    $stmt_daily->close();
} else {
    error_log("Error preparing daily sales query in income-report.php: " . $conn->error);
}


$chart_labels = [];
$chart_data_revenue = [];
for ($i = 0; $i < 7; $i++) {
    $current_date = clone $start_of_week;
    $current_date->modify("+{$i} days");
    $formatted_date_for_db = $current_date->format('Y-m-d');
    
    $chart_labels[] = $current_date->format('D, d M');
    $chart_data_revenue[] = $chart_data_from_db[$formatted_date_for_db] ?? 0;
}

$chart_labels_json = json_encode($chart_labels);
$chart_data_revenue_json = json_encode($chart_data_revenue);


// --- Data untuk Logs Omset (Detail per Input) ---
// Query UNION untuk menggabungkan data sales_data (revenue) dan sales_table_room (elysium_share)
$omset_logs = [];
$stmt_logs = $conn->query("
    (
        SELECT
            sd.id as id,
            sd.date,
            sd.input_time,
            e.name as employee_name,
            'Paket Minum' as type_description,
            (sd.paket_sake * {$price_sake}) + (sd.paket_anggur_merah * {$price_anggur_merah}) + (sd.paket_tuak * {$price_tuak}) + (sd.paket_soju * {$price_soju}) + (sd.paket_spicy_1 * {$price_spicy_1}) + (sd.paket_spicy_2 * {$price_azul_1}) + (sd.paket_spicy_3 * {$price_azul_2}) as omset_transaksi,
            sd.paket_sake, sd.paket_anggur_merah, sd.paket_tuak, sd.paket_soju, sd.paket_spicy_1, sd.paket_spicy_2 as paket_azul_1, sd.paket_spicy_3 as paket_azul_2
        FROM sales_data sd
        JOIN employees e ON sd.employee_id = e.id
    )
    UNION ALL
    (
        SELECT
            str.id as id,
            str.sale_date as date,
            str.input_time,
            e.name as employee_name,
            CONCAT('Table & Room (', str.base_package, ')') as type_description,
            str.elysium_share as omset_transaksi,
            0, 0, 0, 0, 0, 0, 0
        FROM sales_table_room str
        JOIN employees e ON str.employee_id = e.id
    )
    ORDER BY input_time DESC
");

if ($stmt_logs) {
    while ($row = $stmt_logs->fetch_assoc()) {
        $omset_logs[] = [
            'id' => $row['id'],
            'date_time' => date('d/m/Y H:i:s', strtotime($row['input_time'])),
            'employee_name' => $row['employee_name'],
            'type_description' => $row['type_description'],
            'paket_sake' => $row['paket_sake'] ?? 0,
            'paket_anggur_merah' => $row['paket_anggur_merah'] ?? 0,
            'paket_tuak' => $row['paket_tuak'] ?? 0,
            'paket_soju' => $row['paket_soju'] ?? 0,
            'paket_spicy_1' => $row['paket_spicy_1'] ?? 0,
            'paket_azul_1' => $row['paket_azul_1'] ?? 0,
            'paket_azul_2' => $row['paket_azul_2'] ?? 0,
            'omset_transaksi' => (float)$row['omset_transaksi']
        ];
    }
    $stmt_logs->close();
}


// --- Fungsionalitas Unduh Laporan Detail untuk Audit ---
if (isset($_GET['export']) && $_GET['export'] == 'detailed_income') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="laporan_pemasukan_detail_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    $headers = [
        'ID Transaksi',
        'Tanggal & Waktu Input',
        'Nama Anggota',
        'Tipe Transaksi',
        'Sake (Jumlah)',
        'Anggur Merah (Jumlah)',
        'Tuak (Jumlah)',
        'Soju (Jumlah)',
        'Spicy 1 (Jumlah)',
        'Azul 1 (Jumlah)',        
        'Azul 2 (Jumlah)',    
        'Omset Kotor Transaksi (Rp)'
    ];
    fputcsv($output, $headers);

    foreach ($omset_logs as $log) {
        $data_row = [
            $log['id'],
            $log['date_time'],
            htmlspecialchars_decode($log['employee_name']),
            $log['type_description'],
            $log['paket_sake'],
            $log['paket_anggur_merah'],
            $log['paket_tuak'],
            $log['paket_soju'],
            $log['paket_spicy_1'],
            $log['paket_azul_1'],       
            $log['paket_azul_2'],    
            $log['omset_transaksi']
        ];
        fputcsv($output, $data_row);
    }

    fclose($output);
    exit;
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pemasukan - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .income-report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: var(--spacing-xl);
            margin-bottom: var(--spacing-2xl);
        }
        .income-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            padding: var(--spacing-xl);
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            text-align: center;
        }
        .income-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .income-card .icon {
            font-size: 3.5rem;
            margin-bottom: var(--spacing-md);
        }
        .income-card h4 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .income-card .value {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--primary-color);
            letter-spacing: -0.025em;
            margin: 0;
        }
        .income-card.total .value {
            color: var(--success-color);
            font-size: 2.8rem;
        }
        .income-card.danger-total .value { /* Gaya baru untuk pengeluaran/net */
            color: var(--danger-color);
            font-size: 2.8rem;
        }
        .income-card .detail-text {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: var(--spacing-sm);
        }
        .chart-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
            margin-top: var(--spacing-2xl);
            width: 100%;
            height: 550px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .chart-container h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--spacing-xl);
            text-align: center;
        }
        .chart-canvas-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
        }
        #dailyRevenueChart {
            max-width: 100%;
            max-height: 100%;
        }

        /* Styles for Logs Omset table */
        .logs-omset-section {
            margin-top: var(--spacing-2xl);
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-sm);
            padding: var(--spacing-xl);
        }
        .logs-omset-section .card-header {
            padding: 0;
            border-bottom: none;
            margin-bottom: var(--spacing-xl);
        }
        .logs-omset-section h3 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: var(--spacing-xl);
        }
        .logs-omset-section .activities-table-improved th,
        .logs-omset-section .activities-table-improved td {
            padding: var(--spacing-sm);
            font-size: 0.85rem;
            vertical-align: middle;
        }
        .logs-omset-section .activities-table-improved .employee-name-cell {
            white-space: normal;
        }
        @media (min-width: 769px) {
            .logs-omset-section .activities-table-improved thead {
                display: table-header-group;
            }
            .logs-omset-section .activities-table-improved tr {
                display: table-row;
            }
            .logs-omset-section .activities-table-improved td {
                display: table-cell;
                padding-left: var(--spacing-sm);
                text-align: left;
                white-space: nowrap;
            }
            .logs-omset-section .activities-table-improved td:nth-child(3),
            .logs-omset-section .activities-table-improved td:nth-child(4),
            .logs-omset-section .activities-table-improved td:nth-child(5) {
                width: 10%;
                min-width: 60px;
                text-align: center;
            }
            .logs-omset-section .activities-table-improved td:nth-child(6),
            .logs-omset-section .activities-table-improved td:nth-child(7),
            .logs-omset-section .activities-table-improved td:nth-child(8),
            .logs-omset-section .activities-table-improved td:nth-child(9),
            .logs-omset-section .activities-table-improved td:nth-child(10) {
                 width: 8%;
                 min-width: 50px;
                 text-align: center;
            }
            .logs-omset-section .activities-table-improved td:last-child {
                width: 10%;
                min-width: 100px;
                text-align: right;
            }

            .logs-omset-section .activities-table-improved td:before {
                content: none;
            }
        }
        @media (max-width: 768px) {
            .logs-omset-section .activities-table-improved thead {
                display: none;
            }
            .logs-omset-section .activities-table-improved,
            .logs-omset-section .activities-table-improved tbody,
            .logs-omset-section .activities-table-improved tr,
            .logs-omset-section .activities-table-improved td {
                display: block;
            }
            .logs-omset-section .activities-table-improved tr {
                margin-bottom: var(--spacing-md);
                padding: var(--spacing-md);
            }
            .logs-omset-section .activities-table-improved td {
                padding: var(--spacing-xs) 0;
                padding-left: 45%;
                position: relative;
                text-align: left;
                white-space: normal;
            }
            .logs-omset-section .activities-table-improved td:before {
                content: attr(data-label) ": ";
                position: absolute;
                left: 6px;
                width: 40%;
                white-space: nowrap;
                font-weight: bold;
                color: var(--text-secondary);
            }
            .income-report-grid {
                 grid-template-columns: 1fr;
                 gap: var(--spacing-lg);
            }
            .income-card.total, .income-card.danger-total {
                 max-width: 100% !important;
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
                    <span class="page-icon">📈</span>
                    Laporan Pemasukan
                </h1>
                <p>Ikhtisar total pemasukan dari penjualan paket makan minum dan *share* Table/Room.</p>
                <div class="page-actions" style="margin-top: var(--spacing-md);">
                    <a href="income-report.php?export=detailed_income" class="btn btn-info" target="_blank">
                        <span class="btn-icon">⬇️</span>
                        Unduh Laporan Detail
                    </a>
                </div>
            </div>

            <div class="income-report-grid">
                <div class="income-card">
                    <div class="icon" style="color: #60a5fa;">🍷</div>
                    <h4>Total Revenue Paket Minum</h4>
                    <p class="value" style="color: var(--info-color);"><?= formatRupiah($overall_total_income) ?></p>
                    <p class="detail-text">Hanya dari data **sales_data**</p>
                </div>
                
                <div class="income-card">
                    <div class="icon" style="color: var(--primary-color);">💎</div>
                    <h4>Total Share Elysium (T&R)</h4>
                    <p class="value" style="color: var(--primary-color);"><?= formatRupiah($total_elysium_share) ?></p>
                    <p class="detail-text">Dari Table & Room Sales (Profit Share 40%)</p>
                </div>
                
                <div class="income-card total" style="border-left: 4px solid var(--primary-color);">
                    <div class="icon" style="color: var(--primary-color);">💲</div>
                    <h4>PEMASUKAN KOTOR (GROSS)</h4>
                    <p class="value" style="color: var(--primary-color);"><?= formatRupiah($gross_income) ?></p>
                    <p class="detail-text">Revenue Paket Minum + Share T&R</p>
                </div>
                
                <div class="income-card danger-total" style="border-left: 4px solid var(--danger-color);">
                    <div class="icon" style="color: var(--danger-color);">💸</div>
                    <h4>PENGELUARAN GAJI</h4>
                    <p class="value" style="color: var(--danger-color);">- <?= formatRupiah($total_payroll_expenditure) ?></p>
                    <p class="detail-text">Total gaji karyawan *completed duty*</p>
                </div>

                <div class="income-card total" style="grid-column: 1 / -1; max-width: 50%; margin: 0 auto;">
                    <div class="icon" style="color: var(--success-color);">✅</div>
                    <h4>PEMASUKAN BERSIH (NET)</h4>
                    <p class="value" style="color: var(--success-color);"><?= formatRupiah($net_income) ?></p>
                    <p class="detail-text">Pemasukan Kotor - Pengeluaran Gaji</p>
                </div>
                
                <div class="income-card">
                    <div class="icon" style="color: #60a5fa;">🍶</div>
                    <h4>Pemasukan Sake</h4>
                    <p class="value"><?= formatRupiah($total_income_sake) ?></p>
                    <p class="detail-text"><?= $total_sake_packages ?> paket @ <?= formatRupiah($price_sake) ?></p>
                </div>

                <div class="income-card">
                    <div class="icon" style="color: #fbbf24;">🍷</div>
                    <h4>Pemasukan Anggur Merah</h4>
                    <p class="value"><?= formatRupiah($total_income_anggur_merah) ?></p>
                    <p class="detail-text"><?= $total_anggur_merah_packages ?> paket @ <?= formatRupiah($price_anggur_merah) ?></p>
                </div>

                <div class="income-card">
                    <div class="icon" style="color: #10b981;">🍶</div>
                    <h4>Pemasukan Tuak</h4>
                    <p class="value"><?= formatRupiah($total_income_tuak) ?></p>
                    <p class="detail-text"><?= $total_tuak_packages ?> paket @ <?= formatRupiah($price_tuak) ?></p>
                </div>

                <div class="income-card">
                    <div class="icon" style="color: #10b981;">🍶</div>
                    <h4>Pemasukan Soju</h4>
                    <p class="value"><?= formatRupiah($total_income_soju) ?></p>
                    <p class="detail-text"><?= $total_soju_packages ?> paket @ <?= formatRupiah($price_soju) ?></p>
                </div>

                <div class="income-card">
                    <div class="icon" style="color: #dc2626;">🌶️</div>
                    <h4>Pemasukan Spicy 1</h4>
                    <p class="value"><?= formatRupiah($total_income_spicy_1) ?></p>
                    <p class="detail-text"><?= $total_spicy_1_packages ?> paket @ <?= formatRupiah($price_spicy_1) ?></p>
                </div>

                <div class="income-card" style="border-left: 4px solid #0796ff;">
                    <div class="icon" style="color: #0796ff;">🔵</div>
                    <h4>Pemasukan Azul 1</h4>
                    <p class="value"><?= formatRupiah($total_income_azul_1) ?></p>
                    <p class="detail-text"><?= $total_azul_1_packages ?> paket @ <?= formatRupiah($price_azul_1) ?></p>
                </div>

                <div class="income-card" style="border-left: 4px solid #0796ff;">
                    <div class="icon" style="color: #0796ff;">🔵</div>
                    <h4>Pemasukan Azul 2</h4>
                    <p class="value"><?= formatRupiah($total_income_azul_2) ?></p>
                    <p class="detail-text"><?= $total_azul_2_packages ?> paket @ <?= formatRupiah($price_azul_2) ?></p>
                </div>
            </div>

            <div class="chart-container">
                <h3>Grafik Omset Kotor Mingguan</h3>
                <div class="chart-canvas-wrapper">
                    <canvas id="dailyRevenueChart"></canvas>
                </div>
            </div>

            <div class="logs-omset-section">
                <div class="card-header">
                    <h3>Logs Omset Kotor (Detail per Input)</h3>
                </div>
                <div class="card-content" style="padding-top: 0;">
                    <?php if (empty($omset_logs)): ?>
                        <div class="no-data">Belum ada data penjualan untuk ditampilkan di log.</div>
                    <?php else: ?>
                        <div class="responsive-table-container">
                            <table class="activities-table-improved">
                                <thead>
                                    <tr>
                                        <th>Tanggal & Waktu</th>
                                        <th>Nama Anggota</th>
                                        <th>Tipe Transaksi</th>
                                        <th>Sake</th>
                                        <th>Anggur Merah</th>
                                        <th>Tuak</th>
                                        <th>Soju</th>
                                        <th>Spicy 1</th>
                                        <th>Azul 1</th>
                                        <th>Azul 2</th>
                                        <th>Omset Kotor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($omset_logs as $log): ?>
                                    <tr>
                                        <td data-label="Tanggal & Waktu"><?= $log['date_time'] ?></td>
                                        <td data-label="Nama Anggota" class="employee-name-cell"><?= htmlspecialchars($log['employee_name']) ?></td>
                                        <td data-label="Tipe Transaksi"><?= htmlspecialchars($log['type_description']) ?></td>
                                        <td data-label="Sake"><?= $log['paket_sake'] ?></td>
                                        <td data-label="Anggur Merah"><?= $log['paket_anggur_merah'] ?></td>
                                        <td data-label="Tuak"><?= $log['paket_tuak'] ?></td>
                                        <td data-label="Soju"><?= $log['paket_soju'] ?></td>
                                        <td data-label="Spicy 1"><?= $log['paket_spicy_1'] ?></td>
                                        <td data-label="Azul 1"><?= $log['paket_azul_1'] ?></td>
                                        <td data-label="Azul 2"><?= $log['paket_azul_2'] ?></td>
                                        <td data-label="Omset Transaksi"><?= formatRupiah($log['omset_transaksi']) ?></td>
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

    <script src="script.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const chartLabels = <?= $chart_labels_json ?>;
            const chartDataRevenue = <?= $chart_data_revenue_json ?>;

            const ctx = document.getElementById('dailyRevenueChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'Omset Kotor Harian (Rp)',
                        data: chartDataRevenue,
                        backgroundColor: 'rgba(59, 130, 246, 0.6)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 1,
                        borderRadius: 5,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Omset Kotor (Rp)'
                            },
                            ticks: {
                                callback: function(value, index, ticks) {
                                    return 'Rp ' + value.toLocaleString('id-ID');
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Tanggal'
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>