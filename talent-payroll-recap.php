<?php
// File: talent-payroll-recap.php
// Skrip untuk mengakumulasi Talent Share Mingguan dan mencatatnya ke tabel rekap.

// Hanya izinkan akses jika dipost dari management page, atau jika diakses dari CLI (Cron)
if (php_sapi_name() !== 'cli' && (!isset($_POST['action']) || $_POST['action'] !== 'recap_now')) {
    http_response_code(403);
    die("Access denied.");
}

require_once 'config.php'; 

// Fungsi utilitas untuk redirect dengan pesan
function redirect_with_message($message, $type) {
    if (php_sapi_name() !== 'cli') {
        header("Location: talent-management.php?msg=" . urlencode($message) . "&type=" . urlencode($type));
        exit;
    } else {
        echo "LOG: " . $type . " - " . $message . "\n";
    }
}

$conn->begin_transaction();
$log_messages = [];

try {
    // Tentukan periode minggu lalu: Selasa Minggu Lalu - Senin Kemarin
    // Karena Talent Pay dihitung dari Sel-Senin.

    // Senin kemarin (End Date)
    $week_end_obj = (new DateTime('yesterday')); 
    // Selasa 6 hari sebelumnya (Start Date)
    $week_start_obj = (clone $week_end_obj)->modify('-6 days'); 

    $week_start_date = $week_start_obj->format('Y-m-d');
    $week_end_date = $week_end_obj->format('Y-m-d');

    // Cek apakah sudah ada rekap untuk minggu ini (Selasa - Senin). Jika ada, jangan proses.
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM talent_weekly_salary_recap WHERE week_start = ? AND week_end = ?");
    if (!$check_stmt) { throw new Exception("Prepare check query failed: " . $conn->error); }
    $check_stmt->bind_param("ss", $week_start_date, $week_end_date);
    $check_stmt->execute();
    $recap_count = $check_stmt->get_result()->fetch_assoc()['count'];
    $check_stmt->close();

    if ($recap_count > 0) {
        throw new Exception("RECAP GAGAL: Data rekap gaji Talent untuk periode {$week_start_date} hingga {$week_end_date} sudah ada ({$recap_count} entri).");
    }

    // 1. Ambil semua Talent aktif
    $talents = getAllActiveTalents();
    if (empty($talents)) {
        throw new Exception("Tidak ada Talent aktif yang terdaftar untuk diproses.");
    }
    
    $processed_count = 0;
    
    // 2. Query untuk mengakumulasi total share Talent dari sales_table_room
    $sql_accumulate = "
        SELECT 
            str.employee_id,
            e.name as employee_name,
            COALESCE(SUM(str.talent_share), 0) as total_talent_share_accumulated
        FROM sales_table_room str
        JOIN employees e ON str.employee_id = e.id
        WHERE DATE(str.sale_date) BETWEEN ? AND ?
        GROUP BY str.employee_id, e.name
    ";

    $stmt_accumulate = $conn->prepare($sql_accumulate);
    if (!$stmt_accumulate) { throw new Exception("Prepare accumulate query failed: " . $conn->error); }
    $stmt_accumulate->bind_param("ss", $week_start_date, $week_end_date);
    $stmt_accumulate->execute();
    $accumulated_results = $stmt_accumulate->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_accumulate->close();

    $share_map = [];
    foreach ($accumulated_results as $row) {
        $share_map[$row['employee_id']] = $row;
    }
    
    // 3. Masukkan data ke tabel rekap (hanya untuk Talent yang ada di daftar aktif)
    $sql_insert = "
        INSERT INTO talent_weekly_salary_recap 
        (employee_id, employee_name, week_start, week_end, total_talent_share_accumulated)
        VALUES (?, ?, ?, ?, ?)
    ";
    $stmt_insert = $conn->prepare($sql_insert);
    if (!$stmt_insert) { throw new Exception("Prepare insert query failed: " . $conn->error); }

    foreach ($talents as $talent) {
        $employee_id = $talent['id'];
        $employee_name = $talent['name'];
        $total_share = (int)($share_map[$employee_id]['total_talent_share_accumulated'] ?? 0);

        // Hanya masukkan jika ada share atau jika Talent tetap ingin di-log
        if ($total_share >= 0) { 
            // Binding 5 parameter: i s s s i
            $stmt_insert->bind_param("isssi", 
                $employee_id,           
                $employee_name,         
                $week_start_date,       
                $week_end_date,         
                $total_share     
            );
            $stmt_insert->execute();
            $processed_count++;
        }
    }
    $stmt_insert->close();
    
    if ($processed_count === 0) {
        throw new Exception("Proses selesai, tetapi tidak ada transaksi Talent yang ditemukan dalam periode ini.");
    }

    $conn->commit();
    $message = "Berhasil memproses rekap gaji Talent untuk periode {$week_start_date} hingga {$week_end_date}. Total {$processed_count} Talent dicatat.";
    redirect_with_message($message, 'success');

} catch (Exception $e) {
    $conn->rollback();
    redirect_with_message($e->getMessage(), 'error');
}
?>