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
            $remote = $socket->getLocalAddress();

            yield $socket->write('Hello!');

            asyncCall(function () use ($datagram, $remote) {
                while (list($data, $address) = yield $datagram->receive()) {
                    $this->assertSame('Hello!', $data);
                    $this->assertSame($remote, $address);
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
            $remote = $socket->getLocalAddress();

            yield $socket->write('a');

            asyncCall(function () use ($datagram, $remote) {
                while (list($data, $address) = yield $datagram->receive()) {
                    $this->assertSame('a', $data);
                    $this->assertSame($remote, $address);
                    yield $datagram->send('b', $address);
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

            while (list($data, $address) = yield $datagram->receive()) {
                yield $datagram->send(\str_repeat('-', 2 ** 20), $address);
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
