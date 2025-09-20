<?php
require_once 'config.php';

// Hanya direktur, wakil direktur, dan manager yang bisa mengakses halaman ini
if (!isLoggedIn() || !hasRole(['direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();

$success = null;
$error = null;

// Handle actions (Approve, Decline, Update Payment)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'] ?? '';
    $booking_id = (int)($_POST['booking_id'] ?? 0);

    if ($booking_id <= 0) {
        $error = "ID pemesanan tidak valid!";
    } else {
        $conn->begin_transaction();
        try {
            // Ambil data booking sebelum diupdate
            $stmt_get = $conn->prepare("
                SELECT rb.*, r.room_name
                FROM room_bookings rb
                JOIN rooms r ON rb.room_id = r.id
                WHERE rb.id = ?
            ");
            $stmt_get->bind_param("i", $booking_id);
            $stmt_get->execute();
            $booking_data = $stmt_get->get_result()->fetch_assoc();
            $stmt_get->close();

            if (!$booking_data) {
                throw new Exception("Pemesanan tidak ditemukan.");
            }

            $message = '';
            $status_to_update = '';

            switch ($action) {
                case 'approve_booking':
                    $status_to_update = 'approved';
                    $message = "Pemesanan ruangan `{$booking_data['room_name']}` oleh `{$booking_data['booking_name']}` berhasil disetujui.";
                    
                    $update_stmt = $conn->prepare("UPDATE room_bookings SET booking_status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("sii", $status_to_update, $user['id'], $booking_id);
                    if (!$update_stmt->execute()) { throw new Exception("Gagal mengupdate status: " . $update_stmt->error); }
                    $update_stmt->close();
                    
                    sendDiscordNotification([
                        'room_name' => $booking_data['room_name'],
                        'booking_name' => $booking_data['booking_name'],
                        'action' => $action,
                        'admin_name' => $user['name']
                    ], 'booking_status_updated');
                    break;

                case 'decline_booking':
                    $status_to_update = 'declined';
                    $message = "Pemesanan ruangan `{$booking_data['room_name']}` oleh `{$booking_data['booking_name']}` berhasil ditolak.";
                    
                    $update_stmt = $conn->prepare("UPDATE room_bookings SET booking_status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("sii", $status_to_update, $user['id'], $booking_id);
                    if (!$update_stmt->execute()) { throw new Exception("Gagal mengupdate status: " . $update_stmt->error); }
                    $update_stmt->close();

                    sendDiscordNotification([
                        'room_name' => $booking_data['room_name'],
                        'booking_name' => $booking_data['booking_name'],
                        'action' => $action,
                        'admin_name' => $user['name']
                    ], 'booking_status_updated');
                    break;

                case 'mark_dp_paid':
                    $payment_status_to_update = 'dp_paid';
                    $message = "Status pembayaran DP untuk pemesanan `{$booking_data['room_name']}` oleh `{$booking_data['booking_name']}` berhasil diperbarui.";
                    
                    $update_stmt = $conn->prepare("UPDATE room_bookings SET payment_status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("sii", $payment_status_to_update, $user['id'], $booking_id);
                    if (!$update_stmt->execute()) { throw new Exception("Gagal mengupdate status pembayaran: " . $update_stmt->error); }
                    $update_stmt->close();
                    
                    sendDiscordNotification([
                        'room_name' => $booking_data['room_name'],
                        'booking_name' => $booking_data['booking_name'],
                        'action' => $action,
                        'admin_name' => $user['name']
                    ], 'payment_status_updated');
                    break;

                case 'mark_full_paid':
                    $payment_status_to_update = 'full_paid';
                    $message = "Status pembayaran penuh untuk pemesanan `{$booking_data['room_name']}` oleh `{$booking_data['booking_name']}` berhasil diperbarui.";
                    
                    $update_stmt = $conn->prepare("UPDATE room_bookings SET payment_status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("sii", $payment_status_to_update, $user['id'], $booking_id);
                    if (!$update_stmt->execute()) { throw new Exception("Gagal mengupdate status pembayaran: " . $update_stmt->error); }
                    $update_stmt->close();

                    sendDiscordNotification([
                        'room_name' => $booking_data['room_name'],
                        'booking_name' => $booking_data['booking_name'],
                        'action' => $action,
                        'admin_name' => $user['name']
                    ], 'payment_status_updated');
                    break;

                case 'mark_as_used':
                    $status_to_update = 'used';
                    $message = "Pemesanan ruangan `{$booking_data['room_name']}` oleh `{$booking_data['booking_name']}` telah selesai digunakan.";
                    
                    $update_stmt = $conn->prepare("UPDATE room_bookings SET booking_status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    $update_stmt->bind_param("sii", $status_to_update, $user['id'], $booking_id);
                    if (!$update_stmt->execute()) { throw new Exception("Gagal mengupdate status: " . $update_stmt->error); }
                    $update_stmt->close();
                    
                    sendDiscordNotification([
                        'room_name' => $booking_data['room_name'],
                        'booking_name' => $booking_data['booking_name'],
                        'action' => $action,
                        'admin_name' => $user['name']
                    ], 'booking_status_updated');
                    break;
                default:
                    throw new Exception("Aksi tidak dikenal.");
            }
            
            $conn->commit();
            $success = $message;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Terjadi kesalahan: " . $e->getMessage();
        }
    }
}

// Get all bookings for display
$all_bookings = [];
$query = "
    SELECT rb.*, r.room_name, e.name AS updated_by_name
    FROM room_bookings rb
    JOIN rooms r ON rb.room_id = r.id
    LEFT JOIN employees e ON rb.updated_by = e.id
    ORDER BY rb.created_at DESC
";
$result = $conn->query($query);
if ($result instanceof mysqli_result) {
    $all_bookings = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
} else {
    $error = "Gagal mengambil data pemesanan: " . $conn->error;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Pemesanan - Galaxy Night Club</title>
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
                    <span class="page-icon">📅</span>
                    Kelola Pemesanan Ruangan
                </h1>
                <p>Pusat pengelolaan semua permintaan dan status pemesanan ruangan.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Daftar Semua Pemesanan</h3>
                </div>
                <div class="card-content">
                    <?php if (empty($all_bookings)): ?>
                        <div class="no-data">Belum ada pemesanan ruangan.</div>
                    <?php else: ?>
                        <div class="responsive-table-container">
                            <table class="activities-table-improved">
                                <thead>
                                    <tr>
                                        <th>Ruangan</th>
                                        <th>Tanggal & Waktu</th>
                                        <th>Nama Pemesan</th>
                                        <th>Nomor HP</th>
                                        <th>Tujuan</th>
                                        <th>Status Booking</th>
                                        <th>Status Bayar</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_bookings as $booking): ?>
                                    <tr>
                                        <td data-label="Ruangan"><strong><?= htmlspecialchars($booking['room_name']) ?></strong></td>
                                        <td data-label="Tanggal & Waktu"><?= date('d/m/Y H:i', strtotime($booking['booking_date'] . ' ' . $booking['booking_time'])) ?></td>
                                        <td data-label="Nama Pemesan">
                                            <?= htmlspecialchars($booking['booking_name']) ?>
                                        </td>
                                        <td data-label="Nomor HP"><?= htmlspecialchars($booking['phone_number']) ?></td>
                                        <td data-label="Tujuan"><?= htmlspecialchars($booking['booking_purpose'] ?? '-') ?></td>
                                        <td data-label="Status Booking">
                                            <span class="status-badge status-<?= strtolower($booking['booking_status']) ?>">
                                                <?= ucfirst(str_replace('_', ' ', $booking['booking_status'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Status Bayar">
                                            <span class="status-badge status-<?= strtolower($booking['payment_status']) ?>">
                                                <?= ucfirst(str_replace('_', ' ', $booking['payment_status'])) ?>
                                            </span>
                                        </td>
                                        <td data-label="Aksi" class="action-column">
                                            <?php if ($booking['booking_status'] === 'pending_approval'): ?>
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menyetujui pemesanan ini?')">
                                                <input type="hidden" name="action" value="approve_booking">
                                                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                <button type="submit" class="btn btn-success btn-sm">Setujui</button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Yakin ingin menolak pemesanan ini?')">
                                                <input type="hidden" name="action" value="decline_booking">
                                                <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Tolak</button>
                                            </form>
                                            <?php elseif ($booking['booking_status'] === 'approved'): ?>
                                                <?php if ($booking['payment_status'] === 'pending'): ?>
                                                <form method="POST" onsubmit="return confirm('Tandai sebagai DP terbayar?')">
                                                    <input type="hidden" name="action" value="mark_dp_paid">
                                                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                    <button type="submit" class="btn btn-warning btn-sm">Bayar DP</button>
                                                </form>
                                                <?php endif; ?>
                                                <?php if ($booking['payment_status'] !== 'full_paid'): ?>
                                                <form method="POST" onsubmit="return confirm('Tandai sebagai sudah lunas?')">
                                                    <input type="hidden" name="action" value="mark_full_paid">
                                                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm">Lunas</button>
                                                </form>
                                                <?php endif; ?>
                                                <form method="POST" onsubmit="return confirm('Tandai pemesanan ini sudah selesai digunakan?')">
                                                    <input type="hidden" name="action" value="mark_as_used">
                                                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                                                    <button type="submit" class="btn btn-secondary btn-sm">Selesai</button>
                                                </form>
                                            <?php endif; ?>
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
</body>
</html>