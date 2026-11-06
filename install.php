<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
        $error = 'Use a valid email and a password of at least 12 characters.';
    } elseif (!is_file($configFile)) {
        $error = 'Copy config.example.php to config.php and configure the database first.';
    } else {
        try {
            $config = require $configFile;
            date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Dhaka');
            $pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $schema = file_get_contents(__DIR__ . '/schema.sql');
            foreach (array_filter(array_map('trim', explode(';', (string)$schema))) as $statement) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $schemaError) {
                    if (($schemaError->errorInfo[1] ?? null) !== 1050) throw $schemaError;
                }
            }
            $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)');
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
            $message = 'Setup complete. Delete install.php, then sign in.';
        } catch (Throwable $e) {
            $error = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Due Flow setup</title><style>body{font:16px system-ui;max-width:420px;margin:3rem auto;padding:1rem}input,button{box-sizing:border-box;width:100%;padding:.8rem;margin:.4rem 0 1rem}button{background:#0b6bcb;color:#fff;border:0;border-radius:.5rem}.ok{color:#176b3a}.error{color:#a12727}</style>
<h1>Due Flow setup</h1><p>Run once after configuring <code>config.php</code>.</p>
<?php if ($message): ?><p class="ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post"><label>Admin email<input type="email" name="email" required></label><label>Password<input type="password" name="password" minlength="12" required></label><button>Create account</button></form>
