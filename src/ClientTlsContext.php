<?php

namespace Amp\Socket;

final class ClientTlsContext
{
    public const TLSv1_0 = \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT;
    public const TLSv1_1 = \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
    public const TLSv1_2 = \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    public const TLSv1_3 = \PHP_VERSION_ID >= 70400 ? \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0;

    private const TLS_VERSIONS = \PHP_VERSION_ID >= 70400 ? [
        'TLSv1.0' => self::TLSv1_0,
        'TLSv1.1' => self::TLSv1_1,
        'TLSv1.2' => self::TLSv1_2,
        'TLSv1.3' => self::TLSv1_3,
    ] : [
        'TLSv1.0' => self::TLSv1_0,
        'TLSv1.1' => self::TLSv1_1,
        'TLSv1.2' => self::TLSv1_2,
    ];

    /** @var int */
    private $minVersion = self::TLSv1_0;
    /** @var string|null */
    private $peerName;
    /** @var bool */
    private $verifyPeer = true;
    /** @var int */
    private $verifyDepth = 10;
    /** @var string|null */
    private $ciphers;
    /** @var string|null */
    private $caFile;
    /** @var string|null */
    private $caPath;
    /** @var bool */
    private $capturePeer = false;
    /** @var bool */
    private $sniEnabled = true;
    /** @var int */
    private $securityLevel = 2;
    /** @var Certificate|null */
    private $certificate;
    /** @var string[] */
    private $alpnProtocols = [];

    public function __construct(string $peerName)
    {
        $this->peerName = $peerName;
    }

    /**
     * Minimum TLS version to negotiate.
     *
     * Defaults to TLS 1.0.
     *
     * @param int $version One of the `ClientTlsContext::TLSv*` constants.
     *
     * @return self Cloned, modified instance.
     * @throws \Error If an invalid minimum version is given.
     */
    public function withMinimumVersion(int $version): self
    {
        if (!\in_array($version, self::TLS_VERSIONS, true)) {
            throw new \Error(\sprintf(
                'Invalid minimum version, only %s allowed',
                implode(', ', \array_keys(self::TLS_VERSIONS))
            ));
        }

        $clone = clone $this;
        $clone->minVersion = $version;

        return $clone;
    }

    /**
     * Returns the minimum TLS version to negotiate.
     *
     * @return int
     */
    public function getMinimumVersion(): int
    {
        return $this->minVersion;
    }

    /**
     * Expected name of the peer.
     *
     * @param string $peerName
     *
     * @return self Cloned, modified instance.
     */
    public function withPeerName(string $peerName): self
    {
        $clone = clone $this;
        $clone->peerName = $peerName;

        return $clone;
    }

    /**
     * @return null|string Expected name of the peer or `null` if such an expectation doesn't exist.
     */
    public function getPeerName(): ?string
    {
        return $this->peerName;
    }

    /**
     * Enable peer verification.
     *
     * @return self Cloned, modified instance.
     */
    public function withPeerVerification(): self
    {
        $clone = clone $this;
        $clone->verifyPeer = true;

        return $clone;
    }

    /**
     * Disable peer verification, this is the default for servers.
     *
     * Warning: You usually shouldn't disable this setting for clients, because it allows active MitM attackers to
     * intercept the communication and change it without anyone noticing.
     *
     * @return self Cloned, modified instance.
     */
    public function withoutPeerVerification(): self
    {
        $clone = clone $this;
        $clone->verifyPeer = false;

        return $clone;
    }

    /**
     * @return bool Whether peer verification is enabled.
     */
    public function hasPeerVerification(): bool
    {
        return $this->verifyPeer;
    }

    /**
     * Maximum chain length the peer might present including the certificates in the local trust store.
     *
     * @param int $verifyDepth Maximum length of the certificate chain.
     *
     * @return self Cloned, modified instance.
     */
    public function withVerificationDepth(int $verifyDepth): self
    {
        if ($verifyDepth < 0) {
            throw new \Error("Invalid verification depth ({$verifyDepth}), must be greater than or equal to 0");
        }

        $clone = clone $this;
        $clone->verifyDepth = $verifyDepth;

        return $clone;
    }

    /**
     * @return int Maximum length of the certificate chain.
     */
    public function getVerificationDepth(): int
    {
        return $this->verifyDepth;
    }

    /**
     * List of ciphers to negotiate, the server's order is always preferred.
     *
     * @param string|null $ciphers List of ciphers in OpenSSL's format (colon separated).
     *
     * @return self Cloned, modified instance.
     */
    public function withCiphers(string $ciphers = null): self
    {
        $clone = clone $this;
        $clone->ciphers = $ciphers;

        return $clone;
    }

    /**
     * @return string List of ciphers in OpenSSL's format (colon separated).
     */
    public function getCiphers(): string
    {
        return $this->ciphers ?? \OPENSSL_DEFAULT_STREAM_CIPHERS;
    }

    /**
     * CAFile to check for trusted certificates.
     *
     * @param string|null $cafile Path to the file or `null` to unset.
     *
     * @return self Cloned, modified instance.
     */
    public function withCaFile(string $cafile = null): self
    {
        $clone = clone $this;
        $clone->caFile = $cafile;

        return $clone;
    }

    /**
     * @return null|string Path to the file if one is set, otherwise `null`.
     */
    public function getCaFile(): ?string
    {
        return $this->caFile;
    }

    /**
     * CAPath to check for trusted certificates.
     *
     * @param string|null $capath Path to the file or `null` to unset.
     *
     * @return self Cloned, modified instance.
     */
    public function withCaPath(string $capath = null): self
    {
        $clone = clone $this;
        $clone->caPath = $capath;

        return $clone;
    }

    /**
     * @return null|string Path to the file if one is set, otherwise `null`.
     */
    public function getCaPath(): ?string
    {
        return $this->caPath;
    }

    /**
     * Capture the certificates sent by the peer.
     *
     * Note: This is the chain as sent by the peer, NOT the verified chain.
     *
     * @return self Cloned, modified instance.
     */
    public function withPeerCapturing(): self
    {
        $clone = clone $this;
        $clone->capturePeer = true;

        return $clone;
    }

    /**
     * Don't capture the certificates sent by the peer.
     *
     * @return self Cloned, modified instance.
     */
    public function withoutPeerCapturing(): self
    {
        $clone = clone $this;
        $clone->capturePeer = false;

        return $clone;
    }

    /**
     * @return bool Whether to capture the certificates sent by the peer.
     */
    public function hasPeerCapturing(): bool
    {
        return $this->capturePeer;
    }

    /**
     * Enable SNI.
     *
     * @return self Cloned, modified instance.
     */
    public function withSni(): self
    {
        $clone = clone $this;
        $clone->sniEnabled = true;

        return $clone;
    }

    /**
     * Disable SNI.
     *
     * @return self Cloned, modified instance.
     */
    public function withoutSni(): self
    {
        $clone = clone $this;
        $clone->sniEnabled = false;

        return $clone;
    }

    /**
     * @return bool Whether SNI is enabled or not.
     */
    public function hasSni(): bool
    {
        return $this->sniEnabled;
    }

    /**
     * Security level to use.
     *
     * Requires OpenSSL 1.1.0 or higher.
     *
     * @param int $level Must be between 0 and 5.
     *
     * @return self Cloned, modified instance.
     */
    public function withSecurityLevel(int $level): self
    {
        // See https://www.openssl.org/docs/manmaster/man3/SSL_CTX_set_security_level.html
        // Level 2 is not recommended, because of SHA-1 by that document,
        // but SHA-1 should be phased out now on general internet use.
        // We therefore default to level 2.

        if ($level < 0 || $level > 5) {
            throw new \Error("Invalid security level ({$level}), must be between 0 and 5.");
        }

        if (!hasTlsSecurityLevelSupport()) {
            throw new \Error("Can't set a security level, as PHP is compiled with OpenSSL < 1.1.0.");
        }

        $clone = clone $this;
        $clone->securityLevel = $level;

        return $clone;
    }

    /**
     * @return int Security level between 0 and 5. Always 0 for OpenSSL < 1.1.0.
     */
    public function getSecurityLevel(): int
    {
        // 0 is equivalent to previous versions of OpenSSL and just does nothing
        if (!hasTlsSecurityLevelSupport()) {
            return 0;
        }

        return $this->securityLevel;
    }

    /**
     * Client certificate to use, if key is no present it assumes it is present in the same file as the certificate.
     *
     * @param Certificate $certificate Certificate and private key info
     *
     * @return self Cloned, modified instance.
     */
    public function withCertificate(Certificate $certificate = null): self
    {
        $clone = clone $this;
        $clone->certificate = $certificate;

        return $clone;
    }

    public function getCertificate(): ?Certificate
    {
        return $this->certificate;
    }

    /**
     * @param string[] $protocols
     *
     * @return self Cloned, modified instance.
     */
    public function withApplicationLayerProtocols(array $protocols): self
    {
        if (!hasTlsAlpnSupport()) {
            throw new \Error("Can't set an application layer protocol list, as PHP is compiled with OpenSSL < 1.0.2.");
        }

        foreach ($protocols as $protocol) {
            if (!\is_string($protocol)) {
                throw new \TypeError("Protocol names must be strings");
            }
        }

        $clone = clone $this;
        $clone->alpnProtocols = $protocols;

        return $clone;
    }

    /**
     * @return string[]
     */
    public function getApplicationLayerProtocols(): array
    {
        return $this->alpnProtocols;
    }

    /**
     * Converts this TLS context into PHP's equivalent stream context array.
     *
     * @return array Stream context array compatible with PHP's streams.
     */
    public function toStreamContextArray(): array
    {
        $options = [
            'crypto_method' => $this->toStreamCryptoMethod(),
            'peer_name' => $this->peerName,
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'verify_depth' => $this->verifyDepth,
            'ciphers' => $this->ciphers ?? \OPENSSL_DEFAULT_STREAM_CIPHERS,
            'capture_peer_cert' => $this->capturePeer,
            'capture_peer_cert_chain' => $this->capturePeer,
            'SNI_enabled' => $this->sniEnabled,
        ];

        if ($this->certificate !== null) {
            $options['local_cert'] = $this->certificate->getCertFile();

            if ($this->certificate->getCertFile() !== $this->certificate->getKeyFile()) {
                $options['local_pk'] = $this->certificate->getKeyFile();
            }
        }

        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }

        if ($this->caPath !== null) {
            $options['capath'] = $this->caPath;
        }

        if (hasTlsSecurityLevelSupport()) {
            $options['security_level'] = $this->securityLevel;
        }

        if (!empty($this->alpnProtocols)) {
            $options['alpn_protocols'] = \implode(',', $this->alpnProtocols);
        }

        return ['ssl' => $options];
    }

    /**
     * @return int Crypto method compatible with PHP's streams.
     */
    public function toStreamCryptoMethod(): int
    {
        switch ($this->minVersion) {
            case self::TLSv1_0:
                return self::TLSv1_0 | self::TLSv1_1 | self::TLSv1_2 | self::TLSv1_3;

            case self::TLSv1_1:
                return self::TLSv1_1 | self::TLSv1_2 | self::TLSv1_3;

            case self::TLSv1_2:
                return self::TLSv1_2 | self::TLSv1_3;

            case self::TLSv1_3:
                return self::TLSv1_3;

            default:
                throw new \RuntimeException('Unknown minimum TLS version: ' . $this->minVersion);
        }
    }
}
