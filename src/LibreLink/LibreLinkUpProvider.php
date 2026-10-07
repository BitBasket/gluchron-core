<?php

declare(strict_types=1);

namespace App\LibreLink;

use App\Contract\GlucoseProvider;
use App\Contract\LibreLinkAuthenticator;
use App\DTO\GlucoseReadingDTO;
use App\DTO\LibreLinkUpSessionDTO;
use App\Support\Config;
use App\Support\Logger;
use Carbon\Carbon;
use PHPExperts\RESTSpeaker\NoAuth;
use PHPExperts\RESTSpeaker\RESTAuthDriver;
use PHPExperts\RESTSpeaker\RESTSpeaker;

final class LibreLinkUpProvider implements GlucoseProvider, LibreLinkAuthenticator
{
    private RESTSpeaker $api;

    private ?LibreLinkUpSessionDTO $session = null;

    /** @var callable(RESTAuthDriver, string): RESTSpeaker */
    private $speakerFactory;

    private int $lastStatus = -1;

    private string $email = '';

    private string $password = '';

    private string $patientId = '';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly SessionStore $sessions,
        ?callable $speakerFactory = null,
        bool $useConfiguredCredentials = true,
    ) {
        $this->speakerFactory = $speakerFactory ?? static function (RESTAuthDriver $auth, string $baseUri): RESTSpeaker {
            return new RESTSpeaker($auth, LibreLinkUpEndpoints::normalizeBaseUri($baseUri));
        };
        $this->api = ($this->speakerFactory)(new NoAuth(), $this->initialBaseUri());
        if ($useConfiguredCredentials) {
            $this->email = $this->config->libreLinkEmail;
            $this->password = $this->config->libreLinkPassword;
            $this->patientId = $this->config->libreLinkPatientId;
        }
    }

    public function login(string $email, string $password, ?string $patientId = null): LibreLinkUpSessionDTO
    {
        $this->email = $email;
        $this->password = $password;
        if ($patientId !== null) {
            $this->patientId = $patientId;
        }

        return $this->authenticate();
    }

    public function hasSession(): bool
    {
        if ($this->session !== null && !$this->isExpired($this->session)) {
            return true;
        }

        $cached = $this->sessions->load();

        return $cached !== null && !$this->isExpired($cached);
    }

    public function needsInteractiveLogin(): bool
    {
        return !$this->hasSession() && ($this->email === '' || $this->password === '');
    }

    public function authenticate(): LibreLinkUpSessionDTO
    {
        $session = $this->loginAt($this->initialBaseUri(), 0);
        $this->session = $session;
        $this->sessions->save($session);
        $this->api = $this->authenticatedSpeaker($session);

        return $session;
    }

    public function getCurrentReading(): GlucoseReadingDTO
    {
        $graph = $this->fetchGraph();
        $measurement = $this->extractMeasurement($graph);

        return $this->normalizeReading($measurement);
    }

    public function getHistory(): array
    {
        $graph = $this->fetchGraph();
        $readings = [];

        $current = $this->extractMeasurement($graph);
        $readings[] = $this->normalizeReading($current);

        $points = $this->property($graph, 'graphData');
        if (is_object($points)) {
            $points = get_object_vars($points);
        }
        if (is_array($points)) {
            foreach ($points as $point) {
                if (is_object($point) || is_array($point)) {
                    $readings[] = $this->normalizeReading($this->asObject($point));
                }
            }
        }

        usort(
            $readings,
            static fn (GlucoseReadingDTO $a, GlucoseReadingDTO $b): int => $a->timestamp <=> $b->timestamp
        );

        return $readings;
    }

    private function fetchGraph(): object
    {
        $session = $this->ensureSession();
        $patientId = $session->patientId ?: $this->discoverPatientId($session);
        if ($patientId !== $session->patientId) {
            $session = new LibreLinkUpSessionDTO(array_merge($session->toArray(), ['patientId' => $patientId]));
            $this->session = $session;
            $this->sessions->save($session);
        }

        $payload = $this->request('GET', LibreLinkUpEndpoints::graph($patientId), allowReauth: true);
        $data = $this->property($payload, 'data');
        if (!is_object($data) && !is_array($data)) {
            $this->rejectMeasurement('data was not an object', $payload);
        }

        return $this->asObject($data);
    }

    private function discoverPatientId(LibreLinkUpSessionDTO $session): string
    {
        $payload = $this->request('GET', LibreLinkUpEndpoints::CONNECTIONS, allowReauth: true);
        $connections = $this->property($payload, 'data');
        if (!is_array($connections) || $connections === []) {
            $status = $this->property($payload, 'status');
            throw new LibreLinkResponseException(
                'LibreLinkUp connection lookup failed'
                . ($status !== null ? ' (status ' . json_encode($status) . ')' : '')
                . ($this->lastStatus > 0 ? ' HTTP ' . $this->lastStatus : ''),
            );
        }

        $configured = $this->patientId;
        foreach ($connections as $connection) {
            $row = $this->asObject($connection);
            $patientId = (string) ($this->property($row, 'patientId') ?? '');
            if ($patientId === '') {
                continue;
            }
            if ($configured === '' || $configured === $patientId) {
                return $patientId;
            }
        }

        throw new LibreLinkResponseException('LibreLinkUp connection lookup failed');
    }

    private function ensureSession(): LibreLinkUpSessionDTO
    {
        if ($this->session !== null && !$this->isExpired($this->session)) {
            $this->api = $this->authenticatedSpeaker($this->session);
            return $this->session;
        }

        $cached = $this->sessions->load();
        if ($cached !== null && !$this->isExpired($cached)) {
            $this->session = $cached;
            $this->api = $this->authenticatedSpeaker($cached);
            return $cached;
        }

        return $this->authenticate();
    }

    private function loginAt(string $baseUri, int $redirectHops): LibreLinkUpSessionDTO
    {
        $baseUri = LibreLinkUpEndpoints::normalizeBaseUri($baseUri);
        $this->api = ($this->speakerFactory)(new NoAuth(), $baseUri);

        [$email, $password] = $this->credentials();
        $payload = $this->send('POST', LibreLinkUpEndpoints::LOGIN, [
            'email' => $email,
            'password' => $password,
        ], [
            'headers' => LibreLinkUpEndpoints::clientHeaders($this->config->libreLinkClientVersion),
        ]);

        $this->assertHttpOk($this->lastStatus, 'LibreLinkUp authentication failed');

        $data = is_object($payload) ? $this->property($payload, 'data') : null;
        $apiStatus = is_object($payload) ? $this->property($payload, 'status') : null;

        if (is_object($payload)) {
            $region = $this->redirectRegion($payload);
            if ($region !== null) {
                if ($redirectHops >= 2) {
                    throw new LibreLinkAuthException('LibreLinkUp authentication failed: regional discovery looped');
                }
                $this->logger->info('LibreLinkUp redirected to region ' . $region);
                return $this->loginAt(LibreLinkUpEndpoints::baseUriForRegion($region), $redirectHops + 1);
            }
        }

        if ((int) $apiStatus !== 0) {
            throw new LibreLinkAuthException('LibreLinkUp authentication failed: HTTP ' . $this->lastStatus);
        }

        if (!is_object($data)) {
            throw new LibreLinkAuthException('LibreLinkUp authentication failed: malformed login response');
        }

        $ticket = $this->asObject($this->property($data, 'authTicket') ?? new \stdClass());
        $token = (string) ($this->property($ticket, 'token') ?? '');
        if ($token === '') {
            throw new LibreLinkAuthException('LibreLinkUp authentication failed: no session token');
        }

        $user = $this->property($data, 'user');
        $accountId = is_object($user) || is_array($user)
            ? (string) ($this->property($this->asObject($user), 'id') ?? '')
            : '';

        $expires = $this->property($ticket, 'expires');
        $expiresAt = is_numeric($expires) ? Carbon::createFromTimestamp((int) $expires, 'UTC') : null;

        $this->logger->info('LibreLinkUp authentication succeeded');

        return new LibreLinkUpSessionDTO([
            'token' => $token,
            'baseUri' => $baseUri,
            'accountId' => $accountId !== '' ? $accountId : null,
            'expiresAt' => $expiresAt,
            'patientId' => $this->patientId !== '' ? $this->patientId : null,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        if ($this->email === '' || $this->password === '') {
            throw new LibreLinkAuthException('LibreLinkUp login required');
        }

        return [$this->email, $this->password];
    }

    private function request(string $method, string $path, bool $allowReauth, int $redirectHops = 0): object
    {
        $payload = $this->send($method, $path);
        $status = $this->lastStatus;
        if ($status === 401 && $allowReauth) {
            $this->logger->info('LibreLinkUp session rejected; authenticating again');
            $this->sessions->clear();
            $this->session = null;
            $this->authenticate();
            return $this->request($method, $path, allowReauth: false, redirectHops: $redirectHops);
        }

        $this->assertHttpOk($status, 'LibreLinkUp request failed');

        if (!is_object($payload)) {
            $this->rejectMeasurement('body was not a JSON object', $payload);
        }

        // A stored session can still be aimed at the global host. Connections
        // and graph then answer HTTP 200 with {redirect, region} instead of a
        // reading. Log in on that regional host and repeat the call.
        $region = $this->redirectRegion($payload);
        if ($region !== null) {
            if ($redirectHops >= 2) {
                throw new LibreLinkAuthException('LibreLinkUp request failed: regional discovery looped');
            }
            $this->logger->info('LibreLinkUp redirected to region ' . $region);
            $this->reauthenticateAt(LibreLinkUpEndpoints::baseUriForRegion($region));
            return $this->request($method, $path, allowReauth: false, redirectHops: $redirectHops + 1);
        }

        return $payload;
    }

    private function redirectRegion(object $payload): ?string
    {
        $data = $this->property($payload, 'data');
        $subject = is_object($data) || is_array($data) ? $this->asObject($data) : $payload;
        $region = $this->property($subject, 'region');
        if (!$this->property($subject, 'redirect') || !is_string($region) || trim($region) === '') {
            return null;
        }

        return $region;
    }

    private function reauthenticateAt(string $baseUri): void
    {
        $knownPatient = $this->session->patientId ?? '';
        if ($knownPatient !== '' && $this->patientId === '') {
            $this->patientId = $knownPatient;
        }
        $this->sessions->clear();
        $this->session = null;
        $session = $this->loginAt($baseUri, 0);
        $this->session = $session;
        $this->sessions->save($session);
        $this->api = $this->authenticatedSpeaker($session);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, mixed $body = null, array $options = []): mixed
    {
        $options['http_errors'] = false;

        try {
            $payload = strtoupper($method) === 'POST'
                ? $this->api->post($path, $body, $options)
                : $this->api->get($path, $options);
            $status = $this->api->getLastStatusCode();
            $this->lastStatus = $status > 0 ? $status : $this->lastStatus;
            return $payload;
        } catch (LibreLinkException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            if ($response === null) {
                $detail = $this->oneLine($e->getMessage());
                throw new LibreLinkNetworkException(
                    'LibreLinkUp request failed: network error' . ($detail !== '' ? ': ' . $detail : ''),
                    0,
                    $e,
                );
            }
            $this->lastStatus = $response->getStatusCode();
            $raw = (string) $response->getBody();
            if ($raw === '') {
                return null;
            }
            $decoded = json_decode($raw);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
    }

    private function assertHttpOk(int $status, string $prefix): void
    {
        if ($status === 429) {
            throw new LibreLinkRateLimitException('LibreLinkUp request rate-limited: HTTP 429');
        }
        if ($status === 401) {
            throw new LibreLinkAuthException($prefix . ': HTTP 401');
        }
        if ($status < 200 || $status >= 300) {
            if ($status < 0) {
                throw new LibreLinkNetworkException($prefix . ': network error');
            }
            throw new LibreLinkResponseException($prefix . ': HTTP ' . $status);
        }
    }

    private function extractMeasurement(object $graph): object
    {
        $connection = $this->property($graph, 'connection');
        if (is_object($connection) || is_array($connection)) {
            $measurement = $this->property($this->asObject($connection), 'glucoseMeasurement');
            if (is_object($measurement) || is_array($measurement)) {
                return $this->asObject($measurement);
            }
        }

        $this->rejectMeasurement('connection.glucoseMeasurement was missing', $graph);
    }

    private function normalizeReading(object $abbott): GlucoseReadingDTO
    {
        $mgDl = $this->property($abbott, 'ValueInMgPerDl');
        if (!is_numeric($mgDl)) {
            $this->rejectMeasurement('ValueInMgPerDl was not numeric', $abbott);
        }

        $timestamp = $this->property($abbott, 'FactoryTimestamp')
            ?? $this->property($abbott, 'Timestamp');
        if (!is_string($timestamp) || $timestamp === '') {
            $this->rejectMeasurement('timestamp was missing', $abbott);
        }

        [$trend, $arrow] = TrendNormalizer::fromArrow($this->property($abbott, 'TrendArrow'));

        return new GlucoseReadingDTO([
            'timestamp' => Carbon::parse($timestamp, 'UTC'),
            'glucoseMgDl' => (int) round((float) $mgDl),
            'trend' => $trend,
            'trendArrow' => $arrow,
            'source' => 'librelinkup',
        ]);
    }

    private function authenticatedSpeaker(LibreLinkUpSessionDTO $session): RESTSpeaker
    {
        return ($this->speakerFactory)(
            new LibreLinkUpAuth(
                $session->token,
                $session->accountId,
                $this->config->libreLinkClientVersion,
            ),
            $session->baseUri,
        );
    }

    private function initialBaseUri(): string
    {
        if ($this->config->libreLinkBaseUri !== '') {
            return LibreLinkUpEndpoints::normalizeBaseUri($this->config->libreLinkBaseUri);
        }

        return LibreLinkUpEndpoints::baseUriForRegion($this->config->libreLinkRegion);
    }

    private function isExpired(LibreLinkUpSessionDTO $session): bool
    {
        if ($session->expiresAt === null) {
            return false;
        }

        return $session->expiresAt->lessThanOrEqualTo(Carbon::now('UTC')->addMinute());
    }

    private function property(mixed $subject, string $name): mixed
    {
        if (is_array($subject)) {
            return $subject[$name] ?? null;
        }
        if (is_object($subject)) {
            return $subject->{$name} ?? null;
        }

        return null;
    }

    private function asObject(mixed $value): object
    {
        if (is_object($value)) {
            return $value;
        }
        if (is_array($value)) {
            return (object) $value;
        }

        $this->rejectMeasurement('value was not an object', $value);
    }

    /**
     * @return never
     */
    private function rejectMeasurement(string $reason, mixed $subject): void
    {
        $http = $this->lastStatus > 0 ? '; HTTP ' . $this->lastStatus : '';
        throw new LibreLinkResponseException(
            'LibreLinkUp response did not contain a glucose measurement'
            . ' (' . $reason . $http . '): '
            . $this->responseSnapshot($subject)
        );
    }

    private function responseSnapshot(mixed $subject): string
    {
        $encoded = json_encode(
            $this->prepareSnapshot($subject),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if (!is_string($encoded) || $encoded === '') {
            return get_debug_type($subject);
        }

        $limit = 4000;
        if (strlen($encoded) > $limit) {
            return substr($encoded, 0, $limit) . '…';
        }

        return $encoded;
    }

    private function prepareSnapshot(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '…';
        }
        if (is_object($value)) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = $this->snapshotField((string) $key, $item, $depth);
            }

            return $copy;
        }
        if (is_array($value)) {
            if (array_is_list($value) && count($value) > 3) {
                return [
                    '_count' => count($value),
                    '_first' => isset($value[0]) ? $this->prepareSnapshot($value[0], $depth + 1) : null,
                ];
            }
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = is_string($key)
                    ? $this->snapshotField($key, $item, $depth)
                    : $this->prepareSnapshot($item, $depth + 1);
            }

            return $out;
        }
        if (is_string($value) && strlen($value) > 500) {
            return substr($value, 0, 500) . '…';
        }

        return $value;
    }

    private function oneLine(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if (strlen($message) > 500) {
            return substr($message, 0, 500) . '…';
        }

        return $message;
    }

    private function snapshotField(string $key, mixed $item, int $depth): mixed
    {
        if (in_array(strtolower($key), ['token', 'password', 'authorization'], true)) {
            return '[redacted]';
        }

        return $this->prepareSnapshot($item, $depth + 1);
    }
}
