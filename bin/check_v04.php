<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configFile)) { fwrite(STDERR, "Missing config/config.php.\n"); exit(1); }
$config = require $configFile;
$db = $config['db'] ?? [];
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'] ?? '127.0.0.1', $db['port'] ?? 3306, $db['database'] ?? '', $db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $requiredColumns = [
        'customers' => ['nationality','gender'],
        'customer_kyc' => ['date_of_birth','id_front_path','id_back_path','customer_photo_path','county','town','next_of_kin_name','next_of_kin_phone','next_of_kin_relationship','consent_customer_name','consent_method','customer_consent_confirmed','mpesa_statement_status'],
    ];
    $missing = [];
    foreach ($requiredColumns as $table => $columns) {
        $found = [];
        foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`','',$table) . '`')->fetchAll() as $column) $found[$column['Field']] = true;
        foreach ($columns as $column) if (!isset($found[$column])) $missing[] = $table . '.' . $column;
    }
    if ($missing) {
        fwrite(STDERR, "Missing onboarding database fields:\n - " . implode("\n - ",$missing) . "\nRun migrations 002, 003 and 004 in order if they have not already been applied.\n");
        exit(1);
    }
    $key = (string)($config['security']['data_encryption_key'] ?? '');
    if (strlen($key) < 32) {
        fwrite(STDERR, "Customer ID encryption key is not configured. Add security.data_encryption_key (at least 32 characters) before collecting ID numbers.\n");
        exit(2);
    }
    $pdo->query('SELECT 1 FROM customer_kyc LIMIT 1');
    echo "FoneFlex v0.4 onboarding database checks passed.\n";
    echo "Customer nationality/gender fields: present.\n";
    echo "KYC consent and M-Pesa statement workflow fields: present.\n";
    echo "Customer ID encryption key: configured.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FoneFlex v0.4 check failed: " . $e->getMessage() . "\n");
    exit(1);
}
