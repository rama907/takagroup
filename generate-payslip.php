<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

$is_admin_or_manager = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

$employee_id_to_view = 0;

if (isset($_GET['employee_id'])) {
    $requested_id = (int)$_GET['employee_id'];
    
    if ($is_admin_or_manager) {
        $employee_id_to_view = $requested_id;
    } elseif ($requested_id === $user['id']) {
        $employee_id_to_view = $user['id'];
    } else {
        header('Location: my-payslip.php');
        exit;
    }
} else {
    $employee_id_to_view = $user['id'];
}

if ($employee_id_to_view <= 0) {
    die("ID anggota tidak valid atau tidak diberikan.");
}

// --- LOGIKA GAJI BARU ---
$hourly_rates = [
    'ceo' => 0,          
    'direktur' => 0,     
    'wakil_direktur' => 0, 
    'manager' => 41175,
    'barista' => 36720,
    'waiters' => 31500,
    'guard' => 31500,
    'karyawan' => 31500,
    'magang' => 27000,
    'chef' => 0, 
];

$min_duty_hours_no_salary = 5;
$min_duty_minutes_no_salary = $min_duty_hours_no_salary * 60; // 300 menit
$min_duty_hours_for_base_salary = 8;
$min_duty_minutes_for_base_salary = $min_duty_hours_for_base_salary * 60; // 480 menit
// --- AKHIR LOGIKA GAJI BARU ---

// --- LANGKAH 1: Ambil Data Dasar Karyawan dan Status Bayar ---
$stmt = $conn->prepare("
    SELECT id, name, role, is_paid
    FROM employees
    WHERE id = ? AND status = 'active'
");
if (!$stmt) {
    die("Gagal menyiapkan query base: " . $conn->error); 
}
$stmt->bind_param("i", $employee_id_to_view);
$stmt->execute();
$employee_data_raw = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employee_data_raw) {
    die("Data slip gaji tidak ditemukan untuk ID anggota ini.");
}

// --- LANGKAH 2: Ambil Total Duty Minutes (Query terpisah agar stabil) ---
$stmt_duty = $conn->prepare("
    SELECT COALESCE(SUM(duration_minutes), 0) as total_duty_minutes
    FROM duty_logs
    WHERE employee_id = ? AND status = 'completed'
");
if (!$stmt_duty) {
    die("Gagal menyiapkan query duty: " . $conn->error); 
}
$stmt_duty->bind_param("i", $employee_id_to_view);
$stmt_duty->execute();
$duty_result = $stmt_duty->get_result()->fetch_assoc();
$stmt_duty->close();


// Inisialisasi variabel dari hasil query
$employee_role = $employee_data_raw['role'];
$total_duty_minutes = (int)($duty_result['total_duty_minutes'] ?? 0);
$is_paid = (bool)($employee_data_raw['is_paid'] ?? false);


// --- PERHITUNGAN GAJI UTAMA ---
$gaji_pokok = 0;
$total_nominal_bonus = 0; 

if (isset($hourly_rates[$employee_role])) {
    $hourly_rate = $hourly_rates[$employee_role];
    
    if (in_array($employee_role, ['ceo', 'direktur', 'wakil_direktur'])) {
        $gaji_pokok = 0;
    } 
    // 1. Jika Total Duty < 5 jam (300 menit): TIDAK DAPAT GAJI
    elseif ($total_duty_minutes < $min_duty_minutes_no_salary) {
        $gaji_pokok = 0;
    }
    // 2. Jika Total Duty antara 5 jam dan < 8 jam (300 <= Duty < 480 menit)
    elseif ($total_duty_minutes < $min_duty_minutes_for_base_salary) { 
        $gaji_8_jam = $hourly_rate * $min_duty_hours_for_base_salary;
        $gaji_pokok = $gaji_8_jam * 0.50;
    }
    // 3. Jika Total Duty >= 8 jam (Duty >= 480 menit)
    else {
        $gaji_per_menit = $hourly_rate / 60;
        $gaji_pokok = $gaji_per_menit * $total_duty_minutes;
    }
} else {
    $gaji_pokok = 0;
}

// Total Gaji (Bersih)
$total_gajian = $gaji_pokok; 

// Helper function untuk format rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}

// Helper function untuk format durasi
function formatDuration($minutes) {
    if ($minutes < 0) return "0 jam 0 menit";
    $hours = floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    return "{$hours} jam {$remainingMinutes} menit";
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Slip Gaji - <?= htmlspecialchars($employee_data_raw['name']) ?></title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f4f7f6;
            color: #333;
            line-height: 1.6;
        }
        .payslip-container {
            width: 100%;
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 30px;
            box-sizing: border-box;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding-left: 120px;
            padding-right: 120px;
            box-sizing: border-box;
        }
        .header h1 {
            margin: 0;
            font-size: 2em;
            font-weight: 900;
            color: #121212;
            flex-shrink: 0;
        }
        .header p {
            margin: 5px 0 0;
            font-size: 0.9em;
            color: #666;
            flex-shrink: 0;
        }
        .header-content-center {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
        }
        .logo-header {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 120px;
            height: auto;
            object-fit: contain;
            z-index: 10;
        }
        .logo-left {
            left: 0px;
        }
        .logo-right {
            right: 0px;
        }
        .section-title {
            font-size: 1.2em;
            font-weight: bold;
            margin-top: 25px;
            margin-bottom: 15px;
            color: #3b82f6;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
            margin-bottom: 20px;
        }
        .info-item span:first-child {
            font-weight: bold;
            color: #555;
            min-width: 120px;
            display: inline-block;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        table th, table td {
            border: 1px solid #eee;
            padding: 10px;
            text-align: left;
            font-size: 14px;
        }
        table th {
            background-color: #f0f0f0;
            color: #555;
            font-weight: bold;
        }
        .total-row {
            font-weight: bold;
            background-color: #e6f0fa;
            color: #3b82f6;
        }
        .total-row td {
            font-size: 1.1em;
        }
        .grand-total-row td {
            font-size: 16px;
            font-weight: 900;
            background-color: #d1ffd1;
            border-top: 3px solid #333;
        }
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            font-size: 0.9em;
        }
        .signature-box {
            text-align: center;
            width: 30%;
        }
        .signature-box p {
            margin-top: 60px;
            border-top: 1px solid #333;
            padding-top: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            font-size: 0.8em;
            color: #888;
        }
        .note-custom {
            margin-top: 15px;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 13px;
            color: #555;
        }
        .note-red {
             color: #dc3545;
             border-color: #dc3545;
             background-color: #fcebeb;
        }
        /* Print styles */
        @media print {
            body {
                background-color: #fff;
                margin: 0;
                padding: 0;
            }
            .payslip-container {
                box-shadow: none;
                border: none;
                margin: 0;
                width: 100%;
                padding: 15px;
            }
            .btn {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="payslip-container">
        <div class="header">
            <img src="LOGO_WOT.png" alt="Logo Kiri" class="logo-header logo-left">
            <div class="header-content-center">
                <h1 class="payslip-header-title">SLIP GAJI KARYAWAN <br> ELYSIUM NIGHT CLUB</h1>
                <p>Data Akumulatif Duty</p>
            </div>
            <img src="LOGO_WOT.png" alt="Logo Kanan" class="logo-header logo-right">
        </div>

        <div class="section-title">Informasi Karyawan</div>
        <div class="info-grid">
            <div class="info-item"><span>Nama:</span> <?= htmlspecialchars($employee_data_raw['name']) ?></div>
            <div class="info-item"><span>Jabatan:</span> <?= getRoleDisplayName($employee_data_raw['role']) ?></div>
            <div class="info-item"><span>ID Karyawan:</span> <?= $employee_data_raw['id'] ?></div>
            <div class="info-item"><span>Tanggal Cetak:</span> <?= date('d/m/Y H:i') ?></div>
            <div class="info-item"><span>Total Duty:</span> <?= formatDuration($total_duty_minutes) ?></div>
            <div class="info-item"><span>Status Pembayaran:</span>
                <span style="color: <?= $is_paid ? 'green' : 'red' ?>; font-weight: bold;">
                    <?= $is_paid ? 'SUDAH DIBAYARKAN' : 'BELUM DIBAYARKAN' ?>
                </span>
            </div>
        </div>

        <div class="section-title">Ringkasan Gaji Bersih</div>
        <table>
            <thead>
                <tr>
                    <th>Komponen</th>
                    <th style="width: 30%; text-align: right;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Gaji Pokok</td>
                    <td style="text-align: right;"><?= formatRupiah($gaji_pokok) ?></td>
                </tr>
                <tr class="total-row">
                    <td>TOTAL GAJI BERSIH</td>
                    <td style="text-align: right;"><?= formatRupiah($total_gajian) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if (in_array($employee_role, ['ceo', 'direktur', 'wakil_direktur'])): ?>
            <div class="note-custom note-red">
                **Keterangan:** Jabatan Anda adalah **<?= getRoleDisplayName($employee_role) ?>**. Sesuai aturan baru, Anda **TIDAK** mendapatkan gaji operasional per jam.
            </div>
        <?php elseif ($total_gajian == 0 && $total_duty_minutes < $min_duty_minutes_no_salary): ?>
            <div class="note-custom note-red">
                **Keterangan:** Gaji Anda **Rp 0** karena total jam duty (<?= formatDuration($total_duty_minutes) ?>) di bawah minimum **<?= $min_duty_hours_no_salary ?> jam**.
            </div>
        <?php elseif ($total_gajian > 0 && $total_duty_minutes < $min_duty_minutes_for_base_salary): ?>
            <div class="note-custom note-red">
                **Keterangan:** Gaji Anda dikenakan **potongan 50%** karena total jam duty (<?= formatDuration($total_duty_minutes) ?>) di bawah minimum **<?= $min_duty_hours_for_base_salary ?> jam**.
            </div>
        <?php endif; ?>

        <div class="signature-section">
            <div class="signature-box">
                Diterima Oleh,<br>
                Karyawan Ybs.
                <p>(<?= htmlspecialchars($employee_data_raw['name']) ?>)</p>
            </div>
            <div class="signature-box">
                Dibuat Oleh,<br>
                Admin Elysium Night Club
                <p>(Admin)</p>
            </div>
        </div>

        <div class="footer">
            <p>Slip gaji ini dibuat secara otomatis dan berlaku tanpa tanda tangan basah.</p>
        </div>

        <button onclick="window.print()" class="btn btn-primary" style="display: block; margin: 20px auto;">Cetak Slip Gaji</button>
    </div>
</body>
</html>