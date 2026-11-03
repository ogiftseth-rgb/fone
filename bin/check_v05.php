<?php
declare(strict_types=1);
$configPath = dirname(__DIR__) . '/config/config.php';
if (!is_file($configPath)) { fwrite(STDERR, "Missing config/config.php\n"); exit(1); }
$config = require $configPath;
$errors = [];
if (strlen((string)($config['security']['data_encryption_key'] ?? '')) < 32) $errors[] = 'security.data_encryption_key must be at least 32 characters.';
foreach (['pdo_mysql','openssl','fileinfo'] as $ext) if (!extension_loaded($ext)) $errors[] = 'PHP extension missing: ' . $ext;
try {
    $d=$config['db'];
    $pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$d['host'],$d['port']??3306,$d['database'],$d['charset']??'utf8mb4'),$d['username'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $required=['device_imei_registry','device_protection_records','device_protection_events'];
    foreach($required as $table){$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');$s->execute([$d['database'],$table]);if((int)$s->fetchColumn()!==1)$errors[]='Database table missing: '.$table.' (run migration 005).';}
    $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=? AND table_name="inventory_items" AND column_name IN ("imei1","imei2","serial_no")');$s->execute([$d['database']]);if((int)$s->fetchColumn()!==3)$errors[]='Expected inventory_items IMEI/serial columns were not found.';
} catch(Throwable $e){$errors[]='Database check failed: '.$e->getMessage();}
if($errors){echo "FoneFlex v0.5 checks FAILED:\n- ".implode("\n- ",$errors)."\n";exit(1);}
echo "FoneFlex v0.5 configuration and device-protection tables: OK\n";
echo "Reminder: this check does not connect to Samsung Knox Guard, Apple Business, Google zero-touch or any financing-provider API.\n";
