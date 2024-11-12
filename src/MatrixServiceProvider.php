<?php

namespace Vocphone\LaravelMatrixSdk;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Illuminate\Notifications\ChannelManager;
use Vocphone\LaravelMatrixSdk\Channels\MatrixChannel;

class MatrixServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(MatrixClient::class, function () {
            return new MatrixClient();
        });

        Notification::resolved(function (ChannelManager $service) {
            $service->extend('matrix', function () {
                return new MatrixChannel();
            });
        });
    }
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/config/matrix.php' => config_path('matrix.php'),
        ], 'matrix-config');
    }

}