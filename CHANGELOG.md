# Changelog

All notable changes to this package are in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versions follow
[Semantic Versioning](https://semver.org).

## [Unreleased]

### Changed

- Four more messages name the way out: `ClosedWithErrors` names a request cut by `close()` and the
  `onError` that fails the test at the error; a chunk that is not a string names `json_encode()`; a handler that returns `null`
  is told to return a `Response` from every path; a host that is not loopback names where the
  browser runs.

- `SECURITY.md` moved to `.github/` and is no longer in the package. The security link in
  `composer.json` points to the security policy on GitHub.
- Three messages name the fix. A handler that gives another type than `Response` names the full
  class and the section "Works with Symfony, Laravel and PSR-15". `ClosedWithErrors` says to fix
  the handler first. A `$host` with a port or a scheme, such as `127.0.0.1:8080`, throws
  `ListenFailed` at once and names `$port`, instead of an error of the resolver.

### Fixed

- `new Server()` refuses a host that resolves to an address that is not loopback, such as
  `0.0.0.0` or `0`, before it listens. Until now it listened on the address for a moment and then
  refused it. A host that cannot be bound now throws `ListenFailed` with "could not bind". A name
  such as `localhost` now listens only on the first address it resolves to.

## [1.0.0] - 2026-11-20

The first public release. HttpServer is an in-process HTTP/1.1 server for Pest and PHPUnit
browser tests, built for coding agents. It serves the page from inside the test process, so the
page sees the test's `RefreshDatabase` transaction, `actingAs()` user and fakes. It has no event
loop: the test calls `tick()` while it waits for the browser. It listens on loopback only. Within
its scope, the browser gets the bytes that FrankenPHP 1.13.6 would send: its test suites compare each
response with the FrankenPHP binary, in the form that Chrome receives, and the parser with Go
`net/http`. The README states the scope and each deliberate difference. The 0.x releases were
private.

[Unreleased]: https://github.com/sorge-it/http-server/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sorge-it/http-server/releases/tag/v1.0.0
