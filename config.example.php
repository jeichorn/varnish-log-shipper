<?php
return [
    'tmpdir' => '/tmp',
    'maxsize' => 1048576, // 1 meg max file (gz compressed)
    'rotate' => 'Y-m-d:H', // rotate hourly
    'commands' => [
        'varnishncsa' => 'varnishncsa'
    ],
    'destinations' => [
        'main' => [
            'host' => 'myserver.example.com',
            'path' => '/domain/{domain}/{date}/{time}.log.gz',
            'auth' => 'key sent in Authorize header',
        ]
    ],
    'domains' => [
        'test.example.com' => 'main',
        '*example.com' => 'main',
    ],
];
