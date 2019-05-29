<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;
use function Amp\asyncCall;
use function Amp\ByteStream\buffer;
use function Amp\Promise\wait;

class SocketTest extends TestCase
{
    public function testReadAndClose(): void
    {
        Loop::run(function () {
            $data = "Testing\n";

            [$serverSock, $clientSock] = Socket\createPair();

            yield $serverSock->end($data);

            $this->assertSame($data, yield buffer($clientSock));
        });
    }

    public function testSocketAddress(): void
    {
        try {
            $s = \stream_socket_server('unix://' . __DIR__ . '/socket.sock');
            $c = \stream_socket_client('unix://' . __DIR__ . '/socket.sock');

            $clientSocket = Socket\ResourceSocket::fromClientSocket($c);
            $serverSocket = Socket\ResourceSocket::fromServerSocket($s);

            $this->assertNotNull($clientSocket->getRemoteAddress());
            $this->assertSame(__DIR__ . '/socket.sock', (string) $clientSocket->getLocalAddress());
            $this->assertEquals($clientSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
            $this->assertEquals($serverSocket->getRemoteAddress(), $serverSocket->getLocalAddress());
            $this->assertEquals($serverSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
        } finally {
            @\unlink(__DIR__ . '/socket.sock');
        }
    }

    public function testEnableCryptoWithoutTlsContext(): void
    {
        $server = Socket\listen('127.0.0.1:0');

        asyncCall(function () use ($server) {
            yield Socket\connect($server->getAddress());
        });

        /** @var Socket\ResourceSocket $client */
        $client = wait($server->accept());

        $this->expectException(Socket\TlsException::class);
        $this->expectExceptionMessage("Can't enable TLS without configuration.");

        wait($client->setupTls());
    }
}
