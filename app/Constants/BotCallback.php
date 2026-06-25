<?php

declare(strict_types=1);

namespace App\Constants;

class BotCallback
{
    // ── Stats periods ─────────────────────────────────────────────────────────
    const STATS_DAY   = 'stats_day';
    const STATS_WEEK  = 'stats_week';
    const STATS_MONTH = 'stats_month';
    const STATS_YEAR  = 'stats_year';
    const STATS_BACK  = 'stats_back';
    const STATS_ALL   = 'stats_all';

    // ── Dynamic callback prefixes (used with suffix e.g. stats_week_1) ────────
    const STATS_WEEK_PREFIX  = 'stats_week_';
    const STATS_MONTH_PREFIX = 'stats_month_';
    const STATS_YEAR_PREFIX  = 'stats_year_';

    // ── Patterns for preg_match ────────────────────────────────────────────────
    const PATTERN_WEEK  = '/^stats_week_([1-4])$/';
    const PATTERN_MONTH = '/^stats_month_(\d{1,2})$/';
    const PATTERN_YEAR  = '/^stats_year_(\d{4})$/';
    const PACKAGE_BUY_PREFIX  = 'pkg_buy_';
    const PATTERN_PACKAGE_BUY = '/^pkg_buy_(.+)$/';
}