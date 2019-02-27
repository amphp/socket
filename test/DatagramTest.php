<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;
use function Amp\asyncCall;

class DatagramTest extends TestCase
{
    public function testReceive()
    {
        Loop::run(function () {
            $datagram = Socket\datagram('127.0.0.1:0');
            Loop::delay(100, [$datagram, 'close']);

            $this->assertInternalType('resource', $datagram->getResource());

            $socket = yield Socket\connect('udp://' . $datagram->getLocalAddress());
            $address = $socket->getLocalAddress();

            yield $socket->write('Hello!');

            asyncCall(function () use ($datagram, $address) {
                while ($packet = yield $datagram->receive()) {
                    $this->assertInstanceOf(Socket\Packet::class, $packet);
                    $this->assertSame('Hello!', $packet->getData());
                    $this->assertSame($address, $packet->getAddress());
                }
            });
        });
    }

    public function testSend()
    {
        Loop::run(function () {
            $datagram = Socket\datagram('127.0.0.1:0');
            Loop::delay(100, [$datagram, 'close']);

            $this->assertInternalType('resource', $datagram->getResource());

            $socket = yield Socket\connect('udp://' . $datagram->getLocalAddress());

            yield $socket->write('a');

            asyncCall(function () use ($datagram) {
                while ($packet = yield $datagram->receive()) {
                    $this->assertInstanceOf(Socket\Packet::class, $packet);
                    $this->assertSame('a', $packet->getData());
                    $datagram->send($packet->withData('b'));
                }
            });

            $data = yield $socket->read();

            $this->assertSame('b', $data);
        });
    }

    public function testSendPacketTooLarge()
    {
        $this->expectException(Socket\SocketException::class);
        $this->expectExceptionMessage('Could not send packet on datagram: stream_socket_sendto(): Message too long');

        Loop::run(function () {
            $datagram = Socket\datagram('127.0.0.1:0');
            Loop::delay(100, [$datagram, 'close']);

            $socket = yield Socket\connect('udp://' . $datagram->getLocalAddress());

            yield $socket->write('Hello!');

            while ($packet = yield $datagram->receive()) {
                $datagram->send($packet->withData(\str_repeat('-', 2 ** 20)));
            }
        });
    }

    public function testReceiveThenClose()
    {
        Loop::run(function () {
            $datagram = Socket\datagram('127.0.0.1:0');

            $promise = $datagram->receive();

            $datagram->close();

            $this->assertNull(yield $promise);
        });
    }

    public function testReceiveAfterClose()
    {
        Loop::run(function () {
            $datagram = Socket\datagram('127.0.0.1:0');

            $datagram->close();

            $this->assertNull(yield $datagram->receive());
        });
    }

    public function testSimultaneousReceive()
    {
        $this->expectException(Socket\PendingReceiveError::class);

        Loop::run(function () {
            $datagram = Socket\datagram('127.0.0.1:0');
            try {
                $promise = $datagram->receive();
                $datagram->receive();
            } finally {
                $datagram->close();
            }
        });
    }
}
