<?php

namespace Amp\Socket\Test;

use Amp\Dns\Record;
use Amp\Socket;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use PHPUnit\Framework\TestCase;

class ConnectContextTest extends TestCase
{
    public function bindToDataProvider(): array
    {
        return [
            [null],
            ['127.0.0.1:12345'],
        ];
    }

    /**
     * @dataProvider bindToDataProvider
     */
    public function testWithBindTo($bindTo): void
    {
        $contextA = new ConnectContext();
        $contextB = $contextA->withBindTo($bindTo);
        $contextC = $contextB->withoutBindTo();

        self::assertNull($contextA->getBindTo());
        self::assertSame($bindTo, $contextB->getBindTo());
        self::assertNull($contextC->getBindTo());
    }

    public function testWithTcpNoDelay(): void
    {
        $contextA = new ConnectContext();
        $contextB = $contextA->withTcpNoDelay();
        $contextC = $contextB->withoutTcpNoDelay();

        self::assertFalse($contextA->hasTcpNoDelay());
        self::assertTrue($contextB->hasTcpNoDelay());
        self::assertFalse($contextC->hasTcpNoDelay());
    }

    public function withConnectTimeoutDataProvider(): array
    {
        return [
            [1],
            [12345],
        ];
    }

    /**
     * @dataProvider withConnectTimeoutDataProvider
     */
    public function testWithConnectTimeout($timeout): void
    {
        $context = new ConnectContext();
        $clonedContext = $context->withConnectTimeout($timeout);

        self::assertSame(10000, $context->getConnectTimeout());
        self::assertSame($timeout, $clonedContext->getConnectTimeout());
    }

    public function withConnectTimeoutInvalidTimeoutDataProvider(): array
    {
        return [
            [0],
            [-1],
            [-123456],
        ];
    }

    /**
     * @dataProvider withConnectTimeoutInvalidTimeoutDataProvider
     */
    public function testWithConnectTimeoutInvalidTimeout($timeout): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid connect timeout ({$timeout}), must be greater than 0");
        $context = new ConnectContext();
        $context->withConnectTimeout($timeout);
    }

    public function withMaxAttemptsDataProvider(): array
    {
        return [
            [1],
            [12345],
        ];
    }

    /**
     * @dataProvider withMaxAttemptsDataProvider
     */
    public function testWithMaxAttempts($maxAttempts): void
    {
        $context = new ConnectContext();
        $clonedContext = $context->withMaxAttempts($maxAttempts);

        self::assertSame(2, $context->getMaxAttempts());
        self::assertSame($maxAttempts, $clonedContext->getMaxAttempts());
    }

    public function withMaxAttemptsInvalidTimeoutDataProvider(): array
    {
        return [
            [0],
            [-1],
            [-123456],
        ];
    }

    /**
     * @dataProvider withMaxAttemptsInvalidTimeoutDataProvider
     */
    public function testWithMaxAttemptsInvalidTimeout($maxAttempts): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid max attempts ({$maxAttempts}), must be greater than 0");
        $context = new ConnectContext();
        $context->withMaxAttempts($maxAttempts);
    }

    public function withDnsTypeRestrictionDataProvider(): array
    {
        return [
            [null],
            [Record::AAAA],
            [Record::A],
        ];
    }

    /**
     * @dataProvider withDnsTypeRestrictionDataProvider
     */
    public function testWithDnsTypeRestriction($type): void
    {
        $contextA = new ConnectContext();
        $contextB = $contextA->withDnsTypeRestriction($type);
        $contextC = $contextB->withoutDnsTypeRestriction();

        self::assertNull($contextA->getDnsTypeRestriction());
        self::assertSame($type, $contextB->getDnsTypeRestriction());
        self::assertNull($contextC->getDnsTypeRestriction());
    }

    public function withDnsTypeRestrictionInvalidTypeDataProvider(): array
    {
        return [
            [Record::NS],
            [Record::MX],
        ];
    }

    /**
     * @dataProvider withDnsTypeRestrictionInvalidTypeDataProvider
     */
    public function testWithDnsTypeRestrictionInvalidType($type): void
    {
        $context = new ConnectContext();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Invalid resolver type restriction');

        $context->withDnsTypeRestriction($type);
    }

    public function testToStreamContextArray(): void
    {
        $context = new ConnectContext();
        $clonedContext = $context->withBindTo('127.0.0.1:12345')->withTlsContext(new ClientTlsContext('amphp.org'));

        self::assertSame(['socket' => ['tcp_nodelay' => false]], $context->toStreamContextArray());

        $expected = ['socket' => [
            'tcp_nodelay' => false,
            'bindto' => '127.0.0.1:12345',
        ], 'ssl' => [
            'crypto_method' => ClientTlsContext::TLSv1_0 | ClientTlsContext::TLSv1_1 | ClientTlsContext::TLSv1_2,
            'peer_name' => 'amphp.org',
            'verify_peer' => true,
            'verify_peer_name' => true,
            'verify_depth' => 10,
            'ciphers' => \OPENSSL_DEFAULT_STREAM_CIPHERS,
            'capture_peer_cert' => false,
            'capture_peer_cert_chain' => false,
            'SNI_enabled' => true,
        ]];

        if (Socket\hasTlsSecurityLevelSupport()) {
            $expected['ssl']['security_level'] = 2;
        }

        self::assertSame($expected, $clonedContext->toStreamContextArray());
    }
}
