# Changelog

All notable changes to this package are in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow
[Semantic Versioning](https://semver.org).

## [Unreleased]

## [1.1.1] - 2026-11-22

### Changed

- The reference is FrankenPHP 1.14.0 with PHP 8.6.0. Its responses are the same as those of
  1.13.6 with PHP 8.5.13; the suites compare them byte for byte.

## [1.1.0] - 2026-11-21

### Changed

- For a name such as `localhost`, the server listens on the address that its loopback check
  resolved the name to. When the port is taken there, `new Server()` throws `ListenFailed` instead
  of trying the next address.
- For a `$host` with a port or a scheme, such as `127.0.0.1:8080`, `new Server()` throws
  `ListenFailed` at once and names `$port`, instead of passing on an error of the resolver. For a
  host that cannot be bound, the message of `ListenFailed` contains "could not bind".
- Error messages name the way out:
  - a handler that returns `null`: return a `Response` from every path;
  - a handler that returns another value: the fully qualified `Response` class and the README
    section "Works with Symfony, Laravel and PSR-15";
  - `ClosedWithErrors`: fix the handler or let each request finish before `close()`, and the
    `onError` that fails the test at the error;
  - a chunk of a streamed body that is not a string: `json_encode()` for JSON;
  - a host that is not loopback: run the browser on the same host or in the same container.
- `SECURITY.md` moved to `.github/` and is no longer in the package. The security link in
  `composer.json` points to the security policy on GitHub.

### Fixed

- `new Server()` refuses a host that resolves to an address that is not loopback, such as
  `0.0.0.0` or `0`, before it listens. Previously, it listened on the address for a moment and
  then refused it.

## [1.0.0] - 2026-11-20

The first public release. HttpServer is an in-process HTTP/1.1 server for Pest and PHPUnit
browser tests, built for coding agents. It serves the page from inside the test process, so the
page sees the test's `RefreshDatabase` transaction, `actingAs()` user and fakes. It has no event
loop: the test calls `tick()` while it waits for the browser. It listens on loopback only. Within
its scope, the browser gets the bytes that FrankenPHP 1.13.6 would send: its test suites compare each
response with the FrankenPHP binary, in the form that Chrome receives, and the parser with Go
`net/http`. The README states the scope and each deliberate difference. The 0.x releases were
private.

[Unreleased]: https://github.com/sorge-it/http-server/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/sorge-it/http-server/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/sorge-it/http-server/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/sorge-it/http-server/releases/tag/v1.0.0
