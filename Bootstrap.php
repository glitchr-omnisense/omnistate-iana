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

    /** @var array<string, list<array{mixed, mixed}>> */
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
                if (\in_array($suffix, self::entries('dns', $entries), true)) {
                    return self::server('dns', $urls);
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
        $registry = 4 === \strlen($binary) ? 'ipv4' : 'ipv6';
        foreach ($this->services($registry) as [$ranges, $urls]) {
            foreach (self::ranges($registry, $ranges) as $range) {
                [$network, $length] = array_pad(explode('/', $range, 2), 2, null);
                $length = (int) $length;
                if ($length > $bestLength && self::contains($binary, (string) $network, $length)) {
                    [$best, $bestLength] = [self::server($registry, $urls), $length];
                }
            }
        }

        return $best;
    }

    /** The RDAP server for an AS number. */
    public function forAutnum(int $number): ?string
    {
        foreach ($this->services('asn') as [$ranges, $urls]) {
            foreach (self::ranges('asn', $ranges) as $range) {
                [$from, $to] = array_pad(explode('-', $range, 2), 2, null);
                if ($number >= (int) $from && $number <= (int) ($to ?? $from)) {
                    return self::server('asn', $urls);
                }
            }
        }

        return null;
    }

    /** @return list<array{mixed, mixed}> */
    private function services(string $registry): array
    {
        return $this->services[$registry] ??= null === $this->cache
            ? $this->fetch($registry)
            : self::shaped($registry, $this->cache->get('omnistate.iana.bootstrap.'.$registry, function (ItemInterface $item) use ($registry) {
                $item->expiresAfter(86400);

                return $this->fetch($registry);
            }));
    }

    /** @return list<array{mixed, mixed}> */
    private function fetch(string $registry): array
    {
        try {
            $response = $this->http->request('GET', sprintf(self::URL, $registry), ['timeout' => $this->timeout]);
            if (429 === $response->getStatusCode()) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new UnavailableException(sprintf('IANA\'s %s bootstrap: too many calls.', $registry), is_numeric($retry) ? max(1, (int) $retry) : 60, null, 429);
            }
            $answer = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw new UnavailableException(sprintf('IANA\'s %s bootstrap did not answer: %s', $registry, $e->getMessage()), null, $e);
        }

        return self::shaped($registry, array_values(array_filter(self::list($registry, $answer['services'] ?? null), fn ($service) => \is_array($service) && 2 === \count($service))));
    }

    /**
     * The services as the file lists them, each its entries and its URLs as
     * they came: a service is read when a lookup reaches it (entries(),
     * ranges(), server()), so one of another shape fails only the lookups
     * that need it, the others keep answering. A file whose services are not
     * a list is unavailable (the family's rule on a body that is not what it
     * should be).
     *
     * @return list<array{mixed, mixed}>
     *
     * @throws UnavailableException
     */
    private static function shaped(string $registry, mixed $services): array
    {
        $shaped = [];
        foreach (self::list($registry, $services) as $service) {
            if (\is_array($service) && 2 === \count($service)) {
                $shaped[] = [$service[0] ?? null, $service[1] ?? null];
            }
        }

        return $shaped;
    }

    /**
     * A DNS service's entries, as in_array() read them: a list (what is not a
     * string in it never matches); anything else is another shape.
     *
     * @return array<mixed>
     *
     * @throws UnavailableException
     */
    private static function entries(string $registry, mixed $entries): array
    {
        return \is_array($entries) ? $entries : throw self::malformed($registry);
    }

    /**
     * An IP or AS service's ranges, as foreach and explode() read them: what
     * is not an array has none, a number is its text; a range of another
     * shape fails the lookup that reaches it.
     *
     * @return \Generator<string>
     *
     * @throws UnavailableException
     */
    private static function ranges(string $registry, mixed $ranges): \Generator
    {
        if (!\is_array($ranges)) {
            return;
        }
        foreach ($ranges as $range) {
            yield \is_string($range) || \is_int($range) || \is_float($range) ? (string) $range : throw self::malformed($registry);
        }
    }

    /**
     * A list of the file (null: none), its items in order.
     *
     * @return list<mixed>
     *
     * @throws UnavailableException
     */
    private static function list(string $registry, mixed $value): array
    {
        return match (true) {
            null === $value => [],
            \is_array($value) => array_values($value),
            default => throw self::malformed($registry),
        };
    }

    private static function malformed(string $registry): UnavailableException
    {
        return new UnavailableException(sprintf('IANA\'s %s bootstrap answered services of another shape than RFC 9224\'s.', $registry));
    }

    /**
     * HTTPS first; a trailing slash, so paths are appended. URLs that are not
     * a list of strings are another shape, refused for the lookup that matched them.
     *
     * @throws UnavailableException
     */
    private static function server(string $registry, mixed $urls): ?string
    {
        $strings = [];
        foreach (\is_array($urls) ? $urls : throw self::malformed($registry) as $url) {
            $strings[] = \is_string($url) ? $url : throw self::malformed($registry);
        }
        $urls = $strings;
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
