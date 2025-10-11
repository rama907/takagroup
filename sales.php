<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// Tentukan apakah pengguna memiliki peran admin yang diizinkan untuk menginput data orang lain
$is_admin_or_manager = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);
$is_director_level = hasRole(['ceo', 'direktur', 'wakil_direktur']);

// Inisialisasi ID karyawan yang akan diinput datanya. Defaultnya adalah user yang login.
$employee_id_to_submit = $user['id'];
$selected_employee_name = $user['name'];
$selected_employee_role = $user['role'];

// Jika pengguna memiliki peran admin, ambil daftar semua karyawan untuk dropdown
$all_employees = [];
if ($is_admin_or_manager) {
    $all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    // Jika ada ID anggota yang dipilih dari form, gunakan ID tersebut
    if (isset($_GET['employee_id']) && !empty($_GET['employee_id'])) {
        $employee_id_to_submit = (int)$_GET['employee_id'];
        foreach ($all_employees as $emp) {
            if ($emp['id'] === $employee_id_to_submit) {
                $selected_employee_name = htmlspecialchars($emp['name']);
                $selected_employee_role = $emp['role'];
                break;
            }
        }
    }
}

// Ambil jumlah permohonan pending untuk indikator sidebar
$pending_requests_count = getPendingRequestCount();

$success_message = null;
$error_message = null;

// --- Handle Delete Sales Entry ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'delete_sales_entry')) {
    $sales_entry_id = (int)($_POST['sales_entry_id'] ?? 0);

    if ($sales_entry_id <= 0) {
        $error_message = "ID entri penjualan tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil detail entri sebelum dihapus untuk notifikasi
            $stmt_get_entry = $conn->prepare("
                SELECT *, date, input_time, employee_id
                FROM sales_data
                WHERE id = ?
            ");
            if (!$stmt_get_entry) {
                throw new Exception("Gagal menyiapkan query ambil detail entri penjualan: " . $conn->error);
            }
            $stmt_get_entry->bind_param("i", $sales_entry_id);
            $stmt_get_entry->execute();
            $entry_details = $stmt_get_entry->get_result()->fetch_assoc();
            $stmt_get_entry->close();

            if (!$entry_details) {
                throw new Exception("Entri penjualan tidak ditemukan.");
            }
            
            // Hapus entri penjualan
            $stmt_delete = $conn->prepare("DELETE FROM sales_data WHERE id = ?");
            if (!$stmt_delete) {
                throw new Exception("Gagal menyiapkan query hapus entri penjualan: " . $conn->error);
            }
            $stmt_delete->bind_param("i", $sales_entry_id);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success_message = "Entri penjualan tanggal " . date('d/m/Y H:i', strtotime($entry_details['input_time'])) . " berhasil dihapus.";
                
                sendDiscordNotification([
                    'employee_name' => getEmployeeNameById($entry_details['employee_id']),
                    'sales_date_time' => date('d/m/Y H:i', strtotime($entry_details['input_time'])),
                    'paket_sake' => $entry_details['paket_sake'] ?? 0,
                    'paket_anggur_merah' => $entry_details['paket_anggur_merah'] ?? 0,
                    'paket_tuak' => $entry_details['paket_tuak'] ?? 0,
                    'paket_soju' => $entry_details['paket_soju'] ?? 0,
                    'paket_spicy_1' => $entry_details['paket_spicy_1'] ?? 0,
                    'paket_spicy_2' => $entry_details['paket_spicy_2'] ?? 0,
                    'paket_spicy_3' => $entry_details['paket_spicy_3'] ?? 0,

                ], 'sale_deleted');

            } else {
                throw new Exception("Gagal menghapus entri penjualan. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Terjadi kesalahan: " . $e->getMessage();
        }
        header("Location: sales.php?msg=" . urlencode($success_message ?? $error_message) . "&type=" . urlencode(isset($success_message) ? 'success' : 'error') . "&employee_id=" . $employee_id_to_submit);
        exit;
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success_message = $feedback_message;
    } else {
        $error_message = $feedback_message;
    }
}

// --- START: MODIFIKASI PHP UNTUK PENJUALAN RUANGAN ---
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (isset($_POST['action']) && $_POST['action'] === 'update_sales')) {
    $employee_id_from_form = (int)($_POST['employee_id'] ?? $user['id']);
    $date_input = $_POST['date'] ?? '';

    // Penjualan Paketan
    $paket_sake = (int)($_POST['paket_sake'] ?? 0);
    $paket_anggur_merah = (int)($_POST['paket_anggur_merah'] ?? 0);
    $paket_tuak = (int)($_POST['paket_tuak'] ?? 0);
    $paket_soju = (int)($_POST['paket_soju'] ?? 0);
    
    $paket_spicy_1 = $is_director_level ? (int)($_POST['paket_spicy_1'] ?? 0) : 0;
    $paket_spicy_2 = $is_director_level ? (int)($_POST['paket_spicy_2'] ?? 0) : 0;
    $paket_spicy_3 = $is_director_level ? (int)($_POST['paket_spicy_3'] ?? 0) : 0;
    
    // Penjualan Ruangan (NEW VARIABLES)
    $paket_vip_person = (int)($_POST['paket_vip_person'] ?? 0); // Jumlah orang di Ruangan VIP
    $paket_special_30min = (int)($_POST['paket_special_30min'] ?? 0); // Jumlah 30 menit sesi Ruangan Spesial
    
    $error_message = null; 

    if (empty($date_input) && !$error_message) { 
        $error_message = "Tanggal harus diisi!";
    }

    $date_obj = null;
    $formatted_date = null;

    if (!isset($error_message)) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        $errors = DateTime::getLastErrors();
        
        if (!$date_obj || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
            DateTime::getLastErrors(); 
            $date_obj = DateTime::createFromFormat('d/m/Y', $date_input);
            $errors = DateTime::getLastErrors();
        }

        if (!$date_obj || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
            $error_message = "Format tanggal tidak valid! Harap gunakan format YYYY-MM-DD (misal: 2025-07-24) atau DD/MM/YYYY (misal: 24/07/2025) yang lengkap dan akurat.";
        }
        
        if (!$error_message) {
            $formatted_date = $date_obj->format('Y-m-d');

            $today_limit = new DateTime();
            $today_limit->setTime(23, 59, 59);

            if ($date_obj > $today_limit) {
                $error_message = "Tanggal tidak boleh di masa depan!";
            }
            
            $thirty_days_ago = new DateTime();
            $thirty_days_ago->sub(new DateInterval('P30D'));
            $thirty_days_ago->setTime(0, 0, 0);
            
            if ($date_obj < $thirty_days_ago) {
                $error_message = "Tanggal tidak boleh lebih dari 30 hari yang lalu!";
            }
        }
    }

    if (isset($error_message)) {
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    }

    $week_number = (int)$date_obj->format('W');
    $year = (int)$date_obj->format('Y');
    $input_time = date('Y-m-d H:i:s'); 
    
    try {
        // PERHATIAN: Asumsi kolom 'paket_vip_person' dan 'paket_special_30min' sudah ada di tabel sales_data
        $stmt = $conn->prepare("
            INSERT INTO sales_data (
                employee_id, date, input_time, week_number, year, 
                paket_sake, paket_anggur_merah, paket_tuak, paket_soju,
                paket_spicy_1, paket_spicy_2, paket_spicy_3,
                paket_vip_person, paket_special_30min
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("isssiiiiiiiiii", 
            $employee_id_from_form, 
            $formatted_date, 
            $input_time,
            $week_number,
            $year,
            $paket_sake,
            $paket_anggur_merah,
            $paket_tuak,
            $paket_soju,
            $paket_spicy_1,
            $paket_spicy_2,
            $paket_spicy_3,
            $paket_vip_person,         // NEW
            $paket_special_30min       // NEW
        );
        
        $result = $stmt->execute();
        
        if (!$result) {
            $error_message = "Gagal menyimpan data: " . $stmt->error;
            header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
            exit;
        } else {
            $success_message = "Data penjualan berhasil disimpan untuk tanggal " . date('d/m/Y', strtotime($formatted_date)) . " pada jam " . date('H:i', strtotime($input_time)) . "!";
            sendDiscordNotification([
                'employee_name' => getEmployeeNameById($employee_id_from_form),
                'date' => $formatted_date,
                'input_time' => $input_time,
                'paket_sake' => $paket_sake,
                'paket_anggur_merah' => $paket_anggur_merah,
                'paket_tuak' => $paket_tuak,
                'paket_soju' => $paket_soju,
                'paket_spicy_1' => $paket_spicy_1,
                'paket_spicy_2' => $paket_spicy_2,
                'paket_spicy_3' => $paket_spicy_3,
                'paket_vip_person' => $paket_vip_person,
                'paket_special_30min' => $paket_special_30min
            ], 'sale_input');
            
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($success_message) . "&type=success" . "&employee_id=" . $employee_id_to_submit);
            exit;
        }
    } catch (Exception $e) {
        $error_message = "Error database: " . $e->getMessage();
        header("Location: sales.php?msg=" . urlencode($error_message) . "&type=error" . "&employee_id=" . $employee_id_to_submit);
        exit;
    } finally {
        if (isset($stmt)) {
            $stmt->close(); 
        }
    }
}

// Query untuk Ringkasan Input Penjualan (menyeluruh)
$overall_sales_summary = [
    'paket_sake' => 0,
    'paket_anggur_merah' => 0,
    'paket_tuak' => 0,
    'paket_soju' => 0,
    'paket_spicy_1' => 0,
    'paket_spicy_2' => 0,
    'paket_spicy_3' => 0,
    'paket_vip_person' => 0,        // NEW
    'paket_special_30min' => 0,     // NEW
];
$stmt = $conn->prepare("
    SELECT 
        SUM(paket_sake) as paket_sake,
        SUM(paket_anggur_merah) as paket_anggur_merah,
        SUM(paket_tuak) as paket_tuak,
        SUM(paket_soju) as paket_soju,
        SUM(paket_spicy_1) as paket_spicy_1,
        SUM(paket_spicy_2) as paket_spicy_2,
        SUM(paket_spicy_3) as paket_spicy_3,
        COALESCE(SUM(paket_vip_person), 0) as paket_vip_person,
        COALESCE(SUM(paket_special_30min), 0) as paket_special_30min
    FROM sales_data 
    WHERE employee_id = ?
");
$stmt->bind_param("i", $employee_id_to_submit);
$stmt->execute();
$overall_sales_summary_result = $stmt->get_result()->fetch_assoc();
if ($overall_sales_summary_result) {
    $overall_sales_summary = $overall_sales_summary_result;
}
$stmt->close();

$total_overall_sales = array_sum($overall_sales_summary);


$today = date('Y-m-d');
$today_data = []; 
$stmt = $conn->prepare("
    SELECT *
    FROM sales_data 
    WHERE employee_id = ? AND date = ? 
    ORDER BY input_time DESC
");
$stmt->bind_param("is", $employee_id_to_submit, $today);
$stmt->execute();
$today_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Hitung total harian
$daily_total = [
    'paket_sake' => 0,
    'paket_anggur_merah' => 0,
    'paket_tuak' => 0,
    'paket_soju' => 0,
    'paket_spicy_1' => 0,
    'paket_spicy_2' => 0,
    'paket_spicy_3' => 0,
    'paket_vip_person' => 0,
    'paket_special_30min' => 0,
    'total_entries' => count($today_data)
];
foreach ($today_data as $entry) {
    $daily_total['paket_sake'] += $entry['paket_sake'];
    $daily_total['paket_anggur_merah'] += $entry['paket_anggur_merah'];
    $daily_total['paket_tuak'] += $entry['paket_tuak'];
    $daily_total['paket_soju'] += $entry['paket_soju'];
    $daily_total['paket_spicy_1'] += $entry['paket_spicy_1'];
    $daily_total['paket_spicy_2'] += $entry['paket_spicy_2'];
    $daily_total['paket_spicy_3'] += $entry['paket_spicy_3'];
    $daily_total['paket_vip_person'] += $entry['paket_vip_person'];
    $daily_total['paket_special_30min'] += $entry['paket_special_30min'];
}


$recent_sales = []; 
$stmt = $conn->prepare("
    SELECT *
    FROM sales_data 
    WHERE employee_id = ? 
    ORDER BY input_time DESC 
    LIMIT 20
");
$stmt->bind_param("i", $employee_id_to_submit);
$stmt->execute();
$recent_sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// --- END: MODIFIKASI PHP UNTUK PENJUALAN RUANGAN ---

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Penjualan - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .sales-input-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-md);
            margin-top: var(--spacing-lg);
        }
        .product-card {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: var(--spacing-md);
            display: flex;
            flex-direction: column;
            gap: var(--spacing-xs);
            position: relative;
            background: var(--bg-secondary);
        }
        .product-card.active {
            background: var(--primary-light);
            border-color: var(--primary-color);
        }
        .product-card label {
            font-size: 1rem;
            font-weight: 600;
        }
        .product-card p {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .quantity-group {
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            margin-top: var(--spacing-sm);
        }
        .quantity-group input {
            width: 70px;
            text-align: center;
        }
        .product-card-spicy {
            background-color: var(--danger-light);
            border-color: var(--danger-color);
        }
        .product-card-room { /* NEW STYLE FOR ROOMS */
            background-color: var(--info-light);
            border-color: var(--info-color);
        }
        .today-badge {
            background-color: var(--info-color);
            color: white;
            padding: 2px 8px;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            margin-left: var(--spacing-sm);
            font-weight: 600;
        }
        .section-separator {
            grid-column: 1 / -1;
            margin-top: var(--spacing-xl);
            margin-bottom: var(--spacing-lg);
            padding-bottom: var(--spacing-md);
            border-bottom: 2px solid var(--primary-color);
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            text-transform: uppercase;
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
                    <span class="page-icon">💰</span>
                    Data Penjualan
                </h1>
                <p>Input dan kelola data penjualan harian Anda.</p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success_message) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error_message) ?></div>
            <?php endif; ?>

            <div class="card full-width" style="margin-bottom: var(--spacing-xl);">
                <div class="card-header">
                    <h3>Ringkasan Input Penjualan</h3>
                    <span class="entry-count">
                        <?= $total_overall_sales ?? 0 ?> Total Transaksi
                    </span>
                </div>
                <div class="card-content">
                    <div class="stats-grid-small">
                        <div class="stat-item">
                            <span class="stat-label">SAKE</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_sake'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">ANGGUR MERAH</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_anggur_merah'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">TUAK</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_tuak'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">SOJU</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_soju'] ?? 0 ?></span>
                        </div>
                        <?php if ($is_director_level): ?>
                        <div class="stat-item">
                            <span class="stat-label">SPICY 1</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_spicy_1'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">SPICY 2</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_spicy_2'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">SPICY 3</span>
                            <span class="stat-value" style="font-size: 1.2em;"><?= $overall_sales_summary['paket_spicy_3'] ?? 0 ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="stat-item" style="border: 1px solid var(--info-color);">
                            <span class="stat-label">RUANGAN VIP (ORANG)</span>
                            <span class="stat-value" style="font-size: 1.2em; color: var(--info-color);"><?= $overall_sales_summary['paket_vip_person'] ?? 0 ?></span>
                        </div>
                        <div class="stat-item" style="border: 1px solid var(--info-color);">
                            <span class="stat-label">RUANGAN SPESIAL (30 MIN)</span>
                            <span class="stat-value" style="font-size: 1.2em; color: var(--info-color);"><?= $overall_sales_summary['paket_special_30min'] ?? 0 ?></span>
                        </div>
                        </div>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Input Data Penjualan</h3>
                    <div class="current-time">
                        <span class="time-icon">⏰</span>
                        <span id="current-time"><?= date('H:i:s') ?></span>
                    </div>
                </div>
                <div class="card-content">
                    
                    <div class="info-message" style="margin-bottom: var(--spacing-xl);">
                        <strong>Penting:</strong> Jumlah yang dimasukkan adalah **jumlah paket/satuan layanan yang terjual**, BUKAN jumlah item/botol yang dikeluarkan dari stok.
                    </div>
                    
                    <form method="POST" class="sales-form" id="sales-form">
                        <input type="hidden" name="action" value="update_sales">
                        <input type="hidden" id="employee_role" value="<?= htmlspecialchars($selected_employee_role) ?>">
                        
                        <?php if ($is_admin_or_manager): ?>
                        <div class="form-group">
                            <label for="employee_id_input">Untuk Anggota</label>
                            <select name="employee_id" id="employee_id_input" class="form-select" onchange="window.location.href='sales.php?employee_id=' + this.value">
                                <option value="<?= $user['id'] ?>" <?= ($employee_id_to_submit == $user['id']) ? 'selected' : '' ?>>-- Untuk Diri Sendiri --</option>
                                <?php foreach ($all_employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($employee_id_to_submit == $emp['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($emp['name']) ?> (<?= getRoleDisplayName($emp['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="employee_id" value="<?= $user['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="form-group"> <label for="date">Tanggal Penjualan</label>
                            <input type="date" 
                                    name="date" 
                                    id="date" 
                                    value="<?= htmlspecialchars(date('Y-m-d')) ?>" 
                                    class="form-input" 
                                    required
                                    max="<?= date('Y-m-d') ?>"
                                    min="<?= date('Y-m-d', strtotime('-30 days')) ?>"
                                    onchange="formatDateInput(this)">
                            <small class="form-help">
                                Pilih tanggal penjualan (maksimal 30 hari ke belakang).
                                <br>Data akan disimpan pada jam: <strong id="preview-time"><?= date('H:i:s') ?></strong>
                            </small>
                        </div>
                        
                        <div class="sales-input-grid">
                            
                            <div class="section-separator">Penjualan Paketan</div>
                            <div class="product-card">
                                <label for="paket_sake">SAKE</label>
                                <p>$20,000.00</p>
                                <div class="quantity-group">
                                    <label for="paket_sake">Paket</label>
                                    <input type="number" name="paket_sake" id="paket_sake" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="paket_anggur_merah">ANGGUR MERAH</label>
                                <p>$20,000.00</p>
                                <div class="quantity-group">
                                    <label for="paket_anggur_merah">Paket</label>
                                    <input type="number" name="paket_anggur_merah" id="paket_anggur_merah" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="paket_tuak">TUAK</label>
                                <p>$20,000.00</p>
                                <div class="quantity-group">
                                    <label for="paket_tuak">Paket</label>
                                    <input type="number" name="paket_tuak" id="paket_tuak" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card">
                                <label for="paket_soju">SOJU</label>
                                <p>$20,000.00</p>
                                <div class="quantity-group">
                                    <label for="paket_soju">Paket</label>
                                    <input type="number" name="paket_soju" id="paket_soju" value="0" min="0">
                                </div>
                            </div>
                            
                            <?php if ($is_director_level): ?>
                            <div class="product-card product-card-spicy">
                                <label for="paket_spicy_1">SPICY 1</label>
                                <p>$65,000.00</p>
                                <div class="quantity-group">
                                    <label for="paket_spicy_1">Paket</label>
                                    <input type="number" name="paket_spicy_1" id="paket_spicy_1" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card product-card-spicy">
                                <label for="paket_spicy_2">SPICY 2</label>
                                <p>$45,000.00</p>
                                <div class="quantity-group">
                                    <label for="paket_spicy_2">Paket</label>
                                    <input type="number" name="paket_spicy_2" id="paket_spicy_2" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card product-card-spicy">
                                <label for="paket_spicy_3">SPICY 3</label>
                                <p>$35,000.00</p>
                                <div class="quantity-group">
                                    <label for="paket_spicy_3">Paket</label>
                                    <input type="number" name="paket_spicy_3" id="paket_spicy_3" value="0" min="0">
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="section-separator" style="border-bottom-color: var(--info-color);">Penjualan Ruangan</div>
                            
                            <div class="product-card product-card-room">
                                <label for="paket_vip_person">RUANGAN VIP</label>
                                <p>$ 50,000.00 / Orang</p>
                                <div class="quantity-group">
                                    <label for="paket_vip_person">Jumlah Orang</label>
                                    <input type="number" name="paket_vip_person" id="paket_vip_person" value="0" min="0">
                                </div>
                            </div>
                            <div class="product-card product-card-room">
                                <label for="paket_special_30min">RUANGAN SPESIAL</label>
                                <p>$ 350,000.00 / 30 Menit</p>
                                <div class="quantity-group">
                                    <label for="paket_special_30min">Sesi (30 Menit)</label>
                                    <input type="number" name="paket_special_30min" id="paket_special_30min" value="0" min="0">
                                </div>
                            </div>
                            </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" id="submit-btn">
                                <span class="btn-icon">💾</span>
                                Simpan Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Riwayat Penjualan Terbaru</h3>
                    <span class="entry-count">20 Terakhir</span>
                </div>
                <div class="card-content">
                    <?php if (empty($recent_sales)): ?>
                        <div class="no-data">Belum ada data penjualan. Silakan input data pertama Anda!</div>
                    <?php else: ?>
                        <div class="responsive-table-container">
                            <table class="activities-table-improved"> 
                                <thead>
                                    <tr>
                                        <th>Tanggal & Waktu</th>
                                        <th>Sake</th>
                                        <th>Anggur Merah</th>
                                        <th>Tuak</th>
                                        <th>Soju</th>
                                        <th>Spicy 1</th>
                                        <th>Spicy 2</th>
                                        <th>Spicy 3</th>
                                        <th>Ruangan VIP (Orang)</th>
                                        <th>Ruangan Spesial (30 Menit)</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_sales as $sale): ?>
                                    <?php 
                                        $is_today = date('Y-m-d', strtotime($sale['date'])) === date('Y-m-d');
                                    ?>
                                    <tr class="<?= $is_today ? 'today-row' : '' ?>">
                                        <td data-label="Tanggal & Waktu">
                                            <div class="datetime-cell">
                                                <?= date('d/m/Y H:i:s', strtotime($sale['input_time'])) ?>
                                                <?php if ($is_today): ?>
                                                    <span class="today-badge">Hari Ini</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="Sake"><?= $sale['paket_sake'] ?></td>
                                        <td data-label="Anggur Merah"><?= $sale['paket_anggur_merah'] ?></td>
                                        <td data-label="Tuak"><?= $sale['paket_tuak'] ?></td>
                                        <td data-label="Soju"><?= $sale['paket_soju'] ?></td>
                                        <td data-label="Spicy 1"><?= $sale['paket_spicy_1'] ?></td>
                                        <td data-label="Spicy 2"><?= $sale['paket_spicy_2'] ?></td>
                                        <td data-label="Spicy 3"><?= $sale['paket_spicy_3'] ?></td>
                                        <td data-label="Ruangan VIP (Orang)"><?= $sale['paket_vip_person'] ?? 0 ?></td>
                                        <td data-label="Ruangan Spesial (30 Menit)"><?= $sale['paket_special_30min'] ?? 0 ?></td>
                                        <td data-label="Aksi">
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menghapus entri penjualan ini? Aksi ini TIDAK DAPAT DIBATALKAN.')">
                                                <input type="hidden" name="action" value="delete_sales_entry">
                                                <input type="hidden" name="sales_entry_id" value="<?= $sale['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                            </form>
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

    <script src="script.js"></script> 
    <script>
        // Update current time display
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('id-ID', { 
                hour12: false,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            
            const currentTimeElement = document.getElementById('current-time');
            const previewTimeElement = document.getElementById('preview-time');
            const submitBtn = document.getElementById('submit-btn');
            
            if (currentTimeElement) {
                currentTimeElement.textContent = timeString;
            }
            
            if (previewTimeElement) {
                previewTimeElement.textContent = timeString;
            }
            
            if (submitBtn) {
                const shortTime = now.toLocaleTimeString('id-ID', { 
                    hour12: false,
                    hour: '2-digit',
                    minute: '2-digit'
                });
                submitBtn.innerHTML = `<span class="btn-icon">💾</span> Simpan Data (${shortTime})`;
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('sales-form');
            
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            form.addEventListener('submit', function(e) {
                const submitBtn = document.getElementById('submit-btn');
                
                const now = new Date();
                const timeString = now.toLocaleTimeString('id-ID', { 
                    hour12: false,
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                
                if (!confirm(`Yakin ingin menyimpan data penjualan pada jam ${timeString}?`)) {
                    e.preventDefault();
                    return false;
                }
            });
        });

        setInterval(() => {
            updateCurrentTime();
        }, 1000);
    </script>
</body>
</html>