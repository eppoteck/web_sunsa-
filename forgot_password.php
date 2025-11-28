<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $errors['email'] = 'Email harus diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Format email tidak valid.';
    } else {
        // Check if email exists in database
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user) {
            // Check if user has a password set (not null or empty)
            $stmt_pass = $conn->prepare("SELECT password FROM users WHERE email = ? LIMIT 1");
            $stmt_pass->bind_param("s", $email);
            $stmt_pass->execute();
            $result_pass = $stmt_pass->get_result();
            $user_pass = $result_pass->fetch_assoc();

            if (empty($user_pass['password'])) {
                $errors['email'] = 'Akun ini terdaftar melalui Google dan tidak dapat mengubah password melalui sistem ini.';
            } else {
                // Generate confirmation code
                $confirmation_code = generateConfirmationCode(6);

                // Store confirmation code in database
                $stmt_update = $conn->prepare("UPDATE users SET confirmation_code = ? WHERE email = ?");
                $stmt_update->bind_param("ss", $confirmation_code, $email);
                if ($stmt_update->execute()) {
                    // Send email with confirmation code using PHPMailer
                    require_once 'includes/send_mail.php';
                    if (sendConfirmationEmail($email, $confirmation_code, 'reset_password')) {
                        $_SESSION['email_to_verify'] = $email;
                        header('Location: reset_password.php');
                        exit();
                    } else {
                        $errors['general'] = 'Gagal mengirim email. Silakan coba lagi.';
                    }
                } else {
                    $errors['general'] = 'Gagal menyimpan kode konfirmasi. Silakan coba lagi.';
                }
            }
        } else {
            $errors['email'] = 'Email tidak terdaftar.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Lupa Password | Modern Auth System</title>
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
        <h1>Lupa Password</h1>
        <?php if (isset($errors['general'])): ?>
            <div class="error-message"><?php echo $errors['general']; ?></div>
        <?php endif; ?>
        <form action="" method="POST">
            <div class="form-group">
                <label for="email">Masukkan Email Terdaftar</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="Email Anda" required>
                <?php if (isset($errors['email'])): ?>
                    <div class="error-text"><?php echo $errors['email']; ?></div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn">Kirim Kode Konfirmasi</button>
        </form>
        <div class="form-footer">
            <a href="index.php">Kembali ke Login</a>
        </div>
    </div>
</body>
</html>
