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
}
