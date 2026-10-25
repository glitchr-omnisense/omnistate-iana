<?php

namespace Omnistate\Iana;

use Omnistate\Exception\UnavailableException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * IANA's RDAP bootstrap registries (RFC 9224): which RDAP server answers for
 * a top-level domain, an IPv4 or IPv6 range, a range of AS numbers. Read
 * once, kept a day when a cache is given: IANA asks clients not to fetch
 * them on every lookup.
 */
final class Bootstrap
{
    public const URL = 'https://data.iana.org/rdap/%s.json';

    /** @var array<string, list<array{list<string>, list<string>}>> */
    private array $services = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?CacheInterface $cache = null,
        private readonly float $timeout = 10,
    ) {
    }

    /** The RDAP server for a domain, by its top-level domain. */
    public function forDomain(string $domain): ?string
    {
        $labels = explode('.', $domain);
        // The longest suffix a service lists wins ("co.uk" before "uk" - IANA lists TLDs, but the lookup allows both).
        for ($i = 0; $i < \count($labels); ++$i) {
            $suffix = implode('.', \array_slice($labels, $i));
            foreach ($this->services('dns') as [$entries, $urls]) {
                if (\in_array($suffix, $entries, true)) {
                    return self::server($urls);
                }
            }
        }

        return null;
    }

    /** The RDAP server for an IP address, by the range holding it. */
    public function forIp(string $ip): ?string
    {
        $binary = @inet_pton($ip);
        if (false === $binary) {
            return null;
        }
        $best = null;
        $bestLength = -1;
        foreach ($this->services(4 === \strlen($binary) ? 'ipv4' : 'ipv6') as [$ranges, $urls]) {
            foreach ($ranges as $range) {
                [$network, $length] = array_pad(explode('/', $range, 2), 2, null);
                $length = (int) $length;
                if ($length > $bestLength && self::contains($binary, (string) $network, $length)) {
                    [$best, $bestLength] = [self::server($urls), $length];
                }
            }
        }

        return $best;
    }

    /** The RDAP server for an AS number. */
    public function forAutnum(int $number): ?string
    {
        foreach ($this->services('asn') as [$ranges, $urls]) {
            foreach ($ranges as $range) {
                [$from, $to] = array_pad(explode('-', $range, 2), 2, null);
                if ($number >= (int) $from && $number <= (int) ($to ?? $from)) {
                    return self::server($urls);
                }
            }
        }

        return null;
    }

    /** @return list<array{list<string>, list<string>}> */
    private function services(string $registry): array
    {
        return $this->services[$registry] ??= null === $this->cache
            ? $this->fetch($registry)
            : $this->cache->get('omnistate.iana.bootstrap.'.$registry, function (ItemInterface $item) use ($registry) {
                $item->expiresAfter(86400);

                return $this->fetch($registry);
            });
    }

    /** @return list<array{list<string>, list<string>}> */
    private function fetch(string $registry): array
    {
        try {
            $response = $this->http->request('GET', sprintf(self::URL, $registry), ['timeout' => $this->timeout]);
            if (429 === $response->getStatusCode()) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new UnavailableException(sprintf('IANA\'s %s bootstrap: too many calls.', $registry), is_numeric($retry) ? max(1, (int) $retry) : 60);
            }
            $answer = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new UnavailableException(sprintf('IANA\'s %s bootstrap did not answer: %s', $registry, $e->getMessage()), null, $e);
        }

        return array_values(array_filter($answer['services'] ?? [], fn ($service) => \is_array($service) && 2 === \count($service)));
    }

    /** HTTPS first; a trailing slash, so paths are appended. @param list<string> $urls */
    private static function server(array $urls): ?string
    {
        usort($urls, fn (string $a, string $b) => str_starts_with($b, 'https:') <=> str_starts_with($a, 'https:'));
        $url = $urls[0] ?? null;

        return null === $url ? null : rtrim($url, '/').'/';
    }

    private static function contains(string $binary, string $network, int $length): bool
    {
        $prefix = @inet_pton($network);
        if (false === $prefix || \strlen($prefix) !== \strlen($binary)) {
            return false;
        }
        $bytes = intdiv($length, 8);
        if (0 !== strncmp($binary, $prefix, $bytes)) {
            return false;
        }
        $bits = $length % 8;
        if (0 === $bits) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (\ord($binary[$bytes]) & $mask) === (\ord($prefix[$bytes]) & $mask);
    }
}
