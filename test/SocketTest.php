<?php

namespace Amp\Socket\Test;

use Amp\Socket\Socket;

class SocketTest extends \PHPUnit_Framework_TestCase {
    /**
     * @dataProvider provideInvalidLengthParameters
     * @expectedException \InvalidArgumentException
     */
    public function testReadFailsOnInvalidLengthParameter($badLen) {
        \Amp\execute(function () use ($badLen) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            list($serverSock, $clientSock) = $sockets;
            $client = new Socket($clientSock);
            yield $client->read($badLen);
        });
    }

    public function provideInvalidLengthParameters() {
        return [
            [-1],
            [0],
            [true],
            [new \StdClass],
        ];
    }
}
