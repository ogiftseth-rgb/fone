<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$configFile=$root.'/config/config.php';
if(!is_file($configFile)){fwrite(STDERR,"Missing config/config.php. Copy config/config.example.php first.\n");exit(1);}
$config=require $configFile;$d=$config['db'];
$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',$d['host'],$d['port']??3306,$d['database'],$d['charset']??'utf8mb4');
try{$pdo=new PDO($dsn,$d['username'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}catch(Throwable $e){fwrite(STDERR,"Database connection failed: ".$e->getMessage()."\n");exit(1);}
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
echo "FoneFlex platform administrator setup\n";
$name=trim((string)readline('Full name: '));$email=strtolower(trim((string)readline('Email: ')));$password=(string)readline('Password (minimum 14 characters): ');
if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<14){fwrite(STDERR,"Invalid details. Use a valid email and password of at least 14 characters.\n");exit(1);}
$check=$pdo->prepare('SELECT id FROM users WHERE email=?');$check->execute([$email]);if($check->fetch()){fwrite(STDERR,"That email already exists.\n");exit(1);}
$stmt=$pdo->prepare('INSERT INTO users(organization_id,full_name,email,password_hash,role) VALUES(NULL,?,?,?,"platform_admin")');$stmt->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
echo "Platform administrator created. Sign in at the FoneFlex login page.\n";
