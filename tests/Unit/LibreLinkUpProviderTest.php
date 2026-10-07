<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\LibreLink\LibreLinkAuthException;
use App\LibreLink\LibreLinkNetworkException;
use App\LibreLink\LibreLinkRateLimitException;
use App\LibreLink\LibreLinkResponseException;
use App\LibreLink\LibreLinkUpProvider;
use App\LibreLink\SessionStore;
use App\Support\Logger;
use App\Tests\Support\ConfigFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPExperts\RESTSpeaker\HTTPSpeaker;
use PHPExperts\RESTSpeaker\RESTSpeaker;
use PHPUnit\Framework\TestCase;

final class LibreLinkUpProviderTest extends TestCase
{
    public function testAuthenticatesAndNormalizesGraphReading(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse($this->fixture('graph.json')),
        ], $history);

        $reading = $provider->getCurrentReading();

        $this->assertSame(174, $reading->glucoseMgDl);
        $this->assertSame('falling', $reading->trend);
        $this->assertSame('↘', $reading->trendArrow);
        $this->assertSame('librelinkup', $reading->source);
        $this->assertSame('2026-09-01 19:31:00', $reading->timestamp->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('llu/auth/login', $this->path($history[0]));
        $this->assertSame('llu/connections', $this->path($history[1]));
        $this->assertSame('llu/connections/patient-1/graph', $this->path($history[2]));
        $this->assertSame('Bearer test-token', $history[2]['request']->getHeaderLine('Authorization'));
        $this->assertSame(
            hash('sha256', '11111111-1111-1111-1111-111111111111'),
            $history[2]['request']->getHeaderLine('Account-Id')
        );
        $this->assertSame('llu.android', $history[2]['request']->getHeaderLine('product'));
    }

    public function testFollowsRegionalRedirect(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-redirect.json')),
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse($this->fixture('graph.json')),
        ], $history);

        $session = $provider->authenticate();

        $this->assertSame('https://api-ae.libreview.io/', $session->baseUri);
        $this->assertStringContainsString('api.libreview.io', (string) $history[0]['request']->getUri());
        $this->assertStringContainsString('api-ae.libreview.io', (string) $history[1]['request']->getUri());
    }

    public function testHistoryUsesGraphData(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse($this->fixture('graph.json')),
        ], $history);

        $readings = $provider->getHistory();
        $this->assertCount(3, $readings);
        $this->assertSame(160, $readings[0]->glucoseMgDl);
        $this->assertSame(174, $readings[2]->glucoseMgDl);
    }

    public function testHistoryAcceptsObjectShapedGraphData(): void
    {
        $history = [];
        $graph = json_decode($this->fixture('graph.json'), true);
        $graph['data']['graphData'] = (object) $graph['data']['graphData'];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse(json_encode($graph, JSON_THROW_ON_ERROR)),
        ], $history);

        $readings = $provider->getHistory();
        $this->assertCount(3, $readings);
        $this->assertSame(160, $readings[0]->glucoseMgDl);
    }

    public function testInvalidLogin(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-invalid.json')),
        ], $history);

        $this->expectException(LibreLinkAuthException::class);
        $provider->authenticate();
    }

    public function testHttp401(): void
    {
        $history = [];
        $provider = $this->provider([
            new Response(401, ['Content-Type' => 'application/json'], '{"status":2}'),
        ], $history);

        $this->expectException(LibreLinkAuthException::class);
        $provider->authenticate();
    }

    public function testHttp429(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            new Response(429, ['Content-Type' => 'application/json'], '{"message":"rate"}'),
        ], $history);

        $this->expectException(LibreLinkRateLimitException::class);
        $provider->getCurrentReading();
    }

    public function testMalformedGraph(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse($this->fixture('malformed.json')),
        ], $history);

        $this->expectException(LibreLinkResponseException::class);
        $this->expectExceptionMessage('connection.glucoseMeasurement was missing');
        $this->expectExceptionMessage('{"connection":{}}');
        $provider->getCurrentReading();
    }

    public function testMissingDataIncludesTheResponseBody(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse('{"status":4,"data":null,"ticket":{"token":"secret-token"}}'),
        ], $history);

        try {
            $provider->getCurrentReading();
            $this->fail('Expected a LibreLink response exception');
        } catch (LibreLinkResponseException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('data was not an object', $message);
            $this->assertStringContainsString('HTTP 200', $message);
            $this->assertStringContainsString('"status":4', $message);
            $this->assertStringContainsString('[redacted]', $message);
            $this->assertStringNotContainsString('secret-token', $message);
        }
    }

    public function testNetworkFailureIncludesTheTransportMessage(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            new ConnectException('cURL error 6: Could not resolve host', new Request('GET', 'https://api.libreview.io/llu/connections')),
        ], $history);

        try {
            $provider->getCurrentReading();
            $this->fail('Expected a LibreLink network exception');
        } catch (LibreLinkNetworkException $e) {
            $this->assertStringContainsString('Could not resolve host', $e->getMessage());
        }
    }

    public function testRemembersPatientIdSoLaterPollsSkipConnectionLookup(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse($this->fixture('graph.json')),
            $this->jsonResponse($this->fixture('graph.json')),
        ], $history);

        $provider->getCurrentReading();
        $provider->getCurrentReading();

        $this->assertCount(4, $history);
        $this->assertSame('llu/connections/patient-1/graph', $this->path($history[3]));
    }

    public function testLoginOverridesConfigCredentials(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
        ], $history);

        $provider->login('posted@example.com', 'posted-secret');

        $body = (string) $history[0]['request']->getBody();
        $this->assertStringContainsString('posted@example.com', $body);
        $this->assertStringContainsString('posted-secret', $body);
        $this->assertStringNotContainsString('user@example.com', $body);
        $this->assertTrue($provider->hasSession());
    }

    public function testEmptyPasswordWithoutSessionRequiresLogin(): void
    {
        $history = [];
        $tmp = sys_get_temp_dir() . '/gluchron-session-' . uniqid('', true);
        @mkdir($tmp, 0700, true);
        $provider = $this->provider([], $history, ConfigFactory::make($tmp, email: '', password: ''));

        $this->assertFalse($provider->hasSession());
        $this->assertTrue($provider->needsInteractiveLogin());
        $this->expectException(LibreLinkAuthException::class);
        $this->expectExceptionMessage('LibreLinkUp login required');
        $provider->authenticate();
    }

    public function testSkipsConfiguredCredentialsWhenAsked(): void
    {
        $history = [];
        $tmp = sys_get_temp_dir() . '/gluchron-session-' . uniqid('', true);
        @mkdir($tmp, 0700, true);
        $provider = $this->provider(
            [],
            $history,
            ConfigFactory::make($tmp, email: 'user@example.com', password: 'secret'),
            useConfiguredCredentials: false,
        );

        $this->assertTrue($provider->needsInteractiveLogin());
        $this->expectException(LibreLinkAuthException::class);
        $this->expectExceptionMessage('LibreLinkUp login required');
        $provider->authenticate();
    }

    public function testCachedSessionDoesNotNeedPassword(): void
    {
        $history = [];
        $tmp = sys_get_temp_dir() . '/gluchron-session-' . uniqid('', true);
        @mkdir($tmp, 0700, true);
        file_put_contents($tmp . '/libre-session.json', json_encode([
            'token' => 'test-token',
            'baseUri' => 'https://api.libreview.io/',
            'accountId' => '11111111-1111-1111-1111-111111111111',
            'patientId' => 'patient-1',
        ], JSON_THROW_ON_ERROR));
        $provider = $this->provider(
            [$this->jsonResponse($this->fixture('graph.json'))],
            $history,
            ConfigFactory::make($tmp, email: '', password: ''),
        );

        $reading = $provider->getCurrentReading();

        $this->assertSame(174, $reading->glucoseMgDl);
        $this->assertCount(1, $history);
        $this->assertSame('llu/connections/patient-1/graph', $this->path($history[0]));
    }

    public function testGraphRedirectLogsInOnTheRegionalHost(): void
    {
        $history = [];
        $tmp = sys_get_temp_dir() . '/gluchron-session-' . uniqid('', true);
        @mkdir($tmp, 0700, true);
        file_put_contents($tmp . '/libre-session.json', json_encode([
            'token' => 'test-token',
            'baseUri' => 'https://api.libreview.io/',
            'accountId' => '11111111-1111-1111-1111-111111111111',
            'patientId' => 'patient-1',
        ], JSON_THROW_ON_ERROR));
        $provider = $this->provider([
            $this->jsonResponse('{"status":0,"data":{"redirect":true,"region":"eu"}}'),
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('graph.json')),
        ], $history, ConfigFactory::make($tmp));

        $reading = $provider->getCurrentReading();

        $this->assertSame(174, $reading->glucoseMgDl);
        $this->assertSame('llu/connections/patient-1/graph', $this->path($history[0]));
        $this->assertStringContainsString('api.libreview.io', (string) $history[0]['request']->getUri());
        $this->assertSame('llu/auth/login', $this->path($history[1]));
        $this->assertStringContainsString('api-eu.libreview.io', (string) $history[1]['request']->getUri());
        $this->assertSame('llu/connections/patient-1/graph', $this->path($history[2]));
        $this->assertStringContainsString('api-eu.libreview.io', (string) $history[2]['request']->getUri());
    }

    public function testConnectionsRedirectLogsInOnTheRegionalHost(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse('{"status":0,"data":{"redirect":true,"region":"eu"}}'),
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            $this->jsonResponse($this->fixture('graph.json')),
        ], $history);

        $reading = $provider->getCurrentReading();

        $this->assertSame(174, $reading->glucoseMgDl);
        $this->assertSame('llu/connections', $this->path($history[1]));
        $this->assertStringContainsString('api-eu.libreview.io', (string) $history[3]['request']->getUri());
        $this->assertStringContainsString('api-eu.libreview.io', (string) $history[4]['request']->getUri());
    }

    public function testRegionalRedirectLoopStops(): void
    {
        $history = [];
        $tmp = sys_get_temp_dir() . '/gluchron-session-' . uniqid('', true);
        @mkdir($tmp, 0700, true);
        file_put_contents($tmp . '/libre-session.json', json_encode([
            'token' => 'test-token',
            'baseUri' => 'https://api.libreview.io/',
            'accountId' => '11111111-1111-1111-1111-111111111111',
            'patientId' => 'patient-1',
        ], JSON_THROW_ON_ERROR));
        $redirect = $this->jsonResponse('{"status":0,"data":{"redirect":true,"region":"eu"}}');
        $provider = $this->provider([
            $redirect,
            $this->jsonResponse($this->fixture('login-success.json')),
            $redirect,
            $this->jsonResponse($this->fixture('login-success.json')),
            $redirect,
        ], $history, ConfigFactory::make($tmp));

        $this->expectException(LibreLinkAuthException::class);
        $this->expectExceptionMessage('regional discovery looped');
        $provider->getCurrentReading();
    }

    public function testReauthenticatesAfter401OnGraph(): void
    {
        $history = [];
        $provider = $this->provider([
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('connections.json')),
            new Response(401, ['Content-Type' => 'application/json'], '{"status":2}'),
            $this->jsonResponse($this->fixture('login-success.json')),
            $this->jsonResponse($this->fixture('graph.json')),
        ], $history);

        $reading = $provider->getCurrentReading();
        $this->assertSame(174, $reading->glucoseMgDl);
    }

    /**
     * @param list<Response> $responses
     * @param list<array<string, mixed>> $history
     */
    private function provider(
        array $responses,
        array &$history,
        ?\App\Support\Config $config = null,
        bool $useConfiguredCredentials = true,
    ): LibreLinkUpProvider {
        if ($config === null) {
            $tmp = sys_get_temp_dir() . '/gluchron-session-' . uniqid('', true);
            @mkdir($tmp, 0700, true);
            $config = ConfigFactory::make($tmp);
        }
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $factory = static function ($auth, string $baseUri) use ($stack): RESTSpeaker {
            $guzzle = new Client([
                'handler' => $stack,
                'base_uri' => $baseUri,
                'http_errors' => false,
            ]);

            return new RESTSpeaker($auth, $baseUri, new HTTPSpeaker($baseUri, $guzzle));
        };

        $logger = new Logger(fopen('php://memory', 'ab'));

        return new LibreLinkUpProvider(
            $config,
            $logger,
            new SessionStore($config->sessionPath, $logger),
            $factory,
            $useConfiguredCredentials,
        );
    }

    private function jsonResponse(string $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], $body);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $name);
    }

    /**
     * @param array<string, mixed> $transaction
     */
    private function path(array $transaction): string
    {
        return ltrim($transaction['request']->getUri()->getPath(), '/');
    }
}
