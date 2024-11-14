<?php

namespace Vocphone\LaravelMatrixSdk;

use Vocphone\LaravelMatrixSdk\Crypto\OlmDevice;
use Vocphone\LaravelMatrixSdk\Exceptions\MatrixUnexpectedResponse;
use Vocphone\LaravelMatrixSdk\Exceptions\ValidationException;
use phpDocumentor\Reflection\Types\Callable_;
use Illuminate\Support\Facades\Cache;
use Vocphone\LaravelMatrixSdk\Exceptions\MatrixRequestException;

//TODO: port OLM bindings
define('ENCRYPTION_SUPPORT', false);

/**
 * The client API for Matrix. For the raw HTTP calls, see MatrixHttpApi.
 *
 * Examples:
 *
 *    Create a new user and send a message::
 *
 *    $client = new MatrixClient("https://matrix.org");
 *    $token = $client->registerWithPassword($username="foobar", $password="monkey");
 *    $room = $client->createRoom("myroom");
 *    $room->sendImage($fileLikeObject);
 *
 *    Send a message with an already logged in user::
 *
 *    $client = new MatrixClient("https://matrix.org", $token="foobar", $userId="@foobar:matrix.org");
 *    $client->addListener(func);  // NB: event stream callback
 *    $client->rooms[0]->addListener(func);  // NB: callbacks just for this room.
 *    $room = $client->joinRoom("#matrix:matrix.org");
 *    $response = $room->sendText("Hello!");
 *    $response = $room->kick("@bob:matrix.org");
 *
 *    Incoming event callbacks (scopes)::
 *
 *    function userCallback($user, $incomingEvent);
 *
 *    function $roomCallback($room, $incomingEvent);
 *
 *    function globalCallback($incoming_event);
 *
 * @package MatrixPhp
 */
class MatrixClient {


    /**
     * @var int
     */
    protected $cacheLevel;

    /**
     * @var bool
     */
    protected $encryption;

    /**
     * @var MatrixHttpApi
     */
    protected $api;
    /**
     * @var array
     */
    protected $listeners = [];
    protected $presenceListeners = [];
    protected $inviteListeners = [];
    protected $leftListeners = [];
    protected $ephemeralListeners = [];
    protected $deviceId;
    /**
     * @var OlmDevice
     */
    protected $olmDevice;
    protected $syncToken;
    protected $syncFilter;
    protected $syncThread;
    protected $shouldListen = false;
    /**
     * @var int Time to wait before attempting a /sync request after failing.
     */
    protected $badSyncTimeoutLimit = 3600;
    protected $rooms = [];
    /**
     * @var array A map from user ID to `User` object.
     *          It is populated automatically while tracking the membership in rooms, and
     *          shouldn't be modified directly.
     *          A `User` object in this array is shared between all `Room`
     *          objects where the corresponding user is joined.
     */
    public $users = [];
    protected $userId;
    protected $token;
    protected $hs;

    /**
     * MatrixClient constructor.
     * @param string $baseUrl The url of the HS preceding /_matrix. e.g. (ex: https://localhost:8008 )
     * @param string|null $token If you have an access token supply it here.
     * @param bool $validCertCheck Check the homeservers certificate on connections?
     * @param int $syncFilterLimit
     * @param int $cacheLevel One of Cache::NONE, Cache::SOME, or Cache::ALL
     * @param bool $encryption Optional. Whether or not to enable end-to-end encryption support
     * @param array $encryptionConf Optional. Configuration parameters for encryption.
     * @throws Exceptions\MatrixException
     * @throws Exceptions\MatrixHttpLibException
     * @throws Exceptions\MatrixRequestException
     * @throws ValidationException
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $token = null,
        bool $validCertCheck = true,
        int $syncFilterLimit = 20,
        int $cacheLevel = MatrixCache::ALL,
        $encryption = false,
        $encryptionConf = []
    ) {

        // @phpstan-ignore-next-line
        if ($encryption && ENCRYPTION_SUPPORT) {
            throw new ValidationException('Failed to enable encryption. Please make sure the olm library is available.');
        }
        $doSync = true;

        if( !$token  ) {
            if( Cache::has("LARAVEL_MATRIX_TOKEN") ) {
                $useToken = Cache::get("LARAVEL_MATRIX_TOKEN");
            } else {
                $this->setApi($baseUrl, $token, $validCertCheck);
                $useToken = $this->login();
            }

            $doSync = false;
        } else {
            $useToken = $token;
        }
        $this->setApi($baseUrl, $useToken, $validCertCheck);
        $this->encryption = $encryption;
        if (!in_array($cacheLevel, MatrixCache::$levels)) {
            throw new ValidationException('$cacheLevel must be one of MatrixCache.php::NONE, MatrixCache.php::SOME, MatrixCache.php::ALL');
        }
        $this->cacheLevel = $cacheLevel;
        $this->syncFilter = sprintf('{ "room": { "timeline" : { "limit" : %d } } }', $syncFilterLimit);

        if ($useToken) {
            $this->token = $useToken;

            try {
                $response = $this->api->whoami();
                $this->userId = $response['user_id'];
            } catch ( \Exception $e ) {
                if( !$token  ) {
                    \Log::Warning("[Matrix] original token {$useToken} failed: {$e->getMessage()} attempting to login using stored credentials for user ".env('MATRIX_USERNAME'));
                    $this->token = $this->login();
                    $response = $this->api->whoami();
                } else {
                    throw $e;
                }
            }
            if( $doSync )
                $this->sync();
        }
    }

    /**
     * sets or ignore the api to make sure we can access the matrix api property
     * @param  string|null  $baseUrl
     * @param  string|null  $token
     * @param  bool         $validCertCheck
     * @return void
     * @throws Exceptions\MatrixException
     */
    private function setApi(?string $baseUrl = null, ?string $token = null, bool $validCertCheck = true ) {
        if( !$baseUrl ) {
            $baseUrl = env("MATRIX_URL");
        }
        
        if( !$this->api ) {

            $this->api = new MatrixHttpApi($baseUrl, $token);
            $this->api->validateCertificate($validCertCheck);
        }
    }

    /**
     * Register a guest account on this HS.
     *
     * Note: HS must have guest registration enabled.
     *
     * @return string|null Access Token
     * @throws Exceptions\MatrixException
     */
    public function registerAsGuest(): ?string {
        $response = $this->api->register([], 'guest');

        return $this->postRegistration($response);
    }

    /**
     * Register for a new account on this HS.
     *
     * @param string $username Account username
     * @param string $password Account password
     * @return string|null Access Token
     * @throws Exceptions\MatrixException
     */
    public function registerWithPassword(string $username, string $password): ?string {
        $auth = ['type' => 'm.login.dummy'];
        $response = $this->api->register($auth, 'user', false, $username, $password);

        return $this->postRegistration($response);
    }

    protected function postRegistration(array $response) {
        $this->userId = array_get($response, 'user_id');
        $this->token = array_get($response, 'access_token');
        $this->hs = array_get($response, 'home_server');
        $this->api->setToken($this->token);
        $this->sync();

        return $this->token;
    }

    public function login(?string $username = null, ?string $password = null, bool $sync = true,
                          int $limit = 10, ?string $deviceId = null, bool $cacheToken = true ): ?string {
        if( !$username ) {
            $username = env('MATRIX_USERNAME');
            $password = env('MATRIX_PASSWORD');
        }

        $response = $this->api->login('m.login.password', [
            'identifier' => [
                'type' => 'm.id.user',
                'user' => $username,
            ],
            'user' => $username,
            'password' => $password,
            'device_id' => $deviceId
        ]);

        return $this->finalizeLogin($response, $sync, $limit, $cacheToken);
    }

    /**
     * Log in with a JWT.
     *
     * @param string $token JWT token.
     * @param bool $refreshToken Whether to request a refresh token.
     * @param bool $sync Indicator whether to sync.
     * @param int $limit Sync limit.
     *
     * @return string Access token.
     *
     * @throws \MatrixPhp\Exceptions\MatrixException
     */
    public function jwtLogin(string $token, bool $refreshToken = false, bool $sync = true, int $limit = 10): ?string {
        $response = $this->api->login(
            'org.matrix.login.jwt',
            [
                'token' => $token,
                'refresh_token' => $refreshToken,
            ]
        );

        return $this->finalizeLogin($response, $sync, $limit);
    }

    /**
     * Finalize login, e.g. after password or JWT login.
     *
     * @param array $response Login response array.
     * @param bool $sync Sync flag.
     * @param int $limit Sync limit.
     *
     * @return string Access token.
     *
     * @throws \MatrixPhp\Exceptions\MatrixException
     * @throws \MatrixPhp\Exceptions\MatrixRequestException
     */
    protected function finalizeLogin(array $response, bool $sync, int $limit, bool $cacheToken = true): string {
        $this->userId = $response['user_id'];
        $this->token = $response['access_token'];
        $this->hs = $response['home_server'];
        $this->api->setToken($this->token);
        $this->deviceId = $response['device_id'];
        if( $cacheToken && !empty($this->token) ) {
            Cache::put("LARAVEL_MATRIX_TOKEN", $this->token);
        }

        if ($this->encryption) {
            $this->olmDevice = new OlmDevice($this->api, $this->userId, $this->deviceId, $this->encryptionConf);
            $this->olmDevice->uploadIdentityKeys();
            $this->olmDevice->uploadOneTimeKeys();
        }

        if ($sync) {
            $this->syncFilter = sprintf('{ "room": { "timeline" : { "limit" : %d } } }', $limit);
            $this->sync();
        }

        return $this->token;
    }

    /**
     * Logout from the homeserver.
     *
     * @throws Exceptions\MatrixException
     */
    public function logout() {
        $this->stopListenerThread();
        $this->api->logout();
         if( Cache::has("LARAVEL_MATRIX_TOKEN") ) {
            Cache::forget("LARAVEL_MATRIX_TOKEN");
        }
    }

    /**
     * Create a new room on the homeserver.
     * TODO: move room creation/joining to User class for future application service usage
     * NOTE: we may want to leave thin wrappers here for convenience
     *
     * @param string|null $alias The canonical_alias of the room.
     * @param bool $isPublic The public/private visibility of the room.
     * @param array $invitees A set of user ids to invite into the room.
     * @param bool $isSpace is this room a space room?
     * @return Room
     * @throws Exceptions\MatrixException
     */
    public function createRoom(?string $alias = null, bool $isPublic = false, array $invitees = [], bool $isSpace = false ): Room {
        $additionalOptions = null;
        $name = null;
        if( $isSpace ) {
            $additionalOptions = [
                'creation_content' => [
                    'type' => 'm.space'
                ],
            ];
            $name = $alias;
            $alias = null;


        }
        $response = $this->api->createRoom($alias, $name, $isPublic, $invitees, null, $additionalOptions);

        return $this->mkRoom($response['room_id']);
    }

    /**
     * Join a room.
     *
     * @param string $roomIdOrAlias Room ID or an alias.
     * @return Room
     * @throws Exceptions\MatrixException
     */
    public function joinRoom(string $roomIdOrAlias): Room {
        $response = $this->api->joinRoom($roomIdOrAlias);
        $roomId = array_get($response, 'room_id', $roomIdOrAlias);

        return $this->mkRoom($roomId);
    }

    public function getRooms(): array {
        return $this->rooms;
    }

    /**
     * Add a listener that will send a callback when the client recieves an event.
     *
     * @param callable $callback Callback called when an event arrives.
     * @param string $eventType The event_type to filter for.
     * @return string Unique id of the listener, can be used to identify the listener.
     */
    public function addListener(callable $callback, string $eventType) {
        $listenerId = uniqid();
        $this->listeners[] = [
            'uid' => $listenerId,
            'callback' => $callback,
            'event_type' => $eventType,
        ];

        return $listenerId;
    }

    /**
     * Remove listener with given uid.
     *
     * @param string $uid Unique id of the listener to remove.
     */
    public function removeListener(string $uid) {
        $this->listeners = array_filter($this->listeners, function (array $a) use ($uid) {
            return $a['uid'] != $uid;
        });
    }

    /**
     * Add a presence listener that will send a callback when the client receives a presence update.
     *
     * @param callable $callback Callback called when a presence update arrives.
     * @return string Unique id of the listener, can be used to identify the listener.
     */
    public function addPresenceListener(callable $callback) {
        $listenerId = uniqid();
        $this->presenceListeners[$listenerId] = $callback;

        return $listenerId;
    }

    /**
     * Remove presence listener with given uid
     *
     * @param string $uid Unique id of the listener to remove
     */
    public function removePresenceListener(string $uid) {
        unset($this->presenceListeners[$uid]);
    }

    /**
     * Add an ephemeral listener that will send a callback when the client recieves an ephemeral event.
     *
     * @param callable $callback Callback called when an ephemeral event arrives.
     * @param string|null $eventType Optional. The event_type to filter for.
     * @return string Unique id of the listener, can be used to identify the listener.
     */
    public function addEphemeralListener(callable $callback, ?string $eventType = null) {
        $listenerId = uniqid();
        $this->ephemeralListeners[] = [
            'uid' => $listenerId,
            'callback' => $callback,
            'event_type' => $eventType,
        ];

        return $listenerId;
    }

    /**
     * Remove ephemeral listener with given uid.
     *
     * @param string $uid Unique id of the listener to remove.
     */
    public function removeEphemeralListener(string $uid) {
        $this->ephemeralListeners = array_filter($this->ephemeralListeners, function (array $a) use ($uid) {
            return $a['uid'] != $uid;
        });
    }

    /**
     * Add a listener that will send a callback when the client receives an invite.
     * @param callable $callback Callback called when an invite arrives.
     */
    public function addInviteListener(callable $callback) {
        $this->inviteListeners[] = $callback;
    }

    /**
     * Add a listener that will send a callback when the client has left a room.
     *
     * @param callable $callback Callback called when the client has left a room.
     */
    public function addLeaveListener(callable $callback) {
        $this->leftListeners[] = $callback;
    }

    public function listenForever(int $timeoutMs = 30000, ?callable $exceptionHandler = null, int $badSyncTimeout = 5) {
        $tempBadSyncTimeout = $badSyncTimeout;
        $this->shouldListen = true;
        // @phpstan-ignore-next-line
        while ($this->shouldListen) {
            try {
                $this->sync($timeoutMs);
                $tempBadSyncTimeout = $badSyncTimeout;
            } catch (MatrixRequestException $e) {
                // TODO: log error
                if ($e->getHttpCode() >= 500) {
                    sleep($badSyncTimeout);
                    $tempBadSyncTimeout = min($tempBadSyncTimeout * 2, $this->badSyncTimeoutLimit);
                } elseif (is_callable($exceptionHandler)) {
                    $exceptionHandler($e);
                } else {
                    throw $e;
                }
            } catch (\Exception $e) {
                if (is_callable($exceptionHandler)) {
                    $exceptionHandler($e);
                } else {
                    throw $e;
                }
            }
            // TODO: we should also handle MatrixHttpLibException for retry in case no response
        }
    }

    public function startListenerThread(int $timeoutMs = 30000, ?callable $exceptionHandler = null) {
        // Just no
    }

    public function stopListenerThread() {
        if ($this->syncThread) {
            $this->shouldListen = false;
        }
    }

    /**
     * Upload content to the home server and recieve a MXC url.
     * TODO: move to User class. Consider creating lightweight Media class.
     *
     * @param mixed $content The data of the content.
     * @param string $contentType The mimetype of the content.
     * @param string|null $filename Optional. Filename of the content.
     * @return mixed
     * @throws Exceptions\MatrixException
     * @throws Exceptions\MatrixHttpLibException
     * @throws MatrixRequestException If the upload failed for some reason.
     * @throws MatrixUnexpectedResponse If the homeserver gave a strange response
     */
    public function upload($content, string $contentType, ?string $filename = null) {
        try {
            $response = $this->api->mediaUpload($content, $contentType, $filename);
            if (array_key_exists('content_uri', $response)) {
                return $response['content_uri'];
            }

            throw new MatrixUnexpectedResponse('The upload was successful, but content_uri wasn\'t found.');
        } catch (MatrixRequestException $e) {
            throw new MatrixRequestException($e->getHttpCode(), 'Upload failed: ' . $e->getMessage());
        }
    }

    /**
     * @param string $roomId
     * @return Room
     * @throws Exceptions\MatrixException
     * @throws MatrixRequestException
     */
    private function mkRoom(string $roomId): Room {
        $room = new Room($this, $roomId);
        if ($this->encryption) {
            try {
                $event = $this->api->getStateEvent($roomId, "m.room.encryption");
                if ($event['algorithm'] === "m.megolm.v1.aes-sha2") {
                    $room->enableEncryption();
                }
            } catch (MatrixRequestException $e) {
                if ($e->getHttpCode() != 404) {
                    throw $e;
                }
            }
        }
        $this->rooms[$roomId] = $room;

        return $room;
    }

    /**
     * a simple send message to a room
     * @param  string  $message html message content
     * @param  string  $roomId
     * @return array|string
     * @throws Exceptions\MatrixException
     * @throws Exceptions\MatrixHttpLibException
     * @throws Exceptions\MatrixRequestException
     */
    public function sendMessage( string $message, string $roomId ) {
        $room = $this->mkRoom($roomId);
        return $room->sendHtml($message);
    }

    /**
     * TODO better handling of the blocking I/O caused by update_one_time_key_counts
     *
     * @param int $timeoutMs
     * @throws Exceptions\MatrixException
     * @throws MatrixRequestException
     */
    public function sync(int $timeoutMs = 30000) {
        $response = collect($this->api->sync($this->syncToken, $timeoutMs, $this->syncFilter));
        $this->syncToken = $response['next_batch'];
        
        foreach ((array)collect($response->get('presence'))->get('events') as $presenceUpdate) {
            foreach ($this->presenceListeners as $cb) {
                $cb($presenceUpdate);
            }
        }

        foreach ((array)collect($response->get('rooms'))->get('invite') as $roomId => $inviteRoom) {
            foreach ($this->inviteListeners as $cb) {
                $cb($roomId, $inviteRoom['invite_state']);
            }
        }
        foreach ((array)collect($response->get('rooms'))->get('leave') as $roomId => $leftRoom) {
            foreach ($this->leftListeners as $cb) {
                $cb($roomId, $leftRoom);
            }
            if (array_key_exists($roomId, $this->rooms)) {
                unset($this->rooms[$roomId]);
            }
        }
        if ($this->encryption && array_key_exists('device_one_time_keys_count', $response)) {
            $this->olmDevice->updateOneTimeKeysCounts($response['device_one_time_keys_count']);
        }
        foreach (collect($response->get('rooms'))->get('join') as $roomId => $syncRoom) {
            if (!empty($inviteRoom)) {
                foreach ($this->inviteListeners as $cb) {
                    $cb($roomId, $inviteRoom['invite_state']);
                }
            }
            if (!array_key_exists($roomId, $this->rooms)) {
                $this->mkRoom($roomId);
            }
            $room = $this->rooms[$roomId];
            // TODO: the rest of this for loop should be in room object method
            $room->prevBatch = $syncRoom["timeline"]["prev_batch"];
            foreach ((array)collect($response->get('state'))->get('events') as $event) {
                $event['room_id'] = $roomId;
                $room->processStateEvent($event);
            }
            foreach (collect(collect($syncRoom)->get('timeline'))->get('events') as $event) {
                $event['room_id'] = $roomId;
                $room->putEvent($event);

                // TODO: global listeners can still exist but work by each
                // $room.listeners[$uuid] having reference to global listener

                // Dispatch for client (global) listeners
                foreach ($this->listeners as $listener) {
                    if ($listener['event_type'] == null || $listener['event_type'] == $event['type']) {
                        $listener['callback']($event);
                    }
                }
            }
            foreach ((array)collect($response->get('ephemeral'))->get('events') as $event) {
                $event['room_id'] = $roomId;
                $room->putEphemeralEvent($event);

                // Dispatch for client (global) listeners
                foreach ($this->ephemeralListeners as $listener) {
                    if ($listener['event_type'] == null || $listener['event_type'] == $event['type']) {
                        $listener['callback']($event);
                    }
                }
            }
        }
    }

    /**
     * invite a user to a room
     * @param  string  $roomId
     * @param  string  $userId
     * @return array|string
     * @throws Exceptions\MatrixException
     * @throws Exceptions\MatrixHttpLibException
     * @throws MatrixRequestException
     */
    public function inviteUser(string $roomId, string $userId)
    {
        return $this->api->inviteUser($roomId, $userId);
    }

    /**
     * Remove mapping of an alias
     *
     * @param string $roomAlias The alias to be removed.
     * @return bool True if the alias is removed, false otherwise.
     * @throws Exceptions\MatrixException
     * @throws Exceptions\MatrixHttpLibException
     */
    public function removeRoomAlias(string $roomAlias): bool {
        try {
            $this->api->removeRoomAlias($roomAlias);
        } catch (MatrixRequestException $e) {
            return false;
        }

        return true;
    }

    public function api(): MatrixHttpApi {
        return $this->api;
    }

    public function userId():?string {
        return $this->userId;
    }

    public function cacheLevel() {
        return $this->cacheLevel;
    }

    /**
     * get the current access token
     * @return mixed
     */
    public function getToken() {
        return $this->token;
    }
}
