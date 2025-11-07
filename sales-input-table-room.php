<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// --- DEFINISI HARGA DAN KOMPONEN ---
$PRICES = [
    // Base Packages
    'regular_table' => 150000,
    'vip_table' => 200000,
    'vvip_table' => 300000,
    'vvip_room_only' => 300000,
    'vvip_room_angel_demon' => 1000000,
    'svip_room_angel_demon' => 1500000,

    // Add-Ons
    'addon_drink_3pak' => 50000,
    'addon_angel_demon_15m' => 400000, // Open Table A/D Add-on
    'addon_vvip_extra_guest' => 50000,
    'addon_vvip_extra_time_10m' => 200000, 
    'addon_vvip_angel_extra_guest' => 250000,
    'addon_vvip_angel_extra_time_15m' => 700000, 
    'addon_vvip_angel_extra_angel' => 500000,
    'addon_svip_angel_extra_guest' => 300000,
    'addon_svip_angel_extra_time_15m' => 700000, 
    'addon_svip_angel_extra_angel' => 700000,

    // COGS (Cost of Goods Sold)
    'cost_per_pak_minuman' => 20000,
];

// Inisialisasi variabel feedback
$success = null;
$error = null;

// Ambil daftar semua karyawan untuk dropdown
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
// BARU: Ambil daftar Talent aktif
$active_talents = getAllActiveTalents(); 

// --- TANGGAL FILTER (BARU) ---
$today = date('Y-m-d');
$selected_date = $_GET['filter_date'] ?? $today; // Menggunakan hari ini sebagai default
// Pastikan format tanggal valid sebelum digunakan dalam query
if (!DateTime::createFromFormat('Y-m-d', $selected_date)) {
    $selected_date = $today;
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}

// Helper untuk mengubah key paket menjadi nama yang rapi
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

// --- Handle Delete Single Sales Entry ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_single_sale') {
    $sale_id = (int)($_POST['sale_id'] ?? 0);
    $employee_id_of_log = (int)($user['id'] ?? 0); // User who performs deletion

    if ($sale_id <= 0) {
        $error = "ID transaksi tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Ambil detail entri sebelum dihapus untuk notifikasi
            $stmt_get_entry = $conn->prepare("
                SELECT str.*, e.name as employee_input_name
                FROM sales_table_room str
                JOIN employees e ON str.employee_id = e.id
                WHERE str.id = ?
            ");
            if (!$stmt_get_entry) { throw new Exception("Gagal menyiapkan query ambil detail entri: " . $conn->error); }
            $stmt_get_entry->bind_param("i", $sale_id);
            $stmt_get_entry->execute();
            $entry_details = $stmt_get_entry->get_result()->fetch_assoc();
            $stmt_get_entry->close();

            if (!$entry_details) { throw new Exception("Entri penjualan tidak ditemukan."); }

            // 2. Hapus entri penjualan
            $stmt_delete = $conn->prepare("DELETE FROM sales_table_room WHERE id = ?");
            if (!$stmt_delete) { throw new Exception("Gagal menyiapkan query hapus entri penjualan: " . $conn->error); }
            $stmt_delete->bind_param("i", $sale_id);
            
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $conn->commit();
                $success = "Entri penjualan Table & Room ID: " . $sale_id . " berhasil dihapus.";
                
                // Kirim notifikasi Discord
                sendDiscordNotification([
                    'employee_name' => $entry_details['employee_input_name'],
                    'sales_date_time' => date('d/m/Y H:i', strtotime($entry_details['input_time'])),
                    'room_name' => getPackageDisplayName($entry_details['base_package'])
                ], 'sale_deleted'); // Reuse existing type, it's fine for admin deletion
            } else {
                throw new Exception("Gagal menghapus entri penjualan. Mungkin sudah dihapus atau tidak ada perubahan.");
            }
            $stmt_delete->close();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan saat menghapus: " . $e->getMessage();
        }
    }
    // Redirect with message
    $redirect_msg = $success ?? $error;
    $redirect_type = isset($success) ? 'success' : 'error';
    header("Location: sales-input-table-room.php?msg=" . urlencode($redirect_msg) . "&type=" . urlencode($redirect_type) . "&filter_date=" . urlencode($selected_date));
    exit;
}

// --- Handle Delete All Sales Entries ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_all_sales') {
    $conn->begin_transaction();
    try {
        // Hapus semua data dari sales_table_room
        $stmt_delete = $conn->prepare("DELETE FROM sales_table_room");
        if (!$stmt_delete) {
            throw new Exception("Gagal menyiapkan query hapus semua entri penjualan: " . $conn->error);
        }
        
        if ($stmt_delete->execute()) {
            $deleted_count = $stmt_delete->affected_rows;
            $conn->commit();
            $success = "Berhasil menghapus **semua** ({$deleted_count}) riwayat transaksi Table & Room.";
            
            // Kirim notifikasi Discord
            sendDiscordNotification([
                'admin_name' => $user['name'],
                'deleted_count' => $deleted_count,
                'action_type' => 'delete_all_table_room_sales' // custom type
            ], 'admin_system_action'); // Use a general admin action notification
        } else {
            throw new Exception("Gagal menghapus semua entri penjualan. Mungkin tabel kosong.");
        }
        $stmt_delete->close();
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Terjadi kesalahan saat menghapus semua data: " . $e->getMessage();
    }
    // Redirect with message
    $redirect_msg = $success ?? $error;
    $redirect_type = isset($success) ? 'success' : 'error';
    header("Location: sales-input-table-room.php?msg=" . urlencode($redirect_msg) . "&type=" . urlencode($redirect_type) . "&filter_date=" . urlencode($selected_date));
    exit;
}

// --- Handle Form Submission (PHP) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    
    // Konversi nilai yang seharusnya numerik
    $employee_id = (int)($data['employee_id'] ?? $user['id']);
    $base_package = $data['base_package'] ?? '';
    $total_guest = (int)($data['total_guest'] ?? 0);
    $total_drink_addon = (int)($data['total_drink_addon'] ?? 0);
    $total_extra_time = (int)($data['total_extra_time_minutes'] ?? 0); 
    $diskon_percentage = (int)($data['diskon_percentage'] ?? 0);
    
    // Hasil Kalkulasi Final (Diasumsikan ini adalah nilai integer dari JS)
    $total_gross_revenue = (int)($data['final_gross_revenue'] ?? 0);
    $total_net_revenue = (int)($data['final_net_revenue'] ?? 0);
    $talent_share = (int)($data['final_talent_share'] ?? 0);
    $elysium_share = (int)($data['final_elysium_share'] ?? 0);
    $talent_name = $data['talent_name'] ?? ''; // <--- FIELD UNTUK DISIMPAN
    
    // Validasi dasar
    if (empty($base_package) || $total_gross_revenue < 0) {
        $error = "Data penjualan tidak valid. Harap isi paket dasar dan hitung ulang.";
    } else {
        $conn->begin_transaction();
        try {
            // INSERT query dengan 11 parameter (Termasuk talent_name)
            $stmt = $conn->prepare("
                INSERT INTO sales_table_room 
                (employee_id, sale_date, input_time, base_package, total_guest, total_drink_addon, total_extra_time, diskon_percentage, total_gross_revenue, total_net_revenue, talent_share, elysium_share, talent_name)
                VALUES (?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query simpan data: " . $conn->error);
            }
            
            // Binding 11 parameter: i s i i i i d d d d s 
            if (!$stmt->bind_param("isiiiidddds", 
                $employee_id, 
                $base_package, 
                $total_guest, 
                $total_drink_addon, 
                $total_extra_time, 
                $diskon_percentage, 
                $total_gross_revenue, 
                $total_net_revenue,
                $talent_share,
                $elysium_share,
                $talent_name // <-- VARIABEL BARU
            )) {
                 throw new Exception("Gagal mengikat parameter: Cek jumlah placeholder dan tipe data. Error: " . $stmt->error);
            }
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal menyimpan data penjualan: " . $stmt->error);
            }
            
            $conn->commit();
            $success = "Data penjualan Table & Room berhasil disimpan! Pendapatan Bersih: " . formatRupiah($total_net_revenue);
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error saat menyimpan: " . $e->getMessage();
        }
    }
    
    // Redirect dengan filter tanggal hari ini agar tetap relevan setelah submit
    $redirect_msg = $success ?? $error;
    $redirect_type = isset($success) ? 'success' : 'error';
    
    header("Location: sales-input-table-room.php?msg=" . urlencode($redirect_msg) . "&type=" . urlencode($redirect_type) . "&filter_date=" . $today);
    exit;
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success = $feedback_message;
    } else {
        $error = $feedback_message;
    }
}


// --- LOGIKA PENGAMBILAN DATA HARIAN (Menggunakan $selected_date) ---
$today_sales_summary = [
    'daily_net_revenue' => 0,
    'daily_talent_share' => 0,
    'daily_elysium_share' => 0
];
$recent_sales_history = [];

// 1. Query untuk Ringkasan Harian (Filter oleh $selected_date)
$stmt_daily_summary = $conn->prepare("
    SELECT
        COALESCE(SUM(total_net_revenue), 0) as daily_net_revenue,
        COALESCE(SUM(talent_share), 0) as daily_talent_share,
        COALESCE(SUM(elysium_share), 0) as daily_elysium_share
    FROM sales_table_room
    WHERE DATE(sale_date) = ?
");
if ($stmt_daily_summary) {
    $stmt_daily_summary->bind_param("s", $selected_date);
    $stmt_daily_summary->execute();
    $today_sales_summary_result = $stmt_daily_summary->get_result()->fetch_assoc();
    if ($today_sales_summary_result) {
        $today_sales_summary = $today_sales_summary_result;
    }
    $stmt_daily_summary->close();
}

// 2. Query untuk Riwayat Transaksi Terbaru (Semua transaksi pada $selected_date)
$stmt_recent_sales = $conn->prepare("
    SELECT str.id, str.input_time, str.base_package, str.total_net_revenue, str.talent_share, str.elysium_share, 
           str.talent_name, e.name as employee_input_name
    FROM sales_table_room str
    JOIN employees e ON str.employee_id = e.id
    WHERE DATE(str.sale_date) = ? 
    ORDER BY str.input_time DESC
    LIMIT 20
");
if ($stmt_recent_sales) {
    $stmt_recent_sales->bind_param("s", $selected_date);
    $stmt_recent_sales->execute();
    $recent_sales_history = $stmt_recent_sales->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_recent_sales->close();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Sales Table & Room</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>
        .calculator-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        .main-form-section {
            grid-column: 1 / 2;
        }
        .results-section {
            grid-column: 2 / 3;
            position: sticky;
            top: 20px;
            align-self: flex-start;
        }
        .package-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .package-btn {
            padding: 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-lg);
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            background: var(--bg-secondary);
            transition: all 0.2s ease;
        }
        .package-btn.selected, .package-btn:hover {
            border-color: var(--primary-color);
            background: var(--primary-light);
            color: var(--primary-color);
        }
        .add-on-group {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            margin-bottom: 20px;
        }
        .add-on-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-light);
        }
        .add-on-item:last-child {
            border-bottom: none;
        }
        .add-on-item label {
            flex: 1;
            font-size: 0.9rem;
        }
        .add-on-item input[type="number"] {
            width: 70px;
            text-align: center;
            padding: 5px;
            font-size: 0.9rem;
        }
        .results-section .card {
            padding: 0;
        }
        .results-summary {
            font-size: 0.95em;
        }
        .result-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .result-row strong {
            font-size: 1.05em;
        }
        .result-row.final-net {
            border-top: 2px solid var(--primary-color);
            padding-top: 15px;
            margin-top: 10px;
        }
        .result-row.profit-share {
            font-size: 1em;
        }
        .share-elysium { color: var(--success-color); }
        .share-talent { color: var(--primary-color); }
        
        /* Gaya Baru untuk Notifikasi Perubahan Share */
        .split-active {
            border: 2px solid var(--primary-color);
            border-radius: var(--radius-md);
            padding: 10px;
            background-color: var(--primary-light);
        }
        .split-active .share-elysium { 
             font-weight: 700;
        }
        
        /* Gaya untuk Ringkasan Harian */
        .daily-summary-grid {
            display: flex;
            justify-content: space-around;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .daily-summary-box {
            text-align: center;
            padding: 15px;
            border-radius: var(--radius-lg);
            flex: 1;
            min-width: 150px;
        }
        .daily-summary-box h4 {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
            text-transform: uppercase;
        }
        .daily-summary-box .value {
            font-size: 1.25rem;
            font-weight: 700;
        }
        .daily-summary-box.total {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-light);
        }
        .daily-summary-box.talent {
            border: 1px solid var(--primary-color);
            background: var(--primary-light);
            color: var(--primary-color);
        }
        .daily-summary-box.elysium {
            border: 1px solid var(--success-color);
            background: var(--success-light);
            color: var(--success-color);
        }
        
        /* Gaya untuk Riwayat Transaksi */
        .recent-sales-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .recent-sales-item {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-light);
            padding: var(--spacing-md);
            display: flex;
            flex-direction: column;
            gap: 5px;
            font-size: 0.9rem;
        }
        .recent-sales-item:last-child {
            border-bottom: none;
        }
        .recent-sales-item .name-info {
            font-weight: 600;
            color: var(--text-primary);
        }
        .recent-sales-item .time-info {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .recent-sales-item .net-revenue {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .filter-control-container {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: flex-end;
        }

        @media (max-width: 1024px) {
            .calculator-grid {
                grid-template-columns: 1fr;
            }
            .results-section {
                position: static;
            }
        }
        @media (max-width: 768px) {
            .daily-summary-grid {
                flex-direction: column;
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
                    <span class="page-icon">💎</span>
                    Input Penjualan Table & Room
                </h1>
                <p>Kalkulator khusus untuk transaksi paket table, room, dan *talent*.</p>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="sales-calculator-form">
                <input type="hidden" name="employee_id" value="<?= $user['id'] ?>">
                <input type="hidden" name="base_package" id="base_package_input">
                <input type="hidden" name="total_guest" id="total_guest_input" value="0">
                <input type="hidden" name="total_extra_time_minutes" id="total_extra_time_minutes_input" value="0">
                <input type="hidden" name="final_gross_revenue" id="final_gross_revenue_input">
                <input type="hidden" name="final_net_revenue" id="final_net_revenue_input">
                <input type="hidden" name="final_talent_share" id="final_talent_share_input">
                <input type="hidden" name="final_elysium_share" id="final_elysium_share_input">


                <div class="calculator-grid">
                    <div class="main-form-section">
                        <div class="card">
                            <div class="card-header"><h3>Pilih Paket Dasar</h3></div>
                            <div class="card-content">
                                <div class="package-options">
                                    <div class="package-btn" data-package="regular_table" data-price="<?= $PRICES['regular_table'] ?>" data-includes="0" data-has-angel="false">Regular Table</div>
                                    <div class="package-btn" data-package="vip_table" data-price="<?= $PRICES['vip_table'] ?>" data-includes="0" data-has-angel="false">VIP Table</div>
                                    <div class="package-btn" data-package="vvip_table" data-price="<?= $PRICES['vvip_table'] ?>" data-includes="0" data-has-angel="false">VVIP Table</div>
                                    <div class="package-btn" data-package="vvip_room_only" data-price="<?= $PRICES['vvip_room_only'] ?>" data-includes="2" data-has-angel="false">VVIP Room Only (+2 Pak Minuman)</div>
                                    <div class="package-btn" data-package="vvip_room_angel_demon" data-price="<?= $PRICES['vvip_room_angel_demon'] ?>" data-includes="2" data-has-angel="true">VVIP ROOM & Angel (+2 Pak Minuman)</div>
                                    <div class="package-btn" data-package="svip_room_angel_demon" data-price="<?= $PRICES['svip_room_angel_demon'] ?>" data-includes="4" data-has-angel="true">SVIP ROOM & Angel (+4 Pak Minuman)</div>
                                </div>
                                <div class="form-group" style="margin-top: 20px;">
                                    <label for="talent_name">Nama Talent/Angel/Demon yang Melayani (Wajib isi jika ada Talent terlibat)</label>
                                    <select name="talent_name" id="talent_name" class="form-select">
                                        <option value="">-- Kosongkan (Tidak Ada Talent Terlibat) --</option>
                                        <?php foreach ($active_talents as $talent): ?>
                                            <option value="<?= htmlspecialchars($talent['name']) ?>"><?= htmlspecialchars($talent['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="form-help">Pilih nama Talent yang terlibat. Ini akan mengaktifkan pembagian 60/40.</small>
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-top: 20px;">
                            <div class="card-header"><h3>Add-Ons Tambahan</h3></div>
                            <div class="card-content">
                                <div class="add-on-group" id="general-addons">
                                    <div class="add-on-item">
                                        <label>Drink (3 Pak) - @<?= formatRupiah($PRICES['addon_drink_3pak']) ?></label>
                                        <input type="number" id="addon_drink_3pak_input" name="total_drink_addon" min="0" value="0">
                                    </div>
                                </div>

                                <div class="add-on-group" id="package-specific-addons" style="display: none;">
                                    <p style="font-weight: 600; color: var(--primary-color); margin-bottom: 10px;">ADD-ONS BERDASARKAN PAKET</p>
                                    <div id="addon-content">
                                        </div>
                                </div>
                                
                                <div class="add-on-group">
                                    <div class="form-group">
                                        <label for="diskon_percentage">Diskon (%)</label>
                                        <input type="number" id="diskon_percentage" name="diskon_percentage" min="0" max="100" value="0" class="form-input" style="width: 100px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="results-section">
                        <div class="card">
                            <div class="card-header"><h3>Ringkasan Keuangan (Real-Time)</h3></div>
                            <div class="card-content">
                                <div class="results-summary">
                                    <div class="result-row"><span>Paket Dasar:</span> <strong id="base_price_display">Rp 0</strong></div>
                                    <div class="result-row"><span>Total Add-Ons:</span> <strong id="addons_total_display">Rp 0</strong></div>
                                    <div class="result-row"><span>Biaya Layanan (Total):</span> <strong id="initial_total_display">Rp 0</strong></div>
                                    <div class="result-row" style="color: var(--danger-color);"><span>Diskon (<span id="diskon_percent_display">0</span>%):</span> <strong id="discount_amount_display">- Rp 0</strong></div>
                                    
                                    <div class="result-row final-net" style="border-bottom: 1px dashed var(--border-light);">
                                        <span>**PENDAPATAN KOTOR**</span> <strong id="gross_revenue_display">Rp 0</strong>
                                    </div>
                                    
                                    <div class="result-row"><span>(-) Biaya Minuman Fasilitas:</span> <strong id="cogs_cost_display">- Rp 0</strong></div>

                                    <div class="result-row final-net" style="border-bottom: none;">
                                        <span>**PENDAPATAN BERSIH (NETT)**</span> <strong id="net_revenue_display">Rp 0</strong>
                                    </div>
                                </div>

                                <div class="section-title" style="margin-top: 20px;">
                                    <h3>Pembagian Hasil</h3>
                                </div>
                                
                                <div id="share-wrapper">
                                    <div class="result-row profit-share share-talent">
                                        <span>Pemasukan Talent (<span id="talent_share_percent">0</span>%):</span> <strong id="talent_share_display">Rp 0</strong>
                                    </div>
                                    <div class="result-row profit-share share-elysium">
                                        <span>Pemasukan Elysium (<span id="elysium_share_percent_display">100</span>%):</span> <strong id="elysium_share_display">Rp 0</strong>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary" id="save-button" style="margin-top: 20px;">
                                    Simpan Transaksi Final
                                </button>
                                <p id="error-message" class="error-message" style="display:none; margin-top: 10px; text-align: center;"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div class="card full-width" style="margin-top: 20px;">
                <div class="card-header">
                    <h3>Riwayat & Ringkasan Penjualan</h3>
                </div>
                <div class="card-content">
                     <div class="filter-control-container">
                        <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="filter_date">Pilih Tanggal Riwayat</label>
                                <input type="date" name="filter_date" id="filter_date" class="form-input" 
                                       value="<?= htmlspecialchars($selected_date) ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Lihat Riwayat</button>
                        </form>
                    </div>

                    <h4 style="margin-top: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                            Ringkasan Tanggal <?= date('d/m/Y', strtotime($selected_date)) ?>
                    </h4>
                    <div class="daily-summary-grid">
                        <div class="daily-summary-box total">
                            <h4>PENDAPATAN BERSIH</h4>
                            <p class="value" style="color: var(--text-primary);"><?= formatRupiah($today_sales_summary['daily_net_revenue']) ?></p>
                        </div>
                        <div class="daily-summary-box talent">
                            <h4>TOTAL SHARE TALENT</h4>
                            <p class="value"><?= formatRupiah($today_sales_summary['daily_talent_share']) ?></p>
                        </div>
                        <div class="daily-summary-box elysium">
                            <h4>TOTAL SHARE ELYSIUM</h4>
                            <p class="value"><?= formatRupiah($today_sales_summary['daily_elysium_share']) ?></p>
                        </div>
                    </div>

                    <div style="margin-top: 2rem;">
                        <h4 style="border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">
                            Riwayat Transaksi (<?= count($recent_sales_history) ?> Entri)
                        </h4>
                        
                        <form method="POST" onsubmit="return confirm('⚠️ PERINGATAN KERAS! Yakin ingin menghapus SELURUH riwayat transaksi Table & Room? Tindakan ini TIDAK DAPAT DIBATALKAN dan akan menghapus semua entri dari database.')" style="margin-top: 10px;">
                            <input type="hidden" name="action" value="delete_all_sales">
                            <button type="submit" class="btn btn-danger btn-sm">
                                <span class="btn-icon">🗑️</span> Hapus SELURUH Riwayat
                            </button>
                        </form>
                    </div>

                    <?php if (empty($recent_sales_history)): ?>
                        <div class="no-data">Tidak ada riwayat input Table & Room pada tanggal ini.</div>
                    <?php else: ?>
                        <ul class="recent-sales-list" style="margin-top: 15px;">
                            <?php foreach ($recent_sales_history as $sale): ?>
                                <li class="recent-sales-item">
                                    <span class="time-info">
                                        <?= date('d/m/Y H:i:s', strtotime($sale['input_time'])) ?> (ID: <?= $sale['id'] ?>)
                                    </span>
                                    <span class="name-info">Input Oleh: <?= htmlspecialchars($sale['employee_input_name']) ?></span>
                                    <?php if (!empty($sale['talent_name'])): ?>
                                        <span class="name-info">Talent: <?= htmlspecialchars($sale['talent_name']) ?></span>
                                    <?php endif; ?>
                                    
                                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 5px;">
                                        <div>
                                            <strong>Paket: <?= getPackageDisplayName($sale['base_package']) ?></strong>
                                            <span class="net-revenue" style="display: block;">Net Revenue: <?= formatRupiah($sale['total_net_revenue']) ?></span>
                                            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--text-secondary); margin-top: 5px;">
                                                <span>Talent Share: <?= formatRupiah($sale['talent_share']) ?> | </span>
                                                <span>  | Elysium Share: <?= formatRupiah($sale['elysium_share']) ?></span>
                                            </div>
                                        </div>
                                        
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus transaksi ID: <?= $sale['id'] ?>? Tindakan ini TIDAK DAPAT DIBATALKAN.')" style="flex-shrink: 0;">
                                            <input type="hidden" name="action" value="delete_single_sale">
                                            <input type="hidden" name="sale_id" value="<?= $sale['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            </main>
    </div>

    <script src="script.js"></script>
    <script>
        const PRICES = <?= json_encode($PRICES) ?>;

        const PACKAGE_DETAILS = {
            regular_table: { label: "Regular Table", base: PRICES.regular_table, included_packs: 0, has_angel: false },
            vip_table: { label: "VIP Table", base: PRICES.vip_table, included_packs: 0, has_angel: false }, 
            vvip_table: { label: "VVIP Table", base: PRICES.vvip_table, included_packs: 0, has_angel: false },
            vvip_room_only: { label: "VVIP ROOM Only", base: PRICES.vvip_room_only, included_packs: 2, has_angel: false },
            vvip_room_angel_demon: { label: "VVIP ROOM & Angel", base: PRICES.vvip_room_angel_demon, included_packs: 2, has_angel: true },
            svip_room_angel_demon: { label: "SVIP ROOM & Angel", base: PRICES.svip_room_angel_demon, included_packs: 4, has_angel: true },
        };
        
        // --- REUSABLE ADDON TEMPLATE ---
        const OPEN_TABLE_ANGEL_ADDON = `
            <div class="add-on-item">
                <label>1 Angel / Demon (15 Menit) - @${formatRupiah(PRICES.addon_angel_demon_15m)}</label>
                <input type="number" id="addon_angel_demon_15m_input" name="addon_angel_demon_15m" min="0" value="0" data-is-angel="true" data-price-per-unit="${PRICES.addon_angel_demon_15m}">
            </div>
            <div class="info-message" style="margin-top: 10px;">
                **Catatan:** Add-on ini akan mengaktifkan pembagian 60/40.
            </div>
        `;
        // --- END REUSABLE ADDON TEMPLATE ---

        const ADDON_TEMPLATES = {
            // Updated to allow Angel/Demon Add-on on Regular Table
            regular_table: OPEN_TABLE_ANGEL_ADDON,
            
            vip_table: OPEN_TABLE_ANGEL_ADDON,
            
            vvip_table: OPEN_TABLE_ANGEL_ADDON,

            vvip_room_only: `
                <div class="add-on-item">
                    <label>Extra Guest: @${formatRupiah(PRICES.addon_vvip_extra_guest)} / Org (Max 3 Guest)</label>
                    <input type="number" id="addon_vvip_extra_guest_input" name="total_guest_addon_qty" min="0" value="0" data-base-guest="3" data-price-per-unit="${PRICES.addon_vvip_extra_guest}">
                </div>
                <div class="add-on-item">
                    <label>Extra Time: @${formatRupiah(PRICES.addon_vvip_extra_time_10m)} / 10 Menit (Max 30m)</label>
                    <input type="number" id="addon_vvip_extra_time_input" name="total_extra_time" min="0" value="0" data-unit="10" data-price-per-unit="${PRICES.addon_vvip_extra_time_10m}">
                </div>
            `,
            vvip_room_angel_demon: `
                 <div class="add-on-item">
                    <label>Extra Guest: @${formatRupiah(PRICES.addon_vvip_angel_extra_guest)} / Org (Max 2 Extra)</label>
                    <input type="number" id="addon_vvip_angel_extra_guest_input" name="total_guest_addon_qty" min="0" value="0" data-base-guest="1" data-price-per-unit="${PRICES.addon_vvip_angel_extra_guest}">
                </div>
                <div class="add-on-item">
                    <label>Extra Time: @${formatRupiah(PRICES.addon_vvip_angel_extra_time_15m)} / 15 Menit (Max 30m)</label>
                    <input type="number" id="addon_vvip_angel_extra_time_input" name="total_extra_time" min="0" value="0" data-unit="15" data-price-per-unit="${PRICES.addon_vvip_angel_extra_time_15m}">
                </div>
                <div class="add-on-item">
                    <label>Extra Angel / Demon: @${formatRupiah(PRICES.addon_vvip_angel_extra_angel)} / Org</label>
                    <input type="number" id="addon_vvip_angel_extra_angel_input" name="addon_extra_angel" min="0" value="0" data-is-angel="true" data-price-per-unit="${PRICES.addon_vvip_angel_extra_angel}">
                </div>
            `,
            svip_room_angel_demon: `
                 <div class="add-on-item">
                    <label>Extra Guest: @${formatRupiah(PRICES.addon_svip_angel_extra_guest)} / Org (Max 4 Extra)</label>
                    <input type="number" id="addon_svip_angel_extra_guest_input" name="total_guest_addon_qty" min="0" value="0" data-base-guest="1" data-price-per-unit="${PRICES.addon_svip_angel_extra_guest}">
                </div>
                <div class="add-on-item">
                    <label>Extra Time: @${formatRupiah(PRICES.addon_svip_angel_extra_time_15m)} / 15 Menit (Max 30m)</label>
                    <input type="number" id="addon_svip_angel_extra_time_input" name="total_extra_time" min="0" value="0" data-unit="15" data-price-per-unit="${PRICES.addon_svip_angel_extra_time_15m}">
                </div>
                <div class="add-on-item">
                    <label>Extra Angel / Demon: @${formatRupiah(PRICES.addon_svip_angel_extra_angel)} / Org</label>
                    <input type="number" id="addon_svip_angel_extra_angel_input" name="addon_extra_angel" min="0" value="0" data-is-angel="true" data-price-per-unit="${PRICES.addon_svip_angel_extra_angel}">
                </div>
            `,
        };
        
        let selectedPackage = null;

        // Utility to format Rupiah client-side
        function formatRupiah(angka) {
            angka = Math.round(angka);
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            }).format(angka).replace('IDR', 'Rp');
        }

        function calculateTotal() {
            let basePrice = 0;
            let totalAddonsCost = 0;
            let includedPacks = 0;
            let totalExtraTimeMinutes = 0;
            let isTalentInvolved = false; 
            let finalGrossRevenue = 0;

            const currentDiscount = parseInt(document.getElementById('diskon_percentage').value) || 0;
            const talentNameSelect = document.getElementById('talent_name');
            const talentNameInput = talentNameSelect.value.trim();
            const shareWrapper = document.getElementById('share-wrapper');
            
            if (selectedPackage) {
                const pkg = PACKAGE_DETAILS[selectedPackage];
                basePrice = pkg.base;
                includedPacks = pkg.included_packs;
                
                // 1. Cek apakah paket dasar sudah termasuk Angel/Demon
                if (pkg.has_angel) {
                    isTalentInvolved = true;
                }

                // --- 2. Hitung Add-Ons Umum (Drink 3 Pak) ---
                const drinkAddonInput = document.getElementById('addon_drink_3pak_input');
                const drinkAddonQty = parseInt(drinkAddonInput ? drinkAddonInput.value : 0) || 0;
                totalAddonsCost += drinkAddonQty * PRICES.addon_drink_3pak;
                
                // --- 3. Hitung Add-Ons Spesifik Paket ---
                const addonContent = document.getElementById('addon-content');
                if (addonContent.innerHTML !== '') {
                    const form = document.getElementById('sales-calculator-form');
                    
                    // a. Add-on Angel/Demon (Open Table - now includes Regular)
                    const openTableAngelInput = addonContent.querySelector('input[name="addon_angel_demon_15m"]');
                    if (openTableAngelInput) {
                        const angelDemonQty = parseInt(openTableAngelInput.value) || 0;
                        const pricePerUnit = parseInt(openTableAngelInput.dataset.pricePerUnit);
                        totalAddonsCost += angelDemonQty * pricePerUnit;
                        
                        if (angelDemonQty > 0) isTalentInvolved = true; // AKSI KRITIS: Aktifkan 60/40
                        document.getElementById('total_guest_input').value = angelDemonQty; // Total Angel/Demon di Open Table
                    }

                    // b. Extra Angel Status (Room & Angel Add-on)
                    const extraAngelInput = addonContent.querySelector('input[name="addon_extra_angel"]');
                    if (extraAngelInput) {
                        const angelQty = parseInt(extraAngelInput.value) || 0;
                        const angelPricePerUnit = parseInt(extraAngelInput.dataset.pricePerUnit);
                        totalAddonsCost += angelQty * angelPricePerUnit;
                        if (angelQty > 0) isTalentInvolved = true;
                    }


                    // c. Logic Extra Guest / Guest Qty
                    const extraGuestInput = addonContent.querySelector('input[name="total_guest_addon_qty"]');
                    if (extraGuestInput) {
                        const guestQty = parseInt(extraGuestInput.value) || 0;
                        const guestBase = parseInt(extraGuestInput.dataset.baseGuest) || 0;
                        const guestPricePerUnit = parseInt(extraGuestInput.dataset.pricePerUnit);

                        if (guestQty > 0) {
                            totalAddonsCost += guestQty * guestPricePerUnit;
                        }
                        // Update total guest di hidden field
                        document.getElementById('total_guest_input').value = guestQty + guestBase;
                    }


                    // d. Logic Extra Time
                    const extraTimeInput = addonContent.querySelector('input[name="total_extra_time"]');
                    if (extraTimeInput) {
                        const timeQty = parseInt(extraTimeInput.value) || 0;
                        const pricePerUnit = parseInt(extraTimeInput.dataset.pricePerUnit);
                        const unitMinutes = parseInt(extraTimeInput.dataset.unit);

                        totalAddonsCost += timeQty * pricePerUnit;
                        totalExtraTimeMinutes = timeQty * unitMinutes;
                        
                        document.getElementById('total_extra_time_minutes_input').value = totalExtraTimeMinutes;
                    }
                }
            }
            
            // --- 4. Perhitungan Total Biaya Layanan ---
            const initialTotal = basePrice + totalAddonsCost;
            
            // --- 5. Hitung Diskon ---
            const discountAmount = initialTotal * (currentDiscount / 100);
            finalGrossRevenue = initialTotal - discountAmount;
            
            // --- 6. Hitung COGS (Biaya Minuman Fasilitas) ---
            const totalCOGS = includedPacks * PRICES.cost_per_pak_minuman;
            
            // --- 7. Hitung Pendapatan Bersih ---
            const totalNetRevenue = finalGrossRevenue - totalCOGS;

            // --- 8. Profit Sharing Bersyarat ---
            let talentShare;
            let elysiumShare;
            let talentPercentDisplay;
            let elysiumPercentDisplay;

            // Logika Pembagian: 60/40 JIKA ada Talent terlibat (Nama diisi ATAU Angel Addon dibeli), 0/100 JIKA tidak.
            if (isTalentInvolved || talentNameInput !== '') {
                talentShare = totalNetRevenue * 0.60;
                elysiumShare = totalNetRevenue * 0.40;
                talentPercentDisplay = 60;
                elysiumPercentDisplay = 40;
                shareWrapper.classList.add('split-active');
            } else {
                talentShare = 0;
                elysiumShare = totalNetRevenue;
                talentPercentDisplay = 0;
                elysiumPercentDisplay = 100;
                shareWrapper.classList.remove('split-active');
            }


            // --- UPDATE DISPLAY ---
            document.getElementById('base_price_display').textContent = formatRupiah(basePrice);
            document.getElementById('addons_total_display').textContent = formatRupiah(totalAddonsCost);
            document.getElementById('initial_total_display').textContent = formatRupiah(initialTotal);
            document.getElementById('diskon_percent_display').textContent = currentDiscount;
            document.getElementById('discount_amount_display').textContent = `- ${formatRupiah(discountAmount)}`;
            document.getElementById('gross_revenue_display').textContent = formatRupiah(finalGrossRevenue);
            document.getElementById('cogs_cost_display').textContent = `- ${formatRupiah(totalCOGS)}`;
            document.getElementById('net_revenue_display').textContent = formatRupiah(totalNetRevenue);
            
            document.getElementById('talent_share_display').textContent = formatRupiah(talentShare);
            document.getElementById('elysium_share_display').textContent = formatRupiah(elysiumShare);
            
            document.getElementById('talent_share_percent').textContent = talentPercentDisplay;
            document.getElementById('elysium_share_percent_display').textContent = elysiumPercentDisplay;

            // --- UPDATE HIDDEN INPUTS UNTUK SUBMIT ---
            document.getElementById('final_gross_revenue_input').value = Math.round(finalGrossRevenue);
            document.getElementById('final_net_revenue_input').value = Math.round(totalNetRevenue);
            document.getElementById('final_talent_share_input').value = Math.round(talentShare);
            document.getElementById('final_elysium_share_input').value = Math.round(elysiumShare);
        }

        function initEventListeners() {
            const packageBtns = document.querySelectorAll('.package-btn');
            const addonContentDiv = document.getElementById('addon-content');
            const specificAddonsDiv = document.getElementById('package-specific-addons');
            const form = document.getElementById('sales-calculator-form');
            const talentNameSelect = form.querySelector('#talent_name');

            // Listener untuk Pilihan Paket Dasar
            packageBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    packageBtns.forEach(b => b.classList.remove('selected'));
                    btn.classList.add('selected');
                    selectedPackage = btn.dataset.package;
                    
                    // Reset input values (kecuali diskon dan talent name)
                    form.querySelectorAll('input[type="number"]').forEach(input => {
                        if (input.id !== 'diskon_percentage') input.value = 0;
                    });
                    
                    document.getElementById('base_package_input').value = selectedPackage; // Set package name
                    
                    // Muat Add-ons Spesifik
                    addonContentDiv.innerHTML = ''; 
                    if (ADDON_TEMPLATES[selectedPackage] && PACKAGE_DETAILS[selectedPackage]) {
                        addonContentDiv.innerHTML = ADDON_TEMPLATES[selectedPackage];
                        specificAddonsDiv.style.display = 'block';
                    } else {
                        specificAddonsDiv.style.display = 'none';
                    }
                    
                    // Tambahkan kembali listener ke input baru dan jalankan perhitungan
                    initInputListeners();
                    calculateTotal();
                });
            });

            // Listener untuk semua input angka dan nama talent (untuk profit sharing)
            function initInputListeners() {
                const allInputs = form.querySelectorAll('input, select');

                // Hapus semua listener input lama (untuk menghindari duplikasi)
                allInputs.forEach(input => {
                    input.removeEventListener('input', calculateTotal);
                    input.removeEventListener('change', calculateTotal); 
                });

                // Tambahkan listener input baru ke semua input (angka dan teks)
                allInputs.forEach(input => {
                    if (input.type === 'number' || input.type === 'text') {
                        input.addEventListener('input', calculateTotal);
                    } else if (input.tagName === 'SELECT' || input.type === 'radio' || input.type === 'checkbox') {
                         input.addEventListener('change', calculateTotal);
                    }
                });
                
                // Listener khusus untuk nama talent (dropdown)
                talentNameSelect.addEventListener('change', calculateTotal);
            }
            
            // Inisialisasi awal (klik paket pertama untuk memuat add-on)
            if(packageBtns.length > 0) {
                 packageBtns[0].click();
            } else {
                 initInputListeners(); // Jika tidak ada paket, set listener dasar
            }

            // Form submission final check
            document.getElementById('sales-calculator-form').addEventListener('submit', function(e) {
                // Panggil calculateTotal lagi untuk memastikan hidden input terisi dengan nilai terbaru
                calculateTotal(); 

                const netRevenueInput = document.getElementById('final_net_revenue_input');
                const netRevenue = parseInt(netRevenueInput.value) || 0;
                const packageSelected = document.getElementById('base_package_input').value;
                const talentName = document.getElementById('talent_name').value.trim();
                
                // Logika cek keterlibatan Angel/Demon: Cek paket dasar ATAU cek input addon_angel_demon_15m / addon_extra_angel
                const isAngelAddonPresent = (document.getElementById('addon-content').querySelector('input[name="addon_angel_demon_15m"]')?.value > 0) || 
                                            (document.getElementById('addon-content').querySelector('input[name="addon_extra_angel"]')?.value > 0);
                const isTalentPackage = PACKAGE_DETAILS[packageSelected]?.has_angel;
                const isTalentInvolved = isTalentPackage || isAngelAddonPresent || (talentName !== '');

                const errorMessage = document.getElementById('error-message');
                errorMessage.style.display = 'none';

                // Validasi 1: Paket harus dipilih
                if (!packageSelected) {
                    e.preventDefault();
                    errorMessage.textContent = '❌ Harap pilih Paket Dasar sebelum menyimpan.';
                    errorMessage.style.display = 'block';
                    return;
                }

                // Validasi 2: Jika ada Angel/Demon terlibat, nama talent WAJIB diisi.
                if (isTalentInvolved && talentName === '') {
                    e.preventDefault();
                    errorMessage.textContent = '❌ Nama Talent wajib diisi untuk paket Angel/Demon atau jika Add-on Angel dibeli.';
                    errorMessage.style.display = 'block';
                    return;
                }
                
                // Validasi 3: Pendapatan bersih tidak boleh negatif
                if (netRevenue < 0) {
                    e.preventDefault();
                    errorMessage.textContent = '❌ Pendapatan Bersih tidak boleh negatif.';
                    errorMessage.style.display = 'block';
                    return;
                }
                
                if (!confirm(`Yakin ingin menyimpan transaksi ini?\nPENDAPATAN BERSIH: ${formatRupiah(netRevenue)}`)) {
                    e.preventDefault();
                    return;
                }
            });
        }

        document.addEventListener('DOMContentLoaded', initEventListeners);
    </script>
</body>
</html>