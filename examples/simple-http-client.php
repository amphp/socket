#!/usr/bin/env php
<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.
// league/uri-schemes required for this example.

use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Socket\ClientConnectContext;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\EncryptableSocket;
use League\Uri;
use function Amp\Socket\connect;

Loop::run(static function () use ($argv) {
    $stdout = new ResourceOutputStream(STDOUT);

    if (\count($argv) !== 2) {
        yield $stdout->write('Usage: examples/simple-http-client.php <url>' . PHP_EOL);
        exit(1);
    }

    $uri = Uri\Http::createFromString($argv[1]);
    $host = $uri->getHost();
    $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
    $path = $uri->getPath() ?: '/';

    $connectContext = (new ClientConnectContext)
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
    // In this case you can also use `yield Amp\ByteStream\pipe($socket, $stdout)` instead of the while loop,
    // but we want to demonstrate the `read()` method here.
});
