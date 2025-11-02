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
            position: relative; /* Penting untuk penempatan link admin */
        }
        
        /* Header Refinements */
        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 800;
            justify-content: center; 
            color: var(--text-primary);
        }
        
        .page-header p {
            font-size: 1.05rem;
            color: var(--text-secondary);
            margin: 5px auto 0;
        }

        /* New Wrapper for Tidy Look (Header Action) */
        .header-actions-wrapper {
            margin-top: var(--spacing-xl);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: var(--spacing-lg);
            position: relative;
        }
        
        .katalog-info-box {
            background: var(--bg-secondary);
            border: 2px solid var(--primary-color);
            border-radius: var(--radius-lg);
            padding: var(--spacing-lg);
            width: 100%;
            max-width: 550px;
            text-align: left;
            position: relative;
        }
        
        .katalog-info-box strong {
            display: block;
            font-size: 1rem;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .katalog-info-box p {
             margin: 0 0 10px 0;
             font-size: 0.95rem;
        }
        
        .btn-katalog {
            font-size: 0.9rem !important;
            padding: 0.5rem 1.25rem !important;
        }

        /* Discreet Admin Link Style (Moved to top right corner of the header card) */
        .discreet-admin-link {
            position: absolute;
            top: 20px;
            right: 20px;
            font-size: 0.75rem;
            color: var(--text-muted);
            text-decoration: none;
            padding: 5px 10px;
            border-radius: var(--radius-md);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .discreet-admin-link:hover {
            color: var(--primary-color);
            background: var(--bg-secondary);
        }
        
        /* Media Query for positioning the discreet link on smaller screens */
        @media (max-width: 768px) {
            .discreet-admin-link {
                position: static;
                order: 3; /* Move to bottom of wrapper */
                margin-top: 10px;
                font-size: 0.85rem;
                justify-content: center;
            }
        }

        /* Grid Ruangan */
        .public-booking-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: var(--spacing-lg); 
        }

        /* Card Ruangan yang Ditingkatkan */
        .room-card {
            background: var(--bg-card);
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
             border-color: var(--primary-color);
        }
        
        /* Bagian Gambar - Gallery Container (Paling Menonjol) */
        .room-image-container {
            width: 100%; 
            height: 200px; /* Lebih kompak */
            overflow: hidden;
            position: relative;
            background: #000;
        }
        
        /* New Top Banner Pricing */
        .price-tag-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            font-weight: 700;
            z-index: 20;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            text-shadow: 0 0 2px #000;
            text-align: center;
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
        
        /* Wrapper Konten */
        .room-details-wrapper {
             padding: var(--spacing-xl);
        }

        /* Header Ruangan Baru */
        .room-header-new {
            margin-bottom: var(--spacing-lg);
            border-bottom: 2px dashed var(--border-light);
            padding-bottom: var(--spacing-md);
        }
        
        .room-header-new h3 {
            color: var(--text-primary);
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.025em;
        }
        
        .room-description-summary {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin: 5px 0 0;
        }
        
        /* Blok Informasi Fasilitas/Ketentuan */
        .room-info-block-new {
            margin-bottom: var(--spacing-lg);
        }
        
        .info-item-box {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: var(--spacing-md);
            margin-bottom: var(--spacing-md);
            border-left: 4px solid var(--primary-color);
        }
        
        .info-item-box strong {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 0.25rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        /* Highlight FASILITAS UNGGULAN (Biru/Hijau) */
        .info-item-box:nth-child(1) {
            border-left: 4px solid var(--success-color); 
        }
        .info-item-box:nth-child(1) strong {
            color: var(--success-color); 
        }
        
        /* Highlight KETENTUAN PENTING (Merah/Danger) */
        .info-item-box:nth-child(2) {
            border-left: 4px solid var(--danger-color);
        }
        .info-item-box:nth-child(2) strong {
            color: var(--danger-color);
        }

        .info-item-box p {
            margin: 0;
            font-size: 0.85rem;
            color: var(--text-secondary);
            white-space: pre-wrap; 
        }

        /* Formulir */
        .booking-form {
            padding: var(--spacing-xl);
            border-top: 1px solid var(--border-color);
            background: var(--bg-secondary);
        }
        
        .booking-form .form-group {
            /* Tight stacking as seen in screenshot */
            margin-bottom: var(--spacing-md); 
        }
        
        .booking-form label {
            /* Matches the stacked label look in the screenshot */
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary); 
            margin-bottom: var(--spacing-sm);
        }
        
        .booking-form .form-input,
        .booking-form .form-textarea {
            /* Styling inputs for the dark, enclosed look from the screenshot */
            background: var(--bg-secondary); 
            border: 1px solid var(--border-light); 
            border-radius: var(--radius-md);
            padding: var(--spacing-md);
            font-size: 1rem;
            color: var(--text-primary);
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.3);
        }

        .booking-form .form-textarea {
             min-height: 100px; /* Slightly taller textarea */
        }
        
        .form-row {
             display: grid;
             grid-template-columns: 3fr 2fr; /* Proporsi Tanggal dan Jam */
             gap: var(--spacing-md);
        }

        .booking-form .btn-primary {
            /* Prominent yellow button matching the example */
            background: #FFC107; 
            color: #121212;
            font-weight: 700;
            box-shadow: 0 4px 10px rgba(255, 193, 7, 0.4);
            width: 100%;
            margin-top: var(--spacing-lg);
        }
        
        /* Mobile Responsif */
        @media (max-width: 600px) {
            .page-content-wrapper {
                padding: 0 10px;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .room-image-container {
                height: 160px;
            }
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
                    
                    <div class="header-actions-wrapper">
                        <div class="katalog-info-box">
                            <strong>Katalog Elysium:</strong>
                            <p>Di dalamnya terdapat informasi lengkap mengenai **TNC** (Syarat & Ketentuan) dan **Katalog Talent** yang tersedia.</p>
                            <a href="https://elysium-night-club.my.canva.site/" target="_blank" class="btn btn-info btn-sm btn-katalog">
                                <span class="btn-icon">📚</span> Lihat Katalog Elysium
                            </a>
                        </div>

                        <a href="manage-public-booking.php" class="discreet-admin-link">
                            <span class="btn-icon">⚙️</span> Admin Login Area
                        </a>
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
                            <span class="price-tag-overlay">
                                <?= htmlspecialchars($room['price_info'] ?? 'Harga Tanyakan Admin') ?>
                            </span>
                        </div>
                        
                        <div class="room-details-wrapper">
                            
                            <div class="room-header-new">
                                <h3><?= htmlspecialchars($room['room_name']) ?></h3>
                                <p class="room-description-summary"><?= htmlspecialchars($room['description'] ?? '') ?></p>
                            </div>

                            <div class="room-info-block-new">
                                
                                <div class="info-item-box">
                                    <strong>FASILITAS UNGGULAN</strong>
                                    <p><?= nl2br(htmlspecialchars($room['facilities'] ?? 'N/A')) ?></p>
                                </div>
                                
                                <div class="info-item-box">
                                    <strong>KETENTUAN PENTING</strong>
                                    <p><?= nl2br(htmlspecialchars($room['rules'] ?? 'Tidak ada ketentuan khusus.')) ?></p>
                                </div>
                            </div>
                        </div>

                        <form method="POST" class="booking-form">
                            <input type="hidden" name="action" value="book_room">
                            <input type="hidden" name="room_id" value="<?= $room['id'] ?>">
                            
                            <div class="form-group">
                                <label for="booking_name_<?= $room['id'] ?>">NAMA IC ANDA</label>
                                <input type="text" name="booking_name" id="booking_name_<?= $room['id'] ?>" class="form-input" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone_number_<?= $room['id'] ?>">NOMOR HP IC</label>
                                <input type="tel" name="phone_number" id="phone_number_<?= $room['id'] ?>" class="form-input" placeholder="08xxxxxxxxxx" required>
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
                            
                            <div class="form-group" style="margin-top: 20px; margin-bottom: 0;">
                                <label for="booking_purpose_<?= $room['id'] ?>">TUJUAN BOOKING</label>
                                <textarea name="booking_purpose" id="booking_purpose_<?= $room['id'] ?>" rows="2" class="form-textarea" placeholder="Contoh: Ulang tahun teman, meeting, dll."></textarea>
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