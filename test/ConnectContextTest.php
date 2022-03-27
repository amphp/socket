<?php

namespace Amp\Socket;

use Amp\Dns\Record;
use Amp\Socket;
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
            [1.0],
            [123.45],
        ];
    }

    /**
     * @dataProvider withConnectTimeoutDataProvider
     */
    public function testWithConnectTimeout($timeout): void
    {
        $context = new ConnectContext();
        $clonedContext = $context->withConnectTimeout($timeout);

        self::assertEquals(10.0, $context->getConnectTimeout());
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

        $expected = [
            'socket' => [
                'tcp_nodelay' => false,
                'bindto' => '127.0.0.1:12345',
            ],
            'ssl' => [
                'crypto_method' => ClientTlsContext::TLSv1_2 | ClientTlsContext::TLSv1_3,
                'peer_name' => 'amphp.org',
                'verify_peer' => true,
                'verify_peer_name' => true,
                'verify_depth' => 10,
                'ciphers' => \OPENSSL_DEFAULT_STREAM_CIPHERS,
                'capture_peer_cert' => false,
                'capture_peer_cert_chain' => false,
                'SNI_enabled' => true,
            ],
        ];

        if (Socket\hasTlsSecurityLevelSupport()) {
            $expected['ssl']['security_level'] = 2;
        }

        self::assertSame($expected, $clonedContext->toStreamContextArray());
    }
}
