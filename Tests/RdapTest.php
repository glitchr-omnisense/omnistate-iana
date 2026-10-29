<?php

namespace Omnistate\Iana\Tests;

use Omnistate\Exception\OmnistateException;
use Omnistate\Exception\UnavailableException;
use Omnistate\Iana\Bootstrap;
use Omnistate\Iana\Rdap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RdapTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    public function testADomain(): void
    {
        $domain = $this->rdap()->domain('Glitchr.DEV.');

        self::assertSame('glitchr.dev', $domain->name);
        self::assertSame('Gandi SAS', $domain->registrar->name);
        self::assertSame('81', $domain->registrar->ianaId);
        self::assertSame('2022-05-19', $domain->registeredAt->format('Y-m-d'));
        self::assertSame('2027-05-19', $domain->expiresAt->format('Y-m-d'));
        self::assertSame(['ns-1-b.gandi.net', 'ns-210-c.gandi.net', 'ns-238-a.gandi.net'], $domain->nameservers);
        self::assertSame(['client transfer prohibited'], $domain->statuses);
        self::assertFalse($domain->dnssec);
        self::assertNull($domain->registrant, 'not published');
        self::assertSame('https://pubapi.registry.google/rdap/', $domain->server);
    }

    public function testADomainNobodyRegistered(): void
    {
        self::assertNull($this->rdap()->domain('glitchr.fr'));
        self::assertNull($this->rdap()->domain('glitchr.nowhere'), 'a TLD without RDAP');
    }

    public function testAnIpAddress(): void
    {
        $network = $this->rdap()->network('193.0.6.139');

        self::assertSame('RIPE-NCC', $network->name);
        self::assertSame('NL', $network->country);
        self::assertSame('ASSIGNED PA', $network->type);
        self::assertSame(['193.0.0.0', '193.0.7.255'], [$network->startAddress, $network->endAddress]);
        self::assertStringContainsString('RIPE NCC', $network->registrant->name);
        self::assertSame('abuse@ripe.net', $network->abuse->email);
        self::assertSame('https://rdap.db.ripe.net/', $network->server);
    }

    public function testAnAutonomousSystem(): void
    {
        $network = $this->rdap()->network('AS3333');

        self::assertSame('AS3333', $network->handle);
        self::assertSame(3333, $network->startAutnum);
        self::assertContains('https://rdap.db.ripe.net/autnum/3333', $this->asked);
    }

    public function testTheBootstrapPrefersHttpsAndTheMostSpecificRange(): void
    {
        $bootstrap = new Bootstrap($this->client());

        self::assertSame('https://rdap.arin.net/registry/', $bootstrap->forIp('8.8.8.8'));
        self::assertSame('https://rdap.db.ripe.net/', $bootstrap->forAutnum(3154));
        self::assertNull($bootstrap->forAutnum(1));
    }

    public function testARegistryDown(): void
    {
        $this->expectException(UnavailableException::class);
        $this->rdap(['https://pubapi.registry.google/rdap/domain/glitchr.dev' => new MockResponse('', ['http_code' => 503])])->domain('glitchr.dev');
    }

    /**
     * A server that refuses to answer is not a domain nobody registered: 401 and 403
     * (RFC 7480, 5.3: an authorization the server wants) are UnavailableException, and so
     * is 429 (RFC 7480, 5.5), with its Retry-After or 60 s.
     * https://www.rfc-editor.org/rfc/rfc7480#section-5
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusalIsUnavailableNotUnregistered(MockResponse $answer, ?int $retryAfter): void
    {
        try {
            $this->rdap(['https://pubapi.registry.google/rdap/domain/glitchr.dev' => $answer])->domain('glitchr.dev');
            self::fail('A refusal read as an answer.');
        } catch (UnavailableException $e) {
            if (null === $retryAfter) {
                self::assertNull($e->retryAfter);
            } else {
                self::assertGreaterThanOrEqual($retryAfter, $e->retryAfter);
                self::assertLessThanOrEqual($retryAfter + 5, $e->retryAfter);
            }
        }
    }

    public static function refusals(): iterable
    {
        yield '401' => [new MockResponse('', ['http_code' => 401]), null];
        yield '403' => [new MockResponse('{"errorCode":403,"title":"Forbidden"}', ['http_code' => 403]), null];
        yield '429 with Retry-After' => [new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => '30']]), 30];
        yield '429 with Retry-After as a date' => [new MockResponse('', ['http_code' => 429, 'response_headers' => ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120)]]), 118];
        yield '429 without Retry-After' => [new MockResponse('', ['http_code' => 429]), 60];
    }

    /**
     * A question the server refuses (400, RFC 7480 5.4; another 4xx) is an OmnistateException
     * with the server's reason (RFC 9083, 6: errorCode, title, description), not null.
     * https://www.rfc-editor.org/rfc/rfc9083#section-6
     */
    public function testAQuestionRefusedIsAnExceptionWithTheServersReason(): void
    {
        $rdap = $this->rdap([
            'https://pubapi.registry.google/rdap/domain/glitchr.dev' => new MockResponse('{"errorCode":400,"title":"Bad Request","description":["Unsupported label"]}', ['http_code' => 400]),
            'https://rdap.db.ripe.net/ip/193.0.6.139' => new MockResponse('', ['http_code' => 422]),
        ]);
        foreach (['domain' => 'glitchr.dev', 'network' => '193.0.6.139'] as $method => $question) {
            try {
                $rdap->{$method}($question);
                self::fail($question.': a refused question read as not registered.');
            } catch (UnavailableException $e) {
                self::fail($question.': a refused question is not the server being unavailable.');
            } catch (OmnistateException $e) {
                self::assertStringContainsString('domain' === $method ? 'Unsupported label' : '422', $e->getMessage());
            }
        }
        self::assertNull($this->rdap(['https://pubapi.registry.google/rdap/domain/glitchr.dev' => new MockResponse('', ['http_code' => 410])])->domain('glitchr.dev'), 'gone: not registered');
    }

    /** @param array<string, MockResponse> $overrides */
    private function rdap(array $overrides = []): Rdap
    {
        $client = $this->client($overrides);

        return new Rdap($client, new Bootstrap($client));
    }

    /** @param array<string, MockResponse> $overrides */
    private function client(array $overrides = []): MockHttpClient
    {
        $routes = [
            'https://data.iana.org/rdap/dns.json' => 'dns.json',
            'https://data.iana.org/rdap/ipv4.json' => 'ipv4.json',
            'https://data.iana.org/rdap/ipv6.json' => 'ipv6.json',
            'https://data.iana.org/rdap/asn.json' => 'asn.json',
            'https://pubapi.registry.google/rdap/domain/glitchr.dev' => 'domain.json',
            'https://rdap.nic.fr/domain/glitchr.fr' => 'domain-missing.json',
            'https://rdap.db.ripe.net/ip/193.0.6.139' => 'ip.json',
            'https://rdap.db.ripe.net/autnum/3333' => 'autnum.json',
        ];

        return new MockHttpClient(function (string $method, string $url) use ($routes, $overrides) {
            $this->asked[] = $url;
            if (isset($overrides[$url])) {
                return $overrides[$url];
            }
            if (!isset($routes[$url])) {
                return new MockResponse('{}', ['http_code' => 404]);
            }

            return new MockResponse(file_get_contents(__DIR__.'/Fixtures/'.$routes[$url]), ['http_code' => str_contains($routes[$url], 'missing') ? 404 : 200]);
        });
    }
}
