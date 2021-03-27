<?php

namespace Amp\Socket\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\Server;
use function Amp\ByteStream\buffer;
use function Revolt\EventLoop\defer;

class SocketTest extends AsyncTestCase
{
    public function testReadAndClose(): void
    {
        $data = "Testing\n";

        [$serverSock, $clientSock] = Socket\createPair();

        $serverSock->end($data);

        self::assertSame($data, buffer($clientSock));
    }

    public function testSocketAddress(): void
    {
        try {
            $s = \stream_socket_server('unix://' . __DIR__ . '/socket.sock');
            $c = \stream_socket_client('unix://' . __DIR__ . '/socket.sock');

            $clientSocket = Socket\ResourceSocket::fromClientSocket($c);
            $serverSocket = Socket\ResourceSocket::fromServerSocket($s);

            self::assertNotNull($clientSocket->getRemoteAddress());
            self::assertSame(__DIR__ . '/socket.sock', (string) $clientSocket->getLocalAddress());
            self::assertEquals($clientSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
            self::assertEquals($serverSocket->getRemoteAddress(), $serverSocket->getLocalAddress());
            self::assertEquals($serverSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
        } finally {
            @\unlink(__DIR__ . '/socket.sock');
        }
    }

    public function testEnableCryptoWithoutTlsContext(): void
    {
        $server = Server::listen('127.0.0.1:0');

        defer(function () use ($server): void {
            $socket = Socket\connect($server->getAddress());
            $socket->close();
        });

        $client = $server->accept();

        $this->expectException(Socket\TlsException::class);
        $this->expectExceptionMessage("Can't enable TLS without configuration.");

        try {
            $client->setupTls();
        } finally {
            $server->close();
            $client->close();
        }
    }
}
