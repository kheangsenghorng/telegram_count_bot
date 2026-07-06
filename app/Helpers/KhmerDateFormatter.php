<?php

declare(strict_types=1);

namespace App\Helpers;

use Carbon\Carbon;

class KhmerDateFormatter
{
    private const MONTHS = [
        1  => 'មករា',
        2  => 'កុម្ភៈ',
        3  => 'មីនា',
        4  => 'មេសា',
        5  => 'ឧសភា',
        6  => 'មិថុនា',
        7  => 'កក្កដា',
        8  => 'សីហា',
        9  => 'កញ្ញា',
        10 => 'តុលា',
        11 => 'វិច្ឆិកា',
        12 => 'ធ្នូ',
    ];

    private const DIGITS = ['០','១','២','៣','៤','៥','៦','៧','៨','៩'];

    // ── Convert any number string to Khmer digits ─────────────────────────────
    public static function toKhmerNum(int|string $number): string
    {
        return str_replace(range(0, 9), self::DIGITS, (string) $number);
    }

    // ── Number with thousand separators in Khmer digits ──────────────────────
    //    formatNumber(4100)        → ៤,១០០
    //    formatNumber(1500000)     → ១,៥០០,០០០
    //    formatNumber(12.5, 2)     → ១២.៥០
    public static function formatNumber(int|float|string $number, int $decimals = 0): string
    {
        $formatted = number_format((float) $number, $decimals);

        return self::toKhmerNum($formatted);
    }

    // ── Currency amounts (dual-currency support) ─────────────────────────────
    //    formatCurrency(4.99, 'USD')   → ៤.៩៩ ដុល្លារ
    //    formatCurrency(20000, 'KHR')  → ២០,០០០ រៀល
    public static function formatCurrency(int|float|string $amount, string $currency): string
    {
        $currency = strtoupper($currency);

        return match ($currency) {
            'USD'   => self::formatNumber($amount, 2) . ' ដុល្លារ',
            'KHR'   => self::formatNumber($amount, 0) . ' រៀល',
            default => self::formatNumber($amount, 2) . ' ' . $currency,
        };
    }

    // ── Full date: ២៣ មិថុនា ២០២៥ ───────────────────────────────────────────
    public static function date(Carbon $date): string
    {
        $day   = self::toKhmerNum($date->day);
        $month = self::MONTHS[$date->month];
        $year  = self::toKhmerNum($date->year);

        return "{$day} {$month} {$year}";
    }

    // ── Alias for date() — used by PaymentConfirmationService ────────────────
    public static function formatDate(Carbon $date): string
    {
        return self::date($date);
    }

    // ── Full date + time: ២៣ មិថុនា ២០២៥ ម៉ោង ១១:៣៩ ព្រឹក ────────────────
    public static function dateTime(Carbon $date): string
    {
        $day       = self::toKhmerNum($date->day);
        $month     = self::MONTHS[$date->month];
        $year      = self::toKhmerNum($date->year);
        $hour      = self::toKhmerNum((int) $date->format('g'));
        $minuteRaw = str_pad((string) $date->minute, 2, '0', STR_PAD_LEFT);
        $minute    = self::toKhmerNum($minuteRaw[0]) . self::toKhmerNum($minuteRaw[1]);
        $period    = $date->hour < 12 ? 'ព្រឹក' : 'រសៀល';

        return "{$day} {$month} {$year} ម៉ោង {$hour}:{$minute} {$period}";
    }

    // ── Month + Year only: មិថុនា ២០២៥ ──────────────────────────────────────
    public static function monthYear(Carbon $date): string
    {
        $month = self::MONTHS[$date->month];
        $year  = self::toKhmerNum($date->year);

        return "{$month} {$year}";
    }

    // ── Month name only: មិថុនា ─────────────────────────────────────────────
    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? '';
    }
    // ── Month name → number: កក្កដា → 7 ─────────────────────────────────────
public static function monthNumber(string $name): ?int
{
    $name = trim($name);

    $number = array_search($name, self::MONTHS, true);

    return $number === false ? null : $number;
}
}