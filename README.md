# Matrix client SDK for Laravel

This package is a fork of the now-abandoned original by [Yoann Celton](https://github.com/Aryess). The architecture does not necessarily reflect best practice in the eyes of current maintainers. Incremental improvements are made as time and resources allow. Contributions are welcome.

---

[![Software License][ico-license]](LICENSE.md)
[![Software Version][ico-version]](https://packagist.org/packages/vocphone/laravel-matrix-sdk)
![Software License][ico-downloads]

This is a Matrix client-server SDK for php 7.4+, initially copied from
[matrix-org/matrix-python-sdk][python-pck] and then forked from [meet-kinksters/php-matrix-sdk][php-pck]

This package is still a work in progress, and at the current time, not everything has been ported:
- Missing E2E encryption, need php bindings for the OLM library
- Live sync
- Unit tests for the client

At the time of writing this, most sdks are lacking in the ability to use spaces, this sdk is space aware.

## Installation

```
composer require vocphone/laravel-matrix-sdk
```

## Configuration

The most simple way to integrate is to add the following environment variables, the app will then cache the login token ( forever ) to assist with speed and perform less logins

```
MATRIX_URL=https://matrix.org
MATRIX_USERNAME=username
MATRIX_PASSWORD=password
```


## Usage

### Basic Usage

#### Send Message
```php
use Vocphone\LaravelMatrixSdk\MatrixClient;

$message = 'My Laravel Test Message';
$roomId = '!someRoom:matrix.org';

app(MatrixClient::class)->sendMessage($message, $roomId);
```

### Notifications
There is the ability to send messages directly into matrix.
#### Notifiable
```php
use Illuminate\Notifications\Notifiable;

class User extends Model
{
    use Notifiable;
    
    public function routeNotificationForMatrix($notification) {
        // If the roomId is null then the message won't attempt to send to matrix
        $roomId = $this->matrix_user_room_id ?? null;
                
        return $roomId;
    }
}
```
#### Notification
create a notification that returns the message content from the ``toMatrix`` method 
```php
use Illuminate\Notifications\Notification;

class MatrixNotification extends Notification
{
    private $message;
    public function __Construct( string $message ) {
        $this->message = $message;
    }
    
    public function toMatrix( $notifiable ) {
        return $this->message;
    }
}
```
### Spaces
#### Create a Space
```php
use Vocphone\LaravelMatrixSdk\MatrixClient;

$matrix = app(MatrixClient::class);

$spaceName = 'My Cool Space';
$public = false; // No way man, it's just for me
$invitees = []; // just me for now
$isSpace = true;
$space = $matrix->createRoom( $spaceName, $public, $invitees, $isSpace);

```
#### Add User to a space
```php
use Vocphone\LaravelMatrixSdk\MatrixClient;

$inviteUserId = '@new_user:matrix.org';

// get the matrix client instance
$matrix = app(MatrixClient::class);
// make sure we have all the current known information
$matrix->sync();
foreach( $matrix->getRooms() as $room ) {
    if( $room->getIsSpace() ) {
        $room->inviteUser($inviteUserId);
    }
}

```

## Structure
The SDK is split into two modules: ``api`` and ``client``.

### API
This contains the raw HTTP API calls and has minimal business logic. You can
set the access token (``token``) to use for requests as well as set a custom
transaction ID (``txn_id``) which will be incremented for each request.

### Client
This encapsulates the API module and provides object models such as ``Room``.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please email support@vocphone.com
instead of using the issue tracker.

## Credits
- [Tom Higgins](https://github.com/vocphone) at [Vocphone](https://vocphone.com)
- [Brad Jones](https://github.com/bradjones1) at [Meet Kinksters](https://tech.kinksters.dating)
- [Yoann Celton](https://github.com/Aryess) (initial port)
- [All Contributors](https://github.com/meet-kinksters/php-matrix-sdk/graphs/contributors)

## License

[MIT License](LICENSE.md).

[ico-version]: https://img.shields.io/packagist/v/meet-kinksters/php-matrix-sdk.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/meet-kinksters/php-matrix-sdk.svg?style=flat-square
[python-pck]: https://github.com/matrix-org/matrix-python-sdk
[php-pck]: https://github.com/vocphone/laravel-matrix-sdk
