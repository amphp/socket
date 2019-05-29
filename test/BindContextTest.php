<?php

namespace Amp\Socket\Test;

use Amp\Socket\BindContext;
use Amp\Socket\ServerTlsContext;
use PHPUnit\Framework\TestCase;

class BindContextTest extends TestCase
{
    public function bindToDataProvider(): array
    {
        return [
            [null],
            ['127.0.0.1:123'],
        ];
    }

    /**
     * @dataProvider bindToDataProvider
     */
    public function testWithBindTo($bindTo): void
    {
        $contextA = new BindContext;

        $contextB = $contextA->withBindTo($bindTo);
        $this->assertNull($contextA->getBindTo());
        $this->assertSame($bindTo, $contextB->getBindTo());

        $contextC = $contextB->withoutBindTo();
        $this->assertSame($bindTo, $contextB->getBindTo());
        $this->assertNull($contextC->getBindTo());
    }

    public function testWithTcpNoDelay(): void
    {
        $contextA = new BindContext;
        $contextB = $contextA->withTcpNoDelay();
        $contextC = $contextB->withoutTcpNoDelay();

        $this->assertFalse($contextA->hasTcpNoDelay());
        $this->assertTrue($contextB->hasTcpNoDelay());
        $this->assertFalse($contextC->hasTcpNoDelay());
    }

    public function testWithTlsContext(): void
    {
        $tlsContext = new ServerTlsContext;

        $contextA = new BindContext;
        $contextB = $contextA->withTlsContext($tlsContext);
        $contextC = $contextB->withoutTlsContext();

        $this->assertNull($contextA->getTlsContext());
        $this->assertSame($tlsContext, $contextB->getTlsContext());
        $this->assertNull($contextC->getTlsContext());
    }

    public function testWithChunkSize(): void
    {
        $chunkSize = 123;

        $contextA = new BindContext;
        $contextB = $contextA->withChunkSize($chunkSize);

        $this->assertSame(8192, $contextA->getChunkSize());
        $this->assertSame($chunkSize, $contextB->getChunkSize());
    }

    public function backlogDataProvider(): array
    {
        return [
            [10],
            [123],
        ];
    }

    /**
     * @dataProvider backlogDataProvider
     */
    public function testWithBacklog($backlog): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withBacklog($backlog);
        $this->assertSame(128, $origContext->getBacklog());
        $this->assertSame($backlog, $clonedContext->getBacklog());
    }

    public function testWithReusePort(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withReusePort();
        $this->assertFalse($origContext->hasReusePort());
        $this->assertTrue($clonedContext->hasReusePort());
    }

    public function testWithoutReusePort(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withoutReusePort();
        $this->assertFalse($origContext->hasReusePort());
        $this->assertFalse($clonedContext->hasReusePort());
    }

    public function testWithBroadcast(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withBroadcast();
        $this->assertFalse($origContext->hasBroadcast());
        $this->assertTrue($clonedContext->hasBroadcast());
    }

    public function testWithoutBroadcast(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withoutBroadcast();
        $this->assertFalse($origContext->hasBroadcast());
        $this->assertFalse($clonedContext->hasBroadcast());
    }
}
