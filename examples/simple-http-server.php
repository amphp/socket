#!/usr/bin/env php
<?php // basic (and dumb) HTTP server

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP server that just prints a message to each client that connects.
// It doesn't check whether the client sent an HTTP request.

// You might notice that your browser opens several connections instead of just one,
// even when only making one request.

use Amp\Socket;
use function Amp\async;

$server = Socket\listen('127.0.0.1:0');

echo 'Listening for new connections on ' . $server->getAddress() . ' ...' . PHP_EOL;
echo 'Open your browser and visit http://' . $server->getAddress() . '/' . PHP_EOL;

while ($socket = $server->accept()) {
    async(function () use ($socket) {
        $address = $socket->getRemoteAddress();
        $ip = $address->getHost();
        $port = $address->getPort();

        echo "Accepted connection from {$address}." . PHP_EOL;

        $body = "Hey, your IP is {$ip} and your local port used is {$port}.";
        $bodyLength = \strlen($body);

        $socket->write("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: {$bodyLength}\r\n\r\n{$body}");
        $socket->end();
    });
}
