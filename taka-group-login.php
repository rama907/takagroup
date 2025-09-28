<?php
session_start();
require_once 'config.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    // Password hardcoded untuk Taka Group. Anda bisa menggantinya.
    $taka_password = 'T@ka2025'; 

    if ($password === $taka_password) {
        $_SESSION['taka_group_access'] = true;
        header('Location: business-selection.php');
        exit;
    } else {
        $error = "Password Taka Group salah!";
    }
}

// --- Logika untuk status klub ---
// Mengambil on-duty dan total employee count
global $conn;
$on_duty_employees_count = $conn->query("SELECT COUNT(*) as count FROM employees WHERE is_on_duty = 1")->fetch_assoc()['count'];
$status_is_open = $on_duty_employees_count > 0;
// --- Akhir logika untuk status klub ---
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Taka Group Business</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body.taka-login-body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #121212 0%, #000000 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: var(--spacing-2xl);
        }

        .taka-main-container {
            display: flex;
            gap: var(--spacing-2xl);
            align-items: flex-start;
            justify-content: center;
            flex-wrap: wrap;
            max-width: 900px;
        }

        .taka-login-card {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(20px);
            border-radius: var(--radius-2xl);
            padding: var(--spacing-2xl);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
            color: white;
        }
        
        .taka-login-card .logo {
            font-size: 2rem;
            margin-bottom: var(--spacing-md);
        }
        
        .taka-login-card h2 {
            font-size: 1.5rem;
            margin-bottom: var(--spacing-xl);
        }

        .taka-login-card .form-input-login {
            background-color: #1e1e1e;
            border-color: #343a40;
            color: white;
        }

        .taka-login-card .btn-login {
            width: 100%;
        }

        .booking-info-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-2xl);
            padding: var(--spacing-2xl);
            width: 100%;
            max-width: 400px;
            text-align: center;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }

        .booking-info-card:hover {
            transform: translateY(-5px);
            border-color: #ffc107;
        }

        .booking-info-card h3 {
            font-size: 1.5rem;
            margin-bottom: var(--spacing-md);
            color: #ffc107;
        }

        .booking-info-card p {
            margin-bottom: var(--spacing-xl);
        }
        
        .booking-info-card .btn-booking {
            width: 80%;
        }
        
        .taka-logo {
            width: 135px;
            height: 135px;
            object-fit: contain;
            margin-bottom: 10px;
        }
        
        /* Gaya baru untuk panel status club */
        .club-status-panel {
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-2xl);
            padding: var(--spacing-xl);
            width: 100%;
            max-width: 400px;
            text-align: center;
            color: white;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .club-status-panel h4 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .status-open {
            color: var(--success-color);
        }
        .status-closed {
            color: var(--danger-color);
        }

        /* Container baru untuk menumpuk kartu di sisi kanan */
        .right-side-panel {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-xl);
            width: 100%;
            max-width: 400px;
        }
        
    </style>
</head>
<body class="taka-login-body">
    <div class="taka-main-container">
        <div class="taka-login-card">
            <img src="LOGO_WOT.png" alt="Taka Group Logo" class="taka-logo">
            <h2>Selamat Datang di Taka Group</h2>
            <p style="color: #adb5bd; margin-bottom: 20px;">Silakan masukkan password untuk melanjutkan.</p>
    
            <form method="POST">
                <div class="form-group-login">
                    <div class="password-wrapper">
                        <input type="password" name="password" id="taka-password" class="form-input-login" placeholder="Masukkan password" required>
                        <button type="button" class="password-toggle" data-target="taka-password" aria-label="Tampilkan Kata Sandi">
                            <span class="icon">👁️</span>
                        </button>
                    </div>
                </div>
                
                <?php if (isset($error)): ?>
                    <div class="error-message-login">
                        <span class="error-icon">⚠️</span>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <button type="submit" class="btn-login btn btn-primary" id="taka-login-button">
                    Masuk
                </button>
            </form>
        </div>
        
        <div class="right-side-panel">
            <div class="booking-info-card">
                <span class="icon" style="font-size: 3rem; color: #ffc107;">🗓️</span>
                <h3>Booking Ruangan Galaxy Night Club By Taka Group</h3>
                <p style="color: #adb5bd;">Dapatkan pengalaman eksklusif dengan memesan ruangan-ruangan VIP kami.</p>
                <a href="public-booking.php" class="btn btn-warning btn-booking">
                    Booking Sekarang
                </a>
            </div>
            
            <div class="club-status-panel">
                <h4>
                    <?php if ($status_is_open): ?>
                        <span class="status-open">🎉</span> Club Sedang Buka
                    <?php else: ?>
                        <span class="status-closed">🌙</span> Club Sedang Tutup
                    <?php endif; ?>
                </h4>
            </div>
        </div>
        </div>
    
    <script src="script.js"></script>
</body>
</html>