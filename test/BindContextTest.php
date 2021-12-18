<?php

namespace Amp\Socket;

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
        self::assertNull($contextA->getBindTo());
        self::assertSame($bindTo, $contextB->getBindTo());

        $contextC = $contextB->withoutBindTo();
        self::assertSame($bindTo, $contextB->getBindTo());
        self::assertNull($contextC->getBindTo());
    }

    public function testWithTcpNoDelay(): void
    {
        $contextA = new BindContext;
        $contextB = $contextA->withTcpNoDelay();
        $contextC = $contextB->withoutTcpNoDelay();

        self::assertFalse($contextA->hasTcpNoDelay());
        self::assertTrue($contextB->hasTcpNoDelay());
        self::assertFalse($contextC->hasTcpNoDelay());
    }

    public function testWithTlsContext(): void
    {
        $tlsContext = new ServerTlsContext;

        $contextA = new BindContext;
        $contextB = $contextA->withTlsContext($tlsContext);
        $contextC = $contextB->withoutTlsContext();

        self::assertNull($contextA->getTlsContext());
        self::assertSame($tlsContext, $contextB->getTlsContext());
        self::assertNull($contextC->getTlsContext());
    }

    public function testWithChunkSize(): void
    {
        $chunkSize = 123;

        $contextA = new BindContext;
        $contextB = $contextA->withChunkSize($chunkSize);

        self::assertSame(8192, $contextA->getChunkSize());
        self::assertSame($chunkSize, $contextB->getChunkSize());
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
        self::assertSame(128, $origContext->getBacklog());
        self::assertSame($backlog, $clonedContext->getBacklog());
    }

    public function testWithReusePort(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withReusePort();
        self::assertFalse($origContext->hasReusePort());
        self::assertTrue($clonedContext->hasReusePort());
    }

    public function testWithoutReusePort(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withoutReusePort();
        self::assertFalse($origContext->hasReusePort());
        self::assertFalse($clonedContext->hasReusePort());
    }

    public function testWithBroadcast(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withBroadcast();
        self::assertFalse($origContext->hasBroadcast());
        self::assertTrue($clonedContext->hasBroadcast());
    }

    public function testWithoutBroadcast(): void
    {
        $origContext = new BindContext;
        $clonedContext = $origContext->withoutBroadcast();
        self::assertFalse($origContext->hasBroadcast());
        self::assertFalse($clonedContext->hasBroadcast());
    }
}
