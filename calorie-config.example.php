<?php
// Copy to /volume1/leadville-private/calorie-config.php, OUTSIDE the website.
// Set a newly generated token there; never commit the populated private file.
return [
    'token' => '', // Required: a new random secret, at least 24 characters.
    'data_dir' => '/volume1/leadville-private',
];
