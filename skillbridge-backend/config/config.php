<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'], // fine for tomorrow; lock down later
    'allowed_headers' => ['*'],
    'supports_credentials' => false,
];