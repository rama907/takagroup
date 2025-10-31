<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$user = getCurrentUser();

// --- DEFINISI HARGA DAN KOMPONEN ---
$PRICES = [
    // Base Packages
    'regular_table' => 150000,
    'vip_table' => 200000,
    'vvip_table' => 300000,
    'vvip_room_only' => 300000,
    'vvip_room_angel_demon' => 1000000,
    'svip_room_angel_demon' => 1500000,

    // Add-Ons
    'addon_drink_3pak' => 50000,
    'addon_angel_demon_15m' => 400000, // Open Table A/D Add-on
    'addon_vvip_extra_guest' => 50000,
    'addon_vvip_extra_time_10m' => 200000, 
    'addon_vvip_angel_extra_guest' => 250000,
    'addon_vvip_angel_extra_time_15m' => 700000, 
    'addon_vvip_angel_extra_angel' => 500000,
    'addon_svip_angel_extra_guest' => 300000,
    'addon_svip_angel_extra_time_15m' => 700000, 
    'addon_svip_angel_extra_angel' => 700000,

    // COGS (Cost of Goods Sold)
    'cost_per_pak_minuman' => 20000,
];

// Inisialisasi variabel feedback
$success = null;
$error = null;

// Ambil daftar semua karyawan untuk dropdown
$all_employees = $conn->query("SELECT id, name, role FROM employees WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);


// --- Handle Form Submission (PHP) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    
    // Konversi nilai yang seharusnya numerik
    $employee_id = (int)($data['employee_id'] ?? $user['id']);
    $base_package = $data['base_package'] ?? '';
    $total_guest = (int)($data['total_guest'] ?? 0);
    $total_drink_addon = (int)($data['total_drink_addon'] ?? 0);
    $total_extra_time = (int)($data['total_extra_time_minutes'] ?? 0); // Disimpan dalam Menit
    $diskon_percentage = (int)($data['diskon_percentage'] ?? 0);
    
    // Hasil Kalkulasi Final
    $total_gross_revenue = (int)($data['final_gross_revenue'] ?? 0);
    $total_net_revenue = (int)($data['final_net_revenue'] ?? 0);
    $talent_share = (int)($data['final_talent_share'] ?? 0);
    $elysium_share = (int)($data['final_elysium_share'] ?? 0);
    $talent_name = $data['talent_name'] ?? '';


    if (empty($base_package) || $total_gross_revenue < 0) {
        $error = "Data penjualan tidak valid. Harap isi paket dasar dan hitung ulang.";
    } else {
        $conn->begin_transaction();
        try {
            // Asumsi tabel sales_table_room sudah dibuat sesuai panduan
            $stmt = $conn->prepare("
                INSERT INTO sales_table_room 
                (employee_id, sale_date, input_time, base_package, total_guest, total_drink_addon, total_extra_time, diskon_percentage, total_gross_revenue, total_net_revenue, talent_share, elysium_share)
                VALUES (?, NOW(), NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                throw new Exception("Gagal menyiapkan query simpan data: " . $conn->error);
            }

            $stmt->bind_param("isiiiiidddd", 
                $employee_id, 
                $base_package, 
                $total_guest, 
                $total_drink_addon, 
                $total_extra_time, 
                $diskon_percentage, 
                $total_gross_revenue, 
                $total_net_revenue,
                $talent_share,
                $elysium_share
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Gagal menyimpan data penjualan: " . $stmt->error);
            }
            
            $conn->commit();
            $success = "Data penjualan Table & Room berhasil disimpan! Pendapatan Bersih: " . formatRupiah($total_net_revenue);
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error saat menyimpan: " . $e->getMessage();
        }
    }
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.') . '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Sales Table & Room - Elysium Night Club</title>
    <link rel="icon" href="LOGO_WOT.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <style>
        .calculator-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        .main-form-section {
            grid-column: 1 / 2;
        }
        .results-section {
            grid-column: 2 / 3;
            position: sticky;
            top: 20px;
            align-self: flex-start;
        }
        .package-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .package-btn {
            padding: 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-lg);
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            background: var(--bg-secondary);
            transition: all 0.2s ease;
        }
        .package-btn.selected, .package-btn:hover {
            border-color: var(--primary-color);
            background: var(--primary-light);
            color: var(--primary-color);
        }
        .add-on-group {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: var(--spacing-lg);
            margin-bottom: 20px;
        }
        .add-on-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-light);
        }
        .add-on-item:last-child {
            border-bottom: none;
        }
        .add-on-item label {
            flex: 1;
            font-size: 0.9rem;
        }
        .add-on-item input[type="number"] {
            width: 70px;
            text-align: center;
            padding: 5px;
            font-size: 0.9rem;
        }
        .results-section .card {
            padding: 0;
        }
        .results-summary {
            font-size: 0.95em;
        }
        .result-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .result-row strong {
            font-size: 1.05em;
        }
        .result-row.final-net {
            border-top: 2px solid var(--primary-color);
            padding-top: 15px;
            margin-top: 10px;
        }
        .result-row.profit-share {
            font-size: 1em;
        }
        .share-elysium { color: var(--success-color); }
        .share-talent { color: var(--primary-color); }
        @media (max-width: 1024px) {
            .calculator-grid {
                grid-template-columns: 1fr;
            }
            .results-section {
                position: static;
            }
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
                    <span class="page-icon">💎</span>
                    Input Penjualan Table & Room
                </h1>
                <p>Kalkulator khusus untuk transaksi paket table, room, dan *talent*.</p>
            </div>
            
            <?php if (isset($success)): ?>
                <div class="success-message">🎉 <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="error-message">❌ <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" id="sales-calculator-form">
                <input type="hidden" name="employee_id" value="<?= $user['id'] ?>">
                <input type="hidden" name="base_package" id="base_package_input">
                <input type="hidden" name="total_guest" id="total_guest_input" value="0">
                <input type="hidden" name="total_extra_time_minutes" id="total_extra_time_minutes_input" value="0">
                <input type="hidden" name="final_gross_revenue" id="final_gross_revenue_input">
                <input type="hidden" name="final_net_revenue" id="final_net_revenue_input">
                <input type="hidden" name="final_talent_share" id="final_talent_share_input">
                <input type="hidden" name="final_elysium_share" id="final_elysium_share_input">


                <div class="calculator-grid">
                    <div class="main-form-section">
                        <div class="card">
                            <div class="card-header"><h3>Pilih Paket Dasar</h3></div>
                            <div class="card-content">
                                <div class="package-options">
                                    <div class="package-btn" data-package="regular_table" data-price="<?= $PRICES['regular_table'] ?>" data-includes="0">Regular Table</div>
                                    <div class="package-btn" data-package="vip_table" data-price="<?= $PRICES['vip_table'] ?>" data-includes="0">VIP Table</div>
                                    <div class="package-btn" data-package="vvip_table" data-price="<?= $PRICES['vvip_table'] ?>" data-includes="0">VVIP Table</div>
                                    <div class="package-btn" data-package="vvip_room_only" data-price="<?= $PRICES['vvip_room_only'] ?>" data-includes="2">VVIP ROOM Only (+2 Pak Minuman)</div>
                                    <div class="package-btn" data-package="vvip_room_angel_demon" data-price="<?= $PRICES['vvip_room_angel_demon'] ?>" data-includes="2">VVIP ROOM & Angel (+2 Pak Minuman)</div>
                                    <div class="package-btn" data-package="svip_room_angel_demon" data-price="<?= $PRICES['svip_room_angel_demon'] ?>" data-includes="4">SVIP ROOM & Angel (+4 Pak Minuman)</div>
                                </div>
                                <div class="form-group" style="margin-top: 20px;">
                                    <label for="talent_name">Nama Talent/Angel/Demon yang Melayani (Kosongkan jika bukan paket Angel/Demon)</label>
                                    <input type="text" id="talent_name" name="talent_name" class="form-input" placeholder="Wajib isi jika ada Angel/Demon yang terlibat">
                                </div>
                            </div>
                        </div>

                        <div class="card" style="margin-top: 20px;">
                            <div class="card-header"><h3>Add-Ons Tambahan</h3></div>
                            <div class="card-content">
                                <div class="add-on-group" id="general-addons">
                                    <div class="add-on-item">
                                        <label>Drink (3 Pak) - @<?= formatRupiah($PRICES['addon_drink_3pak']) ?></label>
                                        <input type="number" id="addon_drink_3pak_input" name="total_drink_addon" min="0" value="0">
                                    </div>
                                </div>

                                <div class="add-on-group" id="package-specific-addons" style="display: none;">
                                    <p style="font-weight: 600; color: var(--primary-color); margin-bottom: 10px;">ADD-ONS BERDASARKAN PAKET</p>
                                    <div id="addon-content">
                                        </div>
                                </div>
                                
                                <div class="add-on-group">
                                    <div class="form-group">
                                        <label for="diskon_percentage">Diskon (%)</label>
                                        <input type="number" id="diskon_percentage" name="diskon_percentage" min="0" max="100" value="0" class="form-input" style="width: 100px;">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="results-section">
                        <div class="card">
                            <div class="card-header"><h3>Ringkasan Keuangan (Real-Time)</h3></div>
                            <div class="card-content">
                                <div class="results-summary">
                                    <div class="result-row"><span>Paket Dasar:</span> <strong id="base_price_display">Rp 0</strong></div>
                                    <div class="result-row"><span>Total Add-Ons:</span> <strong id="addons_total_display">Rp 0</strong></div>
                                    <div class="result-row"><span>Biaya Layanan (Total):</span> <strong id="initial_total_display">Rp 0</strong></div>
                                    <div class="result-row" style="color: var(--danger-color);"><span>Diskon (<span id="diskon_percent_display">0</span>%):</span> <strong id="discount_amount_display">- Rp 0</strong></div>
                                    
                                    <div class="result-row final-net" style="border-bottom: 1px dashed var(--border-light);">
                                        <span>**PENDAPATAN KOTOR**</span> <strong id="gross_revenue_display">Rp 0</strong>
                                    </div>
                                    
                                    <div class="result-row"><span>(-) Biaya Minuman Fasilitas:</span> <strong id="cogs_cost_display">- Rp 0</strong></div>

                                    <div class="result-row final-net" style="border-bottom: none;">
                                        <span>**PENDAPATAN BERSIH (NETT)**</span> <strong id="net_revenue_display">Rp 0</strong>
                                    </div>
                                </div>

                                <div class="section-title" style="margin-top: 20px;">
                                    <h3>Pembagian Hasil (<span id="share_percent_display">100</span>%)</h3>
                                </div>
                                
                                <div class="result-row profit-share share-talent">
                                    <span>Pemasukan Talent (<span id="talent_share_percent">0</span>%):</span> <strong id="talent_share_display">Rp 0</strong>
                                </div>
                                <div class="result-row profit-share share-elysium">
                                    <span>Pemasukan Elysium (<span id="elysium_share_percent">100</span>%):</span> <strong id="elysium_share_display">Rp 0</strong>
                                </div>
                                
                                <button type="submit" class="btn btn-primary" id="save-button" style="margin-top: 20px;">
                                    Simpan Transaksi Final
                                </button>
                                <p id="error-message" class="error-message" style="display:none; margin-top: 10px; text-align: center;"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script src="script.js"></script>
    <script>
        const PRICES = <?= json_encode($PRICES) ?>;

        const PACKAGE_DETAILS = {
            regular_table: { label: "Regular Table", base: PRICES.regular_table, included_packs: 0, has_angel: false },
            vip_table: { label: "VIP Table", base: PRICES.vip_table, included_packs: 0, has_angel: false },
            vvip_table: { label: "VVIP Table", base: PRICES.vvip_table, included_packs: 0, has_angel: false },
            vvip_room_only: { label: "VVIP ROOM Only", base: PRICES.vvip_room_only, included_packs: 2, has_angel: false },
            vvip_room_angel_demon: { label: "VVIP ROOM & Angel", base: PRICES.vvip_room_angel_demon, included_packs: 2, has_angel: true },
            svip_room_angel_demon: { label: "SVIP ROOM & Angel", base: PRICES.svip_room_angel_demon, included_packs: 4, has_angel: true },
        };

        const ADDON_TEMPLATES = {
            regular_table: `
                <div class="add-on-item">
                    <label>1 Angel / Demon (15 Menit) - @${formatRupiah(PRICES.addon_angel_demon_15m)}</label>
                    <input type="number" id="addon_angel_demon_15m_input" name="addon_angel_demon_15m" min="0" value="0" data-is-angel="true">
                </div>
            `,
            vip_table: `
                <div class="add-on-item">
                    <label>1 Angel / Demon (15 Menit) - @${formatRupiah(PRICES.addon_angel_demon_15m)}</label>
                    <input type="number" id="addon_angel_demon_15m_input" name="addon_angel_demon_15m" min="0" value="0" data-is-angel="true">
                </div>
            `,
            vvip_table: `
                <div class="add-on-item">
                    <label>1 Angel / Demon (15 Menit) - @${formatRupiah(PRICES.addon_angel_demon_15m)}</label>
                    <input type="number" id="addon_angel_demon_15m_input" name="addon_angel_demon_15m" min="0" value="0" data-is-angel="true">
                </div>
            `,
            vvip_room_only: `
                <div class="add-on-item">
                    <label>Extra Guest: @${formatRupiah(PRICES.addon_vvip_extra_guest)} / Org (Max 3 Guest)</label>
                    <input type="number" id="addon_vvip_extra_guest_input" name="total_guest" min="0" value="0" data-base-guest="3">
                </div>
                <div class="add-on-item">
                    <label>Extra Time: @${formatRupiah(PRICES.addon_vvip_extra_time_10m)} / 10 Menit (Max 30m)</label>
                    <input type="number" id="addon_vvip_extra_time_input" name="total_extra_time" min="0" value="0" data-unit="10" data-price-per-unit="${PRICES.addon_vvip_extra_time_10m}">
                </div>
            `,
            vvip_room_angel_demon: `
                 <div class="add-on-item">
                    <label>Extra Guest: @${formatRupiah(PRICES.addon_vvip_angel_extra_guest)} / Org (Max 2 Extra)</label>
                    <input type="number" id="addon_vvip_angel_extra_guest_input" name="total_guest" min="0" value="0" data-base-guest="1">
                </div>
                <div class="add-on-item">
                    <label>Extra Time: @${formatRupiah(PRICES.addon_vvip_angel_extra_time_15m)} / 15 Menit (Max 30m)</label>
                    <input type="number" id="addon_vvip_angel_extra_time_input" name="total_extra_time" min="0" value="0" data-unit="15" data-price-per-unit="${PRICES.addon_vvip_angel_extra_time_15m}">
                </div>
                <div class="add-on-item">
                    <label>Extra Angel / Demon: @${formatRupiah(PRICES.addon_vvip_angel_extra_angel)} / Org</label>
                    <input type="number" id="addon_vvip_angel_extra_angel_input" name="addon_extra_angel" min="0" value="0" data-is-angel="true">
                </div>
            `,
            svip_room_angel_demon: `
                 <div class="add-on-item">
                    <label>Extra Guest: @${formatRupiah(PRICES.addon_svip_angel_extra_guest)} / Org (Max 4 Extra)</label>
                    <input type="number" id="addon_svip_angel_extra_guest_input" name="total_guest" min="0" value="0" data-base-guest="1">
                </div>
                <div class="add-on-item">
                    <label>Extra Time: @${formatRupiah(PRICES.addon_svip_angel_extra_time_15m)} / 15 Menit (Max 30m)</label>
                    <input type="number" id="addon_svip_angel_extra_time_input" name="total_extra_time" min="0" value="0" data-unit="15" data-price-per-unit="${PRICES.addon_svip_angel_extra_time_15m}">
                </div>
                <div class="add-on-item">
                    <label>Extra Angel / Demon: @${formatRupiah(PRICES.addon_svip_angel_extra_angel)} / Org</label>
                    <input type="number" id="addon_svip_angel_extra_angel_input" name="addon_extra_angel" min="0" value="0" data-is-angel="true">
                </div>
            `,
        };
        
        let selectedPackage = null;
        let isTalentInvolved = false;

        // Utility to format Rupiah client-side
        function formatRupiah(angka) {
            angka = Math.round(angka);
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            }).format(angka).replace('IDR', 'Rp');
        }

        function calculateTotal() {
            let basePrice = 0;
            let totalAddonsCost = 0;
            let includedPacks = 0;
            let totalExtraTimeMinutes = 0;
            isTalentInvolved = false;
            
            const currentDiscount = parseInt(document.getElementById('diskon_percentage').value) || 0;
            
            if (selectedPackage) {
                const pkg = PACKAGE_DETAILS[selectedPackage];
                basePrice = pkg.base;
                includedPacks = pkg.included_packs;
                
                // Cek apakah paket dasar sudah termasuk Angel/Demon (misalnya VVIP/SVIP ROOM & Angel)
                if (pkg.has_angel) {
                    isTalentInvolved = true;
                }

                // --- 1. Hitung Add-Ons Umum ---
                const drinkAddonQty = parseInt(document.getElementById('addon_drink_3pak_input').value) || 0;
                totalAddonsCost += drinkAddonQty * PRICES.addon_drink_3pak;
                
                // --- 2. Hitung Add-Ons Spesifik Paket ---
                const addonContent = document.getElementById('addon-content');
                if (addonContent.innerHTML !== '') {
                    const extraGuestInput = addonContent.querySelector('input[name="total_guest"]');
                    const extraTimeInput = addonContent.querySelector('input[name="total_extra_time"]');
                    const extraAngelInput = addonContent.querySelector('input[name="addon_extra_angel"]');
                    
                    // Logic Extra Guest / Open Table Angel (Angel Add-on di Open Table)
                    if (extraGuestInput) {
                        const guestQty = parseInt(extraGuestInput.value) || 0;
                        const guestPriceKey = extraGuestInput.id.replace('_input', ''); 
                        const guestBase = parseInt(extraGuestInput.dataset.baseGuest) || 0;

                        if (guestPriceKey === 'addon_angel_demon_15m') { // Khusus Table VIP/VVIP
                            totalAddonsCost += guestQty * PRICES.addon_angel_demon_15m;
                            if (guestQty > 0) isTalentInvolved = true;
                            document.getElementById('total_guest_input').value = guestQty; // Total Angel/Demon
                        } else if (guestQty > 0) { // Room Packages: Extra Guest
                            totalAddonsCost += guestQty * PRICES[guestPriceKey];
                            document.getElementById('total_guest_input').value = guestQty + guestBase;
                        } else {
                            document.getElementById('total_guest_input').value = guestBase;
                        }
                    } else {
                        // Untuk Regular/VIP/VVIP table yang tidak ada Angel/Demon add-on
                        document.getElementById('total_guest_input').value = 0; 
                    }

                    // Logic Extra Time
                    if (extraTimeInput) {
                        const timeQty = parseInt(extraTimeInput.value) || 0;
                        const pricePerUnit = parseInt(extraTimeInput.dataset.pricePerUnit);
                        const unitMinutes = parseInt(extraTimeInput.dataset.unit);

                        totalAddonsCost += timeQty * pricePerUnit;
                        totalExtraTimeMinutes = timeQty * unitMinutes;
                    }
                    
                    // Logic Extra Angel/Demon (hanya Room & Angel)
                    if (extraAngelInput) {
                        const angelQty = parseInt(extraAngelInput.value) || 0;
                        const angelPriceKey = extraAngelInput.id.replace('_input', ''); 
                        totalAddonsCost += angelQty * PRICES[angelPriceKey];
                        if (angelQty > 0) isTalentInvolved = true;
                    }
                }
            }
            
            // --- 3. Perhitungan Total Biaya Layanan ---
            const initialTotal = basePrice + totalAddonsCost;
            
            // --- 4. Hitung Diskon ---
            const discountAmount = initialTotal * (currentDiscount / 100);
            const finalGrossRevenue = initialTotal - discountAmount;
            
            // --- 5. Hitung COGS (Biaya Minuman Fasilitas) ---
            const totalCOGS = includedPacks * PRICES.cost_per_pak_minuman;
            
            // --- 6. Hitung Pendapatan Bersih ---
            const totalNetRevenue = finalGrossRevenue - totalCOGS;

            // --- 7. Profit Sharing Bersyarat ---
            let talentShare;
            let elysiumShare;
            let talentPercentDisplay;
            let elysiumPercentDisplay;

            // Jika ada nama talent yang diinput, atau paket dasar termasuk Angel, atau Add-On Angel dibeli
            const talentNameInput = document.getElementById('talent_name').value.trim();
            if (isTalentInvolved || talentNameInput !== '') {
                talentShare = totalNetRevenue * 0.60;
                elysiumShare = totalNetRevenue * 0.40;
                talentPercentDisplay = 60;
                elysiumPercentDisplay = 40;
            } else {
                talentShare = 0;
                elysiumShare = totalNetRevenue;
                talentPercentDisplay = 0;
                elysiumPercentDisplay = 100;
            }


            // --- UPDATE DISPLAY ---
            document.getElementById('base_price_display').textContent = formatRupiah(basePrice);
            document.getElementById('addons_total_display').textContent = formatRupiah(totalAddonsCost);
            document.getElementById('initial_total_display').textContent = formatRupiah(initialTotal);
            document.getElementById('diskon_percent_display').textContent = currentDiscount;
            document.getElementById('discount_amount_display').textContent = `- ${formatRupiah(discountAmount)}`;
            document.getElementById('gross_revenue_display').textContent = formatRupiah(finalGrossRevenue);
            document.getElementById('cogs_cost_display').textContent = `- ${formatRupiah(totalCOGS)}`;
            document.getElementById('net_revenue_display').textContent = formatRupiah(totalNetRevenue);
            
            document.getElementById('talent_share_display').textContent = formatRupiah(talentShare);
            document.getElementById('elysium_share_display').textContent = formatRupiah(elysiumShare);
            document.getElementById('share_percent_display').textContent = elysiumPercentDisplay; 
            document.getElementById('talent_share_percent').textContent = talentPercentDisplay;
            document.getElementById('elysium_share_percent').textContent = elysiumPercentDisplay;


            // --- UPDATE HIDDEN INPUTS UNTUK SUBMISSION ---
            document.getElementById('final_gross_revenue_input').value = Math.round(finalGrossRevenue);
            document.getElementById('final_net_revenue_input').value = Math.round(totalNetRevenue);
            document.getElementById('final_talent_share_input').value = Math.round(talentShare);
            document.getElementById('final_elysium_share_input').value = Math.round(elysiumShare);
            document.getElementById('total_extra_time_minutes_input').value = totalExtraTimeMinutes;
        }

        function initEventListeners() {
            const packageBtns = document.querySelectorAll('.package-btn');
            const addonContentDiv = document.getElementById('addon-content');
            const specificAddonsDiv = document.getElementById('package-specific-addons');
            const talentNameInput = document.getElementById('talent_name');
            
            // Listener untuk Pilihan Paket Dasar
            packageBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    packageBtns.forEach(b => b.classList.remove('selected'));
                    btn.classList.add('selected');
                    selectedPackage = btn.dataset.package;
                    
                    // Reset add-ons & diskon
                    addonContentDiv.innerHTML = '';
                    document.getElementById('addon_drink_3pak_input').value = 0;
                    document.getElementById('diskon_percentage').value = 0;
                    document.getElementById('base_package_input').value = selectedPackage; // Set package name
                    
                    // Muat Add-ons Spesifik
                    if (ADDON_TEMPLATES[selectedPackage]) {
                        addonContentDiv.innerHTML = ADDON_TEMPLATES[selectedPackage];
                        specificAddonsDiv.style.display = 'block';
                    } else {
                        specificAddonsDiv.style.display = 'none';
                    }
                    
                    // Tambahkan kembali listener ke input baru dan jalankan perhitungan
                    initInputListeners();
                    calculateTotal();
                });
            });

            // Listener untuk semua input angka dan nama talent (untuk profit sharing)
            function initInputListeners() {
                const form = document.getElementById('sales-calculator-form');
                const numberInputs = form.querySelectorAll('input[type="number"]');
                const allInputs = form.querySelectorAll('input, select');

                // Hapus semua listener input lama
                allInputs.forEach(input => {
                    input.removeEventListener('input', calculateTotal);
                });

                // Tambahkan listener input baru ke semua input (angka dan teks)
                allInputs.forEach(input => {
                    input.addEventListener('input', calculateTotal);
                });
                
                // Tambahkan listener untuk change pada select/input file jika ada
                form.querySelector('#talent_name').addEventListener('input', calculateTotal); 
            }
            
            // Inisialisasi awal (jika ada paket, klik yang pertama)
            if(packageBtns.length > 0) {
                 packageBtns[0].click();
            } else {
                 initInputListeners(); // Jika tidak ada paket, set listener dasar
            }

            // Form submission final check
            document.getElementById('sales-calculator-form').addEventListener('submit', function(e) {
                const netRevenue = parseInt(document.getElementById('final_net_revenue_input').value);
                const packageSelected = document.getElementById('base_package_input').value;
                const talentName = document.getElementById('talent_name').value.trim();
                const isAngelAddon = document.getElementById('addon-content').querySelector('input[data-is-angel="true"]')?.value > 0;
                const isTalentPackage = PACKAGE_DETAILS[packageSelected]?.has_angel;
                const errorMessage = document.getElementById('error-message');

                // Validasi 1: Paket harus dipilih
                if (!packageSelected) {
                    e.preventDefault();
                    errorMessage.textContent = '❌ Harap pilih Paket Dasar sebelum menyimpan.';
                    errorMessage.style.display = 'block';
                    return;
                }

                // Validasi 2: Jika ada Angel/Demon terlibat, nama talent WAJIB diisi.
                if ((isTalentPackage || isAngelAddon) && talentName === '') {
                    e.preventDefault();
                    errorMessage.textContent = '❌ Nama Talent wajib diisi untuk paket Angel/Demon.';
                    errorMessage.style.display = 'block';
                    return;
                }
                
                // Validasi 3: Pendapatan bersih tidak boleh negatif
                if (netRevenue < 0) {
                    e.preventDefault();
                    errorMessage.textContent = '❌ Pendapatan Bersih tidak boleh negatif.';
                    errorMessage.style.display = 'block';
                    return;
                }
                
                if (!confirm(`Yakin ingin menyimpan transaksi ini?\nPENDAPATAN BERSIH: ${formatRupiah(netRevenue)}`)) {
                    e.preventDefault();
                    return;
                }
                errorMessage.style.display = 'none';
            });
        }
        
        document.addEventListener('DOMContentLoaded', initEventListeners);
    </script>
</body>
</html>