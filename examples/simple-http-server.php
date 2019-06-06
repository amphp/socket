#!/usr/bin/env php
<?php // basic (and dumb) HTTP server

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP server that just prints a message to each client that connects.
// It doesn't check whether the client sent an HTTP request.

// You might notice that your browser opens several connections instead of just one, even when only making one request.

use Amp\Loop;
use Amp\Socket\ResourceSocket;
use Amp\Socket\Server;
use function Amp\asyncCoroutine;

Loop::run(static function () {
    $clientHandler = asyncCoroutine(static function (ResourceSocket $socket) {
        [$ip, $port] = \explode(':', $socket->getRemoteAddress());

        echo "Accepted connection from {$ip}:{$port}." . PHP_EOL;

        $body = "Hey, your IP is {$ip} and your local port used is {$port}.";
        $bodyLength = \strlen($body);

        yield $socket->end("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: {$bodyLength}\r\n\r\n{$body}");
    });

    $server = Server::listen('127.0.0.1:0');

    echo 'Listening for new connections on ' . $server->getAddress() . ' ...' . PHP_EOL;
    echo 'Open your browser and visit http://' . $server->getAddress() . '/' . PHP_EOL;

    while ($socket = yield $server->accept()) {
        $clientHandler($socket);
    }
});
