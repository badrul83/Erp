<?php
declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Copy config.example.php to config.php and enter the database credentials before running the installer.');
}
$config = require $configFile;
$message = '';
try {
    $db = $config['db'];
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . ($db['port'] ?? 3306) . ';dbname=' . $db['name'] . ';charset=' . ($db['charset'] ?? 'utf8mb4'), $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sql = file_get_contents(__DIR__ . '/database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('database/schema.sql is missing.');
        }
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $statement) {
            $pdo->exec($statement);
        }
        $targetSql = file_get_contents(__DIR__ . '/database/dealer-targets.sql');
        if ($targetSql === false) throw new RuntimeException('database/dealer-targets.sql is missing.');
        $pdo->exec($targetSql);
        $salesForceSql = file_get_contents(__DIR__ . '/database/dealer-sales-force.sql');
        if ($salesForceSql === false) throw new RuntimeException('database/dealer-sales-force.sql is missing.');
        $pdo->exec($salesForceSql);
        $accountingSql = file_get_contents(__DIR__ . '/database/accounting.sql');
        if ($accountingSql === false) throw new RuntimeException('database/accounting.sql is missing.');
        $pdo->exec($accountingSql);
        $easyAccountsSql = file_get_contents(__DIR__ . '/database/easy-accounts.sql');
        if ($easyAccountsSql === false) throw new RuntimeException('database/easy-accounts.sql is missing.');
        $pdo->exec($easyAccountsSql);
        $accessSql = file_get_contents(__DIR__ . '/database/user-access.sql');
        if ($accessSql === false) throw new RuntimeException('database/user-access.sql is missing.');
        $pdo->exec($accessSql);
        $hasPhone = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Users" AND COLUMN_NAME="Phone"')->fetchColumn();
        if (!$hasPhone) $pdo->exec('ALTER TABLE Users ADD COLUMN Phone VARCHAR(60) NULL AFTER Name');
        $hasPhoneIndex = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="Users" AND COLUMN_NAME="Phone" AND NON_UNIQUE=0 AND SEQ_IN_INDEX=1')->fetchColumn();
        if (!$hasPhoneIndex) $pdo->exec('ALTER TABLE Users ADD UNIQUE INDEX Users_Phone_UQ (Phone)');
        $users = (int) $pdo->query('SELECT COUNT(*) FROM Users')->fetchColumn();
        if ($users === 0) {
            
            $adminName = trim((string) ($_POST['admin_name'] ?? ''));
            $adminPhone = preg_replace('/\\D+/', '', (string) ($_POST['admin_phone'] ?? '')) ?? ''; if (str_starts_with($adminPhone, '00880')) $adminPhone=substr($adminPhone,4); elseif (str_starts_with($adminPhone, '880')) $adminPhone=substr($adminPhone,3); if(strlen($adminPhone)===10&&str_starts_with($adminPhone,'1'))$adminPhone='0'.$adminPhone;
            $adminPassword = (string) ($_POST['admin_password'] ?? '');
            $adminNameLength = function_exists('mb_strlen') ? mb_strlen($adminName, 'UTF-8') : strlen($adminName);
            if ($adminNameLength < 2 || $adminNameLength > 160) throw new RuntimeException('Enter the administrator’s full official name.');
            if (!preg_match('/^01[3-9][0-9]{8}$/', $adminPhone)) throw new RuntimeException('Enter an 11-digit Bangladesh mobile number starting with 01.');
            if (strlen($adminPassword) < 8) {
                throw new RuntimeException('Set an administrator password with at least 8 characters.');
            }
            $insert = $pdo->prepare('INSERT INTO Users (UserID, Username, Name, Phone, Role, PasswordHash, Status, CreatedAt, CreatedBy, UpdatedAt, UpdatedBy) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?)');
            $insert->execute(['USR-000001', $adminPhone, $adminName, $adminPhone, 'ADMIN', password_hash($adminPassword, PASSWORD_DEFAULT), 'ACTIVE', 'system', 'system']);
        }
        $message = 'Database installed. Sign in with the mobile number and password you set.';
    }
} catch (Throwable $error) {
    $message = 'Installation error: ' . $error->getMessage();
}
?>
<!doctype html><html lang="en"><meta charset="utf-8"><title>Install Distribution ERP</title><style>body{font-family:Arial,sans-serif;max-width:680px;margin:56px auto;padding:0 20px;color:#173b32}main{border:1px solid #d8ece5;border-radius:14px;padding:28px}button{background:#008f69;color:#fff;border:0;border-radius:7px;padding:11px 16px;font-weight:bold;cursor:pointer}.message{margin:18px 0;padding:12px;background:#f1faf7;border-radius:7px}label{display:block;margin:16px 0 6px;font-weight:bold}input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #c8e1d9;border-radius:7px;margin-bottom:14px}</style><main><h1>Distribution ERP installer</h1><p>This creates the MySQL tables and the initial administrator account. It does not delete existing records.</p><?php if ($message): ?><div class="message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?><form method="post"><label for="admin_name">Administrator full official name</label><input id="admin_name" name="admin_name" maxlength="160" required autocomplete="name"><label for="admin_phone">Administrator mobile number · username / login ID</label><input id="admin_phone" name="admin_phone" type="tel" inputmode="numeric" maxlength="11" pattern="01[3-9][0-9]{8}" placeholder="01XXXXXXXXX" required autocomplete="tel"><label for="admin_password">Administrator password</label><input id="admin_password" name="admin_password" type="password" minlength="8" required autocomplete="new-password"><button type="submit">Install database</button></form><p>For security, delete or rename <code>install.php</code> after installation.</p></main></html>
