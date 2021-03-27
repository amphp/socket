<?php

namespace Amp\Socket\Test;

use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use PHPUnit\Framework\TestCase;

class ClientTlsContextTest extends TestCase
{
    public function minimumVersionDataProvider(): array
    {
        return [
            [ClientTlsContext::TLSv1_0],
            [ClientTlsContext::TLSv1_1],
            [ClientTlsContext::TLSv1_2],
        ];
    }

    /**
     * @dataProvider minimumVersionDataProvider
     *
     * @param int $version
     */
    public function testWithMinimumVersion(int $version): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withMinimumVersion($version);

        self::assertSame(ClientTlsContext::TLSv1_0, $context->getMinimumVersion());
        self::assertSame($version, $clonedContext->getMinimumVersion());
    }

    public function minimumVersionInvalidDataProvider(): array
    {
        return [
            [-1],
        ];
    }

    /**
     * @dataProvider minimumVersionInvalidDataProvider
     *
     * @param int $version
     */
    public function testWithMinimumVersionInvalid(int $version): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Invalid minimum version, only TLSv1.0, TLSv1.1 or TLSv1.2 allowed');

        (new ClientTlsContext(''))->withMinimumVersion($version);
    }

    public function peerNameDataProvider(): array
    {
        return [
            ['127.0.0.1'],
            ['test'],
        ];
    }

    /**
     * @dataProvider peerNameDataProvider
     *
     * @param string $peerName
     */
    public function testWithPeerName(string $peerName): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withPeerName($peerName);

        self::assertSame('', $context->getPeerName());
        self::assertSame($peerName, $clonedContext->getPeerName());
    }

    public function testWithPeerVerification(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withPeerVerification();

        self::assertTrue($context->hasPeerVerification());
        self::assertTrue($clonedContext->hasPeerVerification());
    }

    public function testWithoutPeerVerification(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withoutPeerVerification();

        self::assertTrue($context->hasPeerVerification());
        self::assertFalse($clonedContext->hasPeerVerification());
    }

    public function certificateDataProvider(): array
    {
        return [
            [null],
            [new Certificate('cert.pem')],
        ];
    }

    /**
     * @dataProvider certificateDataProvider
     *
     * @param Certificate|null $certificate
     */
    public function testWithCertificate(?Certificate $certificate): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCertificate($certificate);

        self::assertNull($context->getCertificate());
        self::assertSame($certificate, $clonedContext->getCertificate());
    }

    public function verifyDepthDataProvider(): array
    {
        return [
            [0],
            [123],
        ];
    }

    /**
     * @dataProvider verifyDepthDataProvider
     *
     * @param int $verifyDepth
     */
    public function testWithVerificationDepth(int $verifyDepth): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withVerificationDepth($verifyDepth);

        self::assertSame(10, $context->getVerificationDepth());
        self::assertSame($verifyDepth, $clonedContext->getVerificationDepth());
    }

    public function verifyDepthInvalidDataProvider(): array
    {
        return [
            [-1],
            [-123],
        ];
    }

    /**
     * @dataProvider verifyDepthInvalidDataProvider
     *
     * @param int $verifyDepth
     */
    public function testWithVerificationDepthInvalid(int $verifyDepth): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Invalid verification depth (.*), must be greater than or equal to 0/');

        (new ClientTlsContext(''))->withVerificationDepth($verifyDepth);
    }

    public function ciphersDataProvider(): array
    {
        return [
            ['ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256'],
            ['DHE-DSS-AES128-GCM-SHA256:kEDH+AESGCM:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA256'],
        ];
    }

    /**
     * @dataProvider ciphersDataProvider
     *
     * @param string $ciphers
     */
    public function testWithCiphers(string $ciphers): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCiphers($ciphers);

        self::assertSame(\OPENSSL_DEFAULT_STREAM_CIPHERS, $context->getCiphers());
        self::assertSame($ciphers, $clonedContext->getCiphers());
    }

    public function caFileDataProvider(): array
    {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider caFileDataProvider
     *
     * @param string|null $caFile
     */
    public function testWithCaFile(?string $caFile): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCaFile($caFile);

        self::assertNull($context->getCaFile());
        self::assertSame($caFile, $clonedContext->getCaFile());
    }

    public function caPathDataProvider(): array
    {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider caPathDataProvider
     *
     * @param string|null $caPath
     */
    public function testWithCaPath(?string $caPath): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCaPath($caPath);

        self::assertNull($context->getCaPath());
        self::assertSame($caPath, $clonedContext->getCaPath());
    }

    public function testWithPeerCapturing(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withPeerCapturing();

        self::assertFalse($context->hasPeerCapturing());
        self::assertTrue($clonedContext->hasPeerCapturing());
    }

    public function testWithoutPeerCapturing(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withoutPeerCapturing();

        self::assertFalse($context->hasPeerCapturing());
        self::assertFalse($clonedContext->hasPeerCapturing());
    }

    public function testWithSni(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withSni();

        self::assertTrue($context->hasSni());
        self::assertTrue($clonedContext->hasSni());
    }

    public function testWithoutSni(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withoutSni();

        self::assertTrue($context->hasSni());
        self::assertFalse($clonedContext->hasSni());
    }

    public function invalidSecurityLevelDataProvider(): array
    {
        return [
            [-1],
            [6],
        ];
    }

    /**
     * @dataProvider invalidSecurityLevelDataProvider
     *
     * @param int $level
     */
    public function testWithSecurityLevelInvalid(int $level): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid security level ({$level}), must be between 0 and 5.");

        (new ClientTlsContext(''))->withSecurityLevel($level);
    }

    public function testWithSecurityLevel(): void
    {
        if (!Socket\hasTlsSecurityLevelSupport()) {
            self::markTestSkipped('OpenSSL 1.1.0 required');
        }

        $contextA = new ClientTlsContext('');
        $contextB = $contextA->withSecurityLevel(4);

        self::assertSame(2, $contextA->getSecurityLevel());
        self::assertSame(4, $contextB->getSecurityLevel());
    }

    public function validSecurityLevelDataProvider(): array
    {
        return [
            [0],
            [1],
            [2],
            [3],
            [4],
            [5],
        ];
    }

    /**
     * @dataProvider validSecurityLevelDataProvider
     *
     * @param int $level Security level
     */
    public function testWithSecurityLevelValid(int $level): void
    {
        if (Socket\hasTlsSecurityLevelSupport()) {
            $value = (new ClientTlsContext(''))
                ->withSecurityLevel($level)
                ->getSecurityLevel();

            self::assertSame($level, $value);
        } else {
            $this->expectException(\Error::class);
            $this->expectExceptionMessage("Can't set a security level, as PHP is compiled with OpenSSL < 1.1.0.");

            (new ClientTlsContext(''))->withSecurityLevel($level);
        }
    }

    public function testWithSecurityLevelDefaultValue(): void
    {
        if (\OPENSSL_VERSION_NUMBER >= 0x10100000) {
            self::assertSame(2, (new ClientTlsContext(''))->getSecurityLevel());
        } else {
            self::assertSame(0, (new ClientTlsContext(''))->getSecurityLevel());
        }
    }

    public function testWithApplicationLayerProtocols(): void
    {
        if (!Socket\hasTlsAlpnSupport()) {
            self::markTestSkipped('OpenSSL 1.0.2 required');
        }

        $contextA = new ClientTlsContext('');
        $contextB = $contextA->withApplicationLayerProtocols(['http/1.1', 'h2']);

        self::assertSame([], $contextA->getApplicationLayerProtocols());
        self::assertSame(['http/1.1', 'h2'], $contextB->getApplicationLayerProtocols());
    }

    public function testWithInvalidApplicationLayerProtocols(): void
    {
        if (!Socket\hasTlsAlpnSupport()) {
            self::markTestSkipped('OpenSSL 1.0.2 required');
        }

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Protocol names must be strings');

        $context = new ClientTlsContext('');
        $context->withApplicationLayerProtocols([1, 2]);
    }

    public function testStreamContextArray(): void
    {
        $context = (new ClientTlsContext(''))
            ->withCaPath('/var/foobar');

        $contextArray = $context->toStreamContextArray();
        unset($contextArray['ssl']['security_level']); // present depending on OpenSSL version

        self::assertSame([
            'ssl' => [
                'crypto_method' => $context->toStreamCryptoMethod(),
                'peer_name' => $context->getPeerName(),
                'verify_peer' => $context->hasPeerVerification(),
                'verify_peer_name' => $context->hasPeerVerification(),
                'verify_depth' => $context->getVerificationDepth(),
                'ciphers' => $context->getCiphers(),
                'capture_peer_cert' => $context->hasPeerCapturing(),
                'capture_peer_cert_chain' => $context->hasPeerCapturing(),
                'SNI_enabled' => $context->hasSni(),
                'capath' => $context->getCaPath(),
            ],
        ], $contextArray);
    }
}
