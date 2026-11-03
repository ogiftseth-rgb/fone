<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }
$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) { fwrite(STDERR, "Missing config/config.php\n"); exit(1); }
$config = require $configPath;
$d = $config['db'];
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'] ?? 3306, $d['database'], $d['charset'] ?? 'utf8mb4');
try {
    $pdo = new PDO($dsn, $d['username'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $required = ['users','organizations','customers','inventory_items','agreements','installment_schedules','payments','platform_commissions','collection_followups','customer_disputes'];
    $failed = false;
    echo "FoneFlex v0.2 database check\n";
    foreach ($required as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
        $stmt->execute([$d['database'], $table]);
        $ok = (int)$stmt->fetchColumn() === 1;
        printf("[%s] %s\n", $ok ? 'OK' : 'MISSING', $table);
        $failed = $failed || !$ok;
    }
    echo $failed ? "\nRun database/migrations/002_collections_and_disputes.sql in phpMyAdmin, then run this check again.\n" : "\nDatabase checks passed.\n";
    exit($failed ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Database check failed: ' . $e->getMessage() . "\n");
    exit(1);
}
