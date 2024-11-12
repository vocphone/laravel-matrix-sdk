<?php

namespace Vocphone\LaravelMatrixSdk;

use Illuminate\Support\Facades\Notification;

class MatrixServiceProvider
{
    public function register()
    {
        $this->app->singleton(MatrixClient::class, function () {
            return new MatrixClient();
        });

//        Notification::resolved(function (ChannelManager $service) {
//            $service->extend('matrix', function () {
//                return new MatrixChannel();
//            });
//        });
    }
}