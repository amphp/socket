<?php

namespace Amp\Socket\Test;

use Amp\Socket\ClientTlsContext;
use PHPUnit\Framework\TestCase;

class ClientTlsContextTest extends TestCase {
    public function minimumVersionDataProvider() {
        return [
            [ClientTlsContext::TLSv1_0],
            [ClientTlsContext::TLSv1_1],
            [ClientTlsContext::TLSv1_2],
        ];
    }

    /**
     * @dataProvider minimumVersionDataProvider
     */
    public function testWithMinimumVersion($version) {
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withMinimumVersion($version);
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertSame(ClientTlsContext::TLSv1_0, $origContext->getMinimumVersion());
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
        $origContext = new ClientTlsContext();
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
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withPeerName($peerName);
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertNull($origContext->getPeerName());
        $this->assertSame($peerName, $clonedContext->getPeerName());
    }

    public function testWithPeerVerification() {
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withPeerVerification();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertTrue($origContext->hasPeerVerification());
        $this->assertTrue($clonedContext->hasPeerVerification());
    }

    public function testWithoutPeerVerification() {
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withoutPeerVerification();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertTrue($origContext->hasPeerVerification());
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
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withVerificationDepth($verifyDepth);
        $this->assertNotSame($origContext, $clonedContext);
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
        $origContext = new ClientTlsContext();
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
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withCiphers($ciphers);
        $this->assertNotSame($origContext, $clonedContext);
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
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withCaFile($caFile);
        $this->assertNotSame($origContext, $clonedContext);
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
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withCaPath($caPath);
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertNull($origContext->getCaPath());
        $this->assertSame($caPath, $clonedContext->getCaPath());
    }

    public function testWithPeerCapturing() {
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withPeerCapturing();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertFalse($origContext->hasPeerCapturing());
        $this->assertTrue($clonedContext->hasPeerCapturing());
    }

    public function testWithoutPeerCapturing() {
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withoutPeerCapturing();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertFalse($origContext->hasPeerCapturing());
        $this->assertFalse($clonedContext->hasPeerCapturing());
    }

    public function testWithSni() {
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withSni();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertTrue($origContext->hasSni());
        $this->assertTrue($clonedContext->hasSni());
    }

    public function testWithoutSni() {
        $origContext = new ClientTlsContext();
        $clonedContext = $origContext->withoutSni();
        $this->assertNotSame($origContext, $clonedContext);
        $this->assertTrue($origContext->hasSni());
        $this->assertFalse($clonedContext->hasSni());
    }
}
