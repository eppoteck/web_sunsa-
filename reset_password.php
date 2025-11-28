<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$errors = [];
$success = '';

if (!isset($_SESSION['email_to_verify'])) {
    header('Location: forgot_password.php');
    exit();
}

$email = $_SESSION['email_to_verify'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmation_code = trim($_POST['confirmation_code']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($confirmation_code)) {
        $errors['confirmation_code'] = 'Kode konfirmasi harus diisi.';
    }
    if (empty($new_password)) {
        $errors['new_password'] = 'Password baru harus diisi.';
    }
    if ($new_password !== $confirm_password) {
        $errors['confirm_password'] = 'Konfirmasi password tidak cocok.';
    }

    if (empty($errors)) {
        // Verify confirmation code
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND confirmation_code = ?");
        $stmt->bind_param("ss", $email, $confirmation_code);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            // Update password and clear confirmation code
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt_update = $conn->prepare("UPDATE users SET password = ?, confirmation_code = NULL WHERE email = ?");
            $stmt_update->bind_param("ss", $hashed_password, $email);
            if ($stmt_update->execute()) {
                unset($_SESSION['email_to_verify']);
                $success = 'Password berhasil diubah. Silakan <a href="index.php">login</a> dengan password baru Anda.';
            } else {
                $errors['general'] = 'Gagal memperbarui password. Silakan coba lagi.';
            }
        } else {
            $errors['confirmation_code'] = 'Kode konfirmasi salah atau sudah digunakan.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reset Password | Modern Auth System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: white;
        }
        .container {
            width: 100%;
            max-width: 420px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        h1 {
            text-align: center;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        input.form-control {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: white;
            font-size: 16px;
            transition: all 0.3s;
        }
        input.form-control:focus {
            outline: none;
            border-color: #6366f1;
            background: rgba(255, 255, 255, 0.2);
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #4f46e5;
        }
        .error-text {
            color: #ff6b6b;
            font-size: 13px;
            margin-top: 5px;
        }
        .error-message {
            color: #ff6b6b;
            margin-top: 10px;
            text-align: center;
            font-size: 14px;
        }
        .success-message {
            color: #4ade80;
            margin-top: 10px;
            text-align: center;
            font-size: 14px;
        }
        .form-footer {
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
        }
        .form-footer a {
            color: white;
            text-decoration: none;
            font-weight: 500;
        }
        .form-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reset Password</h1>
        <?php if (!empty($success)): ?>
            <div class="success-message"><?php echo $success; ?></div>
        <?php else: ?>
            <?php if (isset($errors['general'])): ?>
                <div class="error-message"><?php echo $errors['general']; ?></div>
            <?php endif; ?>
            <form action="" method="POST">
                <div class="form-group">
                    <label for="confirmation_code">Kode Konfirmasi</label>
                    <input type="text" id="confirmation_code" name="confirmation_code" class="form-control" placeholder="Masukkan kode konfirmasi" required>
                    <?php if (isset($errors['confirmation_code'])): ?>
                        <div class="error-text"><?php echo $errors['confirmation_code']; ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="new_password">Password Baru</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Masukkan password baru" required>
                    <?php if (isset($errors['new_password'])): ?>
                        <div class="error-text"><?php echo $errors['new_password']; ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Konfirmasi Password Baru</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Konfirmasi password baru" required>
                    <?php if (isset($errors['confirm_password'])): ?>
                        <div class="error-text"><?php echo $errors['confirm_password']; ?></div>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn">Reset Password</button>
            </form>
        <?php endif; ?>
        <div class="form-footer">
            <a href="index.php">Kembali ke Login</a>
        </div>
    </div>
</body>
</html>
