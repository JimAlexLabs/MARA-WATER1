<?php

namespace App\Providers;

use App\Services\DeletionLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Soft or hard deletes: keep a durable log of who removed what.
        // (forceDeleting is only on SoftDeletes models — use deleting + isForceDeleting.)
        Model::deleting(function (Model $model) {
            $forced = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
            app(DeletionLogService::class)->record($model, $forced ? 'force_delete' : null);
        });
    }
}
