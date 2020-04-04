<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\DatagramSocket;
use function Amp\asyncCall;

class DatagramSocketTest extends AsyncTestCase
{
    public function testBindEndpointInvalidScheme(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Only udp scheme allowed for datagram creation');

        DatagramSocket::bind("invalid://127.0.0.1:0");
    }

    public function testBindEndpointError(): void
    {
        $this->expectException(Socket\SocketException::class);

        if (\PHP_VERSION_ID >= 70200) {
            $this->expectExceptionMessageMatches('/Could not create datagram .*: \[Error: #.*\] .*$/');
        } else {
            $this->expectExceptionMessageRegExp('/Could not create datagram .*: \[Error: #.*\] .*$/');
        }

        DatagramSocket::bind('error');
    }

    public function testReceive()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');
        Loop::delay(100, [$endpoint, 'close']);

        $this->assertIsResource($endpoint->getResource());

        $socket = yield Socket\connect('udp://' . $endpoint->getAddress());
        \assert($socket instanceof Socket\EncryptableSocket);
        $remote = $socket->getLocalAddress();

        yield $socket->write('Hello!');

        asyncCall(function () use ($endpoint, $remote) {
            while ([$address, $data] = yield $endpoint->receive()) {
                \assert($address instanceof Socket\SocketAddress);
                $this->assertSame('Hello!', $data);
                $this->assertSame($remote->getHost(), $address->getHost());
                $this->assertSame($remote->getPort(), $address->getPort());
            }
        });
    }

    public function testSend()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');
        Loop::delay(100, [$endpoint, 'close']);

        $this->assertIsResource($endpoint->getResource());

        $socket = yield Socket\connect('udp://' . $endpoint->getAddress());
        \assert($socket instanceof Socket\EncryptableSocket);
        $remote = $socket->getLocalAddress();

        yield $socket->write('a');

        asyncCall(function () use ($endpoint, $remote) {
            while ([$address, $data] = yield $endpoint->receive()) {
                \assert($address instanceof Socket\SocketAddress);
                $this->assertSame('a', $data);
                $this->assertSame($remote->getHost(), $address->getHost());
                $this->assertSame($remote->getPort(), $address->getPort());
                yield $endpoint->send($address, 'b');
            }
        });

        $data = yield $socket->read();

        $this->assertSame('b', $data);
    }

    public function testSendPacketTooLarge()
    {
        $this->expectException(Socket\SocketException::class);
        $this->expectExceptionMessage('Could not send packet on endpoint: stream_socket_sendto(): Message too long');

        $endpoint = DatagramSocket::bind('127.0.0.1:0');
        Loop::delay(100, [$endpoint, 'close']);

        $socket = yield Socket\connect('udp://' . $endpoint->getAddress());
        \assert($socket instanceof Socket\EncryptableSocket);
        yield $socket->write('Hello!');

        while ([$address, $data] = yield $endpoint->receive()) {
            yield $endpoint->send($address, \str_repeat('-', 2 ** 20));
        }
    }

    public function testReceiveThenClose()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');

        $promise = $endpoint->receive();

        $endpoint->close();

        $this->assertNull(yield $promise);
    }

    public function testReceiveAfterClose()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');

        $endpoint->close();

        $this->assertNull(yield $endpoint->receive());
    }

    public function testSimultaneousReceive()
    {
        $this->expectException(Socket\PendingReceiveError::class);

        $endpoint = DatagramSocket::bind('127.0.0.1:0');
        try {
            $promise = $endpoint->receive();
            $endpoint->receive();
        } finally {
            $endpoint->close();
        }
    }

    public function testSetChunkSize()
    {
        $context = (new Socket\BindContext())->withChunkSize(1);

        $endpoint = DatagramSocket::bind('127.0.0.1:0', $context);

        try {
            $socket = yield Socket\connect('udp://' . $endpoint->getAddress());
            \assert($socket instanceof Socket\EncryptableSocket);

            yield $socket->write('Hello!');
            [$address, $data] = yield $endpoint->receive();
            $this->assertSame('H', $data);

            $endpoint->setChunkSize(5);
            yield $socket->write('Hello!');
            [$address, $data] = yield $endpoint->receive();
            $this->assertSame('Hello', $data);
        } finally {
            $endpoint->close();
        }
    }
}
