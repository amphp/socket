<?php

namespace Amp\Socket;

use Amp\PHPUnit\AsyncTestCase;

class StaticConnectorTest extends AsyncTestCase
{
    public function testConnect(): void
    {
        $underlyingConnector = $this->createMock(Connector::class);
        $staticSocketPool = new StaticConnector('override-uri', $underlyingConnector);

        $expected = $this->createMock(EncryptableSocket::class);

        $underlyingConnector->expects(self::once())
            ->method('connect')
            ->with('override-uri', null, null)
            ->willReturn($expected);

        $returned = $staticSocketPool->connect('test-url');

        self::assertSame($expected, $returned);
    }
}
