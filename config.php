<?php
// File: rama907/takagroup/rama907-takagroup-8ee4312790aa74e2b35b9ce31a1ab20b69583b9c/config.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_NOTICE);
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'imjutwnp_grand_company_db');
define('DB_PASS', 'DeuvYrgy7GddWrr8M9JY');
define('DB_NAME', 'imjutwnp_grand_company_db');

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset
$conn->set_charset("utf8");

// Start session
session_start();

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to check user role
function hasRole($roles) {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['role'], $roles);
}

// Function to get current user
function getCurrentUser() {
    global $conn;
    if (!isLoggedIn()) return null;
    
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Function to format duration
function formatDuration($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return $hours . 'j ' . $mins . 'm';
}

// Function to get role display name
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
        'magang' => 'Magang'
    ];
    return $roles[$role] ?? ucfirst($role);
}

// Fungsi untuk mendapatkan nama karyawan berdasarkan ID
function getEmployeeNameById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ?");
    if (!$stmt) {
        error_log("Error preparing getEmployeeNameById statement: " . $conn->error);
        return 'Unknown Employee';
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['name'] ?? 'Tidak Dikenal';
}

// Mendapatkan hitungan pending request yang terpisah
function getPendingRequestCounts() {
    global $conn;
    $counts = [
        'employee_requests' => 0, // Permohonan (Cuti, Resign, Manual Duty, Add Employee, Password Reset)
        'booking_requests' => 0,  // Kelola Pemesanan (Room Bookings)
        'total' => 0
    ];

    // Employee Requests (Permohonan)
    $query = "
        SELECT 
            (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM resignation_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM manual_duty_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM add_employee_requests WHERE status = 'pending') +
            (SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending') as count
    ";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->execute();
        $counts['employee_requests'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }

    // Booking Requests (Kelola Pemesanan)
    $query = "SELECT COUNT(*) as count FROM room_bookings WHERE booking_status = 'pending_approval'";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->execute();
        $counts['booking_requests'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
        $stmt->close();
    }
    
    $counts['total'] = $counts['employee_requests'] + $counts['booking_requests'];
    
    return $counts;
}

// FUNGSI LAMA: Dipertahankan untuk kompatibilitas, kini mengembalikan total.
function getPendingRequestCount() {
    $counts = getPendingRequestCounts();
    return $counts['total'];
}

// Fungsi untuk mengirim notifikasi ke Discord
function sendDiscordNotification($data, $type = 'info') {
    // --- 1. Konfigurasi Webhook & Bot Khusus ---
    
    // Webhook 1: Pusat Notifikasi (Umum, Clock Event, Sales, Admin System Action)
    $general_webhook_url = 'https://discord.com/api/webhooks/1423650015615913987/3SRAoW09O69-IWhsWWsbl_9ucloHGMEvog2fxFPFzzHCjOvsW_o02W1mArcF_6at_zQ5';
    $general_bot_name = "Elysium Night Club Bot";

    // Webhook 2: Permohonan & Surat Menyurat (Leave, Resign, Manual Duty, SP, Booking, dll)
    $request_webhook_url = 'https://discord.com/api/webhooks/1423652596140216371/t6vt2fpAtr6gMcHwVPmGvv8WeMWzN-hPrLWwPdYj-fAZEGP-x4kLq02CJ9JNBqPFgTbq'; 
    $request_bot_name = "Elysium Request Bot";

    // Webhook 3: Stok Kulkas (Deposit/Withdraw) -> HARAP GANTI URL PLACEHOLDER INI
    $refrigerator_webhook_url = 'https://discord.com/api/webhooks/1423652789497888822/6Eac6EBAyaB28cLNmmT2n8L-FlSxRSnKYC0Q1VBGdqi-tGLO9T9vQJlNUX_1o9_gqEvA'; // <-- GANTI INI
    $refrigerator_bot_name = "Elysium Kulkas Bot";

    // Webhook 4: Stok Gudang (Deposit/Withdraw) -> HARAP GANTI URL PLACEHOLDER INI
    $warehouse_webhook_url = 'https://discord.com/api/webhooks/1423652925330297003/Bq7P1EBFNJYQUJNfvUycuX3_CAfL34yl18gmUWnT6woOwabt6gbAejF18CHdGTtaCwIJ'; // <-- GANTI INI
    $warehouse_bot_name = "Elysium Gudang Bot";

    // --- 2. Tentukan Webhook & Bot yang Digunakan ---
    $webhooks_to_send = [];
    $username = $general_bot_name; // Default ke General Bot
    $avatar_url = "https://i.imghippo.com/files/IsTC1830As.png"; // Default Avatar URL (bisa diganti)

    $request_types = [
        'leave_request_submitted', 'resignation_request_submitted', 'manual_duty_request_submitted', 
        'request_status_update', 'warning_letter_issued', 'warning_letter_deleted', 
        'new_employee_request_submitted', 'password_reset_request_submitted',
        'room_booking_submitted', 'booking_status_updated', 'payment_status_updated',
        'duty_log_deleted', 'admin_employee_action', 'admin_system_action'
    ];

    $refrigerator_types = ['refrigerator_deposit', 'refrigerator_withdraw'];
    $warehouse_types = ['warehouse_deposit', 'warehouse_withdraw'];
    
    // Routing Logic
    if (in_array($type, $refrigerator_types)) {
        if (strpos($refrigerator_webhook_url, 'WEBHOOK_URL_HERE') === false) { $webhooks_to_send[] = $refrigerator_webhook_url; }
        $username = $refrigerator_bot_name;
    } elseif (in_array($type, $warehouse_types)) {
        if (strpos($warehouse_webhook_url, 'WEBHOOK_URL_HERE') === false) { $webhooks_to_send[] = $warehouse_webhook_url; }
        $username = $warehouse_bot_name;
    } elseif (in_array($type, $request_types)) {
        if (strpos($request_webhook_url, 'WEBHOOK_URL_HERE') === false) { $webhooks_to_send[] = $request_webhook_url; }
        $username = $request_bot_name;
    } else {
        // Default / General (Clock Events, Sales, Admin System Action)
        $webhooks_to_send[] = $general_webhook_url;
    }

    // --- Critical Fallback Check ---
    // If no specific webhook was configured/used, ensure General Webhook is selected
    if (empty($webhooks_to_send) || (count($webhooks_to_send) === 1 && strpos($webhooks_to_send[0], 'WEBHOOK_URL_HERE') !== false)) {
         $webhooks_to_send = [$general_webhook_url];
         $username = $general_bot_name;
    }

    // Definisikan warna untuk setiap tipe notifikasi
    $colors = [
        'info' => 3447003,    // Biru (contoh: Clock In/Out)
        'success' => 3066993, // Hijau (contoh: Disetujui)
        'warning' => 16776960,// Kuning (contoh: Permohonan Cuti)
        'danger' => 15158332, // Merah (contoh: Permohonan Resign, Ditolak)
        'employee_action' => 5793266, // Ungu (contoh: Perubahan Peran, Nonaktif Anggota)
        'sale_input' => 3066993, // Hijau (Input Penjualan)
        'sale_deleted' => 15548997, // Merah terang (Penghapusan penjualan)
        'salary_paid_single' => 3066993, // Hijau untuk gaji dibayar
        'salary_unpaid_single' => 16776960, // Kuning untuk gaji dibatalkan
        'salary_unpaid_all' => 16750899, // Orange/Merah untuk reset semua gaji
        'warning_letter_issued' => 16750899, // Orange untuk SP
        'warning_letter_deleted' => 15158332, // Merah untuk hapus SP
        'new_employee_request_submitted' => 3447003, // Biru untuk permintaan anggota baru
        'password_reset_request_submitted' => 16776960, // Kuning untuk permintaan reset password
        'refrigerator_deposit' => 3066993, 
        'refrigerator_withdraw' => 15158332,
        'warehouse_deposit' => 3066993,
        'warehouse_withdraw' => 15158332,
    ];
    $color = $colors[$type] ?? 0; // Ambil warna berdasarkan tipe, default hitam

    // Inisialisasi embed dasar
    $embed = [
        'title' => '',
        'description' => '',
        'color' => $color,
        'timestamp' => date('c'), // Waktu saat notifikasi dikirim
        'footer' => [
            'text' => 'Elysium Night Club Management System',
        ],
    ];

    // Logika untuk mengisi embed berdasarkan 'type' dan 'data'
    switch ($type) {
        case 'clock_event': // Data: ['employee_name', 'event_type', 'duration']
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $duty_start_time = date('H:i:s', strtotime($data['duty_start'] ?? ''));
            $duty_end_time = date('H:i:s', strtotime($data['duty_end'] ?? ''));

            if (($data['event_type'] ?? '') === 'clock_in') {
                $embed['title'] = "⏰ Karyawan Mulai Bertugas!";
                $embed['description'] = "**{$employee_name}** telah mulai bertugas.";
                $embed['color'] = $colors['info'];
                $embed['fields'] = [
                    ['name' => 'Waktu Mulai', 'value' => date('H:i:s'), 'inline' => true],
                    ['name' => 'Status', 'value' => '🟢 On Duty', 'inline' => true],
                ];
            } elseif (($data['event_type'] ?? '') === 'clock_out') {
                $embed['title'] = "⏸️ Karyawan Selesai Bertugas!";
                $embed['description'] = "Karyawan **{$employee_name}** telah selesai bertugas.";
                $embed['color'] = $colors['info'];
                $embed['fields'] = [
                    ['name' => 'Waktu Selesai', 'value' => date('H:i:s'), 'inline' => true],
                    ['name' => 'Durasi Tugas', 'value' => htmlspecialchars($data['duration'] ?? 'N/A'), 'inline' => true],
                    ['name' => 'Status', 'value' => '🔴 Off Duty', 'inline' => true],
                ];
            }
            break;

        case 'leave_request_submitted': 
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $embed['title'] = "📝 Permohonan Cuti Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan cuti.";
            $embed['color'] = $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Periode Cuti', 'value' => date('d/m/Y', strtotime($data['start_date'] ?? '')) . ' - ' . date('d/m/Y', strtotime($data['end_date'] ?? '')), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Alasan (OOC)', 'value' => empty($data['reason_ooc']) ? '-' : htmlspecialchars($data['reason_ooc'])],
                ['name' => 'Alasan (IC)', 'value' => empty($data['reason_ic']) ? '-' : htmlspecialchars($data['reason_ic'])],
            ];
            break;
        
        case 'resignation_request_submitted':
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $embed['title'] = "📄 Permohonan Resign Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan resign.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Tanggal Resign', 'value' => date('d/m/Y', strtotime($data['resignation_date'] ?? '')), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Passport', 'value' => htmlspecialchars($data['passport'] ?? 'N/A'), 'inline' => true],
                ['name' => 'CID', 'value' => htmlspecialchars($data['cid'] ?? 'N/A'), 'inline' => true],
                ['name' => 'Alasan (OOC)', 'value' => empty($data['reason_ooc']) ? '-' : htmlspecialchars($data['reason_ooc'])],
                ['name' => 'Alasan (IC)', 'value' => empty($data['reason_ic']) ? '-' : htmlspecialchars($data['reason_ic'])],
            ];
            break;

        case 'manual_duty_request_submitted':
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $embed['title'] = "⏱️ Permohonan Input Jam Manual Baru!";
            $embed['description'] = "Karyawan **{$employee_name}** telah mengajukan permohonan input jam manual.";
            $embed['color'] = $colors['info'];
            $embed['fields'] = [
                ['name' => 'Tanggal', 'value' => date('d/m/Y', strtotime($data['duty_date'] ?? '')), 'inline' => true],
                ['name' => 'Periode Waktu', 'value' => date('H:i', strtotime($data['start_time'] ?? '')) . ' - ' . date('H:i', strtotime($data['end_time'] ?? '')), 'inline' => true],
                ['name' => 'Durasi', 'value' => htmlspecialchars($data['duration_text'] ?? 'N/A'), 'inline' => true],
                ['name' => 'Status', 'value' => '🟡 Pending', 'inline' => true],
                ['name' => 'Alasan', 'value' => empty($data['reason']) ? '-' : htmlspecialchars($data['reason'])],
            ];
            break;

        case 'request_status_update':
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $approver_name = htmlspecialchars($data['approved_by_name'] ?? 'N/A');
            $status_text = '';
            $icon = '';
            $color_status = $colors['info'];

            if (($data['status'] ?? '') === 'approved') {
                $status_text = 'Disetujui';
                $icon = '✅';
                $color_status = $colors['success'];
            } elseif (($data['status'] ?? '') === 'rejected') {
                $status_text = 'Ditolak';
                $icon = '❌';
                $color_status = $colors['danger'];
            }
            
            $embed['title'] = "{$icon} Permohonan " . htmlspecialchars($data['request_type'] ?? 'N/A') . " Diperbarui!";
            $embed['description'] = "Permohonan **" . htmlspecialchars($data['request_type'] ?? 'N/A') . "** dari **{$employee_name}** telah **{$status_text}** oleh **{$approver_name}**.";
            $embed['color'] = $color_status;
            $embed['fields'] = [
                ['name' => 'Karyawan', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Status', 'value' => "{$icon} {$status_text}", 'inline' => true],
                ['name' => 'Diproses Oleh', 'value' => $approver_name, 'inline' => true],
            ];
            break;

        case 'admin_employee_action':
            $admin_name = htmlspecialchars($data['admin_name'] ?? 'N/A');
            $target_name = htmlspecialchars($data['target_employee_name'] ?? 'N/A');
            $embed['color'] = $colors['employee_action'];
            
            if (($data['action_type'] ?? '') === 'update_role') {
                $old_role_display = getRoleDisplayName($data['old_value'] ?? 'N/A');
                $new_role_display = getRoleDisplayName($data['new_value'] ?? 'N/A');
                $embed['title'] = "👥 Perubahan Jabatan Anggota!";
                $embed['description'] = "Jabatan **{$target_name}** telah diubah oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Jabatan Lama', 'value' => $old_role_display, 'inline' => true],
                    ['name' => 'Jabatan Baru', 'value' => $new_role_display, 'inline' => true],
                ];
            } elseif (($data['action_type'] ?? '') === 'deactivate_employee') {
                $embed['title'] = "⛔ Anggota Dinonaktifkan!";
                $embed['description'] = "Anggota **{$target_name}** telah dinonaktifkan oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Status', 'value' => '🔴 Tidak Aktif', 'inline' => true],
                ];
            } elseif (($data['action_type'] ?? '') === 'add_employee') {
                $role_display = getRoleDisplayName($data['role'] ?? 'N/A');
                $embed['title'] = "➕ Anggota Baru Ditambahkan!";
                $embed['description'] = "Anggota baru **{$target_name}** ({$role_display}) telah ditambahkan oleh **{$admin_name}**.";
                $embed['fields'] = [
                    ['name' => 'Nama Anggota', 'value' => $target_name, 'inline' => true],
                    ['name' => 'Jabatan', 'value' => $role_display, 'inline' => true],
                    ['name' => 'Ditambahkan Oleh', 'value' => $admin_name, 'inline' => true],
                ];
            }
            break;

        case 'admin_system_action':
            $admin_name = htmlspecialchars($data['admin_name'] ?? 'N/A');
            $embed['color'] = $colors['employee_action'];
            if (($data['action_type'] ?? '') === 'reset_weekly_data') {
                $embed['title'] = "🔄 Data Mingguan Direset!";
                $embed['description'] = "Data jam tugas dan penjualan mingguan telah direset oleh **{$admin_name}**.";
            }
            break;

        case 'sale_input':
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $sake = $data['paket_sake'] ?? 0;
            $anggur_merah = $data['paket_anggur_merah'] ?? 0;
            $tuak = $data['paket_tuak'] ?? 0;
            $soju = $data['paket_soju'] ?? 0;
            $spicy1 = $data['paket_spicy_1'] ?? 0;
            $spicy2 = $data['paket_spicy_2'] ?? 0;
            $spicy3 = $data['paket_spicy_3'] ?? 0;
            
            $total_items_sold = $sake + $anggur_merah + $tuak + $soju + $spicy1 + $spicy2 + $spicy3;

            $embed['title'] = "💰 Data Penjualan Baru Diinput!";
            $embed['description'] = "**{$employee_name}** telah menginput data penjualan.";
            $embed['color'] = $colors['success'];
            $embed['fields'] = [
                ['name' => 'Tanggal', 'value' => date('d/m/Y', strtotime($data['date'] ?? '')), 'inline' => true],
                ['name' => 'Waktu Input', 'value' => date('H:i:s', strtotime($data['input_time'] ?? '')), 'inline' => true],
            ];

            if ($sake > 0) $embed['fields'][] = ['name' => 'Sake', 'value' => $sake, 'inline' => true];
            if ($anggur_merah > 0) $embed['fields'][] = ['name' => 'Anggur Merah', 'value' => $anggur_merah, 'inline' => true];
            if ($tuak > 0) $embed['fields'][] = ['name' => 'Tuak', 'value' => $tuak, 'inline' => true];
            if ($soju > 0) $embed['fields'][] = ['name' => 'Soju', 'value' => $soju, 'inline' => true];
            if ($spicy1 > 0) $embed['fields'][] = ['name' => 'Spicy 1', 'value' => $spicy1, 'inline' => true];
            if ($spicy2 > 0) $embed['fields'][] = ['name' => 'Spicy 2', 'value' => $spicy2, 'inline' => true];
            if ($spicy3 > 0) $embed['fields'][] = ['name' => 'Spicy 3', 'value' => $spicy3, 'inline' => true];
            
            $embed['fields'][] = ['name' => 'Total Item Terjual', 'value' => $total_items_sold, 'inline' => false];
            break;
        
        case 'sale_deleted':
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $sales_date_time = htmlspecialchars($data['sales_date_time'] ?? 'N/A');
            
            $sake = $data['paket_sake'] ?? 0;
            $anggur_merah = $data['paket_anggur_merah'] ?? 0;
            $tuak = $data['paket_tuak'] ?? 0;
            $soju = $data['paket_soju'] ?? 0;
            $spicy1 = $data['paket_spicy_1'] ?? 0;
            $spicy2 = $data['paket_spicy_2'] ?? 0;
            $spicy3 = $data['paket_spicy_3'] ?? 0;
            
            $total_items_deleted = $sake + $anggur_merah + $tuak + $soju + $spicy1 + $spicy2 + $spicy3;

            $embed['title'] = "🗑️ Data Penjualan Dihapus!";
            $embed['description'] = "Data penjualan dari **{$employee_name}** pada **{$sales_date_time}** telah dihapus.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Waktu Input Asli', 'value' => $sales_date_time, 'inline' => true],
            ];
            $embed['fields'][] = ['name' => 'Total Item Dihapus', 'value' => $total_items_deleted, 'inline' => false];
            break;
            
        case 'refrigerator_deposit':
        case 'refrigerator_withdraw':
        case 'warehouse_deposit':
        case 'warehouse_withdraw':
            $is_deposit = (strpos($type, '_deposit') !== false);
            $is_refrigerator = (strpos($type, 'refrigerator') !== false);
            $stock_type_name = $is_refrigerator ? 'Kulkas' : 'Gudang';
            $action_text = $is_deposit ? 'DEPOSIT (Masuk)' : 'WITHDRAW (Keluar)';
            $icon = $is_deposit ? '➕' : '➖';
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $product_list = $data['product_list'] ?? [];

            $embed['title'] = "{$icon} Transaksi Stok {$stock_type_name}: {$action_text}!";
            $embed['description'] = "**{$employee_name}** telah melakukan transaksi stok {$stock_type_name}.";
            $embed['color'] = $is_deposit ? $colors['success'] : $colors['danger'];
            
            $fields = [
                ['name' => 'Dilakukan Oleh', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Waktu', 'value' => date('H:i:s'), 'inline' => true],
            ];
            $detail_list = "";
            foreach ($product_list as $product => $qty) {
                $detail_list .= "- " . htmlspecialchars(str_replace('_', ' ', $product)) . " (`{$qty}`)\n";
            }
            $fields[] = ['name' => 'Detail Item:', 'value' => trim($detail_list)];

            $embed['fields'] = $fields;
            break;

        case 'room_booking_submitted':
            $room_id = htmlspecialchars($data['room_id'] ?? 'N/A');
            $booking_name = htmlspecialchars($data['booking_name'] ?? 'N/A');
            $booking_datetime = htmlspecialchars($data['booking_datetime'] ?? 'N/A');
            $embed['title'] = "🛎️ Permintaan Booking Ruangan Baru!";
            $embed['description'] = "Permintaan booking ruangan baru telah diajukan oleh **{$booking_name}**.";
            $embed['color'] = $colors['info'];
            $embed['fields'] = [
                ['name' => 'Nama Pemesan', 'value' => $booking_name, 'inline' => true],
                ['name' => 'ID Ruangan', 'value' => $room_id, 'inline' => true],
                ['name' => 'Tanggal & Waktu', 'value' => date('d/m/Y H:i', strtotime($booking_datetime)), 'inline' => false],
            ];
            break;

        case 'booking_status_updated':
            $room_name = htmlspecialchars($data['room_name'] ?? 'N/A');
            $booking_name = htmlspecialchars($data['booking_name'] ?? 'N/A');
            $admin_name = htmlspecialchars($data['admin_name'] ?? 'N/A');
            $action = htmlspecialchars($data['action'] ?? 'N/A');

            $status_text = '';
            $status_icon = '';
            $color = $colors['info'];

            if ($action === 'approve_booking') {
                $status_text = 'disetujui';
                $status_icon = '✅';
                $color = $colors['success'];
            } elseif ($action === 'decline_booking') {
                $status_text = 'ditolak';
                $status_icon = '❌';
                $color = $colors['danger'];
            } elseif ($action === 'mark_as_used') {
                $status_text = 'selesai digunakan';
                $status_icon = '✔️';
                $color = $colors['info'];
            }

            $embed['title'] = "{$status_icon} Status Booking Diperbarui!";
            $embed['description'] = "Booking ruangan **{$room_name}** dari **{$booking_name}** telah **{$status_text}** oleh **{$admin_name}**.";
            $embed['color'] = $color;
            $embed['fields'] = [
                ['name' => 'Ruangan', 'value' => $room_name, 'inline' => true],
                ['name' => 'Pemesan', 'value' => $booking_name, 'inline' => true],
                ['name' => 'Diproses Oleh', 'value' => $admin_name, 'inline' => true],
            ];
            break;

        case 'payment_status_updated':
            $room_name = htmlspecialchars($data['room_name'] ?? 'N/A');
            $booking_name = htmlspecialchars($data['booking_name'] ?? 'N/A');
            $admin_name = htmlspecialchars($data['admin_name'] ?? 'N/A');
            $action = htmlspecialchars($data['action'] ?? 'N/A');
            
            $status_text = '';
            $status_icon = '';
            $color = $colors['info'];
            
            if ($action === 'mark_dp_paid') {
                $status_text = 'Pembayaran DP telah lunas';
                $status_icon = '💰';
                $color = $colors['warning'];
            } elseif ($action === 'mark_full_paid') {
                $status_text = 'Pembayaran penuh telah lunas';
                $status_icon = '✅';
                $color = $colors['success'];
            }
            
            $embed['title'] = "{$status_icon} Status Pembayaran Booking Diperbarui!";
            $embed['description'] = "Status pembayaran untuk booking ruangan **{$room_name}** dari **{$booking_name}** telah diperbarui menjadi **{$status_text}** oleh **{$admin_name}**.";
            $embed['color'] = $color;
            $embed['fields'] = [
                ['name' => 'Ruangan', 'value' => $room_name, 'inline' => true],
                ['name' => 'Pemesan', 'value' => $booking_name, 'inline' => true],
                ['name' => 'Diproses Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Status Terbaru', 'value' => $status_text, 'inline' => false],
            ];
            break;
            
        case 'duty_log_deleted': // Perbaiki format display mentah
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $admin_name = htmlspecialchars($data['admin_name'] ?? 'N/A');
            $duty_start_time = date('d/m/Y H:i', strtotime($data['duty_start'] ?? ''));
            $duty_end_time = ($data['duty_end'] ?? null) ? date('H:i', strtotime($data['duty_end'])) : 'Belum Selesai';
            $duration_display = formatDuration($data['duration_minutes'] ?? 0);

            $embed['title'] = "🗑️ Log Jam Kerja Dihapus!";
            $embed['description'] = "Log jam kerja anggota **{$employee_name}** telah dihapus oleh **{$admin_name}**.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Dihapus Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Tanggal & Waktu Mulai', 'value' => $duty_start_time, 'inline' => false],
                ['name' => 'Waktu Selesai (estimasi)', 'value' => $duty_end_time, 'inline' => true],
                ['name' => 'Durasi (estimasi)', 'value' => $duration_display, 'inline' => true],
                ['name' => 'Saran', 'value' => "Mohon informasikan anggota tersebut untuk mengajukan `Input Jam Manual` jika periode ini perlu dicatat ulang.", 'inline' => false]
            ];
            break;

        case 'warning_letter_deleted': 
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $admin_name = htmlspecialchars($data['admin_name'] ?? 'N/A');
            $embed['title'] = "🗑️ Surat Peringatan Dihapus!";
            $embed['description'] = "Surat Peringatan untuk **{$employee_name}** telah dihapus oleh **{$admin_name}**.";
            $embed['color'] = $colors['danger'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Dihapus Oleh', 'value' => $admin_name, 'inline' => true]
            ];
            break;

        case 'warning_letter_issued': 
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $admin_name = htmlspecialchars($data['admin_name'] ?? 'N/A');
            $sp_type = htmlspecialchars($data['sp_type'] ?? 'N/A');
            $reason = htmlspecialchars($data['reason'] ?? 'N/A');
            $embed['title'] = "⚠️ Surat Peringatan Dikeluarkan!";
            $embed['description'] = "Surat Peringatan **{$sp_type}** untuk **{$employee_name}** telah dikeluarkan oleh **{$admin_name}**.";
            $embed['color'] = $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Anggota', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Tipe SP', 'value' => $sp_type, 'inline' => true],
                ['name' => 'Dikeluarkan Oleh', 'value' => $admin_name, 'inline' => true],
                ['name' => 'Alasan', 'value' => $reason, 'inline' => false]
            ];
            break;

        case 'new_employee_request_submitted':
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $new_employee_name = htmlspecialchars($data['new_employee_name'] ?? 'N/A');
            $requested_role = htmlspecialchars($data['requested_role'] ?? 'N/A');
            $embed['title'] = "➕ Permintaan Anggota Baru!";
            $embed['description'] = "Permintaan untuk menambahkan anggota baru **{$new_employee_name}** ({$requested_role}) telah diajukan oleh **{$employee_name}**.";
            $embed['color'] = $colors['info'];
            $embed['fields'] = [
                ['name' => 'Diajukan Oleh', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Nama Anggota Baru', 'value' => $new_employee_name, 'inline' => true],
                ['name' => 'Jabatan', 'value' => getRoleDisplayName($requested_role), 'inline' => true],
            ];
            break;

        case 'password_reset_request_submitted':
            $employee_name = htmlspecialchars($data['employee_name'] ?? 'N/A');
            $target_employee_name = htmlspecialchars($data['target_employee_name'] ?? 'N/A');
            $reset_type_text = ($data['reset_type'] ?? '') === 'new' ? 'Kata Sandi Baru' : 'Kata Sandi Default';
            $embed['title'] = "🔄 Permintaan Reset Kata Sandi!";
            $embed['description'] = "Permintaan reset kata sandi untuk **{$target_employee_name}** telah diajukan oleh **{$employee_name}**.";
            $embed['color'] = $colors['warning'];
            $embed['fields'] = [
                ['name' => 'Diajukan Oleh', 'value' => $employee_name, 'inline' => true],
                ['name' => 'Untuk Anggota', 'value' => $target_employee_name, 'inline' => true],
                ['name' => 'Tipe Reset', 'value' => $reset_type_text, 'inline' => true],
            ];
            break;
            
        default:
            // Fallback for unrecognized messages
            $embed['title'] = "ℹ️ Notifikasi Umum";
            // Konten mentah hanya akan dikirim jika data adalah array yang tidak dikenali
            $embed['description'] = htmlspecialchars(is_array($data) ? json_encode($data) : $data);
            $embed['color'] = $colors['info'];
            break;
    }
    
    // Payload akhir untuk Discord Webhook
    $discord_payload = json_encode([
        'username' => $username,
        'avatar_url' => $avatar_url,
        'embeds' => [$embed],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => $discord_payload,
        ],
    ];

    // Kirim notifikasi ke setiap URL webhook yang relevan
    foreach ($webhooks_to_send as $webhook_url) {
        $context = stream_context_create($options);
        // Gunakan '@' untuk menekan error
        @file_get_contents($webhook_url, false, $context);
    }
}
?>