<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\PHPUnit\AsyncTestCase;

class StaticConnectorTest extends AsyncTestCase
{
    public function testConnect(): void
    {
        $underlyingConnector = $this->createMock(SocketConnector::class);
        $staticSocketPool = new StaticSocketConnector('override-uri', $underlyingConnector);

        $expected = $this->createMock(Socket::class);

        $underlyingConnector->expects(self::once())
            ->method('connect')
            ->with('override-uri', null, null)
            ->willReturn($expected);

        $returned = $staticSocketPool->connect('test-url');

        self::assertSame($expected, $returned);
    }
}
