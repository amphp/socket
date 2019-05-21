<?php

namespace Amp\Socket\Test;

use Amp\Socket\BindContext;
use PHPUnit\Framework\TestCase;

class BindContextTest extends TestCase
{
    public function bindToDataProvider()
    {
        return [
            [null],
            ['127.0.0.1:123'],
        ];
    }

    /**
     * @dataProvider bindToDataProvider
     */
    public function testWithBindTo($bindTo)
    {
        $origContext = new BindContext();
        $clonedContext = $origContext->withBindTo($bindTo);
        $this->assertNull($origContext->getBindTo());
        $this->assertSame($bindTo, $clonedContext->getBindTo());
    }

    public function testWithTcpNoDelay()
    {
        $context = new BindContext();
        $clonedContext = $context->withTcpNoDelay();

        $this->assertFalse($context->hasTcpNoDelay());
        $this->assertTrue($clonedContext->hasTcpNoDelay());
    }

    public function backlogDataProvider()
    {
        return [
            [10],
            [123],
        ];
    }

    /**
     * @dataProvider backlogDataProvider
     */
    public function testWithBacklog($backlog)
    {
        $origContext = new BindContext();
        $clonedContext = $origContext->withBacklog($backlog);
        $this->assertSame(128, $origContext->getBacklog());
        $this->assertSame($backlog, $clonedContext->getBacklog());
    }

    public function testWithReusePort()
    {
        $origContext = new BindContext();
        $clonedContext = $origContext->withReusePort();
        $this->assertFalse($origContext->hasReusePort());
        $this->assertTrue($clonedContext->hasReusePort());
    }

    public function testWithoutReusePort()
    {
        $origContext = new BindContext();
        $clonedContext = $origContext->withoutReusePort();
        $this->assertFalse($origContext->hasReusePort());
        $this->assertFalse($clonedContext->hasReusePort());
    }

    public function testWithBroadcast()
    {
        $origContext = new BindContext();
        $clonedContext = $origContext->withBroadcast();
        $this->assertFalse($origContext->hasBroadcast());
        $this->assertTrue($clonedContext->hasBroadcast());
    }

    public function testWithoutBroadcast()
    {
        $origContext = new BindContext();
        $clonedContext = $origContext->withoutBroadcast();
        $this->assertFalse($origContext->hasBroadcast());
        $this->assertFalse($clonedContext->hasBroadcast());
    }
}
