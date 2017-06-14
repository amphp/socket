# socket

[![Build Status](https://img.shields.io/travis/amphp/socket/master.svg?style=flat-square)](https://travis-ci.org/amphp/socket)
[![CoverageStatus](https://img.shields.io/coveralls/amphp/socket/master.svg?style=flat-square)](https://coveralls.io/github/amphp/socket?branch=master)
![Unstable](https://img.shields.io/badge/api-unstable-orange.svg?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`amphp/socket` is a socket library for establishing and encrypting non-blocking sockets for [Amp](https://github.com/amphp/amp).

**Required PHP Version**

- PHP 7.0+

**Installation**

```bash
composer require amphp/socket
```

**Example**

```php
<?php // basic server, see examples/simple-http-server.php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Loop;
use Amp\Socket\ServerSocket;

Loop::run(function () {
    $server = Amp\Socket\listen("tcp://127.0.0.1:0", function (ServerSocket $socket) {
        list($ip, $port) = explode(":", $socket->getRemoteAddress());

        $body = "Hey, your IP is {$ip} and your local port used is {$port}.";
        $bodyLength = \strlen($body);

        yield $socket->end("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: {$bodyLength}\r\n\r\n{$body}");
    });

    echo "Listening for new connections on " . $server->getAddress() . " ...\n";
});
```
