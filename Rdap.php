<?php

namespace Omnistate\Iana;

use Omnistate\Exception\UnavailableException;
use Omnistate\Model\Contact;
use Omnistate\Model\Domain;
use Omnistate\Model\Network;
use Omnistate\Registry\InternetRegistryInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Domains, IP ranges and autonomous systems, asked of the RDAP server IANA's
 * bootstrap names for them (RFC 9082/9083) - WHOIS's successor, in JSON,
 * free and without a key: a domain's registrar, dates, statuses, name
 * servers and DNSSEC, and its registrant when the registry still publishes
 * it; a network's holder, country and abuse contact.
 */
final class Rdap implements InternetRegistryInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Bootstrap $bootstrap,
        private readonly float $timeout = 10,
    ) {
    }

    public function name(): string
    {
        return 'iana';
    }

    public function domain(string $name): ?Domain
    {
        $name = self::ascii($name);
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name)) {
            return null; // not a domain name: nothing to ask
        }
        $server = $this->bootstrap->forDomain($name);
        if (null === $server) {
            return null;
        }
        $answer = $this->ask($server.'domain/'.rawurlencode($name));
        if (null === $answer) {
            return null;
        }
        $events = self::events($answer);

        return new Domain(
            name: mb_strtolower((string) ($answer['ldhName'] ?? $name)),
            source: $this->name(),
            handle: self::string($answer['handle'] ?? null),
            statuses: array_values(array_map('strval', $answer['status'] ?? [])),
            registrar: self::contact(self::entity($answer, 'registrar')),
            registrant: self::contact(self::entity($answer, 'registrant')),
            registeredAt: $events['registration'] ?? null,
            expiresAt: $events['expiration'] ?? null,
            changedAt: $events['last changed'] ?? null,
            nameservers: array_values(array_filter(array_map(fn ($ns) => \is_array($ns) && isset($ns['ldhName']) ? mb_strtolower((string) $ns['ldhName']) : null, $answer['nameservers'] ?? []))),
            dnssec: true === ($answer['secureDNS']['delegationSigned'] ?? false),
            server: $server,
            raw: $answer,
        );
    }

    public function network(string $address): ?Network
    {
        if (preg_match('/^(?:AS)?(\d+)$/i', trim($address), $match)) {
            $number = (int) $match[1];
            $server = $this->bootstrap->forAutnum($number);
            $answer = null === $server ? null : $this->ask($server.'autnum/'.$number);
        } else {
            $server = $this->bootstrap->forIp(trim($address));
            $answer = null === $server ? null : $this->ask($server.'ip/'.trim($address));
        }
        if (null === $answer) {
            return null;
        }
        $events = self::events($answer);

        return new Network(
            handle: (string) ($answer['handle'] ?? $address),
            source: $this->name(),
            name: self::string($answer['name'] ?? null),
            type: self::string($answer['type'] ?? null),
            country: self::string($answer['country'] ?? null),
            startAddress: self::string($answer['startAddress'] ?? null),
            endAddress: self::string($answer['endAddress'] ?? null),
            startAutnum: isset($answer['startAutnum']) ? (int) $answer['startAutnum'] : null,
            endAutnum: isset($answer['endAutnum']) ? (int) $answer['endAutnum'] : null,
            registrant: self::contact(self::entity($answer, 'registrant')),
            abuse: self::contact(self::entity($answer, 'abuse')),
            registeredAt: $events['registration'] ?? null,
            changedAt: $events['last changed'] ?? null,
            server: $server,
            raw: $answer,
        );
    }

    /** @return array<string, mixed>|null null when the server does not know it */
    private function ask(string $url): ?array
    {
        try {
            $response = $this->http->request('GET', $url, ['timeout' => $this->timeout, 'headers' => ['Accept' => 'application/rdap+json, application/json']]);
            $status = $response->getStatusCode();
            if (404 === $status) {
                return null;
            }
            if (429 === $status || $status >= 500) {
                $retry = $response->getHeaders(false)['retry-after'][0] ?? null;

                throw new UnavailableException(sprintf('%s answered %d.', parse_url($url, \PHP_URL_HOST), $status), is_numeric($retry) ? (int) $retry : null);
            }
            if ($status >= 400) {
                return null;
            }

            return $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new UnavailableException(sprintf('%s did not answer: %s', parse_url($url, \PHP_URL_HOST), $e->getMessage()), null, $e);
        }
    }

    /**
     * The first entity with that role, looked for among nested entities too
     * (a registrar's abuse contact sits inside the registrar).
     *
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>|null
     */
    private static function entity(array $object, string $role): ?array
    {
        foreach ($object['entities'] ?? [] as $entity) {
            if (!\is_array($entity)) {
                continue;
            }
            if (\in_array($role, $entity['roles'] ?? [], true)) {
                return $entity;
            }
            if ($found = self::entity($entity, $role)) {
                return $found;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $entity */
    private static function contact(?array $entity): ?Contact
    {
        if (null === $entity) {
            return null;
        }
        $card = [];
        foreach ($entity['vcardArray'][1] ?? [] as $property) {
            if (\is_array($property) && isset($property[0], $property[3])) {
                $card[$property[0]] ??= [$property[1] ?? [], $property[3]];
            }
        }
        $text = fn (string $key) => isset($card[$key]) && \is_string($card[$key][1]) && '' !== trim($card[$key][1]) ? trim($card[$key][1]) : null;
        $country = null;
        if (isset($card['adr'])) {
            [$parameters, $address] = $card['adr'];
            $country = \is_array($parameters) && isset($parameters['cc']) ? (string) $parameters['cc'] : (\is_array($address) ? (self::string(end($address)) ?: null) : null);
        }
        $ianaId = null;
        foreach ($entity['publicIds'] ?? [] as $id) {
            if (\is_array($id) && 'IANA Registrar ID' === ($id['type'] ?? null)) {
                $ianaId = (string) $id['identifier'];
            }
        }
        $url = null;
        foreach ($entity['links'] ?? [] as $link) {
            if (\is_array($link) && 'about' === ($link['rel'] ?? null)) {
                $url = self::string($link['href'] ?? null);
            }
        }

        $contact = new Contact(
            name: $text('fn'),
            organization: $text('org'),
            email: $text('email'),
            country: $country && 2 === \strlen($country) ? strtoupper($country) : null,
            handle: self::string($entity['handle'] ?? null),
            ianaId: $ianaId,
            url: $url,
        );

        // Redacted (the GDPR): nothing but a handle.
        return null === $contact->name && null === $contact->organization && null === $contact->email && null === $contact->country && null === $contact->ianaId ? null : $contact;
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, \DateTimeImmutable>
     */
    private static function events(array $object): array
    {
        $events = [];
        foreach ($object['events'] ?? [] as $event) {
            if (\is_array($event) && isset($event['eventAction'], $event['eventDate'])) {
                try {
                    $events[(string) $event['eventAction']] ??= new \DateTimeImmutable((string) $event['eventDate']);
                } catch (\Exception) {
                }
            }
        }

        return $events;
    }

    private static function ascii(string $name): string
    {
        $name = rtrim(mb_strtolower(trim($name)), '.');
        if (\function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $name)) {
            $name = idn_to_ascii($name, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46) ?: $name;
        }

        return $name;
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
