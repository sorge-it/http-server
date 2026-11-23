# Alpine, on musl, with PHP and Composer, pcntl for the test of a signal in `stream_select()`,
# sockets for the test of a reset before the accept, and the Go of the reference of GoNetHttpTest.
# A Dockerfile, so Dependabot updates the digests.
FROM golang:1.27.4-alpine@sha256:70bd7ddea8b6cbe5d6943cde279d6b67feb10cc924fb9100d1d8757c1575b280 AS go

FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0

COPY --from=go /usr/local/go /usr/local/go
ENV PATH=/usr/local/go/bin:$PATH

RUN apk add --no-cache linux-headers > /dev/null \
    && docker-php-ext-install pcntl sockets > /dev/null
