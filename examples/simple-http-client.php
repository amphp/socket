#!/usr/bin/env php
<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.

use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use League\Uri;
use function Amp\Socket\connect;

$stdout = Amp\ByteStream\getStdout();

if (\count($argv) !== 2) {
    $stdout->write('Usage: examples/simple-http-client.php <url>' . PHP_EOL);
    exit(1);
}

$parts = Uri\parse($argv[1]);

$host = $parts['host'];
$port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
$path = $parts['path'] ?: '/';

$connectContext = (new ConnectContext)
    ->withTlsContext(new ClientTlsContext($host));

$socket = connect($host . ':' . $port, $connectContext);

if ($parts['scheme'] === 'https') {
    $socket->setupTls();
}

$socket->write("GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");

while (null !== $chunk = $socket->read()) {
    $stdout->write($chunk);
}

// If `read()` returns `null`, the socket closed and we're done.
// In this case you can also use `await(Amp\ByteStream\pipe($socket, $stdout))` instead of the while loop,
// but we want to demonstrate the `read()` method here.
