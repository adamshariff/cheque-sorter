<?php
session_start();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errors[] = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif ($email !== 'admin@example.com' || $password !== 'password123') {
        $errors[] = 'Invalid credentials. Try admin@example.com / password123.';
    } else {
        $_SESSION['user'] = ['email' => $email];
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="login-card">
        <h1>Welcome Back</h1>
        <p class="subtitle">Sign in to continue</p>

        <?php if (!empty($errors)) : ?>
            <div class="alert" role="alert">
                <?php echo htmlspecialchars($errors[0], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form id="loginForm" method="post" novalidate>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" placeholder="admin@example.com" />

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="password123" />

            <p id="formStatus" class="status" aria-live="polite"></p>
            <button type="submit">Login</button>
        </form>

        <p class="hint">Demo credentials: admin@example.com / password123</p>
    </div>

    <script src="assets/script.js"></script>
</body>
</html>
