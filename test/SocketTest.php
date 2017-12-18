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
        $socket = \socket_create(AF_UNIX, SOCK_STREAM, 0);
        // Prepending the path with a null byte will abstract the socket and remove it when not used anymore see http://man7.org/linux/man-pages/man7/unix.7.html
        \socket_bind($socket, "\0". __DIR__ . '/socket.sock');

        socket_set_nonblock($socket);
        socket_listen($socket);

        $clientSocket = new Socket\ClientSocket(stream_socket_client("unix://\0" . __DIR__ . '/socket.sock'));

        self::assertEquals($clientSocket->getRemoteAddress(), $clientSocket->getLocalAddress());
    }
}
