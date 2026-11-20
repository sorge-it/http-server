<?php

declare(strict_types=1);

// The app as `php -S` runs it: in a process of its own, with a connection of its own.
require __DIR__.'/app.php';

echo page(new PDO('sqlite:'.__DIR__.'/app.sqlite'));
