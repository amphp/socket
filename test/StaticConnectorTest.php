<?php

namespace Amp\Socket\Test;

use Amp\PHPUnit\TestCase;
use Amp\Socket\Connector;
use Amp\Socket\StaticConnector;

class StaticConnectorTest extends TestCase
{
    public function testConnect(): void
    {
        /** @var Connector $underlyingConnector */
        $underlyingConnector = $this->prophesize(Connector::class);
        $staticSocketPool = new StaticConnector('override-uri', $underlyingConnector->reveal());

        $expected = new \Amp\LazyPromise(function () {
            // nothing
        });

        $underlyingConnector->connect('override-uri', null, null)->shouldBeCalled()->willReturn($expected);

        $returned = $staticSocketPool->connect('test-url');

        self::assertSame($expected, $returned);
    }
}
