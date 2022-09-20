#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple chat server.
$server = Amp\Socket\listen('127.0.0.1:0');

$socketAddress = $server->getAddress();
assert($socketAddress instanceof Amp\Socket\InternetAddress);

echo 'Run "nc ' . $socketAddress->getAddress() . ' ' . $socketAddress->getPort() . '" to join.' . PHP_EOL;
echo 'You\'ll only receive messages if you join multiple clients.' . PHP_EOL;

/** @var SplObjectStorage<Amp\Pipeline\Queue, null> $queues */
$queues = new SplObjectStorage();

$broadcast = function (Amp\Pipeline\Queue $ignore, string $message) use ($queues): void {
    foreach ($queues as $queue) {
        if ($queue === $ignore) {
            continue;
        }

        $queue->pushAsync($message);
    }
};

while ($socket = $server->accept()) {
    $queue = new Amp\Pipeline\Queue();
    $queues->attach($queue);

    $queue->pushAsync("Welcome to our little chat server!\r\n");

    Amp\async(function () use ($socket, $queue, $broadcast) {
        $address = $socket->getRemoteAddress();
        assert($address instanceof Amp\Socket\InternetAddress);

        $name = $address->getAddress();
        foreach (Amp\ByteStream\splitLines($socket) as $line) {
            if ($line !== '' && $line[0] === '/') {
                [$command, $input] = explode(' ', $line, 2) + [null, null];

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

    Amp\async(function () use ($socket, $queue) {
        foreach ($queue->pipe() as $bytes) {
            $socket->write($bytes);
        }

        $socket->end();
    });

    $socket->onClose(fn () => $queues->detach($queue));
}
