# socket

[![Build Status](https://img.shields.io/travis/amphp/socket/master.svg?style=flat-square)](https://travis-ci.org/amphp/socket)
[![CoverageStatus](https://img.shields.io/coveralls/amphp/socket/master.svg?style=flat-square)](https://coveralls.io/github/amphp/socket?branch=master)
![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)

`amphp/socket` is a socket library for establishing and encrypting non-blocking sockets PHP based on [Amp](https://github.com/amphp/amp).

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/socket
```

## Documentation

Documentation can be found on [amphp.org](https://amphp.org/socket) as well as in the [`./docs`](./docs) directory.

## Examples

You can find more examples in the [`./examples`](./examples) directory.

#### Client Example

```php
<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.
// league/uri-schemes required for this example.

use Amp\ByteStream;
use Amp\Loop;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\EncryptableSocket;
use League\Uri;
use function Amp\Socket\connect;

Loop::run(static function () use ($argv) {
    $stdout = ByteStream\getStdout();

    if (\count($argv) !== 2) {
        yield $stdout->write('Usage: examples/simple-http-client.php <url>' . PHP_EOL);
        exit(1);
    }

    $uri = Uri\Http::createFromString($argv[1]);
    $host = $uri->getHost();
    $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
    $path = $uri->getPath() ?: '/';

    $connectContext = (new ConnectContext)
        ->withTlsContext(new ClientTlsContext($host));

    /** @var EncryptableSocket $socket */
    $socket = yield connect($host . ':' . $port, $connectContext);

    if ($uri->getScheme() === 'https') {
        yield $socket->setupTls();
    }

    yield $socket->write("GET {$path} HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");

    while (null !== $chunk = yield $socket->read()) {
        yield $stdout->write($chunk);
    }

    // If the promise returned from `read()` resolves to `null`, the socket closed and we're done.
    // In this case you can also use `yield Amp\ByteStream\pipe($socket, $stdout)` instead,
    // but we want to demonstrate the `read()` method here.
});
```

#### Server Example

```php
<?php // basic (and dumb) HTTP server

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP server that just prints a message to each client that connects.
// It doesn't check whether the client sent an HTTP request.

// You might notice that your browser opens several connections instead of just one,
// even when only making one request.

use Amp\Loop;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Server;
use function Amp\asyncCoroutine;

Loop::run(static function () {
    $clientHandler = asyncCoroutine(static function (ResourceSocket $socket) {
        $address = $socket->getRemoteAddress();
        $ip = $address->getHost();
        $port = $address->getPort();

        echo "Accepted connection from {$address}." . PHP_EOL;

        $body = "Hey, your IP is {$ip} and your local port used is {$port}.";
        $bodyLength = \strlen($body);

        $req = "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: {$bodyLength}\r\n\r\n{$body}";
        yield $socket->end($req);
    });

    $server = Server::listen('127.0.0.1:0');

    echo 'Listening for new connections on ' . $server->getAddress() . ' ...' . PHP_EOL;
    echo 'Open your browser and visit http://' . $server->getAddress() . '/' . PHP_EOL;

    while ($socket = yield $server->accept()) {
        $clientHandler($socket);
    }
});
```

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
