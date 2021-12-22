#!/usr/bin/env php
<?php // basic TCP echo server

require __DIR__ . '/../vendor/autoload.php';

use Amp\Socket;
use function Amp\async;
use function Amp\ByteStream\splitLines;

$server = Socket\listen('127.0.0.1:0');

echo 'Listening for new connections on ' . $server->getAddress() . ' ...' . PHP_EOL;
echo 'Connect from a terminal, e.g. ';
echo '"nc ' . $server->getAddress()->getHost() . ' ' . $server->getAddress()->getPort() . '"' . PHP_EOL;

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
