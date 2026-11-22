# Fixtures

Each directory keeps the license of its origin.

| File or directory | Origin | License |
|---|---|---|
| `go-net-http/go/` | [golang/go](https://github.com/golang/go) at tag `go1.27.4` (commit `7b2881024c95a11ddb86663a616340aa3a88b67f`): the seven test files of `go-net-http/go.lock`, `VERSION` and `LICENSE`, byte for byte | BSD-3-Clause, see `go-net-http/go/LICENSE` |
| `go-net-http/cases.json` | The requests that `tests/GoNetHttp/extract` takes from the test files in `go-net-http/go/`, as file, line and test name, then probes of the rules in the other Go files of `go-net-http/go.lock`, as `probe` | BSD-3-Clause, see `go-net-http/go/LICENSE` |
| `caddy/cases.json`, `caddy/*.lock` | Probes of the rules in the files of Caddy that `caddy.lock` names with their blobs at tag `v2.11.9`; `caddy/frankenphp.lock` names the files of FrankenPHP `v1.14.0` that the layer of Caddy follows, with their blobs; no code of either | MIT, as this package |
| `chrome/*.req` | The bytes that Chrome for Testing 147, headless on macOS, sent to a page of a test, recorded by a proxy on 2026-11-12, one file for each of the 6 connections that carried bytes; the first carried none, so the files start at `conn-002`. They hold a navigation, CSS, JS, two images, two forms, `fetch`, XHR and a beacon with bodies of up to 1 MB, the methods `PUT`, `patch`, `DELETE`, `PROPFIND`, `HEAD` and `OPTIONS`, custom fields, one with bytes over 0x7F and one with the list value `1, 2`, two cookies, one with a value of 200 bytes, `Cache-Control: max-age=0`, `Range`, a CORS preflight, a WebSocket, an `EventSource`, a long path, and a path and a query with escapes | MIT, as this package |
| `frankenphp/cases.json`, `frankenphp/*.lock` | Cases of the rules in the files of FrankenPHP `v1.14.0` and PHP `php-8.6.0` that `frankenphp.lock` and `php-src.lock` name with their blobs, and the versions of the binaries in `release.lock`; no code of either | MIT, as this package |

`frankenphp/cases.json` holds for each case `name`, and as needed `ini` (the values that replace
those of `Cases::INI`), `method`, `protocol`, `status`, `headers` (a name and a list of values), one
of `body`, `chunks` and `file`, `set` (the ini the handler sets), `throws` (`true` or the message of
the throw, after the chunks), or `echo` for a body that shows the method, the target, the case and
the ini that the handler read. A body is a string or `{"repeat": "a", "times": n}`. The suite
`FrankenPhp` sends each case to FrankenPHP and to the server.

`go-net-http/cases.json` holds only requests and the limits of a case, no answer: `GoNetHttpTest`
sends each case to a server of the Go that `go-net-http/go/VERSION` names for the parser, and to
FrankenPHP for the answers.

A probe has these fields:

| Field | Value |
|---|---|
| `group` | the data set of the suite that runs it, such as `chunked` |
| `name` | what it tests |
| `source` | `probe` |
| `rule` | the file and function of Go whose rule it tests |
| `request` | the bytes the client sends, then its end |
| `maxHeaderBytes`, `maxHeaderValueCount` | the limits, if not those of Go; `maxHeaderValueCount` sets the count of the parser and of Go in the parser test, and the server always takes 500 field values |
| `maxBody` | `maxBody` of the server, see [Layers](../../README.md#layers-who-decides-what) |

After a change of a probe, `composer go:extract` writes `cases.json` again, with the Go of
`composer go:install`: first the cases of the test files, then the probes in their order. It stops
at a probe without one of the first five fields, or with a field that no case has.

A string of `cases.json` holds one byte as each code point from U+0000 to U+00FF, because a JSON
string holds code points, not bytes. A list holds such strings and `{"repeat": "x", "times": n}` for
a byte repeated.

The test handler is `goHandler()` of `tests/Pest.php`, which FrankenPHP runs as
`tests/FrankenPhp/go/index.php`. For a target other than `/respond?`, it echoes the method and the
target on a first line, then the body. A target of `/read?` echoes in place of the body what it
reads as its query names: `none`, `one=n`, `each=n` or `again=n`. A target of `/respond?` answers as
its pairs name, such as `status=n`, `h=name=value`, `body=`, `chunk=`, `file=` and `panic`. A value
that is not a number counts as 0, and a status outside 100 to 999 leaves 200. Only the pair `panic`
throws, after the chunks. A target of `/headers` echoes the fields as `$_SERVER` of FrankenPHP has
them: one `name: values` on a line for each name, the values joined by a comma, the names in lower
case and sorted, without `Host` and `Proxy`. The parser test of Go has the handler `show` of
`tests/GoNetHttp/reference/main.go`, which gives what Go read.

FrankenPHP gets no half-close of the client after the bytes, because a half-close stops its script.
`FrankenPhpReference::answers()` names how its connection closes instead. So no live suite compares
what the server does at the end of the client; the suites `Connection` and `Limits` test it on their
own.

`caddy/cases.json` has probes in the same form, with the file and function of Caddy as `rule`, and
as limits only `maxBody`: the suite `FrankenPhp` sends them with the defaults of FrankenPHP to
FrankenPHP and to the server. There is no extract, so a probe is written there by hand.
