<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/finance.php';

$cases = [
    [500.00, 30.00, 150.00],
    [125.00, 30.00, 37.50],
    [0.00, 30.00, 0.00],
    [100.00, 0.00, 0.00],
    [10.25, 100.00, 10.25],
];
foreach ($cases as [$interest, $rate, $expected]) {
    $actual = calculate_foneflex_commission($interest, $rate);
    if (abs($actual - $expected) > 0.001) {
        fwrite(STDERR, "FAIL: interest {$interest}, rate {$rate}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}
try {
    calculate_foneflex_commission(-1, 30);
    throw new RuntimeException('Negative interest test did not throw.');
} catch (InvalidArgumentException) {}
try {
    calculate_foneflex_commission(100, 101);
    throw new RuntimeException('Out-of-range rate test did not throw.');
} catch (InvalidArgumentException) {}
echo "Commission calculation tests passed.\n";
