<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;

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

    public function testLocalAddressAsUnixSocket() {
        @unlink(__DIR__ . '/socket.sock');

        $socket = \socket_create(AF_UNIX, SOCK_STREAM, 0);
        \socket_bind($socket, __DIR__ . '/socket.sock');

        socket_set_nonblock($socket);
        socket_listen($socket);

        $clientSocket = new Socket\ClientSocket(stream_socket_client('unix://' . __DIR__ . '/socket.sock'));

        self::assertEquals($clientSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
    }
}
