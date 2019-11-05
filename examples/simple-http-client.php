#!/usr/bin/env php
<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.

use Amp\Loop;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\EncryptableSocket;
use League\Uri;
use function Amp\Socket\connect;

Loop::run(static function () use ($argv) {
    $stdout = Amp\ByteStream\getStdout();

    if (\count($argv) !== 2) {
        yield $stdout->write('Usage: examples/simple-http-client.php <url>' . PHP_EOL);
        exit(1);
    }

    $parts = Uri\parse($argv[1]);

    $host = $parts['host'];
    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    $path = $parts['path'] ?: '/';

    $connectContext = (new ConnectContext)
        ->withTlsContext(new ClientTlsContext($host));

    /** @var EncryptableSocket $socket */
    $socket = yield connect($host . ':' . $port, $connectContext);

    if ($parts['scheme'] === 'https') {
        yield $socket->setupTls();
    }

    yield $socket->write("GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");

    while (null !== $chunk = yield $socket->read()) {
        yield $stdout->write($chunk);
    }

    // If the promise returned from `read()` resolves to `null`, the socket closed and we're done.
    // In this case you can also use `yield Amp\ByteStream\pipe($socket, $stdout)` instead of the while loop,
    // but we want to demonstrate the `read()` method here.
});
