<?php
session_start();
if (!isset($_SESSION['taka_group_access']) || $_SESSION['taka_group_access'] !== true) {
    header('Location: taka-group-login.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilih Bisnis - Taka Group</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body.business-selection-body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #121212 0%, #000000 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .selection-container {
            text-align: center;
            width: 100%;
            max-width: 600px;
            color: white;
        }

        .selection-container h2 {
            font-size: 2rem;
            margin-bottom: var(--spacing-xl);
            color: #ffc107;
        }
        
        .business-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: var(--spacing-lg);
        }

        .business-card {
            background: #1e1e1e;
            border: 1px solid #343a40;
            border-radius: var(--radius-xl);
            padding: var(--spacing-xl);
            width: 250px;
            text-decoration: none;
            color: white;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            text-align: center;
        }

        .business-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            border-color: #ffc107;
        }

        .business-card.coming-soon {
            opacity: 0.5;
            cursor: not-allowed;
            filter: grayscale(100%);
        }

        .business-card .icon {
            font-size: 3rem;
            margin-bottom: var(--spacing-md);
        }

        .business-card h3 {
            font-size: 1.2rem;
            margin: 0;
        }
    </style>
</head>
<body class="business-selection-body">
    <div class="selection-container">
        <h2>Pilih Bisnis untuk Masuk</h2>
        <div class="business-list">
            <a href="index.php" class="business-card">
                <span class="icon">✨</span>
                <h3>Elysium Night Club</h3>
            </a>
            <div class="business-card coming-soon">
                <span class="icon">🚧</span>
                <h3>Coming Soon</h3>
            </div>
            <div class="business-card coming-soon">
                <span class="icon">🚧</span>
                <h3>Coming Soon</h3>
            </div>
        </div>
    </div>
</body>
</html>