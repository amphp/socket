# socket

[![Build Status](https://img.shields.io/travis/amphp/socket/master.svg?style=flat-square)](https://travis-ci.org/amphp/socket)
[![CoverageStatus](https://img.shields.io/coveralls/amphp/socket/master.svg?style=flat-square)](https://coveralls.io/github/amphp/socket?branch=master)
![Unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)


`amphp/socket` is a socket lib for establishing and encrypting non-blocking sockets in the [`amp`](https://github.com/amphp/amp)
concurrency framework.

**Required PHP Version**

- PHP 5.5+

**Installation**

```bash
$ composer require amphp/socket: dev-master
```

**Example**

```php
<?php // basic server

require __DIR__ . '/vendor/autoload.php';

use Amp as amp;
use Amp\Socket as socket;

amp\run(function () {
    $socket = socket\listen("tcp://127.0.0.1:1337");
    $server = new socket\Server($socket);
    echo "listening for new connections ...\n";
    while ($client = (yield $server->accept())) {
        amp\resolve(onClient($client));
    }
});

// Generator coroutine is a lightweight "thread" for each client
function onClient(socket\Client $client) {
    $clientId = $client->id();
    echo "+ connected: {$clientId}\n";
    while ($client->alive()) {
        $data = (yield $client->readLine());
        echo "data read from {$clientId}: {$data}\n";
        $bytes = (yield $client->write("echo: {$data}\n"));
        echo  "{$bytes} written to client {$clientId}\n";
    }
    echo "- disconnected {$clientId}\n";
}
```
