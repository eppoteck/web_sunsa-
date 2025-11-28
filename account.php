<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    redirect('index.php');
}

// Set default values if session variables are not set
$username = $_SESSION['username'] ?? $_SESSION['email'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$email = $_SESSION['email'] ?? '';

// Handle avatar upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_avatar') {
    // basic checks
    if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Tidak ada file yang dipilih atau terjadi kesalahan upload.');
        redirect('account.php');
    }

    $file = $_FILES['avatar'];
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    $det = @getimagesize($file['tmp_name']);
    $type = $det ? $det[2] : null;
    if (!$det || !isset($allowed[$type])) {
        flash('error', 'Tipe file tidak didukung. Unggah gambar JPG, PNG, GIF atau WEBP.');
        redirect('account.php');
    }

    // size limit 2MB
    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        flash('error', 'Ukuran file terlalu besar. Maksimum 2MB.');
        redirect('account.php');
    }

    // prepare destination
    $uploadsDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
    if (!is_dir($uploadsDir)) {
        @mkdir($uploadsDir, 0755, true);
    }

    // filename: user_{id}_{timestamp}.{ext} atau user_guest_{timestamp}
    // Ganti nama lama dengan yang baru (hapus yang lama jika ada)
    $uid = $_SESSION['user_id'] ?? 'guest';
    $ext = $allowed[$type];
    $filename = 'user_' . preg_replace('/[^a-z0-9_-]/i', '', $uid) . '.' . $ext;
    $dest = $uploadsDir . DIRECTORY_SEPARATOR . $filename;

    // Hapus file lama jika ada
    if (file_exists($dest)) {
        @unlink($dest);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        flash('error', 'Gagal menyimpan file. Periksa izin direktori uploads.');
        redirect('account.php');
    }

    // store web path in session for immediate display. Use relative path from project root
    $webPath = 'uploads/avatars/' . $filename;
    $_SESSION['avatar'] = $webPath;

    // Optionally update database if users table has avatar column (best-effort)
    if (isset($conn) && !empty($_SESSION['user_id'])) {
        $uidint = intval($_SESSION['user_id']);
        // check column exists
        $colCheck = @mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'avatar'");
        if ($colCheck && mysqli_num_rows($colCheck) > 0) {
            $esc = mysqli_real_escape_string($conn, $webPath);
            @mysqli_query($conn, "UPDATE users SET avatar='{$esc}' WHERE id={$uidint} LIMIT 1");
        }
    }

    flash('success', 'Foto profil berhasil diunggah. Foto tidak dapat dihapus dan bersifat permanen.');
    redirect('account.php');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Informasi Akun | Modern Auth System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #f97316;
            --dark: #1e293b;
            --light: #f8fafc;
            --success: #10b981;
            --warning: #f59e0b;
            --error: #ef4444;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: #f1f5f9;
            color: var(--dark);
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .btn {
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .btn:hover {
            background: var(--primary-dark);
        }

        .profile-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .profile-header {
            background: linear-gradient(to right, var(--primary), var(--primary-dark));
            color: white;
            padding: 40px;
            text-align: center;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            margin-bottom: 15px;
            object-fit: cover;
            display: inline-block;
        }

        .profile-name {
            font-size: 24px;
            margin-bottom: 5px;
        }

        .profile-role {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
        }

        .profile-content {
            padding: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-item {
            background: var(--light);
            padding: 20px;
            border-radius: 8px;
        }

        .info-item h3 {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-item p {
            font-size: 16px;
            color: var(--dark);
        }

        .section-title {
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--light);
        }

        .activity-list {
            list-style: none;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid var(--light);
        }

        .activity-icon {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .activity-content h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .activity-content p {
            font-size: 14px;
            color: #64748b;
        }

        .activity-date {
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Informasi Akun</h1>
            <a href="dashboard.php" class="btn">
                <i class="fas fa-arrow-left"></i>
                Kembali ke Dashboard
            </a>
        </div>

        <div class="profile-section">
            <div class="profile-header">
                <img src="<?php echo htmlspecialchars($_SESSION['avatar'] ?? 'https://placehold.co/200x200'); ?>" alt="Profile Picture" class="profile-avatar">
                <h2 class="profile-name"><?php echo htmlspecialchars($username); ?></h2>
                <span class="profile-role"><?php echo htmlspecialchars($role); ?></span>
            </div>

            <div class="profile-content">
                <?php if ($m = flash('success')): ?><div style="margin-bottom:12px;padding:12px;border-radius:8px;background:#dcfce7;color:#065f46;border-left:4px solid #10b981"><?php echo htmlspecialchars($m); ?></div><?php endif; ?>
                <?php if ($m = flash('error')): ?><div style="margin-bottom:12px;padding:12px;border-radius:8px;background:#fee2e2;color:#7f1d1d;border-left:4px solid #ef4444"><?php echo htmlspecialchars($m); ?></div><?php endif; ?>

                <!-- Avatar upload form -->
                <form method="POST" enctype="multipart/form-data" style="margin-bottom:18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="action" value="upload_avatar">
                    <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
                        <i class="fas fa-upload" style="color:var(--primary)"></i>
                        <span style="font-size:14px;color:#374151">Unggah Foto Profil</span>
                    </label>
                    <input type="file" name="avatar" accept="image/*" style="padding:8px;border-radius:8px;border:1px solid #e6eef6;background:#fff" required>
                    <button class="btn" type="submit" style="height:40px;padding:8px 12px;">Simpan</button>
                </form>
                <div style="background:#f0f9ff;border-left:4px solid #0284c7;padding:12px;border-radius:6px;margin-bottom:18px;font-size:13px;color:#0c4a6e;">
                    <i class="fas fa-info-circle"></i> <strong>Catatan:</strong> Foto profil yang diunggah bersifat permanen dan tidak dapat dihapus. Anda dapat menggantinya dengan foto baru kapan saja.
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <h3><i class="fas fa-envelope"></i> Email</h3>
                        <p><?php echo htmlspecialchars($email); ?></p>
                    </div>

                    <div class="info-item">
                        <h3><i class="fas fa-user-tag"></i> Role</h3>
                        <p><?php echo htmlspecialchars($role); ?></p>
                    </div>

                    <div class="info-item">
                        <h3><i class="fas fa-clock"></i> Account Created</h3>
                        <p>October 29, 2025</p>
                    </div>

                    <div class="info-item">
                        <h3><i class="fas fa-map-marker-alt"></i> Location</h3>
                        <p>Jakarta, Indonesia</p>
                    </div>
                </div>

                <h3 class="section-title">Aktivitas Terakhir</h3>
                <ul class="activity-list">
                    <li class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-sign-in-alt"></i>
                        </div>
                        <div class="activity-content">
                            <h4>Login Terakhir</h4>
                            <p>Berhasil login ke sistem</p>
                            <span class="activity-date">Hari ini, 10:30 AM</span>
                        </div>
                    </li>
                    <li class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-user-edit"></i>
                        </div>
                        <div class="activity-content">
                            <h4>Profil Diperbarui</h4>
                            <p>Mengubah informasi profil</p>
                            <span class="activity-date">Kemarin, 3:45 PM</span>
                        </div>
                    </li>
                    <li class="activity-item">
                        <div class="activity-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <div class="activity-content">
                            <h4>Password Diubah</h4>
                            <p>Mengubah kata sandi akun</p>
                            <span class="activity-date">25 Oktober 2025, 2:15 PM</span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>