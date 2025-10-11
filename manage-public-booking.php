<?php
require_once 'config.php';

// --- Konfigurasi Admin Booking ---
// Default hash untuk password 'password'. Ini akan diganti oleh nilai di database.
$BOOKING_ADMIN_PASSWORD_HASH = '$2y$10$m4TgOhjTqGqOaQzE16dMou1yIrHXNFY5vZmKvgMGXi3Fw2JLApEMa'; 
$UPLOAD_DIR = 'uploads/room_images/';

// Dapatkan hash dari system_settings
$stmt_setting = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'booking_admin_password'");
if ($stmt_setting && $stmt_setting->num_rows > 0) {
    $BOOKING_ADMIN_PASSWORD_HASH = $stmt_setting->fetch_assoc()['setting_value'];
}

$is_authenticated = false;
$error = null;
$success = null;
$warning = null;
$current_room_id = (int)($_GET['room_id'] ?? 0);

// --- Handle Authentication ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'authenticate') {
    $input_password = $_POST['admin_password'] ?? '';
    if (password_verify($input_password, $BOOKING_ADMIN_PASSWORD_HASH)) {
        $_SESSION['booking_admin_auth'] = true;
        // Redirect to clear POST data
        header("Location: manage-public-booking.php" . ($current_room_id > 0 ? "?room_id={$current_room_id}" : ""));
        exit;
    } else {
        $error = "Password admin salah!";
    }
}

// Cek status autentikasi
if (isset($_SESSION['booking_admin_auth']) && $_SESSION['booking_admin_auth'] === true) {
    $is_authenticated = true;
}

// --- Handle Logout ---
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    unset($_SESSION['booking_admin_auth']);
    header("Location: manage-public-booking.php");
    exit;
}

// --- Handle Room Detail Update (Protected) ---
if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_room_details') {
    $room_id = (int)($_POST['room_id'] ?? 0);
    $room_name = $_POST['room_name'] ?? '';
    $description = $_POST['description'] ?? '';
    $price_info = $_POST['price_info'] ?? '';
    $facilities = $_POST['facilities'] ?? '';
    $rules = $_POST['rules'] ?? '';

    $success_local = null;
    $error_local = null;

    if ($room_id > 0 && $room_name) {
        $stmt = $conn->prepare("
            UPDATE rooms SET 
                room_name = ?, description = ?, price_info = ?, 
                facilities = ?, rules = ?
            WHERE id = ?
        ");
        if (!$stmt) {
             $error_local = "Gagal menyiapkan query update detail: " . $conn->error;
        } else {
            $stmt->bind_param("sssssi", $room_name, $description, $price_info, $facilities, $rules, $room_id);
            if ($stmt->execute()) {
                $success_local = "Detail ruangan **" . htmlspecialchars($room_name) . "** berhasil diperbarui!";
            } else {
                $error_local = "Gagal memperbarui detail ruangan: " . $stmt->error;
            }
            $stmt->close();
        }
    } else {
        $error_local = "ID Ruangan dan Nama Ruangan wajib diisi.";
    }
    header("Location: manage-public-booking.php?room_id={$room_id}&msg=" . urlencode($success_local ?? $error_local) . "&type=" . urlencode(isset($success_local) ? 'success' : 'error'));
    exit;
}

// --- Handle Image Upload (Protected) ---
if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_images') {
    $room_id = (int)($_POST['room_id'] ?? 0);
    $files = $_FILES['room_photos'] ?? [];
    
    $success_local = null;
    $error_local = null;

    if (isset($files['name']) && $room_id > 0) {
        if (!is_dir($UPLOAD_DIR)) {
            mkdir($UPLOAD_DIR, 0777, true);
        }
        
        $upload_count = 0;
        $conn->begin_transaction();
        
        try {
            foreach ($files['name'] as $index => $filename) {
                if ($files['error'][$index] === UPLOAD_ERR_OK) {
                    $file_ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $allowed_types = ['jpg', 'jpeg', 'png', 'webp'];
                    
                    if (in_array($file_ext, $allowed_types)) {
                        $new_filename = uniqid('room_') . '.' . $file_ext;
                        $destination = $UPLOAD_DIR . $new_filename;
                        
                        if (move_uploaded_file($files['tmp_name'][$index], $destination)) {
                            // Insert path ke database
                            $stmt_insert_img = $conn->prepare("INSERT INTO room_images (room_id, image_path) VALUES (?, ?)");
                            if (!$stmt_insert_img) {
                                throw new Exception("MySQL Prepare Error (Insert Image): " . $conn->error);
                            }
                            $image_path_db = $destination; // Simpan path relatif
                            $stmt_insert_img->bind_param("is", $room_id, $image_path_db);
                            $stmt_insert_img->execute();
                            $stmt_insert_img->close();
                            $upload_count++;
                        }
                    }
                }
            }
            $conn->commit();
            $success_local = "Berhasil mengunggah {$upload_count} foto untuk ruangan ini.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_local = "Gagal upload: " . $e->getMessage();
        }
    } else {
        $error_local = "Tidak ada file yang dipilih atau ruangan tidak valid.";
    }
    header("Location: manage-public-booking.php?room_id={$room_id}&msg=" . urlencode($success_local ?? $error_local) . "&type=" . urlencode(isset($success_local) ? 'success' : 'error'));
    exit;
}

// --- Handle Image Deletion (Protected) ---
if ($is_authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_image') {
    $image_id = (int)($_POST['image_id'] ?? 0);
    $room_id = (int)($_POST['room_id'] ?? 0);

    $success_local = null;
    $error_local = null;

    if ($image_id > 0) {
        $stmt_get_path = $conn->prepare("SELECT image_path FROM room_images WHERE id = ?");
        
        // FIX: Error Check untuk Prepare (Line 108 di skrip yang Anda tunjukkan sebelumnya)
        if (!$stmt_get_path) {
            $error_local = "MySQL Prepare Error (Select Image): " . $conn->error;
        } else {
            $stmt_get_path->bind_param("i", $image_id);
            $stmt_get_path->execute();
            $result = $stmt_get_path->get_result()->fetch_assoc();
            $stmt_get_path->close();
            
            if ($result) {
                // Hapus dari disk
                if (file_exists($result['image_path']) && unlink($result['image_path'])) {
                    $status_msg = "Foto berhasil dihapus.";
                } else {
                    $status_msg = "Foto tidak ditemukan di server, namun berhasil dihapus dari database.";
                }

                // Hapus dari database
                $stmt_delete = $conn->prepare("DELETE FROM room_images WHERE id = ?");
                if (!$stmt_delete) {
                    $error_local = "MySQL Prepare Error (Delete Image): " . $conn->error;
                } else {
                    $stmt_delete->bind_param("i", $image_id);
                    $stmt_delete->execute();
                    $stmt_delete->close();
                    $success_local = $status_msg;
                }
            } else {
                $error_local = "ID foto tidak valid.";
            }
        }
    }
    header("Location: manage-public-booking.php?room_id={$room_id}&msg=" . urlencode($success_local ?? $error_local) . "&type=" . urlencode(isset($success_local) ? 'success' : 'error'));
    exit;
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success = $feedback_message;
    } elseif ($feedback_type === 'warning') {
        $warning = $feedback_message;
    } else {
        $error = $feedback_message;
    }
}


// --- Load Data for Display ---
$rooms = $conn->query("SELECT id, room_name FROM rooms ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$selected_room = null;
$room_images = [];

if ($is_authenticated && $current_room_id > 0) {
    $stmt_room = $conn->prepare("SELECT * FROM rooms WHERE id = ?");
    if (!$stmt_room) {
        $error = "Gagal memuat data ruangan: " . $conn->error;
    } else {
        $stmt_room->bind_param("i", $current_room_id);
        $stmt_room->execute();
        $selected_room = $stmt_room->get_result()->fetch_assoc();
        $stmt_room->close();
        
        // Load images for the selected room
        $stmt_images = $conn->prepare("SELECT id, image_path FROM room_images WHERE room_id = ? ORDER BY sort_order ASC, id ASC");
        if ($stmt_images) {
            $stmt_images->bind_param("i", $current_room_id);
            $stmt_images->execute();
            $room_images = $stmt_images->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_images->close();
        } else {
            $warning = ($warning ? $warning . "<br>" : "") . "Perhatian: Tabel 'room_images' tidak ditemukan atau bermasalah. Foto tidak dapat dimuat. Pastikan Anda sudah menjalankan query SQL untuk membuat tabel tersebut.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Booking - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: var(--bg-secondary);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
        }
        .auth-card {
            max-width: 400px;
            margin: 100px auto;
            padding: var(--spacing-2xl);
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            text-align: center;
        }
        .image-gallery {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }
        .image-preview {
            position: relative;
            width: 150px;
            height: 100px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            flex-shrink: 0;
        }
        .image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .delete-img-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 10px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .room-select-group {
            display: flex;
            gap: 20px;
            align-items: center;
            margin-bottom: 20px;
        }
        .room-select-group form {
            flex: 1;
        }
        @media (max-width: 768px) {
            .room-select-group {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body class="login-body">
    <div class="admin-container">
        <div class="page-header">
            <h1>
                <span class="page-icon">⚙️</span>
                Admin Panel: Kelola Booking
            </h1>
            <p>Admin khusus untuk mengedit konten dan foto halaman *public-booking.php*.</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($warning)): ?>
            <div class="warning-message">⚠️ <?= htmlspecialchars($warning) ?></div>
        <?php endif; ?>

        <?php if (!$is_authenticated): ?>
            <div class="auth-card">
                <h3>Masuk Admin Booking</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="authenticate">
                    <div class="form-group">
                        <label for="admin_password">Password Khusus</label>
                        <input type="password" name="admin_password" id="admin_password" class="form-input" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Masuk</button>
                    <p style="margin-top: 15px; font-size: 0.85em; color: var(--text-muted);">
                    </p>
                </form>
            </div>
        <?php else: ?>
            
            <div class="card full-width">
                <div class="card-header">
                    <h3>Pilih Ruangan untuk Diubah</h3>
                </div>
                <div class="card-content">
                    <div class="room-select-group">
                        <form method="GET" style="margin: 0;">
                            <select name="room_id" class="form-select" onchange="this.form.submit()">
                                <option value="0">-- Pilih Ruangan --</option>
                                <?php foreach ($rooms as $room): ?>
                                    <option value="<?= $room['id'] ?>" <?= ($room['id'] == $current_room_id) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($room['room_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <a href="public-booking.php" target="_blank" class="btn btn-info btn-sm">Lihat Halaman Publik</a>
                    </div>
                </div>
            </div>

            <?php if ($selected_room): ?>
                
                <div class="card full-width" style="margin-top: 20px;">
                    <div class="card-header">
                        <h3>Edit Detail Ruangan: <?= htmlspecialchars($selected_room['room_name']) ?></h3>
                    </div>
                    <div class="card-content">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_room_details">
                            <input type="hidden" name="room_id" value="<?= $selected_room['id'] ?>">
                            
                            <div class="form-group">
                                <label for="room_name">Nama Ruangan</label>
                                <input type="text" name="room_name" id="room_name" class="form-input" value="<?= htmlspecialchars($selected_room['room_name'] ?? '') ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="description">Deskripsi Singkat</label>
                                <textarea name="description" id="description" class="form-textarea" rows="2"><?= htmlspecialchars($selected_room['description'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="price_info">Informasi Harga (Akan ditampilkan di bawah nama)</label>
                                <input type="text" name="price_info" id="price_info" class="form-input" value="<?= htmlspecialchars($selected_room['price_info'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="facilities">Fasilitas</label>
                                <textarea name="facilities" id="facilities" class="form-textarea" rows="3"><?= htmlspecialchars($selected_room['facilities'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="rules">Ketentuan (Gunakan Enter untuk baris baru)</label>
                                <textarea name="rules" id="rules" class="form-textarea" rows="4"><?= htmlspecialchars($selected_room['rules'] ?? '') ?></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Simpan Detail</button>
                        </form>
                    </div>
                </div>

                <div class="card full-width" style="margin-top: 20px;">
                    <div class="card-header">
                        <h3>Kelola Foto (Galeri)</h3>
                    </div>
                    <div class="card-content">
                        <h4>Upload Foto Baru</h4>
                        <div class="info-message">
                            Anda dapat mengunggah banyak foto sekaligus. Tipe file yang diterima: JPG, PNG, WEBP.
                            **Catatan:** Pastikan folder `uploads/room_images/` sudah dibuat dan memiliki izin tulis (write permission).
                        </div>
                        <form method="POST" enctype="multipart/form-data" style="margin-bottom: 30px;">
                            <input type="hidden" name="action" value="upload_images">
                            <input type="hidden" name="room_id" value="<?= $selected_room['id'] ?>">
                            <div class="form-group">
                                <input type="file" name="room_photos[]" accept="image/*" multiple class="form-input" required>
                            </div>
                            <button type="submit" class="btn btn-success">Unggah Foto</button>
                        </form>
                        
                        <h4>Galeri Saat Ini (<?= count($room_images) ?> Foto)</h4>
                        <div class="image-gallery">
                            <?php if (empty($room_images)): ?>
                                <div class="no-data" style="width: 100%;">Tidak ada foto terunggah.</div>
                            <?php else: ?>
                                <?php foreach ($room_images as $image): ?>
                                    <div class="image-preview">
                                        <img src="<?= htmlspecialchars($image['image_path']) ?>" alt="Room Image">
                                        <form method="POST" onsubmit="return confirm('Yakin ingin menghapus foto ini?')" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_image">
                                            <input type="hidden" name="image_id" value="<?= $image['id'] ?>">
                                            <input type="hidden" name="room_id" value="<?= $selected_room['id'] ?>">
                                            <button type="submit" class="delete-img-btn">X</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <a href="manage-public-booking.php?logout=true" class="btn btn-danger" style="margin-top: 20px;">Logout Admin Booking</a>
        <?php endif; ?>
    </div>
    <script src="script.js"></script>
</body>
</html>