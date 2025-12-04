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
$summary_totals = [
    'refrigerator' => ['deposit' => 0, 'withdraw' => 0, 'details' => []],
    'warehouse' => ['deposit' => 0, 'withdraw' => 0, 'details' => []],
    'sales' => ['total' => 0, 'details' => []],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search'])) {
    $selected_employee_id = (int)($_POST['employee_id'] ?? 0);
    $selected_date = $_POST['report_date'] ?? date('Y-m-d');

    if ($selected_employee_id > 0) {
        // --- 1. Fetch Refrigerator Transactions ---
        $stmt_fridge = $conn->prepare("
            SELECT rt.product_name, rt.quantity, rt.transaction_type, rt.transaction_at
            FROM refrigerator_transactions rt
            WHERE rt.employee_id = ? AND DATE(rt.transaction_at) = ?
            ORDER BY rt.transaction_at ASC
        ");
        $stmt_fridge->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_fridge->execute();
        $refrigerator_transactions = $stmt_fridge->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_fridge->close();
        
        // Calculate Refrigerator Summary
        foreach ($refrigerator_transactions as $log) {
            $type = $log['transaction_type'];
            $qty = $log['quantity'];
            // Gunakan str_replace untuk membuat kunci ringkasan yang bersih
            $product = str_replace('_', ' ', $log['product_name']); 
            
            $summary_totals['refrigerator'][$type] += $qty;
            if (!isset($summary_totals['refrigerator']['details'][$product][$type])) {
                 $summary_totals['refrigerator']['details'][$product][$type] = 0;
            }
            $summary_totals['refrigerator']['details'][$product][$type] += $qty;
        }

        // --- 2. Fetch Warehouse Transactions ---
        $stmt_warehouse = $conn->prepare("
            SELECT wt.product_name, wt.quantity, wt.transaction_type, wt.transaction_at
            FROM warehouse_transactions wt
            WHERE wt.employee_id = ? AND DATE(wt.transaction_at) = ?
            ORDER BY wt.transaction_at ASC
        ");
        $stmt_warehouse->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_warehouse->execute();
        $warehouse_transactions = $stmt_warehouse->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_warehouse->close();

        // Calculate Warehouse Summary
        foreach ($warehouse_transactions as $log) {
            $type = $log['transaction_type'];
            $qty = $log['quantity'];
            $product = str_replace('_', ' ', $log['product_name']);
            
            $summary_totals['warehouse'][$type] += $qty;
            if (!isset($summary_totals['warehouse']['details'][$product][$type])) {
                 $summary_totals['warehouse']['details'][$product][$type] = 0;
            }
            $summary_totals['warehouse']['details'][$product][$type] += $qty;
        }

        // --- 3. Fetch Sales Details ---
        $stmt_sales = $conn->prepare("
            SELECT 
                input_time,
                paket_sake,
                paket_anggur_merah,
                paket_tuak,
                paket_soju,
                paket_spicy_1,
                paket_spicy_2 as paket_azul_1,
                paket_spicy_3 as paket_azul_2
            FROM sales_data
            WHERE employee_id = ? AND date = ?
            ORDER BY input_time ASC
        ");
        $stmt_sales->bind_param("is", $selected_employee_id, $selected_date);
        $stmt_sales->execute();
        $sales_details = $stmt_sales->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_sales->close();

        // Calculate Sales Summary
        $sales_products = [
            'Sake' => 'paket_sake', 
            'Anggur Merah' => 'paket_anggur_merah', 
            'Tuak' => 'paket_tuak', 
            'Soju' => 'paket_soju', 
            'Spicy 1' => 'paket_spicy_1', 
            'Azul 1' => 'paket_azul_1',
            'Azul 2' => 'paket_azul_2'
        ];
        
        $total_sales_qty = 0;
        $sales_details_summary = [];

        foreach ($sales_details as $entry) {
            foreach ($sales_products as $label => $key) {
                $qty = $entry[$key] ?? 0;
                if ($qty > 0) {
                    if (!isset($sales_details_summary[$label])) {
                        $sales_details_summary[$label] = 0;
                    }
                    $sales_details_summary[$label] += $qty;
                    $total_sales_qty += $qty;
                }
            }
        }
        $summary_totals['sales']['total'] = $total_sales_qty;
        $summary_totals['sales']['details'] = $sales_details_summary;
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
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }
        .summary-card-small {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-sm);
        }
        .summary-card-small h4 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
        }
        .summary-card-small .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .summary-card-detail {
            margin-top: 1rem;
            font-size: 0.85rem;
            line-height: 1.5;
            color: var(--text-primary);
        }
        .summary-card-detail span {
            display: block;
            color: var(--text-muted);
        }
        .summary-card-detail.sales-detail span {
            color: var(--text-primary);
            font-weight: 600;
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
        .sale-item ul {
            list-style-type: none;
            padding-left: 0;
            margin-top: 0.5rem;
        }
        .sale-item li {
            font-size: 0.9rem;
            color: var(--text-primary);
            margin-bottom: 0.2rem;
            background: var(--bg-tertiary);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
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
                <div class="summary-grid">
                    <div class="summary-card-small" style="border-left: 4px solid var(--primary-color);">
                        <h4>Total Penjualan Paket</h4>
                        <p class="value" style="color: var(--primary-color);"><?= $summary_totals['sales']['total'] ?></p>
                        <div class="summary-card-detail sales-detail">
                            <?php 
                            foreach ($summary_totals['sales']['details'] as $product => $qty) {
                                echo "<span>{$product}: {$qty}</span>";
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--success-color);">
                        <h4>Total Deposit Kulkas (Unit)</h4>
                        <p class="value" style="color: var(--success-color);"><?= $summary_totals['refrigerator']['deposit'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $fridge_details = $summary_totals['refrigerator']['details'];
                            // Membuat daftar dinamis dari semua produk yang memiliki transaksi kulkas
                            $product_names = array_keys($fridge_details);
                            sort($product_names);
                            foreach ($product_names as $p) {
                                $qty = $fridge_details[$p]['deposit'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>" . htmlspecialchars($p) . ": {$qty}</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--danger-color);">
                        <h4>Total Withdraw Kulkas (Unit)</h4>
                        <p class="value" style="color: var(--danger-color);"><?= $summary_totals['refrigerator']['withdraw'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $fridge_details = $summary_totals['refrigerator']['details'];
                            // Membuat daftar dinamis dari semua produk yang memiliki transaksi kulkas
                            $product_names = array_keys($fridge_details);
                            sort($product_names);
                            foreach ($product_names as $p) {
                                $qty = $fridge_details[$p]['withdraw'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>" . htmlspecialchars($p) . ": {$qty}</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--success-color);">
                        <h4>Total Deposit Gudang</h4>
                        <p class="value" style="color: var(--success-color);"><?= $summary_totals['warehouse']['deposit'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $warehouse_details = $summary_totals['warehouse']['details'];
                            $products = ['Jagung', 'Anggur', 'Bawang Merah', 'Strawberry', 'Lemon', 'Susu', 'Botol Kosong', 'Gelas Kosong', 'Piring Kosong', 'Bahan Khusus'];
                            foreach ($products as $p) {
                                $qty = $warehouse_details[$p]['deposit'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>$p: $qty</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div class="summary-card-small" style="border-left: 4px solid var(--danger-color);">
                        <h4>Total Withdraw Gudang</h4>
                        <p class="value" style="color: var(--danger-color);"><?= $summary_totals['warehouse']['withdraw'] ?></p>
                        <div class="summary-card-detail">
                            <?php 
                            $warehouse_details = $summary_totals['warehouse']['details'];
                            $products = ['Jagung', 'Anggur', 'Bawang Merah', 'Strawberry', 'Lemon', 'Susu', 'Botol Kosong', 'Gelas Kosong', 'Piring Kosong', 'Bahan Khusus'];
                            foreach ($products as $p) {
                                $qty = $warehouse_details[$p]['withdraw'] ?? 0;
                                if ($qty > 0) {
                                    echo "<span>$p: $qty</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="card full-width">
                    <div class="card-header">
                        <h3>Detail Transaksi Stok Kulkas (Unit)</h3>
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
                                            <span>
                                                pada <?= date('H:i', strtotime($log['transaction_at'])) ?>
                                            </span>
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
                        <h3>Detail Transaksi Stok Gudang</h3>
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
                        <h3>Detail Penjualan (Paket Terjual)</h3>
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
                                            <?php if ($sale_entry['paket_sake'] > 0): ?><li>Sake: <?= $sale_entry['paket_sake'] ?> Paket</li><?php endif; ?>
                                            <?php if ($sale_entry['paket_anggur_merah'] > 0): ?><li>Anggur Merah: <?= $sale_entry['paket_anggur_merah'] ?> Paket</li><?php endif; ?>
                                            <?php if ($sale_entry['paket_tuak'] > 0): ?><li>Tuak: <?= $sale_entry['paket_tuak'] ?> Paket</li><?php endif; ?>
                                            <?php if ($sale_entry['paket_soju'] > 0): ?><li>Soju: <?= $sale_entry['paket_soju'] ?> Paket</li><?php endif; ?>
                                            <?php if ($sale_entry['paket_spicy_1'] > 0): ?><li>Spicy 1: <?= $sale_entry['paket_spicy_1'] ?> Paket</li><?php endif; ?>
                                            <?php if ($sale_entry['paket_azul_1'] > 0): ?><li>Azul 1: <?= $sale_entry['paket_azul_1'] ?> Paket</li><?php endif; ?>
                                            <?php if ($sale_entry['paket_azul_2'] > 0): ?><li>Azul 2: <?= $sale_entry['paket_azul_2'] ?> Paket</li><?php endif; ?>
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