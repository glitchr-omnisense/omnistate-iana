# Changelog

## Unreleased (1.x)

### Fixed
- An RDAP server's HTTP 401 or 403 is an `UnavailableException`, not `null` ("not registered"),
  as the family's rule has it; its 429 without `Retry-After` carries `retryAfter` 60, and a
  `Retry-After` written as an HTTP date is read as the seconds until it.
- An RDAP server's 400 or another 4xx (but 404 and 410, not registered) is an
  `OmnistateException` with the server's reason (the RDAP error's `title` and `description`),
  not `null`.
