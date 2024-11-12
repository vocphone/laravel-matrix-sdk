<?php

namespace Vocphone\LaravelMatrixSdk\Channels;

use Illuminate\Notifications\Notification;
use Vocphone\LaravelMatrixSdk\MatrixClient;
use Vocphone\LaravelMatrixSdk\Room;

class MatrixChannel
{

    public function send( $notifiable, Notification $notification ) {
        $roomId = $notifiable->routeNotificationFor('matrix', $notification);

        if( empty($roomId))
            return;
        $message = $notification->toMatrix($notifiable);

        $matrix = app(MatrixClient::class)->sendMessage($message, $roomId);
    }
}