<?php

declare(strict_types=1);

namespace App\Http;

use App\Contract\LibreLinkAuthenticator;
use App\LibreLink\LibreLinkAuthException;
use App\LibreLink\LibreLinkException;
use App\Support\Logger;
use Closure;

/**
 * HTTP adapter for a one-shot LibreLinkUp login.
 *
 * The public PHP API forwards POST JSON {email, password, patientId?} here
 * over loopback. Persist only the encrypted session token. The password is
 * never logged and never written to disk.
 *
 * Paths are prefix-agnostic: this handler sees `/api/librelink/login`, never
 * `/t/<id>/api/librelink/login`. A Cloud wrap that already parsed a dashboard
 * id passes that id as `$id` (or `X-Dashboard-Id` on the loopback request).
 */
final class AuthIntakeHandler
{
    private const MAX_ATTEMPTS = 5;

    private const ATTEMPT_WINDOW_SECONDS = 300;

    /** @var array<string, list<int>> */
    private array $attempts = [];

    /**
     * @param LibreLinkAuthenticator|(Closure(string): ?LibreLinkAuthenticator) $authenticator
     *        A single authenticator, or a factory keyed by a caller-supplied
     *        dashboard id. This class does not parse tenant URLs.
     */
    public function __construct(
        private readonly LibreLinkAuthenticator|Closure $authenticator,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: bool} status, JSON payload, login succeeded
     */
    public function handle(string $method, string $path, string $body, string $id = ''): array
    {
        $path = strtolower(rtrim(strtok($path, '?') ?: $path, '/') ?: '/');
        $method = strtoupper($method);
        $auth = $this->resolveAuthenticator($id);
        if ($auth === null) {
            return [404, ['ok' => false, 'error' => 'not found'], false];
        }

        if ($path === '/api/librelink/status' || $path === '/status') {
            if ($method !== 'GET' && $method !== 'HEAD') {
                return [405, ['ok' => false, 'error' => 'method not allowed'], false];
            }

            return [200, [
                'ok' => true,
                'authenticated' => $auth->hasSession(),
            ], false];
        }

        if ($path === '/api/librelink/login' || $path === '/login') {
            if ($method !== 'POST') {
                return [405, ['ok' => false, 'error' => 'method not allowed'], false];
            }

            return $this->login($body, $auth, $id);
        }

        return [404, ['ok' => false, 'error' => 'not found'], false];
    }

    private function resolveAuthenticator(string $id): ?LibreLinkAuthenticator
    {
        if ($this->authenticator instanceof LibreLinkAuthenticator) {
            return $this->authenticator;
        }

        return ($this->authenticator)($id);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: bool}
     */
    private function login(string $body, LibreLinkAuthenticator $authenticator, string $bucket): array
    {
        if (!$this->allowAttempt($bucket)) {
            $this->logger->warning('LibreLink login intake rate-limited');

            return [429, ['ok' => false, 'error' => 'too many attempts'], false];
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [400, ['ok' => false, 'error' => 'invalid json'], false];
        }

        if (!is_array($payload)) {
            return [400, ['ok' => false, 'error' => 'invalid json'], false];
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $patientId = trim((string) ($payload['patientId'] ?? ''));

        if ($email === '' || $password === '') {
            return [400, ['ok' => false, 'error' => 'email and password are required'], false];
        }
        if (strlen($email) > 320 || strlen($password) > 256 || strlen($patientId) > 128) {
            return [400, ['ok' => false, 'error' => 'invalid credentials'], false];
        }

        try {
            $authenticator->login($email, $password, $patientId !== '' ? $patientId : null);
        } catch (LibreLinkAuthException) {
            $this->logger->warning('LibreLink login intake rejected by Abbott');

            return [401, ['ok' => false, 'error' => 'authentication failed'], false];
        } catch (LibreLinkException $e) {
            $this->logger->error('LibreLink login intake failed: ' . $e->getMessage());

            return [502, ['ok' => false, 'error' => 'authentication failed'], false];
        } catch (\Throwable $e) {
            $this->logger->error('LibreLink login intake failed: ' . $e->getMessage());

            return [500, ['ok' => false, 'error' => 'unavailable'], false];
        }

        $this->logger->info('LibreLink login intake stored a session');

        return [200, ['ok' => true, 'authenticated' => true], true];
    }

    private function allowAttempt(string $bucket): bool
    {
        $now = time();
        $window = $this->attempts[$bucket] ?? [];
        $window = array_values(array_filter(
            $window,
            static fn (int $at): bool => $at > $now - self::ATTEMPT_WINDOW_SECONDS,
        ));
        if (count($window) >= self::MAX_ATTEMPTS) {
            $this->attempts[$bucket] = $window;

            return false;
        }
        $window[] = $now;
        $this->attempts[$bucket] = $window;

        return true;
    }
}
