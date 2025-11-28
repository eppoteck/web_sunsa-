<?php
// add_magang.php
// Form sederhana untuk menambahkan anak magang ke tabel `karyawan` di database peminjaman_barang_db

require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    // only admins should add interns
    redirect('dashboard.php');
}

$invDb = 'peminjaman_barang_db';
$invConn = mysqli_connect('localhost', DB_USER, DB_PASS, $invDb);
if (!$invConn) {
    $error = 'Gagal koneksi ke database peminjaman (peminjaman_barang_db): ' . mysqli_connect_error();
}

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $id_card = trim($_POST['id_card'] ?? '');
    $uid_kartu = trim($_POST['uid_kartu'] ?? '');
    $divisi = trim($_POST['divisi'] ?? '');
    $jabatan = trim($_POST['jabatan'] ?? 'Magang');

    if ($nama === '' || $id_card === '') {
        $message = ['type'=>'error','text'=>'Nama dan NIS (ID Card) wajib diisi.'];
    } else {
        // check uniqueness (id_card or uid_kartu)
        $existsSql = "SELECT id_karyawan FROM karyawan WHERE id_card = '" . mysqli_real_escape_string($invConn, $id_card) . "' LIMIT 1";
        $res = @mysqli_query($invConn, $existsSql);
        if ($res && mysqli_num_rows($res) > 0) {
            $message = ['type'=>'error','text'=>'NIS (ID Card) sudah ada di sistem.'];
        } else if ($uid_kartu !== '') {
            $existsSql2 = "SELECT id_karyawan FROM karyawan WHERE uid_kartu = '" . mysqli_real_escape_string($invConn, $uid_kartu) . "' LIMIT 1";
            $r2 = @mysqli_query($invConn, $existsSql2);
            if ($r2 && mysqli_num_rows($r2) > 0) {
                $message = ['type'=>'error','text'=>'UID kartu sudah terdaftar. Hapus/ubah sebelum mencoba.'];
            }
        }

        if ($message === null) {
            $cols = ['nama','id_card','uid_kartu','divisi','jabatan'];
            $vals = [
                "'" . mysqli_real_escape_string($invConn, $nama) . "'",
                "'" . mysqli_real_escape_string($invConn, $id_card) . "'",
                ($uid_kartu !== '' ? "'" . mysqli_real_escape_string($invConn, $uid_kartu) . "'" : "NULL"),
                ($divisi !== '' ? "'" . mysqli_real_escape_string($invConn, $divisi) . "'" : "NULL"),
                "'" . mysqli_real_escape_string($invConn, $jabatan) . "'"
            ];

            $ins = "INSERT INTO karyawan (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ")";
            if (mysqli_query($invConn, $ins)) {
                $message = ['type'=>'success','text'=>'Anak magang berhasil ditambahkan.'];
                // clear POST to avoid resubmission
                $_POST = [];
            } else {
                $message = ['type'=>'error','text'=>'Gagal menambahkan anak magang: ' . mysqli_error($invConn)];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Tambah Anak Magang</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{font-family:Segoe UI, Tahoma, sans-serif;background:#f3f4f6;color:#111;margin:0;padding:24px}
        .card{background:white;border-radius:10px;padding:20px;max-width:700px;margin:12px auto;box-shadow:0 6px 20px rgba(2,6,23,0.06)}
        label{display:block;margin-bottom:6px;font-weight:600}
        input[type=text]{width:100%;padding:10px;border-radius:8px;border:1px solid #e6edf3;margin-bottom:12px}
        .btn{background:#4f46e5;color:white;padding:10px 14px;border-radius:8px;border:none;cursor:pointer}
        .muted{color:#64748b}
        .msg{padding:10px;border-radius:8px;margin-bottom:12px}
        .msg.success{background:#dcfce7;color:#065f46}
        .msg.error{background:#fee2e2;color:#7f1d1d}
    </style>
</head>
<body>
    <div class="card">
        <h2>Tambah Anak Magang</h2>
        <p class="muted">Form ini menambah entri ke tabel <code>karyawan</code> pada database <code>peminjaman_barang_db</code>. Kolom wajib: Nama dan NIS (ID Card).</p>

        <?php if (isset($error)): ?>
            <div class="msg error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="msg <?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['text']); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <label for="nama">Nama</label>
            <input type="text" id="nama" name="nama" value="<?php echo htmlspecialchars($_POST['nama'] ?? ''); ?>" required>

            <label for="id_card">NIS / ID Card</label>
            <input type="text" id="id_card" name="id_card" value="<?php echo htmlspecialchars($_POST['id_card'] ?? ''); ?>" required>

            <label for="uid_kartu">UID Kartu (opsional)</label>
            <input type="text" id="uid_kartu" name="uid_kartu" value="<?php echo htmlspecialchars($_POST['uid_kartu'] ?? ''); ?>">

            <label for="divisi">Divisi (opsional)</label>
            <input type="text" id="divisi" name="divisi" value="<?php echo htmlspecialchars($_POST['divisi'] ?? ''); ?>">

            <label for="jabatan">Jabatan</label>
            <input type="text" id="jabatan" name="jabatan" value="<?php echo htmlspecialchars($_POST['jabatan'] ?? 'Magang'); ?>">

            <div style="margin-top:10px;display:flex;gap:8px">
                <button class="btn" type="submit">Tambah</button>
                <a href="absen_tanpa_rfid.php" style="display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:8px;background:#eef2ff;color:#1e3a8a;text-decoration:none">Ke Absen</a>
            </div>
        </form>
    </div>
</body>
</html>
