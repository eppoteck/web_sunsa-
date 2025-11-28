<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('index.php');
}

// Inventory DB and routing
$invDbName = 'peminjaman_barang_db';
$invConn = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
if (!$invConn) {
    flash('error', 'Gagal koneksi ke server database untuk inventory.');
    redirect('dashboard.php');
}

// create database if not exists and select it
mysqli_query($invConn, "CREATE DATABASE IF NOT EXISTS `" . mysqli_real_escape_string($invConn, $invDbName) . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
mysqli_select_db($invConn, $invDbName);

// Ensure barang table exists
$createBarang = "CREATE TABLE IF NOT EXISTS `barang` (
    `id_barang` INT AUTO_INCREMENT PRIMARY KEY,
    `id_kategori` INT,
    `kode_barang` VARCHAR(50) NOT NULL UNIQUE,
    `nama_barang` VARCHAR(100) NOT NULL,
    `deskripsi` TEXT,
    `jumlah_total` INT NOT NULL DEFAULT 1,
    `jumlah_tersedia` INT NOT NULL DEFAULT 1,
    `lokasi_penyimpanan` VARCHAR(100),
    `kondisi` ENUM('baik', 'rusak_ringan', 'rusak_berat') NOT NULL DEFAULT 'baik',
    `status` ENUM('tersedia','dipinjam','dalam_pemeliharaan') NOT NULL DEFAULT 'tersedia',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($invConn, $createBarang);

// Ensure auto_increment counter column exists for code generation
$colCheck = mysqli_query($invConn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . mysqli_real_escape_string($invConn, $invDbName) . "' AND TABLE_NAME='barang' AND COLUMN_NAME='kode_auto_number'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    @mysqli_query($invConn, "ALTER TABLE `barang` ADD COLUMN `kode_auto_number` INT AUTO_INCREMENT UNIQUE");
}

// Ensure flag_status column exists for marking 'hilang'/'habis'
$colCheck = mysqli_query($invConn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . mysqli_real_escape_string($invConn, $invDbName) . "' AND TABLE_NAME='barang' AND COLUMN_NAME='flag_status'");
if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
    @mysqli_query($invConn, "ALTER TABLE `barang` ADD COLUMN `flag_status` VARCHAR(20) NOT NULL DEFAULT ''");
}

// If legacy column `description` exists but `code` is missing or empty, ensure `code` column exists and copy data
$colsRes = mysqli_query($invConn, "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . mysqli_real_escape_string($invConn, $invDbName) . "' AND TABLE_NAME='items'");
$cols = [];
if ($colsRes) {
    while ($crow = mysqli_fetch_assoc($colsRes)) { $cols[] = $crow['COLUMN_NAME']; }
}
if (in_array('description', $cols) && !in_array('code', $cols)) {
    mysqli_query($invConn, "ALTER TABLE `items` ADD COLUMN `code` VARCHAR(255) DEFAULT NULL");
    // copy description -> code (truncate)
    mysqli_query($invConn, "UPDATE `items` SET `code` = LEFT(`description`,255) WHERE (`code` IS NULL OR `code` = '') AND (`description` IS NOT NULL AND `description` != '')");
}

// Ensure peminjaman table exists
$createPeminjaman = "CREATE TABLE IF NOT EXISTS `peminjaman` (
    `id_peminjaman` INT AUTO_INCREMENT PRIMARY KEY,
    `id_karyawan` INT NOT NULL,
    `id_barang` INT DEFAULT NULL,
    `tanggal_pinjam` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `tanggal_kembali_rencana` DATETIME,
    `tanggal_kembali_aktual` DATETIME,
    `tujuan_peminjaman` TEXT,
    `lokasi_penggunaan` VARCHAR(100),
    `status` ENUM('pending','approved','rejected','dipinjam','dikembalikan','terlambat') NOT NULL DEFAULT 'pending',
    `catatan` TEXT,
    `approved_by` INT,
    `approved_at` DATETIME,
    FOREIGN KEY (id_karyawan) REFERENCES karyawan(id_karyawan)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    FOREIGN KEY (id_barang) REFERENCES barang(id_barang)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($invConn, $createPeminjaman);

// Handle add item form (process before any output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $nama_barang = trim($_POST['item_name'] ?? '');
    $kode_barang = trim($_POST['item_code'] ?? '');
    $jumlah_total = intval($_POST['quantity'] ?? 1);
    
    if ($nama_barang !== '') {
        $nama_barangEsc = mysqli_real_escape_string($invConn, $nama_barang);
        $jumlah_total = max(0, $jumlah_total);

        // Auto-generate code if not provided
        if (empty($kode_barang)) {
            // Cari kode unik yang belum dipakai
            $nextNum = 1;
            do {
                $kode_barang_candidate = '#' . str_pad($nextNum, 5, '0', STR_PAD_LEFT);
                $cekKode = mysqli_query($invConn, "SELECT 1 FROM barang WHERE kode_barang = '" . mysqli_real_escape_string($invConn, $kode_barang_candidate) . "' LIMIT 1");
                $exists = ($cekKode && mysqli_num_rows($cekKode) > 0);
                if (!$exists) {
                    $kode_barang = $kode_barang_candidate;
                    break;
                }
                $nextNum++;
            } while (true);
        } else {
            // If user provides code, ensure it starts with #
            if (strpos($kode_barang, '#') !== 0) {
                $kode_barang = '#' . $kode_barang;
            }
        }

        $kode_barangEsc = mysqli_real_escape_string($invConn, $kode_barang);

        // Cek hanya berdasarkan kode_barang (UNIQUE)
        $existing = null;
        if ($kode_barangEsc !== '') {
            $q = "SELECT * FROM barang WHERE kode_barang = '" . $kode_barangEsc . "' LIMIT 1";
            $r = mysqli_query($invConn, $q);
            if ($r && mysqli_num_rows($r) > 0) {
                $existing = mysqli_fetch_assoc($r);
            }
        }

        if ($existing) {
            // Merge: increase jumlah_total and jumlah_tersedia
            $newTotal = intval($existing['jumlah_total']) + $jumlah_total;
            $newAvailable = intval($existing['jumlah_tersedia']) + $jumlah_total;

            // Remove 'habis' flag if quantity is being added
            $flagUpdate = "";
            if ($newTotal > 0 && $existing['flag_status'] === 'habis') {
                $flagUpdate = ", flag_status = ''";
            }

            $uQ = "UPDATE barang SET jumlah_total=" . $newTotal . ", jumlah_tersedia=" . $newAvailable . $flagUpdate . " WHERE id_barang=" . intval($existing['id_barang']) . " LIMIT 1";
            if (mysqli_query($invConn, $uQ)) {
                flash('success', 'Barang sudah terdaftar; jumlah ditambahkan pada item yang ada.');
            } else {
                flash('error', 'Gagal menggabungkan barang: ' . mysqli_error($invConn));
            }
            redirect('inventory.php');
        } else {
            // Insert new item, meskipun nama sama
            $insQ = "INSERT INTO `barang` (`nama_barang`,`kode_barang`,`jumlah_total`,`jumlah_tersedia`) 
                     VALUES ('" . $nama_barangEsc . "','" . $kode_barangEsc . "', " . $jumlah_total . ", " . $jumlah_total . ")";
            if (mysqli_query($invConn, $insQ)) {
                flash('success','Barang berhasil ditambahkan dengan kode: ' . htmlspecialchars($kode_barang));
                redirect('inventory.php');
            } else {
                // handle unique constraint conflict on kode_barang just in case
                if (mysqli_errno($invConn) == 1062 && $kode_barangEsc !== '') {
                    // duplicate code - try to update existing by code
                    $r = mysqli_query($invConn, "SELECT * FROM barang WHERE kode_barang='" . $kode_barangEsc . "' LIMIT 1");
                    if ($r && mysqli_num_rows($r) > 0) {
                        $existing = mysqli_fetch_assoc($r);
                        $newTotal = intval($existing['jumlah_total']) + $jumlah_total;
                        $newAvailable = intval($existing['jumlah_tersedia']) + $jumlah_total;

                        // Remove 'habis' flag if quantity is being added
                        $flagUpdate = "";
                        if ($newTotal > 0 && $existing['flag_status'] === 'habis') {
                            $flagUpdate = ", flag_status = ''";
                        }

                        $uQ = "UPDATE barang SET jumlah_total=" . $newTotal . ", jumlah_tersedia=" . $newAvailable . $flagUpdate . " WHERE id_barang=" . intval($existing['id_barang']) . " LIMIT 1";
                        @mysqli_query($invConn, $uQ);
                        flash('success','Barang kode sama ditemukan; jumlah ditambahkan.');
                        redirect('inventory.php');
                    }
                }
                flash('error','Gagal menambahkan barang: ' . mysqli_error($invConn));
                redirect('inventory.php');
            }
        }
    } else {
        flash('error','Nama barang tidak boleh kosong.');
        redirect('inventory.php');
    }
}

// Handle mark lost/consumed actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_loss') {
    $itemId = intval($_POST['item_id'] ?? 0);
    $mode = $_POST['mode'] ?? 'hilang'; // 'hilang' or 'habis'
    $qty = max(1, intval($_POST['qty'] ?? 1));

    if ($itemId <= 0) {
        flash('error','ID barang tidak valid.');
        redirect('inventory.php');
    }

    // fetch current counts
    $rq = mysqli_query($invConn, "SELECT jumlah_total, jumlah_tersedia FROM barang WHERE id_barang=" . $itemId . " LIMIT 1");
    if (!$rq || mysqli_num_rows($rq) === 0) {
        flash('error','Barang tidak ditemukan.');
        redirect('inventory.php');
    }
    $row = mysqli_fetch_assoc($rq);
    $total = intval($row['jumlah_total']);
    $available = intval($row['jumlah_tersedia']);

    // compute new values depending on mode
    if ($mode === 'habis') {
        // mark as completely used up: zero out totals
            $newTotal = max(0, $total - $qty);
            $reduceAvailable = min($qty, $available);
            $newAvailable = max(0, $available - $reduceAvailable);
            // if after reduction there is nothing left, promote to 'habis'
            if ($newTotal === 0) {
                $mode = 'habis';
            }
    } else {
        // 'hilang' - reduce by qty but do not go below zero
        $newTotal = max(0, $total - $qty);
        $reduceAvailable = min($qty, $available);
        $newAvailable = max(0, $available - $reduceAvailable);
        // if after reduction there is nothing left, promote to 'habis'
        if ($newTotal === 0) {
            $mode = 'habis';
        }
    }

    // decide flag: only mark 'habis' when nothing available; keep 'hilang' when items remain and mode is hilang
    if ($newAvailable === 0) {
        $newFlag = 'habis';
    } elseif ($mode === 'hilang') {
        $newFlag = 'hilang';
    } else {
        $newFlag = '';
    }

    $escFlag = mysqli_real_escape_string($invConn, $newFlag);
    $uQ = "UPDATE barang SET jumlah_total=" . $newTotal . ", jumlah_tersedia=" . $newAvailable . ", flag_status='" . $escFlag . "' WHERE id_barang=" . $itemId . " LIMIT 1";
    if (mysqli_query($invConn, $uQ)) {
        if ($newTotal === 0) {
            @mysqli_query($invConn, "UPDATE barang SET status='dalam_pemeliharaan' WHERE id_barang=" . $itemId . " LIMIT 1");
        }
        // friendly messages
        if ($newFlag === 'habis') {
            flash('success', 'Barang dikurangi: ' . $qty . '. Item sekarang HABIS (0 tersedia).');
        } elseif ($newFlag === 'hilang') {
            flash('success', 'Barang ditandai hilang: ' . $qty . ' unit dikurangi. Tersedia sekarang: ' . $newAvailable);
        } else {
            flash('success', 'Barang dikurangi: ' . $qty . '. Tersedia sekarang: ' . $newAvailable);
        }
    } else {
        flash('error','Gagal memperbarui data barang: ' . mysqli_error($invConn));
    }
    redirect('inventory.php');
}

// Quick mark as HABIS without reducing total (convenience for low-stock checks)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_habis_flag') {
    $itemId = intval($_POST['item_id'] ?? 0);
    if ($itemId <= 0) {
        flash('error','ID barang tidak valid.');
        redirect('inventory.php');
    }
    // set flag_status = 'habis' and jumlah_tersedia = 0 and jumlah_total = 0 (quick mark)
    $uQ = "UPDATE barang SET flag_status='habis', jumlah_tersedia=0, jumlah_total=0, status='dalam_pemeliharaan' WHERE id_barang=" . $itemId . " LIMIT 1";
    if (mysqli_query($invConn, $uQ)) {
        flash('success','Barang ditandai HABIS: jumlah total dan tersedia disetel ke 0.');
    } else {
        flash('error','Gagal menandai HABIS: ' . mysqli_error($invConn));
    }
    redirect('inventory.php');
}

// Get search term if provided (using POST to avoid history)
$search = isset($_POST['search']) ? trim($_POST['search']) : '';
$searchCondition = '';
if (!empty($search)) {
    $searchEsc = mysqli_real_escape_string($invConn, $search);
    $searchCondition = " WHERE nama_barang LIKE '%{$searchEsc}%' OR kode_barang LIKE '%{$searchEsc}%'";
}

// Fetch inventory items (flagged items appear on top)
$inventoryItems = [];
$ri = mysqli_query($invConn, "SELECT * FROM `barang`{$searchCondition} ORDER BY (flag_status <> '') DESC, created_at DESC LIMIT 200");
if ($ri) { while ($r = mysqli_fetch_assoc($ri)) { $inventoryItems[] = $r; } }

// Fetch borrowings with employee info
$borrowedItems = [];
$borrowQ = "SELECT 
    p.id_peminjaman,
    p.tanggal_pinjam,
    p.tanggal_kembali_aktual,
    p.status,
    b.nama_barang,
    b.kode_barang,
    b.jumlah_total,
    k.nama as nama_karyawan,
    k.id_card,
    k.divisi
FROM `peminjaman` p
LEFT JOIN `barang` b ON p.id_barang = b.id_barang
LEFT JOIN `karyawan` k ON p.id_karyawan = k.id_karyawan
WHERE p.status = 'dipinjam'
ORDER BY p.tanggal_pinjam DESC LIMIT 200";
$rb = @mysqli_query($invConn, $borrowQ);
if ($rb) { while ($br = mysqli_fetch_assoc($rb)) { $borrowedItems[] = $br; } }

// --- Export handler for inventory page (CSV) ---
if (isset($_POST['export'])) {
    $which = $_POST['export']; // items | borrowings | all
    $filename = 'inventory_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    if ($which === 'items' || $which === 'all') {
        fputcsv($out, ['ID','Nama Barang','Kode Barang','Jumlah Total','Jumlah Tersedia','Kondisi','Status','Lokasi','Ditambahkan']);
        foreach ($inventoryItems as $it) {
            fputcsv($out, [
                $it['id_barang'],
                $it['nama_barang'],
                $it['kode_barang'] ?? '',
                intval($it['jumlah_total']),
                intval($it['jumlah_tersedia']),
                $it['kondisi'],
                $it['status'],
                $it['lokasi_penyimpanan'] ?? '',
                $it['created_at']
            ]);
        }
    }
    if ($which === 'borrowings' || $which === 'all') {
        fputcsv($out, []);
        fputcsv($out, ['ID Peminjaman','Barang','Kode Barang','Nama Karyawan','ID Card','Divisi','Tanggal Pinjam','Status']);
        foreach ($borrowedItems as $b) {
            fputcsv($out, [
                $b['id_peminjaman'],
                $b['nama_barang'] ?? '',
                $b['kode_barang'] ?? '',
                $b['nama_karyawan'] ?? '',
                $b['id_card'] ?? '',
                $b['divisi'] ?? '',
                $b['tanggal_pinjam'] ?? '',
                $b['status'] ?? ''
            ]);
        }
    }
    fclose($out);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f1f5f9;color:#1e293b;padding:20px; }
        .container { max-width:1100px;margin:0 auto; }
        .card { background:white;border-radius:8px;padding:16px;box-shadow:0 4px 8px rgba(0,0,0,0.06);margin-bottom:16px; }
        .table-container { max-height: 400px; overflow-y: auto; border-radius: 6px; border: 1px solid #e5e7eb; }
        table { width:100%;border-collapse:collapse; }
        th,td { padding:8px;border-bottom:1px solid #e6eef6;text-align:left; }
        thead { position: sticky; top: 0; z-index: 1; }
        .topbar { display:flex;justify-content:space-between;align-items:center;margin-bottom:12px; }
        .btn { background:#4f46e5;color:white;padding:8px 12px;border-radius:6px;text-decoration:none;display:inline-block; border: none; cursor: pointer; }
        .btn:hover { background:#4338ca; }
        .btn-secondary { background:#9ca3af; }
        .btn-secondary:hover { background:#6b7280; }
        .msg-success{padding:8px;background:#dcfce7;color:#065f46;border-radius:6px;margin-bottom:8px}
        .msg-error{padding:8px;background:#fee2e2;color:#7f1d1d;border-radius:6px;margin-bottom:8px}
        .flex{display:flex;gap:12px;align-items:flex-start}
        input,textarea,select{padding:8px;border:1px solid #e5e7eb;border-radius:6px;width:100%;box-sizing:border-box;}
        input[type="number"] { width:120px; }
        .form-group { margin-bottom: 12px; }
        /* row colors */
        .borrowed-row{background:#fff1f2}
        .available-row{background:#ecfdf5}
        thead th{background:#eef2ff}
    .button-group { display: flex; gap: 8px; }
    /* flagged rows (hilang/habis) */
    .flagged-row { background:#fee2e2; }
    .flagged-badge { display:inline-block;padding:4px 8px;background:#ef4444;color:white;border-radius:12px;font-size:12px;margin-left:8px }
    </style>
</head>
<body>
<div class="container">
    <div class="topbar">
        <h2>Inventory - Admin</h2>
        <div>
            <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard</a>
        </div>
    </div>

    <?php if ($m = flash('success')): ?><div class="msg-success"><?php echo htmlspecialchars($m); ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="msg-error"><?php echo htmlspecialchars($m); ?></div><?php endif; ?>

    <div class="card">
        <h3>Tambah Barang</h3>
        <form method="POST" style="max-width:520px;" id="addItemForm" autocomplete="off">
            <input type="hidden" name="action" value="add_item">
            <div class="form-group">
                <input type="text" name="item_name" placeholder="Nama barang" required autocomplete="off">
            </div>
            <div class="form-group">
                <input type="text" name="item_code" placeholder="Kode barang (otomatis jika kosong, mis. #00001)" autocomplete="off">
                <small style="color:#6b7280;margin-top:4px;display:block;">Biarkan kosong untuk auto-generate, atau masukkan custom code (akan ditambah # jika belum ada)</small>
            </div>
            <div class="form-group">
                <input type="number" name="quantity" value="1" min="0" autocomplete="off">
            </div>
            <div class="button-group">
                <button type="submit" class="btn">Tambah Barang</button>
                <button type="reset" class="btn btn-secondary">Reset Form</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <h3 style="margin:0">Daftar Barang (Inventory)</h3>
            <div>
                <form method="POST" action="" style="display:inline">
                    <button type="submit" name="export" value="items" class="btn">Export Items (Excel)</button>
                    <button type="submit" name="export" value="all" class="btn">Export All (Excel)</button>
                </form>
            </div>
        </div>
        
        <!-- Search Form (using POST to avoid history) -->
        <div style="margin-bottom: 16px;">
            <form method="POST" action="" class="search-form" style="display: flex; gap: 8px; max-width: 500px;">
                <input type="text" 
                       name="search" 
                       value="<?php echo htmlspecialchars($search ?? ''); ?>" 
                       placeholder="Cari nama atau kode barang..."
                       style="flex: 1; min-width: 200px; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px;"
                       autocomplete="off">
                <button type="submit" class="btn" style="white-space: nowrap;">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search)): ?>
                    <button type="submit" name="search" value="" class="btn btn-secondary" style="white-space: nowrap;">
                        <i class="fas fa-times"></i> Reset
                    </button>
                <?php endif; ?>
            </form>
            <?php if (!empty($search)): ?>
                <div style="margin-top: 8px; color: #6b7280;">
                    Hasil pencarian untuk: "<?php echo htmlspecialchars($search); ?>"
                </div>
            <?php endif; ?>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>ID</th><th>Nama</th><th>Kode</th><th>Jumlah</th><th>Ditambahkan</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($inventoryItems)): ?>
                        <tr><td colspan="5">Belum ada barang.</td></tr>
                    <?php else: ?>
                        <?php foreach ($inventoryItems as $it): ?>
                            <?php
                                $bq = mysqli_query($invConn, "SELECT COUNT(*) AS cnt FROM peminjaman WHERE id_barang=".intval($it['id_barang'])." AND status='dipinjam'");
                                $brow = $bq ? mysqli_fetch_assoc($bq) : null;
                                $borrowedCnt = $brow ? intval($brow['cnt']) : 0;
                                // flagged items (hilang/habis) should be visually distinct
                                $isFlagged = !empty($it['flag_status']);
                                if ($isFlagged) {
                                    $rowClass = 'flagged-row';
                                } else {
                                    $rowClass = $borrowedCnt>0 ? 'borrowed-row' : 'available-row';
                                }
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td><?php echo htmlspecialchars($it['id_barang']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($it['nama_barang']); ?>
                                    <?php if (!empty($it['flag_status'])): ?>
                                        <span class="flagged-badge"><?php echo htmlspecialchars(strtoupper($it['flag_status'])); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($it['kode_barang']); ?></td>
                                <td><?php echo htmlspecialchars($it['jumlah_total']); ?> (tersedia: <?php echo htmlspecialchars($it['jumlah_tersedia']); ?>)</td>
                                <td><?php echo htmlspecialchars($it['created_at']); ?></td>
                                <td>
                                    <div class="button-group">
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Tandai barang ini sebagai HILANG? Ini akan mengurangi jumlah total.');">
                                            <input type="hidden" name="action" value="mark_loss">
                                            <input type="hidden" name="item_id" value="<?php echo intval($it['id_barang']); ?>">
                                            <input type="hidden" name="mode" value="hilang">
                                            <input type="hidden" name="qty" value="1">
                                            <button class="btn" type="submit" style="background:#ef4444;padding:6px 8px;">Hilang</button>
                                        </form>
                                        <?php if (intval($it['jumlah_total']) > 1): ?>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Tandai barang ini sebagai HABIS TERPAKAI? Ini akan mengurangi jumlah total.');">
                                            <input type="hidden" name="action" value="mark_loss">
                                            <input type="hidden" name="item_id" value="<?php echo intval($it['id_barang']); ?>">
                                            <input type="hidden" name="mode" value="habis">
                                            <input type="hidden" name="qty" value="1">
                                            <button class="btn" type="submit" style="background:#f59e0b;padding:6px 8px;">Habis</button>
                                        </form>
                                        <?php endif; ?>

                                        <!-- Quick 'Habis Stok' button: only show when jumlah tersedia > 5 (exclude 1-4) -->
                                        <?php if (intval($it['jumlah_tersedia']) > 5 && empty($it['flag_status'])): ?>
                                            <form method="POST" style="display:inline" onsubmit="return confirm('Tandai barang ini HABIS STOK? Ini akan menandai item sebagai HABIS dan mengosongkan jumlah tersedia.');">
                                                <input type="hidden" name="action" value="set_habis_flag">
                                                <input type="hidden" name="item_id" value="<?php echo intval($it['id_barang']); ?>">
                                                <button class="btn" type="submit" style="background:#ef4444;padding:6px 8px;">Habis Stok</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3>Daftar Barang yang Dipinjam</h3>
        <div>
            <a class="btn" href="?export=borrowings">Export Borrowings (Excel)</a>
        </div>
    </div>
    <div class="card">
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>ID</th><th>Barang</th><th>Kode Barang</th><th>Karyawan</th><th>Dipinjam Pada</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($borrowedItems)): ?>
                        <tr><td colspan="6">Tidak ada peminjaman aktif.</td></tr>
                    <?php else: ?>
                        <?php foreach ($borrowedItems as $bi): ?>
                            <tr class="borrowed-row">
                                <td><?php echo htmlspecialchars($bi['id_peminjaman']); ?></td>
                                <td><?php echo htmlspecialchars($bi['nama_barang'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($bi['kode_barang'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($bi['nama_karyawan'] ?? '') . ' (' . htmlspecialchars($bi['divisi'] ?? '') . ')'; ?></td>
                                <td><?php echo htmlspecialchars($bi['tanggal_pinjam']); ?></td>
                                <td><?php echo htmlspecialchars($bi['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
