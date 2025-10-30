<?php
// File: api/duty_action.php
// API Endpoint yang dipanggil oleh Discord Bot untuk Clock In/Out.

header('Content-Type: application/json');
// Sesuaikan path jika file config Anda berada di tempat lain (asumsi di root)
require_once '../config.php'; 

// --- Utility Function ---
function send_json_response($status, $message, $data = []) {
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// --- 1. Ambil Data dan Kunci Rahasia ---
$input = file_get_contents('php://input');
$request_data = json_decode($input, true);

// Data yang diharapkan dari Bot
$secret_key = $request_data['api_key'] ?? null;
$discord_id = $request_data['discord_id'] ?? null; // ID Discord pengguna
$action = strtolower($request_data['action'] ?? ''); // 'clock_in' atau 'clock_out'

// --- 2. Autentikasi API Key ---
// Periksa apakah API_SECRET_KEY sama dengan yang dikirim Bot
if ($secret_key !== API_SECRET_KEY) {
    // FIX PENTING: Periksa apakah API_SECRET_KEY di config.php masih default
    $security_check = (API_SECRET_KEY === 'oxIRDdPa8wsfx6xYJO1IHxr6RiXFsGKf') ? 
        'Error: Default API Key is still set in config.php. Please change it.' : 
        'Invalid API Key.';
        
    send_json_response('error', 'Unauthorized: ' . $security_check, ['code' => 401]);
}

if (empty($discord_id)) {
    send_json_response('error', 'Discord User ID is required.', ['code' => 400]);
}
if ($action !== 'clock_in' && $action !== 'clock_out') {
    send_json_response('error', 'Invalid action specified. Must be "clock_in" or "clock_out".', ['code' => 400]);
}

// --- 3. Verifikasi Karyawan melalui Discord ID ---
$employee_data = getEmployeeByDiscordId($discord_id);

if (!$employee_data) {
    send_json_response('error', 'Employee not found or Discord ID not mapped in the database. Please contact HR.', ['code' => 404]);
}

$employee_id = $employee_data['id'];
$employee_name = $employee_data['name'];
$is_on_duty = (bool)$employee_data['is_on_duty'];

$conn->begin_transaction();

try {
    if ($action === 'clock_in') {
        // --- LOGIC: CLOCK IN ---
        if ($is_on_duty) {
            send_json_response('warning', "{$employee_name} sudah terhitung On Duty.", ['state' => 'already_on']);
        }

        // 1. Update status employee
        $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = TRUE, current_duty_start = NOW() WHERE id = ?");
        $stmt_update_employee->bind_param("i", $employee_id);
        $stmt_update_employee->execute();
        $stmt_update_employee->close();

        // 2. Insert new duty log (manual clock-in via bot)
        $stmt_insert_log = $conn->prepare("INSERT INTO duty_logs (employee_id, duty_start, is_manual, status) VALUES (?, NOW(), 1, 'active')");
        $stmt_insert_log->bind_param("i", $employee_id);
        $stmt_insert_log->execute();
        $stmt_insert_log->close();
        
        $conn->commit();
        
        // Kirim notifikasi Discord (satu arah)
        sendDiscordNotification(['employee_name' => $employee_name, 'event_type' => 'clock_in'], 'clock_event');

        send_json_response('success', "On Duty berhasil! Selamat bertugas, {$employee_name}.", ['state' => 'clocked_in']);

    } elseif ($action === 'clock_out') {
        // --- LOGIC: CLOCK OUT ---
        if (!$is_on_duty) {
            send_json_response('warning', "{$employee_name} sudah terhitung Off Duty. Tidak ada shift aktif.", ['state' => 'already_off']);
        }

        // 1. Cari log duty aktif
        $stmt_get_log = $conn->prepare("SELECT id, duty_start FROM duty_logs WHERE employee_id = ? AND duty_end IS NULL ORDER BY id DESC LIMIT 1");
        $stmt_get_log->bind_param("i", $employee_id);
        $stmt_get_log->execute();
        $active_log = $stmt_get_log->get_result()->fetch_assoc();
        $stmt_get_log->close();

        if ($active_log) {
            $duty_start_dt = new DateTime($active_log['duty_start']);
            $now_dt = new DateTime();
            $duration_minutes = ($now_dt->getTimestamp() - $duty_start_dt->getTimestamp()) / 60;

            // 2. Update log duty (status completed)
            $stmt_update_log = $conn->prepare("UPDATE duty_logs SET duty_end = NOW(), duration_minutes = ?, status = 'completed', approved_by = 0 WHERE id = ?"); // approved_by=0 signifies bot action
            $stmt_update_log->bind_param("ii", $duration_minutes, $active_log['id']);
            $stmt_update_log->execute();
            $stmt_update_log->close();
            
            $duration_text = formatDuration($duration_minutes);
        } else {
            $duration_text = 'N/A (Log tidak ditemukan, hanya update status)';
        }
        
        // 3. Update status employee
        $stmt_update_employee = $conn->prepare("UPDATE employees SET is_on_duty = FALSE, current_duty_start = NULL WHERE id = ?");
        $stmt_update_employee->bind_param("i", $employee_id);
        $stmt_update_employee->execute();
        $stmt_update_employee->close();
        
        $conn->commit();
        
        // Kirim notifikasi Discord
        sendDiscordNotification(['employee_name' => $employee_name, 'event_type' => 'clock_out', 'duration' => $duration_text], 'clock_event');

        send_json_response('success', "Off Duty berhasil! Total waktu tugas: {$duration_text}.", ['state' => 'clocked_out', 'duration' => $duration_text]);
    }

} catch (Exception $e) {
    $conn->rollback();
    error_log("API Error: " . $e->getMessage());
    send_json_response('error', "Internal server error during transaction: " . $e->getMessage(), ['code' => 500]);
}

?>