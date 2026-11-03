<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/modules/device_protection.php';
$cases = [
    ['490154203237518', true],
    ['490154203237519', false],
    ['12345678901234', false],
    ['ABCDEFGHIJKLMNO', false],
];
foreach ($cases as [$imei, $expected]) {
    $actual = ffdev_imei_is_valid($imei);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$imei}\n");
        exit(1);
    }
}
echo "IMEI Luhn validation checks passed.\n";
