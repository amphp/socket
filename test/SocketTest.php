<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;
use function Amp\asyncCall;
use function Amp\Promise\wait;

class SocketTest extends TestCase {
    public function testReadAndClose() {
        Loop::run(function () {
            $data = "Testing\n";

            list($serverSock, $clientSock) = Socket\pair();

            \fwrite($serverSock, $data);
            \fclose($serverSock);

            $client = new Socket\ClientSocket($clientSock);

            while (($chunk = yield $client->read()) !== null) {
                $this->assertSame($data, $chunk);
            }
        });
    }

    public function testSocketAddress() {
        try {
            $s = stream_socket_server('unix://' . __DIR__ . '/socket.sock');
            $c = stream_socket_client('unix://' . __DIR__ . '/socket.sock');

            $clientSocket = new Socket\ClientSocket($c);
            $serverSocket = new Socket\ServerSocket($s);

            $this->assertNotNull($clientSocket->getRemoteAddress());
            $this->assertSame(__DIR__ . '/socket.sock', $clientSocket->getLocalAddress());
            $this->assertSame($clientSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
            $this->assertSame($serverSocket->getRemoteAddress(), $serverSocket->getLocalAddress());
            $this->assertSame($serverSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
        } finally {
            @\unlink(__DIR__ . '/socket.sock');
        }
    }

    public function testEnableCryptoWithoutTlsContext() {
        $server = Socket\listen('127.0.0.1:0');

        asyncCall(function () use ($server) {
            yield Socket\connect($server->getAddress());
        });

        /** @var Socket\ServerSocket $client */
        $client = wait($server->accept());

        $this->expectException(Socket\CryptoException::class);
        $this->expectExceptionMessage("Can't enable TLS without configuration.");

        wait($client->enableCrypto());
    }
}
