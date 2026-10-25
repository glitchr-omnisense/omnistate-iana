<?php

namespace Omnistate\Iana\Tests;

use Omnistate\Iana\Bootstrap;
use Omnistate\Iana\Rdap;
use Omnistate\Testing\RegistryContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnistate's conformance suite, on recorded RDAP answers: IANA's bootstrap files, then the registries they name. */
final class RdapContractTest extends TestCase
{
    use RegistryContractTests;

    protected function registry(HttpClientInterface $http): object
    {
        return new Rdap($http, new Bootstrap($http));
    }

    protected function recordedAnswers(): array
    {
        return $this->infrastructureAnswers() + [
            'GET https://pubapi.registry.google/rdap/domain/glitchr.dev' => self::fixture('domain.json'),
            'GET https://rdap.db.ripe.net/autnum/3333' => self::fixture('autnum.json'),
        ];
    }

    protected function infrastructureAnswers(): array
    {
        return [
            'GET https://data.iana.org/rdap/dns.json' => self::fixture('dns.json'),
            'GET https://data.iana.org/rdap/ipv4.json' => self::fixture('ipv4.json'),
            'GET https://data.iana.org/rdap/ipv6.json' => self::fixture('ipv6.json'),
            'GET https://data.iana.org/rdap/asn.json' => self::fixture('asn.json'),
        ];
    }

    private static function fixture(string $name): \Closure
    {
        return static fn () => new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$name), ['response_headers' => ['content-type' => 'application/rdap+json']]);
    }
}
