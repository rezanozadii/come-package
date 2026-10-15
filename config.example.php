<?php
// Copy to config.php. Keep credentials on the server only.
return [
    'mysql' => [
        'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'comeback', 'username' => 'comeback', 'password' => 'CHANGE_ME',
    ],
    // Random secret required to create the very first admin on a remote host.
    'setup_key' => 'REPLACE_WITH_A_LONG_RANDOM_SECRET',
];
