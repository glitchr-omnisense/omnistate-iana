# omnistate/iana

Domains, IP ranges and autonomous systems for
[omnisense/omnistate](https://github.com/glitchr-omnisense/omnistate), through
[IANA's RDAP bootstrap](https://data.iana.org/rdap/) (RFC 9224) and the registry it names (RFC
9082/9083) - WHOIS's successor, in JSON, open. Two classes:

- `Omnistate\Iana\Bootstrap` reads IANA's bootstrap files, which say which RDAP server answers for
  a top-level domain, an IP range, a range of AS numbers;
- `Omnistate\Iana\Rdap`, the `InternetRegistryInterface`, asks that server.

| Operation | Requests |
|---|---|
| bootstrap | `GET https://data.iana.org/rdap/dns.json`, `ipv4.json`, `ipv6.json`, `asn.json`, each once |
| `domain($name)` | `GET <server>domain/<name>` |
| `network($ip)` | `GET <server>ip/<address>` |
| `network('AS<n>')` | `GET <server>autnum/<n>` |

with `Accept: application/rdap+json, application/json`. The bootstrap's address is the constant
`Bootstrap::URL` (`https://data.iana.org/rdap/%s.json`).

## Installation

```sh
composer require omnisense/omnistate omnistate/iana
```

Requires PHP 8.2, `omnisense/omnistate` (1.x) and `symfony/http-client` (6.4, 7 or 8). Suggests
`ext-intl`, to ask an international domain name in its ASCII form. In a Symfony application with
`Omnistate\Bridge\Symfony\OmnistateBundle`, `Bootstrap` and `Rdap` are registered as soon as the
package is installed, `Rdap` aliased as `Omnistate\Registry\InternetRegistryInterface` and given
to the facade; the bootstrap files are kept in the bundle's cache pool.

## Credentials

None: IANA's bootstrap and the registries' RDAP servers are open, without an account or a key.
Each registry may limit its calls (HTTP 429).

## Options

`Bootstrap`:

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `cache` | `?Symfony\Contracts\Cache\CacheInterface` | `null` | where the bootstrap files are kept a day; `null`: kept in the object only |
| `timeout` | float | `10` | seconds IANA has to answer |

`Rdap`:

| Argument | Type | Default | |
|---|---|---|---|
| `http` | `HttpClientInterface` | required | the client to call with |
| `bootstrap` | `Bootstrap` | required | |
| `timeout` | float | `10` | seconds a registry's server has to answer |

## Capabilities

| Contract / question | Supported |
|---|---|
| `InternetRegistryInterface::domain()` | yes: any domain name whose top-level domain IANA lists an RDAP server for |
| `InternetRegistryInterface::network()` | yes: IPv4 and IPv6 addresses, AS numbers (`AS3333`, `3333`) |
| companies, VAT, professionals, facilities, civil records | no |

`name()` is `iana`, the `source` of every `Domain` and `Network`.

## Behaviour

**The bootstrap.** `forDomain($domain)` tries the name's suffixes from the longest
(`example.co.uk`, `co.uk`, `uk`) against the `dns` file's entries; `forIp($ip)` keeps the most
specific range (the longest prefix) of the `ipv4` or `ipv6` file holding the address;
`forAutnum($n)` the `asn` file's range holding the number. Of a service's addresses, an HTTPS one
is preferred, and a trailing slash is added. Each file is fetched the first time it is needed,
then kept in the object; with a cache, kept there 86400 seconds under
`omnistate.iana.bootstrap.<dns|ipv4|ipv6|asn>` (IANA asks clients not to fetch them on every
lookup). An unknown suffix, an address in no range: `null`.

**A domain.** The name is trimmed, lower case, without its trailing dot; with `ext-intl`, a name
with characters outside ASCII is converted (`idn_to_ascii`, UTS #46). Something that is not a
domain name (by its shape: labels of letters, digits, hyphens, underscores, 253 characters at
most) is `null` without any request; so is a name whose top-level domain has no RDAP server.
Mapping:

| `Domain` | From the RDAP answer |
|---|---|
| `name` | `ldhName`, lower case, else the name asked |
| `handle` | `handle` |
| `statuses` | `status` |
| `registrar` | the entity with the role `registrar`, looked for among nested entities too |
| `registrant` | the entity with the role `registrant`, `null` when redacted |
| `registeredAt`, `expiresAt`, `changedAt` | the events `registration`, `expiration`, `last changed` (the first of each) |
| `nameservers` | each `nameservers[].ldhName`, lower case |
| `dnssec` | `secureDNS.delegationSigned` |
| `server` | the RDAP server asked |
| `raw` | the whole answer |

**A network.** A value of digits, with or without `AS`, is an autonomous system; anything else is
read as an IP address (one PHP's `inet_pton()` cannot read is `null` without a request).
`handle`, `name`, `type`, `country`, `startAddress`, `endAddress`, `startAutnum`, `endAutnum`, the
`registrant` and `abuse` entities, the `registration` and `last changed` events, the server.

**Contacts.** From an entity's vCard: `fn` as name, `org`, `email`; the country from the address's
`cc` parameter, else its last line when it is two letters; `handle`; the IANA Registrar ID from
`publicIds`; the `about` link as `url`. An entity with nothing but a handle (redacted under the
GDPR) is `null`.

**Caching.** Only the bootstrap files, as above; the facade's cache keeps the answers.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnistate\Iana\Bootstrap;
use Omnistate\Iana\Rdap;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$bootstrap = new Bootstrap($http);
$rdap = new Rdap($http, $bootstrap);

echo $bootstrap->forDomain('example.fr'), "\n", $bootstrap->forIp('2001:67c:2e8::1'), "\n", $bootstrap->forAutnum(15169), "\n";

$domain = $rdap->domain('afnic.fr');
printf("\n%s: registrar %s, registered %s, expires %s, DNSSEC %s, registrant %s\n", $domain->name, $domain->registrar, $domain->registeredAt?->format('Y-m-d'), $domain->expiresAt?->format('Y-m-d') ?? '-', $domain->dnssec ? 'yes' : 'no', $domain->registrant ?? '-');

$network = $rdap->network('2001:67c:2e8::1');
printf("%s %s %s, %s to %s, abuse %s\n", $network->handle, $network->name, $network->country, $network->startAddress, $network->endAddress, $network->abuse?->email ?? '-');

$as = $rdap->network('15169');
printf("%s %s, registrant %s\n", $as->handle, $as->name, $as->registrant ?? '-');
```

```
https://rdap.nic.fr/
https://rdap.db.ripe.net/
https://rdap.arin.net/registry/

afnic.fr: registrar Registry Operations, registered 2001-12-10, expires 2029-07-18, DNSSEC yes, registrant ASS FRANC NOMMAGE INTERNET EN COOP
2001:67c:2e8::/48 RIPE-NCC-NET NL, 2001:67c:2e8:: to 2001:67c:2e8:ffff:ffff:ffff:ffff:ffff, abuse abuse@ripe.net
AS15169 GOOGLE, registrant Google LLC
```

(run on 2026-10-25 in omnistate's Docker harness: IANA's bootstrap, then AFNIC's, the RIPE NCC's
and ARIN's RDAP servers)

Through the facade, with the bootstrap kept a day:

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$cache = new FilesystemAdapter();
$omnistate = new Omnistate\Omnistate(internet: new Rdap($http, new Bootstrap($http, $cache)), cache: $cache);
$omnistate->domain('Example.COM.');   // example.com
```

## Errors

| What happens | The register |
|---|---|
| a bootstrap file: HTTP 429 | `UnavailableException` (`IANA's dns bootstrap: too many calls.`), `retryAfter` from `Retry-After` (at least 1), else 60 |
| a bootstrap file: another HTTP error, no answer, not JSON | `UnavailableException` (`IANA's dns bootstrap did not answer: ...`) |
| a registry's server: HTTP 404 or 410 | `null`: not registered |
| a registry's server: HTTP 429 | `UnavailableException` (`rdap.example answered 429: too many calls.`), `retryAfter` from `Retry-After` (seconds, or an HTTP date; at least 1), else 60 |
| a registry's server: HTTP 401, 403 or 5xx | `UnavailableException` (`rdap.example answered 503.`) |
| a registry's server: HTTP 400 or another 4xx | `OmnistateException` with the server's reason, the RDAP error's `title` and `description` (`rdap.example refused the question (400): Bad Request Unsupported label`) |
| a registry's server: no answer, not JSON | `UnavailableException` (`rdap.example did not answer: ...`) |

The statuses are RFC 7480's ([section 5](https://www.rfc-editor.org/rfc/rfc7480#section-5)), the
error's body RFC 9083's ([section 6](https://www.rfc-editor.org/rfc/rfc9083#section-6)).

## Verified, and not

Tested offline: `Tests/RdapTest.php` (a domain with its registrar, IANA ID, dates, name servers
and unpublished registrant; a domain no one registered and a TLD without RDAP; an IP address with
its range, holder and abuse contact; an autonomous system; the bootstrap preferring HTTPS and the
most specific range; a registry down; 401, 403 and 429 with and without `Retry-After` as
`UnavailableException`; a 400 and a 422 as an `OmnistateException` with the server's reason, a 410
as `null`) on answers recorded in `Tests/Fixtures`, and
`Tests/RdapContractTest.php`, omnistate's conformance suite, with the bootstrap files as
infrastructure answers.

Run live on 2026-10-25 (the example above, and the core manual's domain and network examples).

## Responsibility

An independent, unofficial client, not affiliated with IANA, ICANN or the registries whose RDAP
servers it reaches. Whoever runs it complies with the terms of IANA ([Terms of
Service](https://www.icann.org/privacy/tos)) and of each registry it reaches: see the README's
Responsibility section.
