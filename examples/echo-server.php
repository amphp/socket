#!/usr/bin/env php
<?php declare(strict_types=1);
// basic TCP echo server

require __DIR__ . '/../vendor/autoload.php';

use Amp\Socket;
use function Amp\async;
use function Amp\ByteStream\splitLines;

$server = Socket\listen('127.0.0.1:0');

$address = $server->getAddress();
assert($address instanceof Socket\InternetAddress);

echo 'Listening for new connections on ' . $address . ' ...' . PHP_EOL;
echo 'Connect from a terminal, e.g. ';
echo '"nc ' . $address->getAddress() . ' ' . $address->getPort() . '"' . PHP_EOL;

while ($socket = $server->accept()) {
    async(function () use ($socket) {
        echo "Accepted connection from {$socket->getRemoteAddress()}." . PHP_EOL;

        foreach (splitLines($socket) as $line) {
            $socket->write($line . PHP_EOL);
        }

        $socket->end();

        echo "Closed connection to {$socket->getRemoteAddress()}." . PHP_EOL;
    });
}
