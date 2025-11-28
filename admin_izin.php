<?php
session_start();
require_once 'includes/config.php';

// Restrict to admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

// Connect to peminjaman DB where izin table exists
$invDbName = 'Peminjaman_barang_db';
$invConn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $invDbName);
if (!$invConn) {
    // try lowercase fallback
    $invConn = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, 'peminjaman_barang_db');
}
if (!$invConn) {
    die('Gagal koneksi ke database izin.');
}

// Handle approve/reject actions
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['id_izin'])) {
        $id = intval($_POST['id_izin']);
        if ($_POST['action'] === 'approve') {
            $sql = "UPDATE izin SET status = 'approved' WHERE id_izin = " . $id;
            if (mysqli_query($invConn, $sql)) $message = ['type'=>'success','text'=>'Izin disetujui.'];
            else $message = ['type'=>'error','text'=>'Gagal menyetujui: '.mysqli_error($invConn)];
        } elseif ($_POST['action'] === 'reject') {
            $sql = "UPDATE izin SET status = 'rejected' WHERE id_izin = " . $id;
            if (mysqli_query($invConn, $sql)) $message = ['type'=>'success','text'=>'Izin ditolak.'];
            else $message = ['type'=>'error','text'=>'Gagal menolak: '.mysqli_error($invConn)];
        }
    }
}

// Fetch izin list with karyawan data
$sql = "SELECT iz.id_izin, iz.id_karyawan, iz.jenis, iz.alasan, iz.status, iz.created_at,
        k.nama, k.id_card, k.divisi
    FROM izin iz
    LEFT JOIN karyawan k ON iz.id_karyawan = k.id_karyawan
    ORDER BY iz.created_at DESC";
$res = mysqli_query($invConn, $sql);
$rows = [];
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manajemen Izin - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{font-family:Segoe UI, Tahoma, sans-serif;background:#f3f4f6;color:#111;margin:0;padding:24px}
        .card{background:white;border-radius:10px;padding:20px;max-width:1100px;margin:12px auto;box-shadow:0 6px 20px rgba(2,6,23,0.06)}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid #eef2f6;text-align:left;vertical-align:top}
        th{background:#f8fafc}
        .btn{padding:6px 10px;border-radius:6px;border:none;cursor:pointer}
        .btn-approve{background:#10b981;color:white}
        .btn-reject{background:#ef4444;color:white}
        .badge-pending{background:#fef3c7;color:#92400e;padding:4px 8px;border-radius:6px}
        .badge-approved{background:#dcfce7;color:#065f46;padding:4px 8px;border-radius:6px}
        .badge-rejected{background:#fee2e2;color:#7f1d1d;padding:4px 8px;border-radius:6px}
        textarea{width:100%;min-height:64px;border:1px solid #e6eef7;padding:8px;border-radius:6px;background:#fbfdff}
    </style>
</head>
<body>
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="admin_panel.php" class="back-btn" style="text-decoration:none;display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:8px;background:#eef2ff;color:#1e3a8a">
                    <i class="fas fa-arrow-left"></i>
                    Kembali ke Admin Panel
                </a>
                <h1 style="margin:0 0 0 0">Daftar Izin</h1>
            </div>
        </div>
        <?php if ($message): ?>
            <div style="padding:8px;border-radius:6px;margin-bottom:10px;background:<?php echo $message['type']==='success'? '#dcfce7':'#fee2e2'; ?>;color:<?php echo $message['type']==='success'? '#065f46':'#7f1d1d'; ?>">
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>ID Izin</th>
                    <th>Nama</th>
                    <th>ID Card</th>
                    <th>Divisi</th>
                    <th>Jenis</th>
                    <th>Alasan</th>
                    <th>Status</th>
                    <th>Dibuat</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9">Belum ada data izin.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo intval($r['id_izin']); ?></td>
                        <td><?php echo htmlspecialchars($r['nama'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($r['id_card'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($r['divisi'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars(str_replace('_',' ',$r['jenis'])); ?></td>
                        <td><textarea readonly><?php echo htmlspecialchars($r['alasan']); ?></textarea></td>
                        <td>
                            <?php if ($r['status'] === 'pending'): ?>
                                <span class="badge-pending">Pending</span>
                            <?php elseif ($r['status'] === 'approved'): ?>
                                <span class="badge-approved">Approved</span>
                            <?php else: ?>
                                <span class="badge-rejected">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                        <td>
                            <?php if ($r['status'] === 'pending'): ?>
                                <form method="POST" style="display:inline">
                                    <input type="hidden" name="id_izin" value="<?php echo intval($r['id_izin']); ?>">
                                    <button name="action" value="approve" class="btn btn-approve">Approve</button>
                                </form>
                                <form method="POST" style="display:inline;margin-left:6px">
                                    <input type="hidden" name="id_izin" value="<?php echo intval($r['id_izin']); ?>">
                                    <button name="action" value="reject" class="btn btn-reject">Reject</button>
                                </form>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
