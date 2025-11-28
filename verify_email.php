<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['email_to_verify'])) {
    redirect('index.php');
}

$errors = [];
$email = $_SESSION['email_to_verify'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input_code = trim($_POST['confirmation_code']);

    if (empty($input_code)) {
        $errors['confirmation_code'] = 'Confirmation code is required';
    } else {
        // Check code in database
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND confirmation_code = ? AND email_confirmed = 0");
        $stmt->bind_param("ss", $email, $input_code);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            // Update email_confirmed to 1
            $stmt_update = $conn->prepare("UPDATE users SET email_confirmed = 1, confirmation_code = NULL WHERE email = ?");
            $stmt_update->bind_param("s", $email);
            if ($stmt_update->execute()) {
                unset($_SESSION['email_to_verify']);
                flash('register_success', 'Email confirmed successfully! You can now login.');
                redirect('index.php');
            } else {
                $errors['general'] = 'Failed to update confirmation status. Please try again.';
            }
        } else {
            $errors['confirmation_code'] = 'Invalid confirmation code or email already confirmed.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Email Verification | Modern Auth System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        /* Basic styling similar to register.php */
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
        <h1>Email Verification</h1>
        <form action="" method="POST">
            <div class="form-group">
                <label for="confirmation_code">Enter Confirmation Code</label>
                <input type="text" id="confirmation_code" name="confirmation_code" class="form-control" placeholder="6-character code" required />
                <?php if (isset($errors['confirmation_code'])): ?>
                    <div class="error-text"><?php echo $errors['confirmation_code']; ?></div>
                <?php endif; ?>
            </div>
            <?php if (isset($errors['general'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $errors['general']; ?>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn">Verify Email</button>
        </form>
        <div class="form-footer">
            Already have an account? <a href="index.php">Login here</a>
        </div>
    </div>
</body>
</html>
