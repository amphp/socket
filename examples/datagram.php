<?php // basic UDP socket

require __DIR__ . '/../vendor/autoload.php';

// Connect with 'nc -u 127.0.0.1 1337'

use Amp\Loop;
use Amp\Socket;

Loop::run(function () use ($argv) {
    $datagram = Socket\datagram('127.0.0.1:1337');

    echo "Datagram active on {$datagram->getLocalAddress()}\n";

    while (list($data, $address) = yield $datagram->receive()) {
        $message = \sprintf("Received '%s' from %s\n", \trim($data), $address);
        $datagram->send($message, $address);
    }
});
