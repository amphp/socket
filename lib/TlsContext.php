<?php

namespace Amp\Socket;

final class TlsContext {
    const TLSv1_0 = \STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
    const TLSv1_1 = \STREAM_CRYPTO_METHOD_TLSv1_1_SERVER;
    const TLSv1_2 = \STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;

    const SERVER = 0;
    const CLIENT = 1;

    private $minVersion = \STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
    private $peerName = null;
    private $verifyPeer = true;
    private $verifyDepth = 10;
    private $ciphers = null;
    private $caFile = null;
    private $caPath = null;
    private $capturePeer = false;
    private $sniEnabled = true;

    public function withMinimumVersion(int $version): self {
        $version = $version >> 1 << 1; /* clear client flag */

        if ($version !== self::TLSv1_0 && $version !== self::TLSv1_1 && $version !== self::TLSv1_2) {
            throw new \Error("Invalid minimum version, only TLSv1.0, TLSv1.1 or TLSv1.2 allowed");
        }

        $clone = clone $this;
        $clone->minVersion = $version;

        return $clone;
    }

    public function getMinimumVersion(): int {
        return $this->minVersion;
    }

    public function withPeerName(string $peerName = null): self {
        $clone = clone $this;
        $clone->peerName = $peerName;

        return $clone;
    }

    public function getPeerName() /* : ?string */ {
        return $this->peerName;
    }

    public function withPeerVerificationEnabled(): self {
        $clone = clone $this;
        $clone->verifyPeer = true;

        return $clone;
    }

    public function withPeerVerificationDisabled(): self {
        $clone = clone $this;
        $clone->verifyPeer = false;

        return $clone;
    }

    public function hasPeerVerificationEnabled(): bool {
        return $this->verifyPeer;
    }

    public function withVerificationDepth(int $verifyDepth): self {
        $clone = clone $this;
        $clone->verifyPeer = $verifyDepth;

        return $clone;
    }

    public function getVerificationDepth(): int {
        return $this->verifyDepth;
    }

    public function withCiphers(string $ciphers = null): self {
        $clone = clone $this;
        $clone->ciphers = $ciphers ?: \OPENSSL_DEFAULT_STREAM_CIPHERS;

        return $clone;
    }

    public function getCiphers(): string {
        return $this->ciphers;
    }

    public function withCaFile(string $cafile = null): self {
        $clone = clone $this;
        $clone->caFile = $cafile;

        return $clone;
    }

    public function getCaFile() /* : ?string */ {
        return $this->caFile;
    }

    public function withCaPath(string $capath = null): self {
        $clone = clone $this;
        $clone->caPath = $capath;

        return $clone;
    }

    public function getCaPath() /* : ?string */ {
        return $this->caPath;
    }

    public function withPeerCapturing(): self {
        $clone = clone $this;
        $clone->capturePeer = true;

        return $clone;
    }

    public function withoutPeerCapturing(): self {
        $clone = clone $this;
        $clone->capturePeer = false;

        return $clone;
    }

    public function hasPeerCapturing(): bool {
        return $this->capturePeer;
    }

    public function withSniEnabled(): self {
        $clone = clone $this;
        $clone->sniEnabled = true;

        return $clone;
    }

    public function withSniDisabled(): self {
        $clone = clone $this;
        $clone->capturePeer = false;

        return $clone;
    }

    public function hasSniEnabled(): bool {
        return $this->sniEnabled;
    }

    public function toStreamContextArray(int $isClient): array {
        if ($isClient !== 0 && $isClient !== 1) {
            throw new \Error("Invalid isClient parameter value, only 0 and 1 allowed");
        }

        return ["ssl" => [
            "crypto_method" => $this->toStreamCryptoMethod($isClient),
            "peer_name" => $this->peerName,
            "verify_peer" => $this->verifyPeer,
            "verify_peer_name" => $this->verifyPeer,
            "verify_depth" => $this->verifyDepth,
            "cafile" => $this->caFile,
            "capath" => $this->caPath,
            "ciphers" => $this->ciphers ?: \OPENSSL_DEFAULT_STREAM_CIPHERS,
            "honor_cipher_order" => true,
            "single_dh_use" => true,
            "capture_peer_cert" => $this->capturePeer,
            "capture_peer_chain" => $this->capturePeer,
            "SNI_enabled" => $this->sniEnabled,
        ]];
    }

    public function toStreamCryptoMethod(int $isClient): int {
        return (~($this->minVersion - 1) & \STREAM_CRYPTO_METHOD_ANY_SERVER) | $isClient;
    }
}
