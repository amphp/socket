#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple chat server.

use Amp\Pipeline\Queue;
use Amp\Socket;
use function Amp\async;
use function Amp\ByteStream\splitLines;

$server = Socket\listen('127.0.0.1:0');

$socketAddress = $server->getAddress();
assert($socketAddress instanceof Socket\InternetAddress);

echo 'Listening for new connections on ' . $socketAddress . ' ...' . PHP_EOL;
echo 'Open your terminal and run nc ' . $socketAddress->getAddress() . ' ' . $socketAddress->getPort() . PHP_EOL;

/** @var SplObjectStorage<Queue, null> $queues */
$queues = new SplObjectStorage();

$broadcast = function (Queue $ignore, string $message) use ($queues): void {
    foreach ($queues as $queue) {
        if ($queue === $ignore) {
            continue;
        }

        $queue->pushAsync($message);
    }
};

while ($socket = $server->accept()) {
    $queue = new Queue();
    $queues->attach($queue);

    $queue->pushAsync("Welcome to our little chat server!\r\n");

    async(function () use ($socket, $queue, $broadcast) {
        $address = $socket->getRemoteAddress();
        assert($address instanceof Socket\InternetAddress);

        $name = $address->getAddress();

        echo "Accepted connection from {$address}." . PHP_EOL;

        foreach (splitLines($socket) as $line) {
            if ($line === '') {
                continue;
            }

            if ($line[0] === '/') {
                [$command, $input] = explode(' ', $line, 2);

                if ($command === '/name') {
                    $name = $input;
                    $queue->pushAsync("You're known as $input now.\r\n");
                } else {
                    $queue->pushAsync("Unknown command: $command\r\n");
                }
            } else {
                $broadcast($queue, $name . ': ' . $line . "\r\n");
            }
        }
    });

    async(function () use ($socket, $queue) {
        foreach ($queue->pipe() as $bytes) {
            $socket->write($bytes);
        }

        $socket->end();
    });

    $socket->onClose(function () use ($queues, $queue) {
        $queues->detach($queue);
    });
}
