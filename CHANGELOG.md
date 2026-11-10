# Changelog

## Unreleased (1.x)

### Added
- `policy.json`, the register's usage policy in omnisense/contracts' schema (named in
  `composer.json`, `extra.omnisense.policy`), given by `usagePolicy()` (`DeclaresUsagePolicyInterface`):
  limits unknown (`rateLimits: null`); the rights unknown until the terms are read again. Requires
  `omnisense/contracts`.
- IANA's bootstrap answering 429 is thrown with `$status` 429: through the facade, the next calls
  wait for its `retryAfter`. A registry's RDAP server answering 429 holds nothing: it is not IANA.
- PHPStan at level max, its baseline what the package had on 2026-11-10.

### Fixed
- An RDAP server's HTTP 401 or 403 is an `UnavailableException`, not `null` ("not registered"),
  as the family's rule has it; its 429 without `Retry-After` carries `retryAfter` 60, and a
  `Retry-After` written as an HTTP date is read as the seconds until it.
- An RDAP server's 400 or another 4xx (but 404 and 410, not registered) is an
  `OmnistateException` with the server's reason (the RDAP error's `title` and `description`),
  not `null`.
