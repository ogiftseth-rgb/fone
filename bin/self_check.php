<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/finance.php';
$ok = calculate_foneflex_commission(500.00, 30.00) === 150.00;
if (!$ok) { fwrite(STDERR, "FoneFlex commission self-check failed.\n"); exit(1); }
echo "FoneFlex core finance self-check passed.\n";
