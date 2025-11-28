<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validasi
    if (empty($username)) $errors['username'] = 'Username is required';
    if (empty($email)) $errors['email'] = 'Email is required';
    if (empty($password)) $errors['password'] = 'Password is required';
    if ($password !== $confirm_password) $errors['confirm_password'] = 'Passwords do not match';
    
    // Check if email exists (menggunakan tabel users)
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $errors['email'] = 'Email already registered';
    }
    
if (empty($errors)) {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Generate confirmation code
    $confirmation_code = bin2hex(random_bytes(3)); // 6 hex characters

    // Insert new user dengan username, email, password, dan confirmation_code
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, confirmation_code) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $email, $hashed_password, $confirmation_code);

    if ($stmt->execute()) {
        // Send confirmation code to user's email
        $to = $email;
        $subject = "Email Confirmation Code";
        $message = "Your confirmation code is: " . $confirmation_code;
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/plain;charset=UTF-8" . "\r\n";
        $headers .= "From: no-reply@example.com" . "\r\n";
        $headers .= "Reply-To: no-reply@example.com" . "\r\n";
        // For local development, configure SMTP in php.ini or use a mail library like PHPMailer for sending emails.
        // Removed ini_set() calls with placeholder SMTP server to avoid connection warnings.

        require_once 'includes/send_mail.php';

        $mail_sent = sendConfirmationEmail($to, $confirmation_code);

        if ($mail_sent) {
            // Store email in session for verification page
            $_SESSION['email_to_verify'] = $email;
            redirect('verify_email.php');
        } else {
            error_log("Failed to send email to $to");
            $errors['general'] = 'Failed to send confirmation email. Please try again.';
        }
    } else {
        $errors['general'] = 'Something went wrong. Please try again.';
    }
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Modern Auth System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Same styles as login page with small additions */
        
        .error-text {
            color: #ff6b6b;
            font-size: 13px;
            margin-top: 5px;
        }
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #f97316;
            --dark: #1e293b;
            --light: #f8fafc;
            --error: #ef4444;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
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
            color: white;
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .logo p {
            opacity: 0.8;
        }
        
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            color: white;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.2);
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: var(--primary-dark);
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
        
        .error-message {
            color: #ff6b6b;
            margin-top: 10px;
            text-align: center;
            font-size: 14px;
        }
        
        .input-icon {
            position: absolute;
            right: 15px;
            top: 40px;
            color: rgba(255, 255, 255, 0.6);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">
            <img src="logo1.png" alt="Company Logo - Modern Design with gradient colors" style="border-radius: 50%; margin-bottom: 15px; width:80px; height:80px;">
            <h1>Create Account</h1>
            <p>Join us today</p>
        </div>
        
        <form action="" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Choose a username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" required>
                <i class="fas fa-user input-icon"></i>
                <?php if (isset($errors['username'])): ?>
                    <div class="error-text"><?php echo $errors['username']; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                <i class="fas fa-envelope input-icon"></i>
                <?php if (isset($errors['email'])): ?>
                    <div class="error-text"><?php echo $errors['email']; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required>
                <i class="fas fa-lock input-icon"></i>
                <?php if (isset($errors['password'])): ?>
                    <div class="error-text"><?php echo $errors['password']; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
                <i class="fas fa-lock input-icon"></i>
                <?php if (isset($errors['confirm_password'])): ?>
                    <div class="error-text"><?php echo $errors['confirm_password']; ?></div>
                <?php endif; ?>
            </div>
            
            <?php if (isset($errors['general'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $errors['general']; ?>
                </div>
            <?php endif; ?>
            
            <button type="submit" class="btn">Register</button>
        </form>
        
        <div class="form-footer">
            Already have an account? <a href="index.php">Login here</a>
        </div>
    </div>
</body>
</html>
