<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="login-card">
        <h1>Login Successful</h1>
        <p class="subtitle">You are signed in as <?php echo htmlspecialchars($_SESSION['user']['email'], ENT_QUOTES, 'UTF-8'); ?></p>
        <a class="link-btn" href="logout.php">Logout</a>
    </div>
</body>
</html>
