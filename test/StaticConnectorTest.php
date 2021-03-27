<?php

namespace Amp\Socket\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket\Connector;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\StaticConnector;

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
