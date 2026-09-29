<?php

namespace Omnistate\Iana\Tests;

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
