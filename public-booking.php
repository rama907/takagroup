<?php
require_once 'config.php';

$success = null;
$error = null;

// Handle booking request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'book_room') {
    $room_id = (int)($_POST['room_id'] ?? 0);
    $booking_name = $_POST['booking_name'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $booking_purpose = $_POST['booking_purpose'] ?? '';
    $booking_date = $_POST['booking_date'] ?? '';
    $booking_time = $_POST['booking_time'] ?? '';

    if ($room_id <= 0 || empty($booking_name) || empty($phone_number) || empty($booking_date) || empty($booking_time)) {
        $error = "Semua field wajib diisi.";
    } else {
        // Cek apakah ruangan sudah dibooking pada tanggal dan jam yang sama
        $stmt_check = $conn->prepare("
            SELECT id FROM room_bookings
            WHERE room_id = ? AND booking_date = ? AND booking_time = ? AND booking_status = 'approved'
        ");
        if ($stmt_check) {
            $stmt_check->bind_param("iss", $room_id, $booking_date, $booking_time);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
    
            if ($result_check->num_rows > 0) {
                $error = "Maaf, ruangan ini sudah dibooking pada tanggal dan jam tersebut.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO room_bookings (room_id, booking_name, phone_number, booking_purpose, booking_date, booking_time)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                if ($stmt) {
                    $stmt->bind_param("isssss", $room_id, $booking_name, $phone_number, $booking_purpose, $booking_date, $booking_time);
                    if ($stmt->execute()) {
                        $success = "Pemesanan ruangan berhasil diajukan! Menunggu persetujuan admin.";
                        // Kirim notifikasi ke Discord (admin)
                        sendDiscordNotification([
                            'room_id' => $room_id,
                            'booking_name' => $booking_name,
                            'booking_datetime' => $booking_date . ' ' . $booking_time,
                        ], 'room_booking_submitted');
                    } else {
                        $error = "Gagal melakukan pemesanan: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Gagal menyiapkan query: " . $conn->error;
                }
            }
            $stmt_check->close();
        } else {
            $error = "Gagal menyiapkan query cek: " . $conn->error;
        }
    }
}

// Get all rooms for display
$rooms = $conn->query("SELECT * FROM rooms ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Ruangan - Galaxy Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .centered-page-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .page-content-wrapper {
            max-width: 900px;
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .public-booking-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--spacing-xl);
        }
        .room-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            padding: var(--spacing-xl);
        }
        .room-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: var(--spacing-md);
            color: var(--primary-color);
        }
        .room-card .price-info {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: var(--spacing-md);
        }
        .room-card .details-list {
            list-style: none;
            padding: 0;
            margin: 0 0 var(--spacing-xl) 0;
        }
        .room-card .details-list li {
            margin-bottom: var(--spacing-xs);
            color: var(--text-secondary);
        }
        .booking-form .form-group {
            margin-bottom: var(--spacing-md);
        }
        .booking-form .form-row {
            display: flex;
            gap: var(--spacing-md);
        }
        .booking-form .form-row .form-group {
            flex: 1;
        }
    </style>
</head>
<body class="login-body">
    <main class="main-content">
        <div class="centered-page-container">
            <div class="page-content-wrapper">
                <div class="page-header">
                    <h1>
                        <span class="page-icon">🗓️</span>
                        Booking Ruangan Galaxy Night Club
                    </h1>
                    <p>Lihat detail ruangan dan ajukan pemesanan. Kami akan segera menghubungi Anda untuk konfirmasi.</p>
                </div>

                <?php if (isset($success)): ?>
                    <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
    
                <div class="public-booking-grid">
                    <?php foreach ($rooms as $room): ?>
                    <div class="room-card">
                        <h3><?= htmlspecialchars($room['room_name']) ?></h3>
                        <p style="color: var(--text-muted);"><?= htmlspecialchars($room['description'] ?? '') ?></p>
                        <div class="price-info">
                            Harga: <?= htmlspecialchars($room['price_info'] ?? '') ?>
                        </div>
                        <ul class="details-list">
                            <li><strong>Fasilitas:</strong> <?= htmlspecialchars($room['facilities'] ?? 'N/A') ?></li>
                            <li><strong>Ketentuan:</strong> <?= nl2br(htmlspecialchars($room['rules'] ?? 'Tidak ada ketentuan khusus.')) ?></li>
                        </ul>

                        <form method="POST" class="booking-form">
                            <input type="hidden" name="action" value="book_room">
                            <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                            
                            <div class="form-group">
                                <label for="booking_name_<?= $room['id'] ?>">Nama Anda</label>
                                <input type="text" name="booking_name" id="booking_name_<?= $room['id'] ?>" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone_number_<?= $room['id'] ?>">Nomor HP</label>
                                <input type="tel" name="phone_number" id="phone_number_<?= $room['id'] ?>" class="form-input" placeholder="08xxxxxxxxxx" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="booking_purpose_<?= $room['id'] ?>">Tujuan Booking</label>
                                <textarea name="booking_purpose" id="booking_purpose_<?= $room['id'] ?>" rows="2" class="form-textarea" placeholder="Contoh: Ulang tahun teman, meeting, dll."></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="booking_date_<?= $room['id'] ?>">Tanggal</label>
                                    <input type="date" name="booking_date" id="booking_date_<?= $room['id'] ?>" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label for="booking_time_<?= $room['id'] ?>">Jam</label>
                                    <input type="time" name="booking_time" id="booking_time_<?= $room['id'] ?>" class="form-input" required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Pesan Sekarang</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
</body>
</html>