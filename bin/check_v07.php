<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config/config.php. Copy config/config.example.php and configure the database first.\n");
    exit(2);
}
$config = require $configPath;
$db = $config['db'] ?? null;
if (!is_array($db)) {
    fwrite(STDERR, "Database configuration is missing.\n");
    exit(2);
}
try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'] ?? 3306, $db['database'], $db['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $required = ['annual_interest_rate', 'interest_method'];
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = "agreements"');
    $stmt->execute([$db['database']]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($required, $columns));
    if ($missing) {
        fwrite(STDERR, 'Migration 007 is not complete. Missing agreements columns: ' . implode(', ', $missing) . "\n");
        exit(1);
    }
    require_once dirname(__DIR__) . '/app/finance.php';
    $demo = calculate_reducing_balance_schedule(100000.00, 24.0, 12, 'monthly');
    printf("PASS: database connection established.\n");
    printf("PASS: agreements has annual_interest_rate and interest_method.\n");
    printf("PASS: calculation helper loaded. Sample: KES 100,000 at 24%% p.a. over 12 monthly instalments has total interest KES %s.\n", number_format((float)$demo['total_interest'], 2));
    printf("NOTE: This check does not create or modify customer records.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'FoneFlex v0.7 check failed: ' . $e->getMessage() . "\n");
    exit(1);
}
