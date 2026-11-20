<?php

declare(strict_types=1);

// The screen of the social preview: the name and the promise, then the page from each server.

exec('rm -f app.sqlite* && php php-s.php', $second);
exec('php http-server.php', $same);

// No cursor: the screenshot shows the output only.
echo "\033[?25l\033[H\033[2J\n";
echo "  \033[1;35msorge-it/http-server\033[0m\n\n";
echo "  \033[1mThe page sees your test's transaction.\033[0m\n\n";
echo "  \033[2m# Ada exists only in the test's transaction.\033[0m\n";
echo "  php -S       \033[1;31m".implode(' ', $second)."\033[0m   \033[2m# a second process\033[0m\n";
echo "  HttpServer   \033[1;32m".implode(' ', $same)."\033[0m    \033[2m# the test process\033[0m\n\n";
echo "  In-process HTTP server for Pest and PHPUnit\n";
echo "  browser tests, built for coding agents.\n";
echo "  Tested against FrankenPHP.\n";
