<?php

namespace Amp\Socket\Test;

use Amp\Dns\Record;
use Amp\Socket\ConnectContext;
use PHPUnit\Framework\TestCase;

class ConnectContextTest extends TestCase
{
    public function bindToDataProvider()
    {
        return [
            [null],
            ['127.0.0.1:12345']
        ];
    }

    /**
     * @dataProvider bindToDataProvider
     */
    public function testWithBindTo($bindTo)
    {
        $context = new ConnectContext();
        $clonedContext = $context->withBindTo($bindTo);

        $this->assertNull($context->getBindTo());
        $this->assertSame($bindTo, $clonedContext->getBindTo());
    }

    public function testWithTcpNoDelay()
    {
        $context = new ConnectContext();
        $clonedContext = $context->withTcpNoDelay();

        $this->assertFalse($context->hasTcpNoDelay());
        $this->assertTrue($clonedContext->hasTcpNoDelay());
    }

    public function withConnectTimeoutDataProvider()
    {
        return [
            [1],
            [12345]
        ];
    }

    /**
     * @dataProvider withConnectTimeoutDataProvider
     */
    public function testWithConnectTimeout($timeout)
    {
        $context = new ConnectContext();
        $clonedContext = $context->withConnectTimeout($timeout);

        $this->assertSame(10000, $context->getConnectTimeout());
        $this->assertSame($timeout, $clonedContext->getConnectTimeout());
    }

    public function withConnectTimeoutInvalidTimeoutDataProvider()
    {
        return [
            [0],
            [-1],
            [-123456]
        ];
    }

    /**
     * @dataProvider withConnectTimeoutInvalidTimeoutDataProvider
     */
    public function testWithConnectTimeoutInvalidTimeout($timeout)
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid connect timeout ({$timeout}), must be greater than 0");
        $context = new ConnectContext();
        $context->withConnectTimeout($timeout);
    }

    public function withMaxAttemptsDataProvider()
    {
        return [
            [1],
            [12345]
        ];
    }

    /**
     * @dataProvider withMaxAttemptsDataProvider
     */
    public function testWithMaxAttempts($maxAttempts)
    {
        $context = new ConnectContext();
        $clonedContext = $context->withMaxAttempts($maxAttempts);

        $this->assertSame(2, $context->getMaxAttempts());
        $this->assertSame($maxAttempts, $clonedContext->getMaxAttempts());
    }

    public function withMaxAttemptsInvalidTimeoutDataProvider()
    {
        return [
            [0],
            [-1],
            [-123456]
        ];
    }

    /**
     * @dataProvider withMaxAttemptsInvalidTimeoutDataProvider
     */
    public function testWithMaxAttemptsInvalidTimeout($maxAttempts)
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid max attempts ({$maxAttempts}), must be greater than 0");
        $context = new ConnectContext();
        $context->withMaxAttempts($maxAttempts);
    }

    public function withDnsTypeRestrictionDataProvider()
    {
        return [
            [null],
            [Record::AAAA],
            [Record::A]
        ];
    }

    /**
     * @dataProvider withDnsTypeRestrictionDataProvider
     */
    public function testWithDnsTypeRestriction($type)
    {
        $context = new ConnectContext();
        $clonedContext = $context->withDnsTypeRestriction($type);

        $this->assertNull($context->getDnsTypeRestriction());
        $this->assertSame($type, $clonedContext->getDnsTypeRestriction());
    }

    public function withDnsTypeRestrictionInvalidTypeDataProvider()
    {
        return [
            [Record::NS],
            [Record::MX],
        ];
    }

    /**
     * @dataProvider withDnsTypeRestrictionInvalidTypeDataProvider
     * @expectedException \Error
     * @expectedExceptionMessage Invalid resolver type restriction
     */
    public function testWithDnsTypeRestrictionInvalidType($type)
    {
        $context = new ConnectContext();
        $context->withDnsTypeRestriction($type);
    }

    public function testToStreamContextArray()
    {
        $context = new ConnectContext();
        $clonedContext = $context->withBindTo('127.0.0.1:12345');

        $this->assertSame(['socket' => ['tcp_nodelay' => false]], $context->toStreamContextArray());
        $this->assertSame(['socket' => [
            'tcp_nodelay' => false,
            'bindto' => '127.0.0.1:12345',
        ]], $clonedContext->toStreamContextArray());
    }
}
