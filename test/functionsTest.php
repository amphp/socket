<?php

namespace Amp\Socket\Test;

use Amp\Socket;
use PHPUnit\Framework\TestCase;

class functionsTest extends TestCase
{
    /**
     * @expectedException \Error
     * @expectedExceptionMessage Only tcp and unix schemes allowed for server creation
     */
    public function testListenInvalidScheme(): void
    {
        Socket\listen("invalid://127.0.0.1:0");
    }

    /**
     * @expectedException \Error
     * @expectedExceptionMessage Only udp scheme allowed for datagram creation
     */
    public function testEndpointInvalidScheme(): void
    {
        Socket\bindDatagramSocket("invalid://127.0.0.1:0");
    }

    /**
     * @expectedException \Amp\Socket\SocketException
     * @expectedExceptionMessageRegExp /Could not create server .*: \[Error: #.*\] .*$/
     */
    public function testListenStreamSocketServerError(): void
    {
        Socket\listen('error');
    }

    /**
     * @expectedException \Amp\Socket\SocketException
     * @expectedExceptionMessageRegExp /Could not create datagram .*: \[Error: #.*\] .*$/
     */
    public function testEndpointError(): void
    {
        Socket\bindDatagramSocket('error');
    }

    public function testListenIPv6(): void
    {
        try {
            $socket = Socket\listen('[::1]:0');
            $this->assertRegExp('(\[::1\]:\d+)', $socket->getAddress());
        } catch (Socket\SocketException $e) {
            if ($e->getMessage() === 'Could not create server tcp://[::1]:0: [Error: #0] Cannot assign requested address') {
                $this->markTestSkipped('Missing IPv6 support');
            }

            throw $e;
        }
    }
}
