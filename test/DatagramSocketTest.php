<?php

namespace Amp\Socket\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\DatagramSocket;
use function Amp\coroutine;
use function Amp\delay;
use function Revolt\launch;

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
        $this->expectExceptionMessageMatches('/Could not create datagram .*: \[Error: #.*\] .*$/');

        DatagramSocket::bind('error');
    }

    public function testReceive()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');

        self::assertIsResource($endpoint->getResource());

        $socket = Socket\connect('udp://' . $endpoint->getAddress());
        $remote = $socket->getLocalAddress();

        $socket->write('Hello!');

        launch(function () use ($endpoint, $remote): void {
            while ([$address, $data] = $endpoint->receive()) {
                \assert($address instanceof Socket\SocketAddress);
                $this->assertSame('Hello!', $data);
                $this->assertSame($remote->getHost(), $address->getHost());
                $this->assertSame($remote->getPort(), $address->getPort());
            }
        });

        delay(0.1);

        $endpoint->close();
        $socket->close();
    }

    public function testSend()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');
        self::assertIsResource($endpoint->getResource());

        $socket = Socket\connect('udp://' . $endpoint->getAddress());
        \assert($socket instanceof Socket\EncryptableSocket);
        $remote = $socket->getLocalAddress();

        $socket->write('a');

        launch(function () use ($endpoint, $remote) {
            while ([$address, $data] = $endpoint->receive()) {
                \assert($address instanceof Socket\SocketAddress);
                $this->assertSame('a', $data);
                $this->assertSame($remote->getHost(), $address->getHost());
                $this->assertSame($remote->getPort(), $address->getPort());
                $endpoint->send($address, 'b');
            }
        });

        $data = $socket->read();

        self::assertSame('b', $data);

        $socket->close();
        $endpoint->close();
    }

    public function testSendPacketTooLarge()
    {
        $this->expectException(Socket\SocketException::class);
        $this->expectExceptionMessage('Could not send packet on endpoint: stream_socket_sendto(): Message too long');

        $endpoint = DatagramSocket::bind('127.0.0.1:0');

        $socket = Socket\connect('udp://' . $endpoint->getAddress());
        \assert($socket instanceof Socket\EncryptableSocket);
        $socket->write('Hello!');

        try {
            while ([$address] = $endpoint->receive()) {
                $endpoint->send($address, \str_repeat('-', 2 ** 20));
            }
        } finally {
            $endpoint->close();
        }
    }

    public function testReceiveThenClose()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');

        $future = coroutine(fn () => $endpoint->receive());

        $endpoint->close();

        self::assertNull($future->await());
    }

    public function testReceiveAfterClose()
    {
        $endpoint = DatagramSocket::bind('127.0.0.1:0');

        $endpoint->close();

        self::assertNull($endpoint->receive());
    }

    public function testSimultaneousReceive()
    {
        $this->expectException(Socket\PendingReceiveError::class);

        $endpoint = DatagramSocket::bind('127.0.0.1:0');
        try {
            coroutine(fn () => $endpoint->receive());
            coroutine(fn () => $endpoint->receive())->await();
        } finally {
            $endpoint->close();
        }
    }

    public function testSetChunkSize()
    {
        $context = (new Socket\BindContext())->withChunkSize(1);

        $endpoint = DatagramSocket::bind('127.0.0.1:0', $context);

        try {
            $socket = Socket\connect('udp://' . $endpoint->getAddress());
            \assert($socket instanceof Socket\EncryptableSocket);

            $socket->write('Hello!');
            [, $data] = $endpoint->receive();
            self::assertSame('H', $data);

            $endpoint->setChunkSize(5);
            $socket->write('Hello!');
            [, $data] = $endpoint->receive();
            self::assertSame('Hello', $data);
        } finally {
            $endpoint->close();
        }
    }
}
