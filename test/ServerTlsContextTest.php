<?php

namespace Amp\Socket\Test;

use Amp\Socket\Certificate;
use Amp\Socket\ServerTlsContext;
use PHPUnit\Framework\TestCase;

class ServerTlsContextTest extends TestCase {
    public function minimumVersionDataProvider() {
        return [
            [ServerTlsContext::TLSv1_0],
            [ServerTlsContext::TLSv1_1],
            [ServerTlsContext::TLSv1_2],
        ];
    }

    /**
     * @dataProvider minimumVersionDataProvider
     */
    public function testWithMinimumVersion($version) {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withMinimumVersion($version);
        $this->assertSame(ServerTlsContext::TLSv1_0, $origContext->getMinimumVersion());
        $this->assertSame($version, $clonedContext->getMinimumVersion());
    }

    public function minimumVersionInvalidDataProvider() {
        return [
            [-1],
        ];
    }

    /**
     * @dataProvider minimumVersionInvalidDataProvider
     * @expectedException \Error
     * @expectedExceptionMessage Invalid minimum version, only TLSv1.0, TLSv1.1 or TLSv1.2 allowed
     */
    public function testWithMinimumVersionInvalid($version) {
        $origContext = new ServerTlsContext();
        $origContext->withMinimumVersion($version);
    }

    public function peerNameDataProvider() {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider peerNameDataProvider
     */
    public function testWithPeerName($peerName) {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withPeerName($peerName);
        $this->assertNull($origContext->getPeerName());
        $this->assertSame($peerName, $clonedContext->getPeerName());
    }

    public function testWithPeerVerification() {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withPeerVerification();
        $this->assertFalse($origContext->hasPeerVerification());
        $this->assertTrue($clonedContext->hasPeerVerification());
    }

    public function testWithoutPeerVerification() {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withoutPeerVerification();
        $this->assertFalse($origContext->hasPeerVerification());
        $this->assertFalse($clonedContext->hasPeerVerification());
    }

    public function verifyDepthDataProvider() {
        return [
            [0],
            [123],
        ];
    }

    /**
     * @dataProvider verifyDepthDataProvider
     */
    public function testWithVerificationDepth($verifyDepth) {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withVerificationDepth($verifyDepth);
        $this->assertSame(10, $origContext->getVerificationDepth());
        $this->assertSame($verifyDepth, $clonedContext->getVerificationDepth());
    }

    public function verifyDepthInvalidDataProvider() {
        return [
            [-1],
            [-123],
        ];
    }

    /**
     * @dataProvider verifyDepthInvalidDataProvider
     * @expectedException \Error
     * @expectedExceptionMessageRegExp /Invalid verification depth (.*), must be greater than or equal to 0/
     */
    public function testWithVerificationDepthInvalid($verifyDepth) {
        $origContext = new ServerTlsContext();
        $origContext->withVerificationDepth($verifyDepth);
    }

    public function ciphersDataProvider() {
        return [
            ['ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256'],
            ['DHE-DSS-AES128-GCM-SHA256:kEDH+AESGCM:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA256'],
        ];
    }

    /**
     * @dataProvider ciphersDataProvider
     */
    public function testWithCiphers($ciphers) {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withCiphers($ciphers);
        $this->assertSame(\OPENSSL_DEFAULT_STREAM_CIPHERS, $origContext->getCiphers());
        $this->assertSame($ciphers, $clonedContext->getCiphers());
    }

    public function caFileDataProvider() {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider caFileDataProvider
     */
    public function testWithCaFile($caFile) {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withCaFile($caFile);
        $this->assertNull($origContext->getCaFile());
        $this->assertSame($caFile, $clonedContext->getCaFile());
    }

    public function caPathDataProvider() {
        return [
            [null],
            ['test'],
        ];
    }

    /**
     * @dataProvider caPathDataProvider
     */
    public function testWithCaPath($caPath) {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withCaPath($caPath);
        $this->assertNull($origContext->getCaPath());
        $this->assertSame($caPath, $clonedContext->getCaPath());
    }

    public function testWithPeerCapturing() {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withPeerCapturing();
        $this->assertFalse($origContext->hasPeerCapturing());
        $this->assertTrue($clonedContext->hasPeerCapturing());
    }

    public function testWithoutPeerCapturing() {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withoutPeerCapturing();
        $this->assertFalse($origContext->hasPeerCapturing());
        $this->assertFalse($clonedContext->hasPeerCapturing());
    }

    public function defaultCertificateDataProvider() {
        return [
            [null],
            [new Certificate('test')]
        ];
    }

    /**
     * @dataProvider defaultCertificateDataProvider
     */
    public function testWithDefaultCertificate($defaultCertificate) {
        $origContext = new ServerTlsContext();
        $clonedContext = $origContext->withDefaultCertificate($defaultCertificate);
        $this->assertNull($origContext->getDefaultCertificate());
        $this->assertSame($defaultCertificate, $clonedContext->getDefaultCertificate());
    }
}
