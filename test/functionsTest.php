<?php

namespace Amp\Socket\Test;

use Amp\Socket;
use PHPUnit\Framework\TestCase;

class functionsTest extends TestCase
{
    /**
     * @expectedException \Error
     * @expectedExceptionMessage Only tcp, udp, unix and udg schemes allowed for server creation
     */
    public function testListenInvalidScheme()
    {
        Socket\listen("invalid://127.0.0.1:0");
    }

    /**
     * @expectedException \Amp\Socket\SocketException
     * @expectedExceptionMessageRegExp /Could not create server .*: \[Error: #.*\] .*$/
     */
    public function testListenStreamSocketServerError()
    {
        Socket\listen('error');
    }

    public function testListenIPv6()
    {
        try {
            $socket = Socket\listen('[::1]:0');
            $this->assertRegExp('(\[::1\]:\d+)', $socket->getAddress());
        } catch (Socket\SocketException $e) {
            if ($e->getMessage() === 'Could not create server [::1]:0: [Error: #0] Cannot assign requested address') {
                $this->markTestSkipped('Missing IPv6 support');
            }

            throw $e;
        }
    }
}
