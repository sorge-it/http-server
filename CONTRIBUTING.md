# Contributing

Pull requests are welcome. The issue tracker is closed: report a bug as a pull request with a
failing test; a fix is welcome but not required. Vulnerabilities are the exception: report them
privately; see [SECURITY.md](SECURITY.md). Everyone who takes part follows the
[Code of Conduct](CODE_OF_CONDUCT.md).

## Setup

You need Composer, PHP 8.4 or a later 8.x with the `sockets`, `pdo_sqlite` and `curl` extensions,
and pcov or Xdebug for line coverage. The two browser examples also need Chrome; see the `Example`
suite below.

```sh
composer install
composer go:install          # the Go version of tests/GoNetHttp/go.mod into .go/, once
composer frankenphp:install  # the FrankenPHP binary of release.lock into .frankenphp/, once
composer test                # Pint and Rector dry runs, PHPStan, 100% type coverage, every suite
```

- The tests need the Go version from `tests/GoNetHttp/go.mod`, which is the version in
  `tests/Fixtures/go-net-http/go/VERSION`, and fail without it. `composer go:install` downloads it
  into `.go/` for macOS and Linux on arm64 and x64, verifies its SHA-256, and installs nothing
  system-wide. The tests run it with its caches and settings kept in `.go/` only.
- Each suite that compares against FrankenPHP needs the binary named in
  `tests/Fixtures/frankenphp/release.lock`. `composer frankenphp:install` downloads it into
  `.frankenphp/`, verifies the SHA-256 against the GitHub API, and checks the versions the binary
  reports; the suite runs `frankenphp:install` itself before it starts FrankenPHP. FrankenPHP
  publishes no binary for macOS on x64, so those suites cannot run there; use a Docker job instead
  (see [Before you open a pull request](#before-you-open-a-pull-request)).
- Where `gh` has a login or a token, `frankenphp:install` also uses `gh attestation verify` to
  check that FrankenPHP's workflow built the binary. A binary that fails the check is deleted.
  The check comes before the binary first runs. Where `gh` has neither, as in a Docker image, the
  binary runs unchecked; so in CI, a Docker job installs the binary on the runner, which has `gh`.

## Reporting a bug

Open a pull request that contains only a failing test (see [Writing a test](#writing-a-test)), and
say what you expected. A maintainer adds the fix.

## Fixing a bug

Open a pull request with:

- a test that fails without the change; see [Writing a test](#writing-a-test);
- the change;
- a line under `[Unreleased]` in `CHANGELOG.md` when the package's behavior changes;
- a description of what went wrong and why the change fixes it, and the output of `php -v`.

If you do not know how to fix it, describe the problem to a coding agent and let it draft the pull
request. Review the change and run the tests before you open it.

### Writing a test

- Where Go `net/http` decides the result, the test is a probe in
  `tests/Fixtures/go-net-http/cases.json`, with the fields `group`, `name`, `source: "probe"`,
  `rule` (Go's file and function) and `request`, and the limits `maxHeaderBytes` or
  `maxHeaderValueCount` where needed. Then run `composer go:extract`. The `GoNetHttp` suite sends
  the probe to Go for the parser and to FrankenPHP for the response, and compares the bytes.
- Where Caddy decides, the probe goes into `tests/Fixtures/caddy/cases.json` in the same form, with
  Caddy's file and function as `rule`, and the `FrankenPhp` suite compares it against FrankenPHP.
- No test spells out a FrankenPHP response as a literal. Where the handler must be PHP, compare
  `answers()` with `modelAnswers()`.
- Other tests go into the suite that covers their part.
  [tests/Fixtures/README.md](tests/Fixtures/README.md) describes the fixtures.

## Proposing a feature

Read [Scope](README.md#scope) and [HTTP behavior](README.md#http-behavior) first. A feature
outside the scope, such as TLS or a public address, will not be merged.

- Each out-of-scope row of Scope has its guard in [`tests/Scope/out.php`](tests/Scope/out.php): a
  pattern, a request, or the reason the row has none. A new out-of-scope row comes with its guard.
- A change that removes out-of-scope behavior adds a pattern or a request that detects the behavior
  if it comes back. Where neither can, the row's reason says why and names the test that detects
  it.
- A new limit comes with a `Limits` property, a status, a test for each side of the limit, and a
  line with its default in [Limits and timeouts](README.md#limits-and-timeouts).

## Before you open a pull request

```sh
composer test:coverage      # 100% line coverage, needs pcov or Xdebug
composer lint               # Rector, then Pint
composer validate --strict  # composer.json against its schema
composer audit --locked     # advisories for the locked packages, needs the network
```

PHPStan, Rector and the type coverage read `src` and `tests`; Pint reads the whole tree.

The code must run on PHP 8.4 and 8.5, on macOS, and on Linux with glibc and with musl. The Docker
jobs in CI run the suites as a non-root user, so that the file-mode tests run. To run
such a job locally, use a clean clone, because the command copies the whole directory. Use `debian`
or `alpine` as the image name:

```sh
docker build --quiet --tag tests .github/docker/debian
docker run --rm --volume "$PWD:/src:ro" --entrypoint sh tests -euc '
  cp -a /src /work
  chown -R www-data /work
  su www-data -s /bin/sh -c "cd /work && export COMPOSER_HOME=/tmp/composer && composer install --no-interaction --no-progress && composer test"
'
```

The `Example` suite runs each test and script of the README and checks that the README shows its
code. The two browser tests need a Chrome that `CHROME` names, and are skipped otherwise.

When the API or the text of a demo script changes, re-record the README's demo and the social
preview with `make -C demo all` (needs Docker).

A workflow uses zizmor to audit the workflows and the Dependabot configuration in `.github`, and
actionlint to check the workflows. Run both when you change anything there:

```sh
docker build --quiet --tag zizmor .github/docker/zizmor
docker run --rm --volume "$PWD:/src:ro" --workdir /src zizmor --offline .github
docker build --quiet --tag actionlint .github/docker/actionlint
docker run --rm --volume "$PWD:/src:ro" --workdir /src actionlint -no-color
```

A workflow runs the mutation tests every week, split into four parts of `src/`. Run them with pcov
when you change `src/`. They run one mutant after another, and all of `src/` takes several hours,
so run only the files you changed, with `--path`. Do not use `--parallel`: with it, Pest Mutate
counts each mutant as killed. A surviving mutant marks a change to `src/` that the tests would
miss:

```sh
composer test:mutation  # fails when the tests kill fewer than 99% of the mutants
composer test:mutation -- --path=src/Internal/Answer.php  # only the mutants of that file
```

Two kinds of results can be misleading:

- A mutant on a line that almost every test runs can show up as untested. Pest Mutate puts the
  names of those tests into a single regex for `--filter`. With about 300 names, PCRE cannot compile
  it, so no test runs. Apply such a mutant's change by hand in a second clone, and run the tests
  there.
- A mutant whose run takes too long counts as killed. Run such a mutant alone with `--id` before
  you count it as killed.

## Review

A maintainer reviews a pull request once it is ready for review and the `tests` workflow passes. A
bug report may fail only in the test that it adds. Inactive drafts may be closed. Pull requests
opened in bulk are closed without review.

## Updating the pins (maintainers)

FrankenPHP rebuilds the binaries of its newest release every night: new bytes, and sometimes a new
PHP or Caddy. That is why `release.lock` names the versions of FrankenPHP, PHP and Caddy, not a
SHA-256.

The weekly workflows compare the lock files in `tests/Fixtures` against the newest Caddy and
FrankenPHP, and against the Go version of the binary that `release.lock` names. Then
`go-net-http.yml` runs the `GoNetHttp` suite with that Go version, and `frankenphp.yml` runs each
suite with the newest FrankenPHP.

```sh
composer go:extract        # cases.json again, after a change of a probe
composer check:go          # go.lock against the Go version of the FrankenPHP in release.lock
composer check:caddy       # caddy.lock and Caddy's frankenphp.lock against the newest Caddy and FrankenPHP
composer check:frankenphp  # release.lock, frankenphp.lock and php-src.lock against the newest FrankenPHP
composer frankenphp:lock   # new FrankenPHP versions into release.lock, needs gh with a login or a token
```

- The `check:` commands need `composer install`, git and the network, but no token. `check:go` first
  runs `frankenphp:install`, as the suites do. `check:go`, `check:frankenphp` and `frankenphp:lock`
  also need PHP's curl extension.
- `check:frankenphp` downloads the newest FrankenPHP binary into `.frankenphp/newest` and runs it.
  When it finds new versions, run `composer frankenphp:lock`. It writes the versions of the newest
  binary into `release.lock` after `gh attestation verify` shows that FrankenPHP's workflow built
  each binary. If the versions are unchanged, it writes nothing.
- Then update each lock file that the `pins` group test in `tests/FrankenPhp/FrankenPhpTest.php`
  reads, each version that it names, and the tags in `tests/Fixtures/README.md`. The README names
  no pinned version.
