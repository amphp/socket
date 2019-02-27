<?php // basic UDP socket

require __DIR__ . '/../vendor/autoload.php';

// Connect with 'nc -u 127.0.0.1 1337'

use Amp\Loop;
use Amp\Socket;
use Amp\Socket\Packet;

Loop::run(function () use ($argv) {
    $datagram = Socket\datagram('127.0.0.1:1337');

    echo "Datagram active on {$datagram->getLocalAddress()}\n";

    while ($packet = yield $datagram->receive()) {
        \assert($packet instanceof Packet);
        $message = \sprintf("Received '%s' from %s\n", \trim($packet->getData()), $packet->getAddress());
        $datagram->send($packet->withData($message));
    }
});
