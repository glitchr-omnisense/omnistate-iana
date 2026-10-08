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

## License

MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
