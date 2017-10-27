<?php

namespace Amp\Socket\Test;

use Amp\Socket\ServerListenContext;
use PHPUnit\Framework\TestCase;

class ServerListenContextTest extends TestCase {
    public function bindToDataProvider() {
        return [
            [null],
            ['127.0.0.1:123'],
        ];
    }

    /**
     * @dataProvider bindToDataProvider
     */
    public function testWithBindTo($bindTo) {
        $origContext = new ServerListenContext();
        $clonedContext = $origContext->withBindTo($bindTo);
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertNull($origContext->getBindTo());
        $this->assertSame($bindTo, $clonedContext->getBindTo());
    }

    public function backlogDataProvider() {
        return [
            [10],
            [123],
        ];
    }

    /**
     * @dataProvider backlogDataProvider
     */
    public function testWithBacklog($backlog) {
        $origContext = new ServerListenContext();
        $clonedContext = $origContext->withBacklog($backlog);
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertSame(128, $origContext->getBacklog());
        $this->assertSame($backlog, $clonedContext->getBacklog());
    }

    public function testWithReusePort() {
        $origContext = new ServerListenContext();
        $clonedContext = $origContext->withReusePort();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertFalse($origContext->hasReusePort());
        $this->assertTrue($clonedContext->hasReusePort());
    }

    public function testWithoutReusePort() {
        $origContext = new ServerListenContext();
        $clonedContext = $origContext->withoutReusePort();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertFalse($origContext->hasReusePort());
        $this->assertFalse($clonedContext->hasReusePort());
    }

    public function testWithBroadcast() {
        $origContext = new ServerListenContext();
        $clonedContext = $origContext->withBroadcast();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertFalse($origContext->hasBroadcast());
        $this->assertTrue($clonedContext->hasBroadcast());
    }

    public function testWithoutBroadcast() {
        $origContext = new ServerListenContext();
        $clonedContext = $origContext->withoutBroadcast();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertFalse($origContext->hasBroadcast());
        $this->assertFalse($clonedContext->hasBroadcast());
    }
}
