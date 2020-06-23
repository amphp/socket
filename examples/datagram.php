#!/usr/bin/env php
<?php // basic UDP socket

require __DIR__ . '/../vendor/autoload.php';

// Connect with 'nc -u 127.0.0.1 1337'

use Amp\Loop;
use Amp\Socket\DatagramSocket;

Loop::run(static function () {
    $datagram = DatagramSocket::bind('127.0.0.1:1337');

    echo "Datagram active on {$datagram->getAddress()}" . PHP_EOL;

    /** @psalm-suppress PossiblyNullArrayAccess */
    while ([$address, $data] = yield $datagram->receive()) {
        $message = \sprintf("Received '%s' from %s\n", \trim($data), (string) $address);
        $datagram->send($address, $message);
    }
});
