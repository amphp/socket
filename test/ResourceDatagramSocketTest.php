<?php

namespace Amp\Socket\Test;

use Amp\CancelledException;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use const Amp\Process\IS_WINDOWS;
use function Amp\async;
use function Amp\delay;

class ResourceDatagramSocketTest extends AsyncTestCase
{
    public function testBindEndpointInvalidScheme(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Only udp scheme allowed for datagram creation');

        Socket\bind("invalid://127.0.0.1:0");
    }

    public function testBindEndpointError(): void
    {
        $this->expectException(Socket\SocketException::class);
        $this->expectExceptionMessageMatches('/Could not create datagram .*: \[Error: #.*\] .*$/');

        Socket\bind('error');
    }

    public function testReceive()
    {
        $endpoint = Socket\bind('127.0.0.1:0');

        self::assertIsResource($endpoint->getResource());

        $socket = Socket\connect('udp://' . $endpoint->getAddress());
        $remote = $socket->getLocalAddress();

        $socket->write('Hello!');

        EventLoop::queue(function () use ($endpoint, $remote): void {
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
        $endpoint = Socket\bind('127.0.0.1:0');
        self::assertIsResource($endpoint->getResource());

        $socket = Socket\connect('udp://' . $endpoint->getAddress());
        \assert($socket instanceof Socket\EncryptableSocket);
        $remote = $socket->getLocalAddress();

        $socket->write('a');

        EventLoop::queue(function () use ($endpoint, $remote) {
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

        if (IS_WINDOWS) {
            $this->expectExceptionMessage('Could not send packet on endpoint: stream_socket_sendto(): A message sent on a datagram socket was larger than the internal message buffer or some other network limit, or the buffer used to receive a datagram into was smaller than the datagram itself');
        } else {
            $this->expectExceptionMessage('Could not send packet on endpoint: stream_socket_sendto(): Message too long');
        }

        $endpoint = Socket\bind('127.0.0.1:0');

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
        $endpoint = Socket\bind('127.0.0.1:0');

        $future = async(fn () => $endpoint->receive());

        $endpoint->close();

        self::assertNull($future->await());
    }

    public function testReceiveAfterClose()
    {
        $endpoint = Socket\bind('127.0.0.1:0');

        $endpoint->close();

        self::assertNull($endpoint->receive());
    }

    public function testSimultaneousReceive()
    {
        $this->expectException(Socket\PendingReceiveError::class);

        $endpoint = Socket\bind('127.0.0.1:0');
        try {
            async(fn () => $endpoint->receive());
            async(fn () => $endpoint->receive())->await();
        } finally {
            $endpoint->close();
        }
    }

    public function testSetChunkSize()
    {
        $context = (new Socket\BindContext())->withChunkSize(1);

        $endpoint = Socket\bind('127.0.0.1:0', $context);

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

    public function testLimit()
    {
        $endpoint = Socket\bind('127.0.0.1:0');

        try {
            $socket = Socket\connect('udp://' . $endpoint->getAddress());
            \assert($socket instanceof Socket\EncryptableSocket);

            $socket->write('Hello!');
            [, $data] = $endpoint->receive(limit: 1);
            self::assertSame('H', $data);

            $socket->write('Hello!');
            [, $data] = $endpoint->receive(limit: 5);
            self::assertSame('Hello', $data);
        } finally {
            $endpoint->close();
        }
    }

    public function testCancelThenAccept(): void
    {
        $datagram = Socket\bind('127.0.0.1:0');

        try {
            $datagram->receive(new TimeoutCancellation(0.01));
            $this->fail('The receive should have been cancelled');
        } catch (CancelledException) {
        }

        $client = Socket\connect('udp://' . $datagram->getAddress());

        $data = 'test';
        $client->write($data);
        self::assertEquals([$client->getLocalAddress(), $data], $datagram->receive());
    }
}
