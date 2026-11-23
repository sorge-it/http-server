# Debian, on glibc, with the lowest PHP the package requires, pcntl for the test of a signal in
# `stream_select()`, sockets for the test of a reset before the accept, and the Go of the reference
# of GoNetHttpTest. A Dockerfile, so Dependabot updates the digests.
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS composer

FROM golang:1.27.4@sha256:205c7636d74e0ffbe3d007162df7d74f859f5a8c8322dfb9f907977686c126cf AS go

FROM php:8.4.27-cli@sha256:d63e8b80cd4c6cfd01cfa7c910522e299cdc7ca1a2b6d6563db48df3c17e6c55

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=go /usr/local/go /usr/local/go
ENV PATH=/usr/local/go/bin:$PATH

RUN apt-get -qq update \
    && apt-get -qq install -y --no-install-recommends unzip > /dev/null \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl sockets > /dev/null
