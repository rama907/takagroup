<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();
$pending_requests_count = getPendingRequestCount();
$is_manager_or_higher = hasRole(['ceo', 'direktur', 'wakil_direktur', 'manager']);

$success = null;
$error = null;

// Handle form submission for deposit or withdraw
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim($_POST['action']);
    $product_name = $_POST['product_name'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 0);

    if (empty($product_name) || $quantity <= 0) {
        $error = "Nama produk dan jumlah harus diisi dengan benar!";
    } else {
        $conn->begin_transaction();
        try {
            // Get current stock
            $stmt = $conn->prepare("SELECT quantity FROM warehouse_stock WHERE product_name = ? FOR UPDATE");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query: " . $conn->error);
            }
            $stmt->bind_param("s", $product_name);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $current_quantity = $result['quantity'] ?? 0;
            $stmt->close();

            $new_quantity = $current_quantity;
            $transaction_type = '';

            if ($action === 'deposit') {
                $new_quantity += $quantity;
                $transaction_type = 'deposit';
            } elseif ($action === 'withdraw') {
                if ($current_quantity < $quantity) {
                    throw new Exception("Stok tidak mencukupi untuk penarikan!");
                }
                $new_quantity -= $quantity;
                $transaction_type = 'withdraw';
            } else {
                throw new Exception("Aksi tidak valid.");
            }

            // Update stock
            $stmt = $conn->prepare("UPDATE warehouse_stock SET quantity = ? WHERE product_name = ?");
            if (!$stmt) {
                 throw new Exception("Gagal menyiapkan query update stok: " . $conn->error);
            }
            $stmt->bind_param("is", $new_quantity, $product_name);
            $stmt->execute();
            $stmt->close();

            // Log transaction
            $stmt = $conn->prepare("INSERT INTO warehouse_transactions (product_name, employee_id, transaction_type, quantity) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query log transaksi: " . $conn->error);
            }
            $stmt->bind_param("sisi", $product_name, $user['id'], $transaction_type, $quantity);
            if (!$stmt->execute()) {
                throw new Exception("Gagal menyimpan log transaksi: " . $stmt->error);
            }
            $stmt->close();

            $conn->commit();
            $success = "Transaksi " . ucfirst($transaction_type) . " berhasil untuk produk " . htmlspecialchars(str_replace('_', ' ', $product_name)) . " sebanyak " . $quantity;
            
            // Redirect untuk menampilkan pesan sukses dan memuat ulang data
            header("Location: warehouse-stock.php?msg=" . urlencode($success) . "&type=success");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Gagal memproses transaksi: " . $e->getMessage();
            // Redirect untuk menampilkan pesan error
            header("Location: warehouse-stock.php?msg=" . urlencode($error) . "&type=error");
            exit;
        }
    }
}

// Menampilkan pesan feedback setelah redirect
if (isset($_GET['msg']) && isset($_GET['type'])) {
    $feedback_message = htmlspecialchars($_GET['msg']);
    $feedback_type = htmlspecialchars($_GET['type']);
    if ($feedback_type === 'success') {
        $success = $feedback_message;
    } else {
        $error = $feedback_message;
    }
}

// Get current stock levels
$stock_levels = $conn->query("SELECT * FROM warehouse_stock")->fetch_all(MYSQLI_ASSOC);

// Get transaction history (only for managers or higher)
$transactions = [];
if ($is_manager_or_higher) {
    $stmt = $conn->prepare("
        SELECT rt.*, e.name as employee_name
        FROM warehouse_transactions rt
        JOIN employees e ON rt.employee_id = e.id
        ORDER BY rt.transaction_at DESC
        LIMIT 50
    ");
    if ($stmt) {
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Stok Gudang - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="style.css">
    <style>
        .stock-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-2xl);
        }
        .stock-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            text-align: center;
            box-shadow: var(--shadow-sm);
        }
        .stock-card h4 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: var(--spacing-xs);
        }
        .stock-card .quantity {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        .stock-form-section {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-lg);
        }
        .stock-form-section .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: var(--spacing-lg);
        }
        .transaction-log-section {
            margin-top: var(--spacing-2xl);
        }
        .log-item {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-light);
            padding: var(--spacing-md);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .log-item:last-child {
            border-bottom: none;
        }
        .log-info {
            display: flex;
            flex-direction: column;
        }
        .log-info span {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        .log-info strong {
            color: var(--text-primary);
        }
        .log-action {
            display: flex;
            align-items: center;
            gap: var(--spacing-md);
            margin-top: var(--spacing-sm);
        }
        .log-type-badge {
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .log-type-deposit {
            background-color: var(--success-light);
            color: var(--success-color);
        }
        .log-type-withdraw {
            background-color: var(--danger-light);
            color: var(--danger-color);
        }
        .log-quantity {
            font-weight: 700;
            color: var(--primary-color);
            font-size: 1.25rem;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'includes/header.php'; ?>
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h1>
                    <span class="page-icon">📦</span>
                    Manajemen Stok Gudang
                </h1>
                <p>Kelola deposit dan withdraw stok produk.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="stock-grid">
                <?php foreach ($stock_levels as $stock): ?>
                    <div class="stock-card">
                        <h4><?= htmlspecialchars(str_replace('_', ' ', $stock['product_name'])) ?></h4>
                        <p class="quantity"><?= $stock['quantity'] ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card full-width">
                <div class="card-header">
                    <h3>Deposit/Withdraw Stok</h3>
                </div>
                <div class="card-content stock-form-section">
                    <form method="POST">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="product_name">Produk</label>
                                <select name="product_name" id="product_name" class="form-select" required>
                                    <option value="">Pilih Produk</option>
                                    <?php foreach ($stock_levels as $stock): ?>
                                        <option value="<?= htmlspecialchars($stock['product_name']) ?>">
                                            <?= htmlspecialchars(str_replace('_', ' ', $stock['product_name'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="quantity">Jumlah</label>
                                <input type="number" name="quantity" id="quantity" class="form-input" min="1" required>
                            </div>
                        </div>
                        <div class="form-actions" style="border-top: none; padding-top: 0;">
                            <button type="submit" name="action" value="deposit" class="btn btn-success">
                                <span class="btn-icon">➕</span> Deposit
                            </button>
                            <button type="submit" name="action" value="withdraw" class="btn btn-danger">
                                <span class="btn-icon">➖</span> Withdraw
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($is_manager_or_higher): ?>
                <div class="card full-width transaction-log-section">
                    <div class="card-header">
                        <h3>Riwayat Transaksi Stok</h3>
                    </div>
                    <div class="card-content">
                        <?php if (empty($transactions)): ?>
                            <div class="no-data">Belum ada riwayat transaksi.</div>
                        <?php else: ?>
                            <div class="requests-list">
                                <?php foreach ($transactions as $log): ?>
                                    <div class="log-item">
                                        <div class="log-info">
                                            <strong><?= htmlspecialchars(str_replace('_', ' ', $log['product_name'])) ?></strong>
                                            <span>
                                                oleh <span style="font-weight: 600;"><?= htmlspecialchars($log['employee_name']) ?></span> pada <?= date('d/m/Y H:i', strtotime($log['transaction_at'])) ?>
                                            </span>
                                        </div>
                                        <div class="log-action">
                                            <span class="log-type-badge log-type-<?= $log['transaction_type'] ?>">
                                                <?= ucfirst($log['transaction_type']) ?>
                                            </span>
                                            <span class="log-quantity"><?= $log['quantity'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>