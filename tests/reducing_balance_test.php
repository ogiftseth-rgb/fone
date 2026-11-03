<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/finance.php';

function assert_close(float $actual, float $expected, float $tolerance, string $label): void {
    if (abs($actual - $expected) > $tolerance) {
        fwrite(STDERR, "FAIL: {$label}; expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

$monthly = calculate_reducing_balance_schedule(100000.00, 24.0, 12, 'monthly');
assert_close((float)$monthly['periodic_rate_percent'], 2.0, 0.000001, 'monthly periodic rate');
assert_close(array_sum(array_column($monthly['schedule'], 'principal')), 100000.00, 0.02, 'monthly principal amortisation');
assert_close((float)end($monthly['schedule'])['closing_balance'], 0.0, 0.01, 'monthly final balance');
if (count($monthly['schedule']) !== 12) throw new RuntimeException('Expected 12 monthly rows.');
if (!((float)$monthly['schedule'][0]['interest'] > (float)$monthly['schedule'][11]['interest'])) throw new RuntimeException('Interest should decline as the balance reduces.');

$zero = calculate_reducing_balance_schedule(12000.00, 0.0, 6, 'monthly');
assert_close((float)$zero['total_interest'], 0.0, 0.001, 'zero-rate total interest');
assert_close(array_sum(array_column($zero['schedule'], 'principal')), 12000.00, 0.02, 'zero-rate principal amortisation');

$weekly = calculate_reducing_balance_schedule(50000.00, 36.0, 10, 'weekly');
assert_close((float)$weekly['periodic_rate_percent'], 36 / 52, 0.000001, 'weekly periodic rate');
assert_close(array_sum(array_column($weekly['schedule'], 'principal')), 50000.00, 0.02, 'weekly principal amortisation');

printf("PASS: reducing-balance amortisation tests. 24%% p.a. on KES 100,000 over 12 months produces KES %s total interest.\n", number_format((float)$monthly['total_interest'], 2));
