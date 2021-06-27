<?php

namespace Amp\Socket\Test;

use Amp\Socket;
use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use PHPUnit\Framework\TestCase;

class ServerTlsContextTest extends TestCase
{
    public function minimumVersionDataProvider()
    {
        return [
            [ServerTlsContext::TLSv1_0],
            [ServerTlsContext::TLSv1_1],
            [ServerTlsContext::TLSv1_2],
            [ServerTlsContext::TLSv1_3],
        ];
    }

    /**
     * @dataProvider minimumVersionDataProvider
     */
    public function testWithMinimumVersion($version)
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withMinimumVersion($version);

        $this->assertSame(ServerTlsContext::TLSv1_2, $context->getMinimumVersion());
        $this->assertSame($version, $clonedContext->getMinimumVersion());
    }

    public function minimumVersionInvalidDataProvider()
    {
        return [
            [-1],
        ];
    }

    /**
     * @dataProvider minimumVersionInvalidDataProvider
     */
    public function testWithMinimumVersionInvalid($version)
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Invalid minimum version');

        (new ServerTlsContext)->withMinimumVersion($version);
    }

    public function peerNameDataProvider()
    {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider peerNameDataProvider
     */
    public function testWithPeerName($peerName)
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withPeerName($peerName);

        $this->assertNull($context->getPeerName());
        $this->assertSame($peerName, $clonedContext->getPeerName());
    }

    public function testWithPeerVerification()
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withPeerVerification();

        $this->assertFalse($context->hasPeerVerification());
        $this->assertTrue($clonedContext->hasPeerVerification());
    }

    public function testWithoutPeerVerification()
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withoutPeerVerification();

        $this->assertFalse($context->hasPeerVerification());
        $this->assertFalse($clonedContext->hasPeerVerification());
    }

    public function verifyDepthDataProvider()
    {
        return [
            [0],
            [123],
        ];
    }

    /**
     * @dataProvider verifyDepthDataProvider
     */
    public function testWithVerificationDepth($verifyDepth)
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withVerificationDepth($verifyDepth);

        $this->assertSame(10, $context->getVerificationDepth());
        $this->assertSame($verifyDepth, $clonedContext->getVerificationDepth());
    }

    public function verifyDepthInvalidDataProvider()
    {
        return [
            [-1],
            [-123],
        ];
    }

    /**
     * @dataProvider verifyDepthInvalidDataProvider
     */
    public function testWithVerificationDepthInvalid($verifyDepth)
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessageMatches('/Invalid verification depth (.*), must be greater than or equal to 0/');

        (new ServerTlsContext)->withVerificationDepth($verifyDepth);
    }

    public function ciphersDataProvider()
    {
        return [
            ['ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256'],
            ['DHE-DSS-AES128-GCM-SHA256:kEDH+AESGCM:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA256'],
        ];
    }

    /**
     * @dataProvider ciphersDataProvider
     */
    public function testWithCiphers($ciphers)
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withCiphers($ciphers);

        $this->assertSame(\OPENSSL_DEFAULT_STREAM_CIPHERS, $context->getCiphers());
        $this->assertSame($ciphers, $clonedContext->getCiphers());
    }

    public function caFileDataProvider()
    {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider caFileDataProvider
     */
    public function testWithCaFile($caFile)
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withCaFile($caFile);

        $this->assertNull($context->getCaFile());
        $this->assertSame($caFile, $clonedContext->getCaFile());
    }

    public function caPathDataProvider()
    {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider caPathDataProvider
     */
    public function testWithCaPath($caPath)
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withCaPath($caPath);

        $this->assertNull($context->getCaPath());
        $this->assertSame($caPath, $clonedContext->getCaPath());
    }

    public function testWithPeerCapturing()
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withPeerCapturing();

        $this->assertFalse($context->hasPeerCapturing());
        $this->assertTrue($clonedContext->hasPeerCapturing());
    }

    public function testWithoutPeerCapturing()
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withoutPeerCapturing();

        $this->assertFalse($context->hasPeerCapturing());
        $this->assertFalse($clonedContext->hasPeerCapturing());
    }

    public function defaultCertificateDataProvider()
    {
        return [
            [null],
            [new Certificate('test')],
        ];
    }

    /**
     * @dataProvider defaultCertificateDataProvider
     */
    public function testWithDefaultCertificate($defaultCertificate)
    {
        $context = new ServerTlsContext;
        $clonedContext = $context->withDefaultCertificate($defaultCertificate);

        $this->assertNull($context->getDefaultCertificate());
        $this->assertSame($defaultCertificate, $clonedContext->getDefaultCertificate());
    }

    public function testWithCertificatesErrorWithoutStringKeys()
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage("Expected an array mapping domain names to Certificate instances");

        (new ServerTlsContext)->withCertificates([new Certificate("/foo/bar")]);
    }

    public function testWithCertificatesErrorWithoutCertificateInstances()
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage("Expected an array of Certificate instances");

        (new ServerTlsContext)->withCertificates(["example.com" => "/foo/bar"]);
    }

    public function testWithCertificatesWithDifferentPathsBeforePhp72()
    {
        if (\PHP_VERSION_ID >= 70200) {
            $this->markTestSkipped("Only relevant in versions lower to PHP 7.2");
        }

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Different files for cert and key are not supported on this version of PHP. Please upgrade to PHP 7.2 or later.");

        (new ServerTlsContext)->withCertificates(["example.com" => new Certificate("/var/foo", "/foo/bar")]);
    }

    public function invalidSecurityLevelDataProvider()
    {
        return [
            [-1],
            [6],
        ];
    }

    /**
     * @dataProvider invalidSecurityLevelDataProvider
     */
    public function testWithSecurityLevelInvalid($level)
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Invalid security level ({$level}), must be between 0 and 5.");

        (new ServerTlsContext)->withSecurityLevel($level);
    }

    public function validSecurityLevelDataProvider()
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
     */
    public function testWithSecurityLevelValid($level)
    {
        if (\OPENSSL_VERSION_NUMBER >= 0x10100000) {
            $value = (new ServerTlsContext)
                ->withSecurityLevel($level)
                ->getSecurityLevel();

            $this->assertSame($level, $value);
        } else {
            $this->expectException(\Error::class);
            $this->expectExceptionMessage("Can't set a security level, as PHP is compiled with OpenSSL < 1.1.0.");

            (new ServerTlsContext)->withSecurityLevel($level);
        }
    }

    public function testWithSecurityLevelDefaultValue()
    {
        if (\OPENSSL_VERSION_NUMBER >= 0x10100000) {
            $this->assertSame(2, (new ServerTlsContext)->getSecurityLevel());
        } else {
            $this->assertSame(0, (new ServerTlsContext)->getSecurityLevel());
        }
    }

    public function testWithApplicationLayerProtocols(): void
    {
        if (!Socket\hasTlsAlpnSupport()) {
            $this->markTestSkipped('OpenSSL 1.0.2 required');
        }

        $contextA = new ServerTlsContext;
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

        $context = new ServerTlsContext;
        $context->withApplicationLayerProtocols([1, 2]);
    }
}
