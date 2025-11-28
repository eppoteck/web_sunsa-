<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// only admin can manage borrowings page, but allow viewing by other roles if desired
if (!isAdmin()) {
    // optionally restrict - for now allow admins only
    redirect('dashboard.php');
}

$invDbName = 'peminjaman_barang_db';

// Pastikan koneksi ke database yang benar
$invConn = mysqli_connect('localhost', 'root', '', $invDbName);
if (!$invConn) {
    error_log("Koneksi database error: " . mysqli_connect_error());
    flash('error','Gagal koneksi ke database peminjaman: ' . mysqli_connect_error());
    redirect('dashboard.php');
}

// Verifikasi koneksi ke database yang benar
if ($invConn && mysqli_select_db($invConn, $invDbName)) {
    error_log("Berhasil terhubung ke database: " . $invDbName);
    
    // Cek struktur tabel karyawan
    $checkTable = mysqli_query($invConn, "DESCRIBE karyawan");
    if ($checkTable) {
        error_log("Tabel karyawan ditemukan dengan kolom:");
        while ($field = mysqli_fetch_assoc($checkTable)) {
            error_log("- " . $field['Field'] . " (" . $field['Type'] . ")");
        }
    } else {
        error_log("Error saat mengecek tabel karyawan: " . mysqli_error($invConn));
    }
} else {
    error_log("Gagal memilih database: " . mysqli_error($invConn));
    flash('error','Gagal memilih database peminjaman.');
    redirect('dashboard.php');
}

// ensure inventory DB exists and select
mysqli_query($invConn, "CREATE DATABASE IF NOT EXISTS `" . mysqli_real_escape_string($invConn, $invDbName) . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
mysqli_select_db($invConn, $invDbName);

// Make sure peminjaman table exists
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

// Fetch RFID options from karyawan table
$rfidOptions = [];
$rfidQuery = "SELECT id_karyawan, nama, uid_kartu, id_card FROM karyawan WHERE uid_kartu IS NOT NULL AND uid_kartu != '' ORDER BY nama";
$rfidResult = mysqli_query($invConn, $rfidQuery);
if ($rfidResult) {
    while ($row = mysqli_fetch_assoc($rfidResult)) {
        // prefer uid_kartu if available, otherwise use id_card as identifier
        $value = (!empty($row['uid_kartu']) ? $row['uid_kartu'] : $row['id_card']);
        $rfidOptions[] = [
            'rfid' => $value,
            'name' => $row['nama'] . ' (' . $row['id_card'] . ')',
            'uid_kartu' => $row['uid_kartu'],
            'id_card' => $row['id_card'],
            'id_karyawan' => $row['id_karyawan']
        ];
    }
}

// Helper: robust lookup in karyawan table for a given RFID value
function findKaryawanByRFID($invConn, $value) {
    $v = trim($value);
    if ($v === '') return null;
    $esc = mysqli_real_escape_string($invConn, $v);

    // 1) exact match on uid_kartu or id_card
    $q = "SELECT id_karyawan, nama, uid_kartu, id_card FROM karyawan WHERE uid_kartu = '" . $esc . "' OR id_card = '" . $esc . "' LIMIT 1";
    $r = @mysqli_query($invConn, $q);
    if ($r && mysqli_num_rows($r) > 0) return mysqli_fetch_assoc($r);

    // 2) trimmed leading zeros match on both columns
    $q2 = "SELECT id_karyawan, nama, uid_kartu, id_card FROM karyawan WHERE TRIM(LEADING '0' FROM uid_kartu) = TRIM(LEADING '0' FROM '" . $esc . "') OR TRIM(LEADING '0' FROM id_card) = TRIM(LEADING '0' FROM '" . $esc . "') LIMIT 1";
    $r2 = @mysqli_query($invConn, $q2);
    if ($r2 && mysqli_num_rows($r2) > 0) return mysqli_fetch_assoc($r2);

    // 3) numeric compare if value is numeric (compare both columns)
    if (preg_match('/^\d+$/', $v)) {
        $q3 = "SELECT id_karyawan, nama, uid_kartu, id_card FROM karyawan WHERE CAST(uid_kartu AS UNSIGNED) = " . intval($v) . " OR CAST(id_card AS UNSIGNED) = " . intval($v) . " LIMIT 1";
        $r3 = @mysqli_query($invConn, $q3);
        if ($r3 && mysqli_num_rows($r3) > 0) return mysqli_fetch_assoc($r3);
    }

    // 4) fallback LIKE on both columns
    $q4 = "SELECT id_karyawan, nama, uid_kartu, id_card FROM karyawan WHERE uid_kartu LIKE '%" . $esc . "%' OR id_card LIKE '%" . $esc . "%' LIMIT 1";
    $r4 = @mysqli_query($invConn, $q4);
    if ($r4 && mysqli_num_rows($r4) > 0) return mysqli_fetch_assoc($r4);

    return null;
}

// Process return POST (before output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return') {
    $borrow_id = intval($_POST['borrow_id'] ?? 0);
    if ($borrow_id <= 0) {
        flash('error','ID peminjaman tidak valid.');
        redirect('peminjaman.php');
    }

    // Mark as returned if currently borrowed
    mysqli_begin_transaction($invConn);
    $upd = mysqli_query($invConn, "UPDATE `peminjaman` SET `status`='dikembalikan', `tanggal_kembali_aktual`=NOW() WHERE id_peminjaman=" . $borrow_id . " AND `status`='dipinjam'");
    if ($upd && mysqli_affected_rows($invConn) > 0) {
        // commit
        mysqli_commit($invConn);
        flash('success','Barang berhasil dikembalikan.');
    } else {
        mysqli_rollback($invConn);
        flash('error','Gagal mengembalikan barang atau sudah dikembalikan.');
    }
    redirect('peminjaman.php');
}

// Process confirmed borrow POST (when admin confirmed an unregistered RFID)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_borrow') {
    $item_id = intval($_POST['item_id'] ?? 0);
    $rfid = trim($_POST['rfid'] ?? '');
    // require explicit acknowledgement checkbox
    if (empty($_POST['confirm_ack'])) {
        flash('error','Anda harus mencentang persetujuan sebelum melanjutkan.');
        redirect('peminjaman.php');
    }
    if ($item_id <= 0 || $rfid === '') {
        flash('error','Pilih RFID dan Barang terlebih dahulu.');
        redirect('peminjaman.php');
    }

    // Try to get employee info from local karyawan table using helper
    $employee_id = null;
    $employee_name = null;
    $k = findKaryawanByRFID($invConn, $rfid);
    if ($k) {
        $employee_id = $k['id_karyawan'];
        $employee_name = $k['nama'];

        $cols = ['id_barang', 'id_karyawan', 'status', 'tanggal_pinjam'];
        $vals = [ 
            intval($item_id), 
            intval($employee_id),
            "'dipinjam'",
            "NOW()"
        ];

        $ins = "INSERT INTO `peminjaman` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ")";
        if (mysqli_query($invConn, $ins)) {
            flash('success','Peminjaman tercatat. (Dikonfirmasi oleh admin)');
        } else {
            flash('error','Gagal mencatat peminjaman: ' . mysqli_error($invConn));
        }
    } else {
        error_log("Karyawan tidak ditemukan untuk RFID: " . $rfid);
        // Cek total data di tabel karyawan
        $checkQuery = "SELECT COUNT(*) as total FROM karyawan";
        $checkResult = mysqli_query($invConn, $checkQuery);
        $checkData = mysqli_fetch_assoc($checkResult);
        error_log("Total data di tabel karyawan: " . $checkData['total']);
        flash('error', 'Karyawan dengan RFID tersebut tidak ditemukan. Total data karyawan: ' . $checkData['total']);
    }
    redirect('peminjaman.php');
}

// Process borrow POST (before output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'borrow') {
    $item_id = intval($_POST['item_id'] ?? 0);
    $rfid = trim($_POST['rfid'] ?? '');
    if ($item_id <= 0 || $rfid === '') {
        flash('error','Pilih RFID dan Barang terlebih dahulu.');
        redirect('peminjaman.php');
    }

    // ensure item exists and has available stock
    $iq = mysqli_query($invConn, "SELECT b.*, COALESCE(bc.cnt,0) AS borrowed_count FROM `barang` b LEFT JOIN (SELECT id_barang, COUNT(*) AS cnt FROM peminjaman WHERE status='dipinjam' GROUP BY id_barang) bc ON bc.id_barang=b.id_barang WHERE b.id_barang=" . $item_id . " LIMIT 1");
    $item = mysqli_fetch_assoc($iq);
    if (!$item) { flash('error','Barang tidak ditemukan.'); redirect('peminjaman.php'); }
    $available = intval($item['jumlah_total']) - intval($item['borrowed_count']);
    if ($available <= 0) { flash('error','Stok tidak tersedia untuk barang ini.'); redirect('peminjaman.php'); }

    // Try to get employee info from karyawan table using helper
    $employee_id = null;
    $employee_name = null;
    $k = findKaryawanByRFID($invConn, $rfid);
    if ($k) {
        $employee_id = $k['id_karyawan'];
        $employee_name = $k['nama'];
    }

    // If RFID not found in karyawan table, show error
    if (!$employee_id) {
        flash('error', 'RFID ' . htmlspecialchars($rfid) . ' tidak terdaftar di sistem. Silahkan daftarkan karyawan terlebih dahulu.');
        redirect('peminjaman.php');
    } else {
        // insert borrowing with employee info
        $cols = ['id_barang', 'id_karyawan', 'status', 'tanggal_pinjam'];
        $vals = [ 
            intval($item_id),
            intval($employee_id),
            "'dipinjam'",
            "NOW()"
        ];
        
        $ins = "INSERT INTO `peminjaman` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ")";
        if (mysqli_query($invConn, $ins)) {
            flash('success','Peminjaman tercatat.');
        } else {
            flash('error','Gagal mencatat peminjaman: ' . mysqli_error($invConn));
        }
        redirect('peminjaman.php');
    }
}

// Prepare lists: borrowed items vs not borrowed
$itemsRes = mysqli_query($invConn, "SELECT i.*, COALESCE(bc.cnt,0) AS borrowed_count FROM `barang` i LEFT JOIN (SELECT id_barang, COUNT(*) AS cnt FROM peminjaman WHERE status='dipinjam' GROUP BY id_barang) bc ON bc.id_barang=i.id_barang ORDER BY i.created_at DESC");
$items = [];
if ($itemsRes) { while ($ir = mysqli_fetch_assoc($itemsRes)) { $items[] = $ir; } }

$borrowedList = array_filter($items, function($it){ return intval($it['borrowed_count']) > 0; });
$notBorrowedList = array_filter($items, function($it){ return intval($it['borrowed_count']) === 0; });

// Fetch active borrowings with employee info from karyawan table
$activeBorrowings = [];
$borrowingsSelect = "SELECT 
    p.id_peminjaman,
    p.id_barang,
    p.id_karyawan,
    p.tanggal_pinjam,
    p.tanggal_kembali_aktual,
    p.status,
    b.nama_barang,
    b.kode_barang,
    k.nama AS nama_karyawan,
    k.id_card,
    k.uid_kartu,
    k.divisi,
    k.jabatan,
    k.created_at
FROM peminjaman p 
LEFT JOIN barang b ON p.id_barang = b.id_barang 
LEFT JOIN karyawan k ON p.id_karyawan = k.id_karyawan
WHERE p.status = 'dipinjam' 
ORDER BY p.tanggal_pinjam DESC";
$rb = @mysqli_query($invConn, $borrowingsSelect);
if ($rb) { while ($row = mysqli_fetch_assoc($rb)) { $activeBorrowings[] = $row; } }

// Data karyawan sudah termasuk dalam query peminjaman aktif, tidak perlu mencari data tambahan lagi

// --- Export handler (CSV) - must run before any HTML output ---
if (isset($_GET['export'])) {
    $which = $_GET['export']; // borrowed | not_borrowed | all
    $filename = 'peminjaman_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // output BOM for Excel to recognize UTF-8
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    if ($which === 'borrowed' || $which === 'all') {
        // header for borrowed
        fputcsv($out, ['ID','Nama','Kode','Jumlah Dipinjam','Terakhir Dipinjam']);
        foreach ($borrowedList as $b) {
            $tq = mysqli_query($invConn, "SELECT borrowed_at FROM borrowings WHERE item_id=".intval($b['id'])." AND status='borrowed' ORDER BY borrowed_at DESC LIMIT 1");
            $tr = $tq ? mysqli_fetch_assoc($tq) : null;
            fputcsv($out, [ $b['id'], $b['name'], $b['code'] ?? '', intval($b['borrowed_count']), $tr['borrowed_at'] ?? '' ]);
        }
    }
    if ($which === 'not_borrowed' || $which === 'all') {
        // header for not borrowed
        fputcsv($out, []); // blank line between sections
        fputcsv($out, ['ID','Nama','Kode','Stok','Ditambahkan']);
        foreach ($notBorrowedList as $n) {
            fputcsv($out, [ $n['id'], $n['name'], $n['code'] ?? '', intval($n['quantity']), $n['created_at'] ]);
        }
    }
    fclose($out);
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Peminjaman Barang</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset & Base Styles */
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            background: #f0f2f5;
            color: #1a1a1a;
            padding: 24px;
            margin: 0;
            line-height: 1.6;
        }
        
        /* Layout */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
        }
        
        /* Card Styles */
        .card {
            background: #fff;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05), 0 8px 16px rgba(0,0,0,0.05);
            margin-bottom: 24px;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 12px 20px rgba(0,0,0,0.08);
        }
        
        /* Typography */
        h2, h3 {
            margin: 0 0 16px 0;
            color: #2c3e50;
        }
        
        /* Flex Layout */
        .flex {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }
        
        /* Form Elements */
        select, input {
            width: 100%;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.2s ease;
            font-size: 14px;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        /* Button Styles */
        .btn {
            background: #4f46e5;
            color: #fff;
            padding: 10px 16px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }
        
        .btn i {
            font-size: 14px;
        }
        
        /* Table Styles */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 8px 0;
        }
        
        th, td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            font-size: 14px;
        }
        
        th {
            font-weight: 600;
            background: #f8fafc;
            position: sticky;
            top: 0;
        }
        
        /* Status Colors */
        .borrowed-row {
            background: rgba(239, 68, 68, 0.05);
        }
        
        .available-row {
            background: rgba(16, 185, 129, 0.05);
        }
        
        /* Helper Classes */
        .muted {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }
        
        /* Form Compact Styles */
        .form-compact {
            width: 100%;
        }

        /* use grid so the three columns are perfectly aligned */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 160px;
            gap: 24px;
            /* align items to the end so inputs and button share the same baseline */
            align-items: end;
        }

        .form-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-field label { margin: 0; }

        .form-actions {
            display: flex;
            align-items: flex-end; /* keep button bottom-aligned with inputs */
            justify-content: flex-end;
        }

        .form-compact input,
        .form-compact select,
        .form-compact datalist,
        .custom-select-display {
            height: 48px; /* uniform height */
            padding: 12px 16px;
            font-size: 14px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            transition: all 0.15s ease;
            box-sizing: border-box;
            background: #fff;
        }

        .form-compact input:focus,
        .form-compact select:focus,
        .custom-select-display:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.07);
            outline: none;
        }

        .form-compact .btn {
            padding: 12px 18px;
            font-size: 14px;
            min-width: 120px;
            justify-content: center;
            font-weight: 500;
            border-radius: 10px;
            height: 48px; /* match inputs */
            align-self: center;
        }

        /* small screens: stack and keep full width */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .form-actions { justify-content: stretch; }
            .form-compact .btn { width: 100%; }
        }

        .custom-select-list {
            position: absolute;
            width: 100%;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 10px 15px rgba(0,0,0,0.08);
            margin-top: 8px;
            z-index: 1000;
        }
        
        .custom-select-list::-webkit-scrollbar {
            width: 8px;
        }
        
        .custom-select-list::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        
        .custom-select-list::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        
        .custom-select-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .custom-select-option {
            padding: 8px 12px;
            transition: all 0.12s ease;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .custom-select-option:last-child {
            border-bottom: none;
        }

        .custom-select-option:hover { background: #f8fafc; }

        .custom-select-option[data-disabled="1"] { opacity: 0.5; cursor: not-allowed; }
        
        /* Message Styles */
        .card.success {
            background: #dcfce7;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .card.error {
            background: #fee2e2;
            color: #7f1d1d;
            border-left: 4px solid #ef4444;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 16px;
            }
            
            .container {
                padding: 0 8px;
            }
            
            .card {
                padding: 16px;
            }
            
            .flex {
                flex-direction: column;
                gap: 12px;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            table {
                font-size: 13px;
            }
            
            th, td {
                padding: 8px;
            }
            
            .form-compact .flex {
                flex-direction: column;
                gap: 12px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card" style="background: linear-gradient(135deg, #4f46e5, #6366f1); color: white; margin-bottom: 24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h2 style="margin:0;color:white;">Peminjaman Barang</h2>
            <a href="dashboard.php" class="btn" style="background:rgba(255,255,255,0.2);backdrop-filter:blur(10px);">
                <i class="fas fa-arrow-left"></i> 
                <span>Dashboard</span>
            </a>
        </div>
    </div>

    <?php if ($m = flash('success')): ?><div class="card success"><?php echo htmlspecialchars($m); ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="card error"><?php echo htmlspecialchars($m); ?></div><?php endif; ?>

    <!-- Form konfirmasi dihapus karena sekarang menggunakan data langsung dari tabel karyawan -->

    <div class="card">
        <h3>Form Peminjaman</h3>
            <p class="muted">Pilih RFID (diambil dari tabel <code>karyawan</code> di database <code>peminjaman_barang_db</code>) dan barang yang ingin dipinjam.</p>
    <form method="POST" class="form-compact" style="margin-top:16px" autocomplete="off">
            <input type="hidden" name="action" value="borrow">
            <div class="form-row">
                <div class="form-field">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#2c3e50;margin:0;">
                        <i class="fas fa-id-card" style="color:#4f46e5"></i>
                        <span>RFID / Karyawan</span>
                    </label>
                    <div style="position:relative;">
                        <input list="rfid_list" name="rfid" id="rfid_input" required placeholder="Ketik atau scan RFID" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                        <i class="fas fa-search" style="position:absolute;right:16px;top:50%;transform:translateY(-50%);color:#94a3b8"></i>
                    </div>
                    <datalist id="rfid_list">
                        <?php if (!empty($rfidOptions)): ?>
                            <?php foreach ($rfidOptions as $r): ?>
                                <?php $label = $r['rfid'] . (isset($r['name']) ? ' — ' . $r['name'] : ''); ?>
                                <option value="<?php echo htmlspecialchars($r['rfid']); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </datalist>
                </div>

                <div class="form-field">
                    <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#2c3e50;margin:0;">
                        <i class="fas fa-box" style="color:#4f46e5"></i>
                        <span>Barang</span>
                    </label>
                    <div class="custom-select-wrapper" style="position:relative;">
                        <div id="item_select_display" class="custom-select-display" role="button" tabindex="0">
                            <span>-- pilih barang --</span>
                            <i class="fas fa-chevron-down" style="color:#94a3b8"></i>
                        </div>
                        <div id="item_select_list" class="custom-select-list" style="display:none;max-height:250px;overflow-y:auto;">
                            <?php foreach ($items as $it): ?>
                                <?php $available = intval($it['jumlah_total']) - intval($it['borrowed_count']); ?>
                                <div class="custom-select-option" data-value="<?php echo intval($it['id_barang']); ?>" <?php echo ($available<=0)?'data-disabled="1"':''; ?>>
                                    <span><?php echo htmlspecialchars($it['nama_barang'] . ' (' . ($it['kode_barang'] ?? '') . ')'); ?></span>
                                    <span style="color:#64748b;font-size:13px;">Tersedia: <?php echo $available; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <select name="item_id" id="item_select_native" required style="display:none;">
                            <option value="">-- pilih barang --</option>
                            <?php foreach ($items as $it): ?>
                                <?php $available = intval($it['jumlah_total']) - intval($it['borrowed_count']); ?>
                                <option value="<?php echo intval($it['id_barang']); ?>" <?php echo ($available<=0)?'disabled':''; ?>><?php echo htmlspecialchars($it['nama_barang'] . ' (' . ($it['kode_barang'] ?? '') . ') — tersedia: ' . $available); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn" type="submit">
                        <i class="fas fa-check"></i>
                        <span>Pinjam</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>
        // focus RFID input to facilitate scanning
        (function(){
            var el = document.getElementById('rfid_input');
            if (el) {
                try {
                    // disable browser autofill and clear any lingering value
                    try { el.setAttribute('autocomplete','off'); } catch(e){}
                    try { el.autocomplete = 'off'; } catch(e){}
                    el.value = '';
                    el.focus(); el.select();
                } catch(e){}
            }
        })();
    </script>

    <style>
        /* custom select option hover and disabled styles */
        .custom-select-list .custom-select-option:hover { background:#f1f5f9; }
        .custom-select-list .custom-select-option[data-disabled="1"] { color:#9ca3af; cursor:not-allowed; }
        .custom-select-display.open { box-shadow:0 6px 18px rgba(2,6,23,0.08); }
    </style>

    <script>
        (function(){
            var display = document.getElementById('item_select_display');
            var list = document.getElementById('item_select_list');
            var native = document.getElementById('item_select_native');

            if (!display || !list || !native) return;

            // toggle list visibility
            display.addEventListener('click', function(e){
                e.stopPropagation();
                var shown = list.style.display === 'block';
                list.style.display = shown ? 'none' : 'block';
                display.classList.toggle('open', !shown);
            });

            // click option
            Array.prototype.forEach.call(list.querySelectorAll('.custom-select-option'), function(opt){
                opt.addEventListener('click', function(e){
                    if (opt.getAttribute('data-disabled') === '1') return;
                    var val = opt.getAttribute('data-value');
                    // set native select value
                    native.value = val;
                    // update display text
                    display.textContent = opt.textContent;
                    // close list
                    list.style.display = 'none';
                    display.classList.remove('open');
                });
            });

            // click outside closes
            document.addEventListener('click', function(){
                list.style.display = 'none';
                display.classList.remove('open');
            });

            // keyboard support: open on ArrowDown, navigate with arrows, enter to select
            var options = list.querySelectorAll('.custom-select-option');
            var idx = -1;
            display.addEventListener('keydown', function(e){
                if (e.key === 'ArrowDown') { e.preventDefault(); list.style.display='block'; display.classList.add('open'); idx = Math.min(idx+1, options.length-1); highlight(); }
                if (e.key === 'ArrowUp') { e.preventDefault(); list.style.display='block'; display.classList.add('open'); idx = Math.max(idx-1, 0); highlight(); }
                if (e.key === 'Enter') { e.preventDefault(); if (idx>=0 && options[idx]) options[idx].click(); }
            });
            function highlight(){
                Array.prototype.forEach.call(options, function(o,i){ o.style.background = (i===idx? '#eef2ff':''); });
                if (idx>=0 && options[idx]) options[idx].scrollIntoView({block:'nearest'});
            }
        })();
    </script>

    <div class="card" style="background:#f8fafc;border-left:4px solid #4f46e5;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;">Daftar Peminjaman Aktif</h3>
            <div style="display:flex;gap:8px;">
                <a class="btn" href="?export=borrowed" style="background:#10b981;">
                    <i class="fas fa-file-export"></i>
                    <span>Export Dipinjam</span>
                </a>
                <a class="btn" href="?export=all" style="background:#6366f1;">
                    <i class="fas fa-file-export"></i>
                    <span>Export Semua</span>
                </a>
            </div>
        </div>
    </div>
    <div class="card">
        <div style="overflow-x:auto;margin-top:8px">
            <table>
                <thead><tr><th>ID</th><th>Barang</th><th>Kode</th><th>ID Karyawan</th><th>ID Card</th><th>UID Kartu</th><th>Nama Karyawan</th><th>Dipinjam Pada</th><th>Status</th></tr></thead>
                <tbody>
                    <?php if (empty($activeBorrowings)): ?>
                        <tr><td colspan="9">Tidak ada peminjaman aktif.</td></tr>
                    <?php else: ?>
                        <?php foreach ($activeBorrowings as $ab): ?>
                            <tr class="borrowed-row">
                                <td><?php echo htmlspecialchars($ab['id_peminjaman']); ?></td>
                                <td><?php echo htmlspecialchars($ab['nama_barang'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ab['kode_barang'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ab['id_karyawan'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ab['id_card'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ab['uid_kartu'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ab['nama_karyawan'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ab['tanggal_pinjam'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($ab['status'] ?? ''); ?></td>
                                <td style="white-space:nowrap">
                                    <form method="POST" style="display:inline;margin:0;padding:0">
                                        <input type="hidden" name="action" value="return">
                                        <input type="hidden" name="borrow_id" value="<?php echo intval($ab['id_peminjaman']); ?>">
                                        <button type="submit" class="btn" style="background:#10b981;padding:6px 8px">Kembalikan</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Barang yang Tidak Dipinjam (Tersedia Semua)</h3>
        <div style="overflow-x:auto;margin-top:8px">
            <table>
                <thead><tr><th>ID</th><th>Nama</th><th>Kode</th><th>Stok</th><th>Ditambahkan</th></tr></thead>
                <tbody>
                    <?php if (empty($notBorrowedList)): ?>
                        <tr><td colspan="5">Semua barang sedang dipinjam atau belum ada barang.</td></tr>
                    <?php else: ?>
                        <?php foreach ($notBorrowedList as $n): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($n['id_barang']); ?></td>
                                <td><?php echo htmlspecialchars($n['nama_barang']); ?></td>
                                <td><?php echo htmlspecialchars($n['kode_barang'] ?? ''); ?></td>
                                <td><?php echo intval($n['jumlah_total']); ?></td>
                                <td><?php echo htmlspecialchars($n['created_at']); ?></td>
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
