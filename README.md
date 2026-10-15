# omnistate/iana

Domains, IP ranges and autonomous systems through [IANA's RDAP bootstrap](https://data.iana.org/rdap/)
(RFC 9224) and the registry it names (RFC 9082/9083) - WHOIS's successor, in JSON, free, no key.
For [glitchr/omnistate](../core).

```php
$rdap = new Rdap($httpClient, new Bootstrap($httpClient, $cache));
$rdap->domain('glitchr.dev');   // registrar (IANA ID), registered/expires, statuses, name servers, DNSSEC
$rdap->network('193.0.6.139');  // the range, its holder, country, abuse contact
$rdap->network('AS15169');      // an autonomous system
```

No framework needed: the package requires `glitchr/omnistate` and `symfony/http-client`.
`$httpClient` is the HTTP client to call with - the application's, `HttpClient::create()` in
plain PHP, a `MockHttpClient` in a test.

The bootstrap files are read once and kept a day with a cache, as IANA asks. A registrant is null where
the registry no longer publishes it (most, since the GDPR).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by IANA, ICANN or the registries whose RDAP servers it reaches. It reaches their services
on behalf of whoever runs it, and it does not redistribute their data beyond the short excerpts
recorded as test fixtures. Its MIT licence covers this package's code only and grants no right to
the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms of IANA ([Terms
of Service](https://www.icann.org/privacy/tos)) and of each registry it reaches, for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

## License

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
