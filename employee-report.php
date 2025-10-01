<?php
require_once 'config.php';

// Pastikan hanya manajer dan level di atasnya yang bisa mengakses
if (!isLoggedIn() || !hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager'])) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$employees = $conn->query("SELECT id, name FROM employees ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

$selected_employee_id = null;
$selected_date = null;

$refrigerator_transactions = [];
$warehouse_transactions = [];
$sales_details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $selected_employee_id = (int)($_POST['employee_id'] ?? 0);
    $selected_date = $_POST['report_date'] ?? date('Y-m-d');

    if ($selected_employee_id > 0) {
        // Fetch Refrigerator Transactions
        $stmt_fridge = $conn->prepare("
            SELECT rt.product_name, rt.quantity, rt.transaction_type, rt.transaction_at, e.name as employee_name
            FROM refrigerator_transactions rt
            JOIN employees e ON rt.employee_id = e.id
            WHERE rt.employee_id = ? AND DATE(rt.transaction_at) = ?
            ORDER BY rt.transaction_at ASC
        ");
        $stmt_fridge->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_fridge->execute();
        $refrigerator_transactions = $stmt_fridge->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_fridge->close();

        // Fetch Warehouse Transactions
        $stmt_warehouse = $conn->prepare("
            SELECT wt.product_name, wt.quantity, wt.transaction_type, wt.transaction_at, e.name as employee_name
            FROM warehouse_transactions wt
            JOIN employees e ON wt.employee_id = e.id
            WHERE wt.employee_id = ? AND DATE(wt.transaction_at) = ?
            ORDER BY wt.transaction_at ASC
        ");
        $stmt_warehouse->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_warehouse->execute();
        $warehouse_transactions = $stmt_warehouse->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_warehouse->close();

        // Perbaikan: Ganti query sales data untuk mencocokkan skema baru
        $stmt_sales = $conn->prepare("
            SELECT 
                input_time,
                paket_sake,
                paket_anggur_merah,
                paket_tuak,
                paket_soju,
                paket_spicy_1,
                paket_spicy_2,
                paket_spicy_3
            FROM sales_data
            WHERE employee_id = ? AND date = ?
            ORDER BY input_time ASC
        ");
        $stmt_sales->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_sales->execute();
        $sales_details = $stmt_sales->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_sales->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Aktivitas Karyawan - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .report-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            margin-bottom: 2rem;
            padding: 1rem;
            background: var(--bg-card);
            border-radius: var(--radius-xl);
        }
        .report-results-section {
            margin-top: 2rem;
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }
        .transaction-log-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .transaction-log-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            margin-bottom: 0.75rem;
            padding: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .transaction-log-item span {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .transaction-log-item strong {
            color: var(--text-primary);
        }
        .sale-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            margin-bottom: 0.75rem;
            padding: 1rem;
        }
        .sale-item p {
            margin: 0.25rem 0;
        }
        .sale-item .item-detail {
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        .badge-deposit {
            background-color: var(--success-light);
            color: var(--success-color);
        }
        .badge-withdraw {
            background-color: var(--danger-light);
            color: var(--danger-color);
        }
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>Laporan Aktivitas Karyawan</h1>
                <p>Lihat detail aktivitas stok dan penjualan per karyawan.</p>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Cari Aktivitas</h3>
                </div>
                <div class="card-content">
                    <form method="POST" class="report-form">
                        <div class="form-group">
                            <label for="employee_id">Pilih Karyawan</label>
                            <select name="employee_id" id="employee_id" class="form-select" required>
                                <option value="">-- Pilih Karyawan --</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= htmlspecialchars($employee['id']) ?>" <?= ($selected_employee_id == $employee['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($employee['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="report_date">Tanggal</label>
                            <input type="date" name="report_date" id="report_date" class="form-input" value="<?= htmlspecialchars($selected_date) ?>" required>
                        </div>
                        <div class="form-actions" style="padding-top: 0; border-top: none;">
                            <button type="submit" name="search" class="btn btn-primary">
                                <span class="btn-icon">🔍</span> Cari
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($_POST['search'])): ?>
            <div class="report-results-section">
                <div class="card full-width">
                    <div class="card-header">
                        <h3>Stok Kulkas</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($refrigerator_transactions)): ?>
                            <div class="no-data">Tidak ada transaksi kulkas untuk tanggal ini.</div>
                        <?php else: ?>
                            <ul class="transaction-log-list">
                                <?php foreach ($refrigerator_transactions as $log): ?>
                                    <li class="transaction-log-item">
                                        <div>
                                            <strong><?= htmlspecialchars(str_replace('_', ' ', $log['product_name'])) ?></strong>
                                            <span>pada <?= date('H:i', strtotime($log['transaction_at'])) ?></span>
                                        </div>
                                        <div class="log-action">
                                            <span class="badge badge-<?= $log['transaction_type'] ?>"><?= ucfirst($log['transaction_type']) ?></span>
                                            <span><?= $log['quantity'] ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card full-width">
                    <div class="card-header">
                        <h3>Stok Gudang</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($warehouse_transactions)): ?>
                            <div class="no-data">Tidak ada transaksi gudang untuk tanggal ini.</div>
                        <?php else: ?>
                            <ul class="transaction-log-list">
                                <?php foreach ($warehouse_transactions as $log): ?>
                                    <li class="transaction-log-item">
                                        <div>
                                            <strong><?= htmlspecialchars(str_replace('_', ' ', $log['product_name'])) ?></strong>
                                            <span>pada <?= date('H:i', strtotime($log['transaction_at'])) ?></span>
                                        </div>
                                        <div class="log-action">
                                            <span class="badge badge-<?= $log['transaction_type'] ?>"><?= ucfirst($log['transaction_type']) ?></span>
                                            <span><?= $log['quantity'] ?></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card full-width">
                    <div class="card-header">
                        <h3>Penjualan</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($sales_details)): ?>
                            <div class="no-data">Tidak ada penjualan untuk tanggal ini.</div>
                        <?php else: ?>
                            <div class="sales-list">
                                <?php foreach ($sales_details as $sale_entry): ?>
                                    <div class="sale-item">
                                        <p><strong>Waktu Input:</strong> <?= date('H:i:s', strtotime($sale_entry['input_time'])) ?></p>
                                        <p><strong>Detail Penjualan:</strong></p>
                                        <ul>
                                            <?php if ($sale_entry['paket_sake'] > 0): ?><li>Sake: <?= $sale_entry['paket_sake'] ?></li><?php endif; ?>
                                            <?php if ($sale_entry['paket_anggur_merah'] > 0): ?><li>Anggur Merah: <?= $sale_entry['paket_anggur_merah'] ?></li><?php endif; ?>
                                            <?php if ($sale_entry['paket_tuak'] > 0): ?><li>Tuak: <?= $sale_entry['paket_tuak'] ?></li><?php endif; ?>
                                            <?php if ($sale_entry['paket_soju'] > 0): ?><li>Soju: <?= $sale_entry['paket_soju'] ?></li><?php endif; ?>
                                            <?php if ($sale_entry['paket_spicy_1'] > 0): ?><li>Spicy 1: <?= $sale_entry['paket_spicy_1'] ?></li><?php endif; ?>
                                            <?php if ($sale_entry['paket_spicy_2'] > 0): ?><li>Spicy 2: <?= $sale_entry['paket_spicy_2'] ?></li><?php endif; ?>
                                            <?php if ($sale_entry['paket_spicy_3'] > 0): ?><li>Spicy 3: <?= $sale_entry['paket_spicy_3'] ?></li><?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>