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

### Changed
- PHPStan at level max with an empty baseline, types only.

### Fixed
- An answer of another shape than its RFC's is an `UnavailableException` (the family's rule on a
  body that is not what it should be), where PHP threw a `TypeError`: an RDAP object whose `status`,
  `nameservers` or an entity's `roles` is not a list, a vCard property named by a list; a bootstrap
  file whose `services` is not a list; a service whose entries, ranges or URLs are of another shape,
  for the lookups that reach it (as before, the well-formed services keep answering).
- An RDAP server's HTTP 401 or 403 is an `UnavailableException`, not `null` ("not registered"),
  as the family's rule has it; its 429 without `Retry-After` carries `retryAfter` 60, and a
  `Retry-After` written as an HTTP date is read as the seconds until it.
- An RDAP server's 400 or another 4xx (but 404 and 410, not registered) is an
  `OmnistateException` with the server's reason (the RDAP error's `title` and `description`),
  not `null`.
