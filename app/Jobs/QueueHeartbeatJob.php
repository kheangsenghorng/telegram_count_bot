<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Controllers\Api\SystemStatusController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;



/*
|--------------------------------------------------------------------------
| Queue Worker Heartbeat
|--------------------------------------------------------------------------
| Dispatched every minute by the scheduler. The key is written ONLY when
| a real worker processes the job — so a fresh timestamp proves the
| laravel-worker Supervisor process is alive and pulling jobs.
|
| routes/console.php (Laravel 11/12):
|
|   use App\Jobs\QueueHeartbeatJob;
|
|   Schedule::job(new QueueHeartbeatJob)
|       ->everyMinute()
|       ->onOneServer();
*/

class QueueHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** Don't let stale heartbeats pile up if the worker is down. */
    public int $timeout = 10;

    public function handle(): void
    {
        Cache::put(
            SystemStatusController::QUEUE_HEARTBEAT_KEY,
            now()->toIso8601String(),
            600 // key expires after 10 min — a dead worker leaves no trace
        );
    }
}