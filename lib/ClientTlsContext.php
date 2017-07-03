<?php

namespace Amp\Socket;

final class ClientTlsContext {
    const TLSv1_0 = \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
    const TLSv1_1 = \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
    const TLSv1_2 = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

    private $minVersion = \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
    private $peerName = null;
    private $verifyPeer = true;
    private $verifyDepth = 10;
    private $ciphers = null;
    private $caFile = null;
    private $caPath = null;
    private $capturePeer = false;
    private $sniEnabled = true;

    public function withMinimumVersion(int $version): self {
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

    public function getPeerName() {
        return $this->peerName;
    }

    public function withPeerVerification(): self {
        $clone = clone $this;
        $clone->verifyPeer = true;

        return $clone;
    }

    public function withoutPeerVerification(): self {
        $clone = clone $this;
        $clone->verifyPeer = false;

        return $clone;
    }

    public function hasPeerVerification(): bool {
        return $this->verifyPeer;
    }

    public function withVerificationDepth(int $verifyDepth): self {
        if ($verifyDepth < 0) {
            throw new \Error("Invalid verification depth ({$verifyDepth}), must be greater than or equal to 0");
        }

        $clone = clone $this;
        $clone->verifyPeer = $verifyDepth;

        return $clone;
    }

    public function getVerificationDepth(): int {
        return $this->verifyDepth;
    }

    public function withCiphers(string $ciphers = null): self {
        $clone = clone $this;
        $clone->ciphers = $ciphers ?? \OPENSSL_DEFAULT_STREAM_CIPHERS;

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

    public function getCaFile() {
        return $this->caFile;
    }

    public function withCaPath(string $capath = null): self {
        $clone = clone $this;
        $clone->caPath = $capath;

        return $clone;
    }

    public function getCaPath() {
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

    public function withSni(): self {
        $clone = clone $this;
        $clone->sniEnabled = true;

        return $clone;
    }

    public function withoutSni(): self {
        $clone = clone $this;
        $clone->sniEnabled = false;

        return $clone;
    }

    public function hasSni(): bool {
        return $this->sniEnabled;
    }

    public function toStreamContextArray(): array {
        $options = [
            "crypto_method" => $this->toStreamCryptoMethod(),
            "peer_name" => $this->peerName,
            "verify_peer" => $this->verifyPeer,
            "verify_peer_name" => $this->verifyPeer,
            "verify_depth" => $this->verifyDepth,
            "ciphers" => $this->ciphers ?? \OPENSSL_DEFAULT_STREAM_CIPHERS,
            "capture_peer_cert" => $this->capturePeer,
            "capture_peer_cert_chain" => $this->capturePeer,
            "SNI_enabled" => $this->sniEnabled,
        ];

        if ($this->caFile !== null) {
            $options["cafile"] = $this->caFile;
        }

        if ($this->caPath !== null) {
            $options["capath"] = $this->caPath;
        }

        return ["ssl" => $options];
    }

    public function toStreamCryptoMethod(): int {
        // -2 to clear client flag and then make all lower versions bits 1
        return (~($this->minVersion - 2) & \STREAM_CRYPTO_METHOD_ANY_CLIENT) | 1;
    }
}
