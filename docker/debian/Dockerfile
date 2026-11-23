# Debian, on glibc, with the lowest PHP the package requires, pcntl for the test of a signal in
# `stream_select()`, sockets for the test of a reset before the accept, and the Go of the reference
# of GoNetHttpTest. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.10.3@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer

FROM golang:1.27.4@sha256:205c7636d74e0ffbe3d007162df7d74f859f5a8c8322dfb9f907977686c126cf AS go

FROM php:8.4.23-cli@sha256:3a70cc45c4b03b691f04ea3c032030613a5ff6ee61053127aadb8a93b39cc7c3

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=go /usr/local/go /usr/local/go
ENV PATH=/usr/local/go/bin:$PATH

RUN apt-get -qq update \
    && apt-get -qq install -y --no-install-recommends unzip > /dev/null \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl sockets > /dev/null
