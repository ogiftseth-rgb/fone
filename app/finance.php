<?php
declare(strict_types=1);

/**
 * Calculates FoneFlex's contractual platform share from interest that has
 * actually been collected and allocated. Principal and uncollected interest
 * must never be passed to this function.
 */
function calculate_foneflex_commission(float $interestCollected, float $commissionRate): float
{
    if ($interestCollected < 0) {
        throw new InvalidArgumentException('Collected interest cannot be negative.');
    }
    if ($commissionRate < 0 || $commissionRate > 100) {
        throw new InvalidArgumentException('Commission rate must be between 0 and 100 percent.');
    }
    return round($interestCollected * ($commissionRate / 100), 2, PHP_ROUND_HALF_UP);
}


/**
 * Build a fixed-payment amortisation schedule using a nominal annual rate
 * applied to the outstanding principal at the start of each repayment period.
 * The periodic rate is APR divided by the standard periods-per-year convention:
 * daily=365, weekly=52, fortnightly=26, monthly=12.
 *
 * Returns the schedule and the sum of all scheduled interest. All money values
 * are rounded to cents; the final principal instalment absorbs rounding residue.
 */
function calculate_reducing_balance_schedule(
    float $principal,
    float $annualRatePercent,
    int $installments,
    string $frequency
): array {
    $periodsPerYear = [
        'daily' => 365,
        'weekly' => 52,
        'fortnightly' => 26,
        'monthly' => 12,
    ];

    if (!is_finite($principal) || $principal < 0) {
        throw new InvalidArgumentException('Financed principal must be a non-negative amount.');
    }
    if (!is_finite($annualRatePercent) || $annualRatePercent < 0 || $annualRatePercent > 100) {
        throw new InvalidArgumentException('Annual reducing-balance interest rate must be between 0 and 100 percent.');
    }
    if ($installments < 1 || $installments > 60) {
        throw new InvalidArgumentException('Installments must be between 1 and 60.');
    }
    if (!isset($periodsPerYear[$frequency])) {
        throw new InvalidArgumentException('Unsupported repayment frequency.');
    }

    $periodicRate = ($annualRatePercent / 100) / $periodsPerYear[$frequency];
    if ($principal <= 0.00001) {
        return [
            'periods_per_year' => $periodsPerYear[$frequency],
            'periodic_rate' => $periodicRate,
            'periodic_rate_percent' => $periodicRate * 100,
            'regular_payment' => 0.0,
            'total_interest' => 0.0,
            'total_repayment' => 0.0,
            'schedule' => [],
        ];
    }

    if ($periodicRate <= 0.0) {
        $regularPayment = $principal / $installments;
    } else {
        $denominator = 1 - pow(1 + $periodicRate, -$installments);
        if ($denominator <= 0.0) {
            throw new RuntimeException('Unable to calculate the reducing-balance installment.');
        }
        $regularPayment = ($principal * $periodicRate) / $denominator;
    }

    $opening = round($principal, 2);
    $totalInterest = 0.0;
    $totalPrincipal = 0.0;
    $schedule = [];

    for ($period = 1; $period <= $installments; $period++) {
        $interestDue = round($opening * $periodicRate, 2, PHP_ROUND_HALF_UP);
        if ($period === $installments) {
            $principalDue = round($opening, 2, PHP_ROUND_HALF_UP);
        } else {
            $principalDue = round($regularPayment - $interestDue, 2, PHP_ROUND_HALF_UP);
            if ($principalDue <= 0.0) {
                throw new RuntimeException('The selected rate and repayment term do not amortise the financed amount.');
            }
            if ($principalDue > $opening) {
                $principalDue = $opening;
            }
        }
        $closing = max(0.0, round($opening - $principalDue, 2, PHP_ROUND_HALF_UP));
        $schedule[] = [
            'period' => $period,
            'opening_balance' => round($opening, 2),
            'interest' => $interestDue,
            'principal' => $principalDue,
            'installment' => round($principalDue + $interestDue, 2),
            'closing_balance' => $closing,
        ];
        $totalInterest += $interestDue;
        $totalPrincipal += $principalDue;
        $opening = $closing;
    }

    if (abs($totalPrincipal - $principal) > 0.02 || $opening > 0.01) {
        throw new RuntimeException('The generated repayment schedule does not fully amortise the financed principal.');
    }

    $totalInterest = round($totalInterest, 2, PHP_ROUND_HALF_UP);
    return [
        'periods_per_year' => $periodsPerYear[$frequency],
        'periodic_rate' => $periodicRate,
        'periodic_rate_percent' => $periodicRate * 100,
        'regular_payment' => round($regularPayment, 2, PHP_ROUND_HALF_UP),
        'total_interest' => $totalInterest,
        'total_repayment' => round($principal + $totalInterest, 2, PHP_ROUND_HALF_UP),
        'schedule' => $schedule,
    ];
}
