# Alpine, on musl, with PHP and Composer, pcntl for the test of a signal in `stream_select()`,
# sockets for the test of a reset before the accept, and the Go of the reference of GoNetHttpTest.
# A Dockerfile, so Dependabot updates the digests.
FROM golang:1.27.4-alpine@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 AS go

FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc

COPY --from=go /usr/local/go /usr/local/go
ENV PATH=/usr/local/go/bin:$PATH

RUN apk add --no-cache linux-headers > /dev/null \
    && docker-php-ext-install pcntl sockets > /dev/null
