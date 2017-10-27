<?php

namespace Amp\Socket\Test;

use Amp\Dns\Record;
use Amp\Socket\ClientConnectContext;
use PHPUnit\Framework\TestCase;

class ClientConnectContextTest extends TestCase {
    public function bindToDataProvider() {
        return [
            [null],
            ['127.0.0.1:12345']
        ];
    }

    /**
     * @dataProvider bindToDataProvider
     */
    public function testWithBindTo($bindTo) {
        $context = new ClientConnectContext();
        $clonedContext = $context->withBindTo($bindTo);

        $this->assertNotSame($clonedContext, $context);
        $this->assertNull($context->getBindTo());
        $this->assertSame($bindTo, $clonedContext->getBindTo());
    }

    public function withConnectTimeoutDataProvider() {
        return [
            [1],
            [12345]
        ];
    }

    /**
     * @dataProvider withConnectTimeoutDataProvider
     */
    public function testWithConnectTimeout($timeout) {
        $context = new ClientConnectContext();
        $clonedContext = $context->withConnectTimeout($timeout);

        $this->assertNotSame($clonedContext, $context);
        $this->assertSame(10000, $context->getConnectTimeout());
        $this->assertSame($timeout, $clonedContext->getConnectTimeout());
    }

    public function withConnectTimeoutInvalidTimeoutDataProvider() {
        return [
            [0],
            [-1],
            [-123456]
        ];
    }

    /**
     * @dataProvider withConnectTimeoutInvalidTimeoutDataProvider
     * @expectedException \Error
     */
    public function testWithConnectTimeoutInvalidTimeout($timeout) {
        $this->expectExceptionMessage("Invalid connect timeout ({$timeout}), must be greater than 0");
        $context = new ClientConnectContext();
        $context->withConnectTimeout($timeout);
    }

    public function withMaxAttemptsDataProvider() {
        return [
            [1],
            [12345]
        ];
    }

    /**
     * @dataProvider withMaxAttemptsDataProvider
     */
    public function testWithMaxAttempts($maxAttempts) {
        $context = new ClientConnectContext();
        $clonedContext = $context->withMaxAttempts($maxAttempts);

        $this->assertNotSame($clonedContext, $context);
        $this->assertSame(2, $context->getMaxAttempts());
        $this->assertSame($maxAttempts, $clonedContext->getMaxAttempts());
    }

    public function withMaxAttemptsInvalidTimeoutDataProvider() {
        return [
            [0],
            [-1],
            [-123456]
        ];
    }

    /**
     * @dataProvider withMaxAttemptsInvalidTimeoutDataProvider
     * @expectedException \Error
     */
    public function testWithMaxAttemptsInvalidTimeout($maxAttempts) {
        $this->expectExceptionMessage("Invalid max attempts ({$maxAttempts}), must be greater than 0");
        $context = new ClientConnectContext();
        $context->withMaxAttempts($maxAttempts);
    }

    public function withDnsTypeRestrictionDataProvider() {
        return [
            [null],
            [Record::AAAA],
            [Record::A]
        ];
    }

    /**
     * @dataProvider withDnsTypeRestrictionDataProvider
     */
    public function testWithDnsTypeRestriction($type) {
        $context = new ClientConnectContext();
        $clonedContext = $context->withDnsTypeRestriction($type);

        $this->assertNotSame($clonedContext, $context);
        $this->assertNull($context->getDnsTypeRestriction());
        $this->assertSame($type, $clonedContext->getDnsTypeRestriction());
    }

    public function withDnsTypeRestrictionInvalidTypeDataProvider() {
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
    public function testWithDnsTypeRestrictionInvalidType($type) {
        $context = new ClientConnectContext();
        $context->withDnsTypeRestriction($type);
    }

    public function testToStreamContextArray() {
        $context = new ClientConnectContext();
        $clonedContext = $context->withBindTo('127.0.0.1:12345');

        $this->assertSame(['socket' => []], $context->toStreamContextArray());
        $this->assertSame(['socket' => ['bindto' => '127.0.0.1:12345']], $clonedContext->toStreamContextArray());
    }
}
