<?php

declare(strict_types=1);

// The page of the app: the users it finds in the database.
function page(PDO $db): string
{
    $names = $db->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);

    return '<h1>Users: '.($names === [] ? 'none' : implode(', ', $names))."</h1>\n";
}
