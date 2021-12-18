#!/usr/bin/env php
<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.
// league/uri required for this example.

use Amp\ByteStream;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use League\Uri\Http;
use function Amp\Socket\connect;
use function Amp\Socket\connectTls;

$stdout = ByteStream\getStdout();

if (\count($argv) !== 2) {
    $stdout->write('Usage: examples/simple-http-client.php <url>' . PHP_EOL);
    exit(1);
}

$uri = Http::createFromString($argv[1]);
$host = $uri->getHost();
$port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
$path = $uri->getPath() ?: '/';

$connectContext = (new ConnectContext)
        ->withTlsContext(new ClientTlsContext($host));

$socket = $uri->getScheme() === 'http'
        ? connect($host . ':' . $port, $connectContext)
        : connectTls($host . ':' . $port, $connectContext);

$socket->write("GET {$path} HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");

ByteStream\pipe($socket, $stdout);
