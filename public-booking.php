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

// --- Function to fetch room data including multiple images ---
$rooms_data = [];
$rooms_result = $conn->query("SELECT id, room_name, description, price_info, facilities, rules FROM rooms ORDER BY id ASC");

if ($rooms_result) {
    while ($room = $rooms_result->fetch_assoc()) {
        $room_id = $room['id'];
        
        // Fetch all images for this room
        $images_result_stmt = $conn->prepare("SELECT image_path FROM room_images WHERE room_id = ? ORDER BY sort_order ASC, id ASC");
        
        if ($images_result_stmt) {
            $images_result_stmt->bind_param("i", $room_id);
            $images_result_stmt->execute();
            $room['images'] = $images_result_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $images_result_stmt->close();
        } else {
            // Jika tabel belum ada (menyebabkan Fatal Error sebelumnya)
            $room['images'] = [];
        }
        
        $rooms_data[] = $room;
    }
}
// --- END FUNCTION ---
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Ruangan - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Perbaikan Layout Utama */
        .centered-page-container {
            display: flex;
            justify-content: center;
            min-height: 100vh;
            padding: 20px 0; 
            width: 100%;
        }
        .page-content-wrapper {
            max-width: 1200px; 
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 30px;
            padding: 0 20px; 
        }
        
        /* Header yang lebih elegan */
        .page-header {
            text-align: center;
            padding: var(--spacing-xl);
            background: var(--bg-card);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
        }
        .page-header h1 {
            justify-content: center; 
            font-size: 2rem;
            margin-bottom: var(--spacing-sm);
        }
        .page-header p {
            color: var(--text-secondary);
            font-size: 1rem;
            margin: 0 auto; 
            max-width: 600px;
        }
        .page-header .page-icon {
            background: none;
            padding: 0;
        }

        /* Grid Ruangan */
        .public-booking-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: var(--spacing-lg); 
        }

        /* Card Ruangan yang Ditingkatkan */
        .room-card {
            background: linear-gradient(145deg, var(--bg-card), var(--bg-secondary));
            border: 1px solid var(--border-color);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            padding: 0;
            overflow: hidden; 
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .room-card:hover {
             transform: translateY(-5px);
             box-shadow: var(--shadow-lg);
        }
        
        /* Header Ruangan */
        .room-header {
            padding: var(--spacing-lg) var(--spacing-xl);
            background: var(--bg-tertiary);
            border-bottom: 1px solid var(--border-color);
        }
        
        .room-header h3 {
            color: var(--primary-color);
            margin: 0;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            font-weight: 700;
        }
        
        /* Blok Informasi */
        .room-info-block {
            padding: var(--spacing-xl);
        }
        
        .info-item-box {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: var(--spacing-md);
            margin-bottom: var(--spacing-md);
            border-left: 4px solid var(--primary-color);
        }
        
        .info-item-box strong {
            display: block;
            font-size: 0.9rem;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .info-item-box p {
            margin: 0;
            font-size: 0.9rem;
            color: var(--text-secondary);
            white-space: pre-wrap; 
        }

        /* Bagian Gambar - Gallery Container */
        .room-image-container {
            width: 100%; 
            height: 250px; 
            overflow: hidden;
            margin: var(--spacing-xl) 0;
            position: relative;
        }
        
        /* Gallery CSS */
        .image-gallery-wrapper {
            width: 100%;
            height: 100%;
            position: relative;
            transform: translateZ(0); 
        }
        
        .gallery-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            top: 0;
            left: 0;
            opacity: 0;
            transition: opacity 1s ease-in-out;
        }
        
        /* Formulir */
        .booking-form {
            padding: var(--spacing-xl);
            border-top: 1px solid var(--border-color);
        }

        .booking-form .form-group {
            margin-bottom: var(--spacing-lg);
        }

        .booking-form .form-input,
        .booking-form .form-textarea {
            border: 1px solid var(--border-light);
            background: var(--bg-card);
            width: 100%; 
            box-sizing: border-box;
        }
        
        .form-row {
             /* FIX: Menggunakan grid untuk layout 2 kolom */
             display: grid;
             grid-template-columns: 1fr 1fr;
             gap: var(--spacing-md);
        }
        
        /* Mobile Responsif */
        @media (max-width: 600px) {
            .page-content-wrapper {
                padding: 0 10px;
            }
            .form-row {
                /* FIX: Kembali ke 1 kolom di mobile untuk menghindari pemotongan */
                grid-template-columns: 1fr;
            }
            
            /* FIX: Memastikan input di form-row mengambil lebar penuh di layout 1 kolom */
            .form-row .form-group {
                width: 100%;
            }
            .form-row .form-group .form-input {
                width: 100%;
            }

            .room-image-container {
                height: 180px;
            }
            .room-header h3 {
                font-size: 1.4rem;
            }
        }
        
        /* Styling untuk tombol pesan agar full width */
        .booking-form .btn-primary {
            width: 100%;
            font-size: 1rem;
            padding: var(--spacing-md) var(--spacing-xl);
            margin-top: var(--spacing-lg);
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
                        Booking Ruangan Elysium Night Club
                    </h1>
                    <p>Lihat detail ruangan dan ajukan pemesanan. Kami akan segera menghubungi Anda untuk konfirmasi.</p>
                    
                    <div style="margin-top: 25px; display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 0 10px;">
                        
                        <div class="info-item-box" style="border-left-color: var(--info-color); background: var(--bg-secondary); padding: 10px 20px; text-align: left; width: 100%; max-width: 500px;">
                            <strong>Katalog Elysium:</strong> Di dalamnya terdapat informasi lengkap mengenai **TNC** (Syarat & Ketentuan) dan **Katalog Talent** yang tersedia.
                        </div>

                        <div style="display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; width: 100%;">
                            <a href="https://elysium-night-club.my.canva.site/" target="_blank" class="btn btn-info btn-sm">
                                <span class="btn-icon">📚</span> Katalog Elysium
                            </a>
                            <a href="manage-public-booking.php" class="btn btn-warning btn-sm">
                                <span class="btn-icon">⚙️</span> Admin Login
                            </a>
                        </div>
                    </div>
                    </div>

                <?php if (isset($success)): ?>
                    <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
    
                <div class="public-booking-grid">
                    <?php foreach ($rooms_data as $room): ?>
                    <div class="room-card">
                        
                        <div class="room-header">
                            <h3><?= htmlspecialchars($room['room_name']) ?></h3>
                        </div>

                        <div class="room-info-block">
                            <p style="color: var(--text-muted); margin-bottom: var(--spacing-lg);"><?= htmlspecialchars($room['description'] ?? '') ?></p>
                            
                            <div class="room-image-container">
                                <div class="image-gallery-wrapper" id="gallery-<?= $room['id'] ?>">
                                    <?php if (!empty($room['images'])): ?>
                                        <?php foreach ($room['images'] as $index => $image): ?>
                                            <img src="<?= htmlspecialchars($image['image_path']) ?>" 
                                                 alt="Foto <?= htmlspecialchars($room['room_name']) ?> <?= $index + 1 ?>" 
                                                 class="gallery-image" 
                                                 style="opacity: <?= $index === 0 ? '1' : '0' ?>; z-index: <?= 10 - $index ?>;">
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="background-color: var(--bg-tertiary); display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">
                                            <span style="color: var(--text-secondary);">[Image not available]</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="info-item-box" style="border-left-color: var(--success-color);">
                                <strong>Harga</strong>
                                <p><?= htmlspecialchars($room['price_info'] ?? 'Tanyakan kepada Admin') ?></p>
                            </div>
                            
                            <div class="info-item-box">
                                <strong>Fasilitas</strong>
                                <p><?= htmlspecialchars($room['facilities'] ?? 'N/A') ?></p>
                            </div>
                            
                            <div class="info-item-box" style="margin-bottom: 0;">
                                <strong>Ketentuan</strong>
                                <p><?= nl2br(htmlspecialchars($room['rules'] ?? 'Tidak ada ketentuan khusus.')) ?></p>
                            </div>
                        </div>

                        <form method="POST" class="booking-form">
                            <input type="hidden" name="action" value="book_room">
                            <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                            
                            <div class="form-group">
                                <label for="booking_name_<?= $room['id'] ?>">NAMA ANDA</label>
                                <input type="text" name="booking_name" id="booking_name_<?= $room['id'] ?>" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone_number_<?= $room['id'] ?>">NOMOR HP (Whatsapp Aktif)</label>
                                <input type="tel" name="phone_number" id="phone_number_<?= $room['id'] ?>" class="form-input" placeholder="08xxxxxxxxxx" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="booking_purpose_<?= $room['id'] ?>">TUJUAN BOOKING</label>
                                <textarea name="booking_purpose" id="booking_purpose_<?= $room['id'] ?>" rows="2" class="form-textarea" placeholder="Contoh: Ulang tahun teman, meeting, dll."></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="booking_date_<?= $room['id'] ?>">TANGGAL</label>
                                    <input type="date" name="booking_date" id="booking_date_<?= $room['id'] ?>" class="form-input" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="booking_time_<?= $room['id'] ?>">JAM</label>
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
    
    <script>
        // Simple JavaScript for Image Carousel/Gallery (Optional)
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.image-gallery-wrapper').forEach(function(gallery) {
                const images = gallery.querySelectorAll('.gallery-image');
                let current = 0;
                
                if (images.length > 1) {
                    setInterval(function() {
                        images[current].style.opacity = 0;
                        current = (current + 1) % images.length;
                        images[current].style.opacity = 1;
                    }, 5000); // Ganti gambar setiap 5 detik
                }
            });
        });
    </script>
    <script src="script.js"></script>
</body>
</html>