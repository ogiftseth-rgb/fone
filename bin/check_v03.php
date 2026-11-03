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
    $required = ['customer_kyc','device_control_actions','platform_services','customer_disputes'];
    $missing = [];
    foreach ($required as $table) {
        $q = $pdo->prepare('SHOW TABLES LIKE ?'); $q->execute([$table]);
        if (!$q->fetchColumn()) $missing[] = $table;
    }
    if ($missing) {
        fwrite(STDERR, 'Missing database table(s): ' . implode(', ', $missing) . ".\nRun migration 002 if needed, then migration 003.\n");
        exit(1);
    }
    $services = $pdo->query('SELECT service_key, enabled, integration_status FROM platform_services ORDER BY service_key')->fetchAll();
    echo "FoneFlex v0.3 database checks passed.\n";
    echo "Required KYC, release ledger, services and dispute tables are present.\n";
    echo "Platform services: " . count($services) . "\n";
    $key = (string)($config['security']['data_encryption_key'] ?? '');
    echo 'Customer ID encryption key: ' . (strlen($key) >= 32 ? "configured\n" : "NOT CONFIGURED — add security.data_encryption_key to config/config.php before saving KYC ID numbers.\n");
    if (strlen($key) < 32) exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, "FoneFlex v0.3 check failed: " . $e->getMessage() . "\n");
    exit(1);
}
