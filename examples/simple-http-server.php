<?php // basic server

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
