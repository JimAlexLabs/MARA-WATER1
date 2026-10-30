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
        Model::deleting(function (Model $model) {
            // forceDelete also fires deleting — log once via forceDeleting.
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }
            app(DeletionLogService::class)->record($model);
        });

        Model::forceDeleting(function (Model $model) {
            app(DeletionLogService::class)->record($model, 'force_delete');
        });
    }
}
