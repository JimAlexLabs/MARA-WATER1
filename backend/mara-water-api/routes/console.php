<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 11 (Backups: never lose data) -- `php artisan backups:run` takes a
// daily snapshot + prunes old ones. Deliberately NOT registered with
// Laravel's Schedule facade here: this app runs as a single persistent
// FrankenPHP process on Railway with no system cron, so Schedule::command()
// would need something else to invoke `php artisan schedule:run` every
// minute anyway -- one more moving part for no benefit. Instead, a separate
// Railway service (`mara-water1-backup-cron` in .railway/railway.ts) runs
// on Railway's own Cron Schedule deploy mode and calls `backups:run`
// directly once a day. See docs/BACKUPS.md for that service's current
// status (specified and plan-validated, not yet actually applied to the
// live project -- the token available when it was built could plan but
// not apply the change) and how to restore a backup in an emergency.
