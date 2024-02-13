#!/usr/bin/env php
<?php declare(strict_types=1);
// basic UDP socket

require __DIR__ . '/../vendor/autoload.php';

// Connect with 'nc -u 127.0.0.1 1337'

use Amp\Socket;

$datagram = Socket\bindUdpSocket('127.0.0.1:1337');

echo "Datagram active on {$datagram->getAddress()}" . PHP_EOL;

/** @psalm-suppress PossiblyNullArrayAccess */
while ([$address, $data] = $datagram->receive()) {
    assert($address instanceof Socket\SocketAddress);
    assert(is_string($data));

    $message = sprintf("Received '%s' from %s\n", trim($data), (string) $address);
    $datagram->send($address, $message);
}
