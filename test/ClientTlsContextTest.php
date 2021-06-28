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
            [ClientTlsContext::TLSv1_3],
        ];
    }

    /**
     * @dataProvider minimumVersionDataProvider
     *
     * @param int $version
     */
    public function testWithMinimumVersion($version): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withMinimumVersion($version);

        $this->assertSame(ClientTlsContext::TLSv1_0, $context->getMinimumVersion());
        $this->assertSame($version, $clonedContext->getMinimumVersion());
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
    public function testWithMinimumVersionInvalid($version): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Invalid minimum version');

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
    public function testWithPeerName($peerName): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withPeerName($peerName);

        $this->assertSame('', $context->getPeerName());
        $this->assertSame($peerName, $clonedContext->getPeerName());
    }

    public function testWithPeerVerification(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withPeerVerification();

        $this->assertTrue($context->hasPeerVerification());
        $this->assertTrue($clonedContext->hasPeerVerification());
    }

    public function testWithoutPeerVerification(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withoutPeerVerification();

        $this->assertTrue($context->hasPeerVerification());
        $this->assertFalse($clonedContext->hasPeerVerification());
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
     * @param Certificate $certificate
     */
    public function testWithCertificate($certificate): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCertificate($certificate);

        $this->assertNull($context->getCertificate());
        $this->assertSame($certificate, $clonedContext->getCertificate());
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
    public function testWithVerificationDepth($verifyDepth): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withVerificationDepth($verifyDepth);

        $this->assertSame(10, $context->getVerificationDepth());
        $this->assertSame($verifyDepth, $clonedContext->getVerificationDepth());
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
    public function testWithVerificationDepthInvalid($verifyDepth): void
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
    public function testWithCiphers($ciphers): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCiphers($ciphers);

        $this->assertSame(\OPENSSL_DEFAULT_STREAM_CIPHERS, $context->getCiphers());
        $this->assertSame($ciphers, $clonedContext->getCiphers());
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
     * @param string $caFile
     */
    public function testWithCaFile($caFile): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCaFile($caFile);

        $this->assertNull($context->getCaFile());
        $this->assertSame($caFile, $clonedContext->getCaFile());
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
     * @param string $caPath
     */
    public function testWithCaPath($caPath): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withCaPath($caPath);

        $this->assertNull($context->getCaPath());
        $this->assertSame($caPath, $clonedContext->getCaPath());
    }

    public function testWithPeerCapturing(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withPeerCapturing();

        $this->assertFalse($context->hasPeerCapturing());
        $this->assertTrue($clonedContext->hasPeerCapturing());
    }

    public function testWithoutPeerCapturing(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withoutPeerCapturing();

        $this->assertFalse($context->hasPeerCapturing());
        $this->assertFalse($clonedContext->hasPeerCapturing());
    }

    public function testWithSni(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withSni();

        $this->assertTrue($context->hasSni());
        $this->assertTrue($clonedContext->hasSni());
    }

    public function testWithoutSni(): void
    {
        $context = new ClientTlsContext('');
        $clonedContext = $context->withoutSni();

        $this->assertTrue($context->hasSni());
        $this->assertFalse($clonedContext->hasSni());
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
    public function testWithSecurityLevelInvalid($level): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid security level ({$level}), must be between 0 and 5.");

        (new ClientTlsContext(''))->withSecurityLevel($level);
    }

    public function testWithSecurityLevel(): void
    {
        if (!Socket\hasTlsSecurityLevelSupport()) {
            $this->markTestSkipped('OpenSSL 1.1.0 required');
        }

        $contextA = new ClientTlsContext('');
        $contextB = $contextA->withSecurityLevel(4);

        $this->assertSame(2, $contextA->getSecurityLevel());
        $this->assertSame(4, $contextB->getSecurityLevel());
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
    public function testWithSecurityLevelValid($level): void
    {
        if (Socket\hasTlsSecurityLevelSupport()) {
            $value = (new ClientTlsContext(''))
                ->withSecurityLevel($level)
                ->getSecurityLevel();

            $this->assertSame($level, $value);
        } else {
            $this->expectException(\Error::class);
            $this->expectExceptionMessage("Can't set a security level, as PHP is compiled with OpenSSL < 1.1.0.");

            (new ClientTlsContext(''))->withSecurityLevel($level);
        }
    }

    public function testWithSecurityLevelDefaultValue(): void
    {
        if (\OPENSSL_VERSION_NUMBER >= 0x10100000) {
            $this->assertSame(2, (new ClientTlsContext(''))->getSecurityLevel());
        } else {
            $this->assertSame(0, (new ClientTlsContext(''))->getSecurityLevel());
        }
    }

    public function testWithApplicationLayerProtocols(): void
    {
        if (!Socket\hasTlsAlpnSupport()) {
            $this->markTestSkipped('OpenSSL 1.0.2 required');
        }

        $contextA = new ClientTlsContext('');
        $contextB = $contextA->withApplicationLayerProtocols(['http/1.1', 'h2']);

        $this->assertSame([], $contextA->getApplicationLayerProtocols());
        $this->assertSame(['http/1.1', 'h2'], $contextB->getApplicationLayerProtocols());
    }

    public function testWithInvalidApplicationLayerProtocols(): void
    {
        if (!Socket\hasTlsAlpnSupport()) {
            $this->markTestSkipped('OpenSSL 1.0.2 required');
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

        $this->assertSame(['ssl' => [
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
        ]], $contextArray);
    }
}
