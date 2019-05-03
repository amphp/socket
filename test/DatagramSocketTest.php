<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;
use function Amp\asyncCall;

class DatagramSocketTest extends TestCase
{
    public function testReceive()
    {
        Loop::run(function () {
            $endpoint = Socket\bindDatagramSocket('127.0.0.1:0');
            Loop::delay(100, [$endpoint, 'close']);

            $this->assertInternalType('resource', $endpoint->getResource());

            $socket = yield Socket\connect('udp://' . $endpoint->getAddress());
            $remote = $socket->getLocalAddress();

            yield $socket->write('Hello!');

            asyncCall(function () use ($endpoint, $remote) {
                while ([$address, $data] = yield $endpoint->receive()) {
                    $this->assertSame('Hello!', $data);
                    $this->assertSame($remote, $address);
                }
            });
        });
    }

    public function testSend()
    {
        Loop::run(function () {
            $endpoint = Socket\bindDatagramSocket('127.0.0.1:0');
            Loop::delay(100, [$endpoint, 'close']);

            $this->assertInternalType('resource', $endpoint->getResource());

            $socket = yield Socket\connect('udp://' . $endpoint->getAddress());
            $remote = $socket->getLocalAddress();

            yield $socket->write('a');

            asyncCall(function () use ($endpoint, $remote) {
                while ([$address, $data] = yield $endpoint->receive()) {
                    $this->assertSame('a', $data);
                    $this->assertSame($remote, $address);
                    yield $endpoint->send($address, 'b');
                }
            });

            $data = yield $socket->read();

            $this->assertSame('b', $data);
        });
    }

    public function testSendPacketTooLarge()
    {
        $this->expectException(Socket\SocketException::class);
        $this->expectExceptionMessage('Could not send packet on endpoint: stream_socket_sendto(): Message too long');

        Loop::run(function () {
            $endpoint = Socket\bindDatagramSocket('127.0.0.1:0');
            Loop::delay(100, [$endpoint, 'close']);

            $socket = yield Socket\connect('udp://' . $endpoint->getAddress());

            yield $socket->write('Hello!');

            while ([$address, $data] = yield $endpoint->receive()) {
                yield $endpoint->send($address, \str_repeat('-', 2 ** 20));
            }
        });
    }

    public function testReceiveThenClose()
    {
        Loop::run(function () {
            $endpoint = Socket\bindDatagramSocket('127.0.0.1:0');

            $promise = $endpoint->receive();

            $endpoint->close();

            $this->assertNull(yield $promise);
        });
    }

    public function testReceiveAfterClose()
    {
        Loop::run(function () {
            $endpoint = Socket\bindDatagramSocket('127.0.0.1:0');

            $endpoint->close();

            $this->assertNull(yield $endpoint->receive());
        });
    }

    public function testSimultaneousReceive()
    {
        $this->expectException(Socket\PendingReceiveError::class);

        Loop::run(function () {
            $endpoint = Socket\bindDatagramSocket('127.0.0.1:0');
            try {
                $promise = $endpoint->receive();
                $endpoint->receive();
            } finally {
                $endpoint->close();
            }
        });
    }
}
