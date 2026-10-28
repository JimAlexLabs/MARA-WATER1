<?php

namespace App\Services;

/**
 * Small, dependency-free demand forecaster for sparse daily series.
 *
 * Method (deliberately simple + explainable, robust to a young business):
 *  1. Day-of-week seasonality indices (shrunk towards 1 until there is
 *     enough history) are removed from the series.
 *  2. Damped Holt linear exponential smoothing (level + trend) is fitted on
 *     the de-seasonalised series; the 14-day-ahead average is the "observed"
 *     forecast.
 *  3. Empirical Bayes shrinkage: the observed forecast is blended with a
 *     plan-based prior (trips/month x trip qty) with weight
 *     w = n / (n + k) where n = days of history. With little data the plan
 *     drives decisions; as sales history grows the data takes over.
 *  4. One-step residual sigma gives lead-time safety stock:
 *     z * sigma * sqrt(leadDays).
 */
class DemandForecaster
{
    /**
     * @param  list<float>  $series  daily demand, oldest -> newest (zeros included)
     * @param  float  $prior  plan-based daily demand
     * @return array<string,mixed>
     */
    public static function forecast(array $series, float $prior, ?int $dowOfLast = null, float $k = 14.0): array
    {
        $series = array_values(array_map('floatval', $series));
        $n = count($series);
        // History starts at first non-zero day (before that the business had no sales to learn from).
        $first = null;
        foreach ($series as $i => $v) {
            if ($v > 0) {
                $first = $i;
                break;
            }
        }
        if ($first === null || $n === 0) {
            return self::pack(null, $prior, $prior, 0.0, 0, 0.0, 'plan-prior (no sales history yet)');
        }
        $hist = array_slice($series, $first);
        $m = count($hist);
        $mean = array_sum($hist) / max(1, $m);

        // Day-of-week indices (only meaningful with >= 3 weeks).
        $dow = array_fill(0, 7, 1.0);
        $useDow = $dowOfLast !== null && $m >= 21 && $mean > 0;
        if ($useDow) {
            $sum = array_fill(0, 7, 0.0);
            $cnt = array_fill(0, 7, 0);
            foreach ($hist as $i => $v) {
                $d = (($dowOfLast - ($m - 1 - $i)) % 7 + 7) % 7;
                $sum[$d] += $v;
                $cnt[$d]++;
            }
            $shrink = max(0.0, min(1.0, ($m - 14) / 14.0)); // 0 at 2 weeks of data, full trust at 4 weeks
            for ($d = 0; $d < 7; $d++) {
                $raw = $cnt[$d] > 0 ? ($sum[$d] / $cnt[$d]) / $mean : 1.0;
                $dow[$d] = 1.0 + $shrink * ($raw - 1.0);
            }
            $avg = array_sum($dow) / 7.0;
            if ($avg > 0) {
                $dow = array_map(static fn ($x) => $x / $avg, $dow); // mean index = 1
            }
        }

        $alpha = 0.35;
        $beta = 0.10;
        $phi = 0.90;
        $level = $hist[0] / ($useDow ? max(0.2, $dow[(($dowOfLast - ($m - 1)) % 7 + 7) % 7]) : 1.0);
        $trend = 0.0;
        $errs = [];
        for ($i = 1; $i < $m; $i++) {
            $idx = $useDow ? max(0.2, $dow[(($dowOfLast - ($m - 1 - $i)) % 7 + 7) % 7]) : 1.0;
            $pred = ($level + $phi * $trend) * $idx;
            $errs[] = $hist[$i] - $pred;
            $y = $hist[$i] / $idx;
            $prevLevel = $level;
            $level = $alpha * $y + (1 - $alpha) * ($level + $phi * $trend);
            $trend = $beta * ($level - $prevLevel) + (1 - $beta) * $phi * $trend;
        }
        // 14-day-ahead average (damped), floored at 0; seasonality averages to ~1 across 14 days.
        $acc = 0.0;
        $damp = 0.0;
        for ($h = 1; $h <= 14; $h++) {
            $damp += pow($phi, $h);
            $idx = $useDow ? max(0.2, $dow[(($dowOfLast + $h) % 7 + 7) % 7]) : 1.0;
            $acc += max(0.0, $level + $damp * $trend) * $idx;
        }
        $observed = $acc / 14.0;

        $sigma = 0.0;
        if (count($errs) > 1) {
            $mu = array_sum($errs) / count($errs);
            $var = 0.0;
            foreach ($errs as $e) {
                $var += ($e - $mu) ** 2;
            }
            $sigma = sqrt($var / (count($errs) - 1));
        } else {
            $sigma = $mean * 0.5;
        }

        $w = $m / ($m + $k);
        $blended = $w * $observed + (1 - $w) * $prior;
        $method = 'damped Holt trend'.($useDow ? ' + weekday seasonality' : '').' blended with plan prior';

        return self::pack($observed, $prior, $blended, $sigma, $m, $w, $method, $trend);
    }

    /** @return array<string,mixed> */
    private static function pack(?float $observed, float $prior, float $blended, float $sigma, int $days, float $w, string $method, float $trend = 0.0): array
    {
        return [
            'observed_daily' => $observed === null ? null : round($observed, 3),
            'prior_daily' => round($prior, 3),
            'forecast_daily' => round(max(0.0, $blended), 3),
            'sigma' => round($sigma, 3),
            'history_days' => $days,
            'data_weight' => round($w, 3),
            'trend_per_day' => round($trend, 4),
            'method' => $method,
        ];
    }

    /** Calendar days for N business days (Mon-Fri), ceil. */
    public static function businessToCalendarDays(int $business): int
    {
        return (int) ceil($business * 7 / 5);
    }

    /** Add N business days to a date (skips Sat/Sun). */
    public static function addBusinessDays(\DateTimeInterface $from, int $days): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromInterface($from);
        $added = 0;
        while ($added < $days) {
            $d = $d->modify('+1 day');
            if ((int) $d->format('N') < 6) {
                $added++;
            }
        }

        return $d;
    }
}
