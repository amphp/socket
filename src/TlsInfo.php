<?php

namespace Amp\Socket;

use Kelunik\Certificate\Certificate;

/**
 * Exposes a connection's negotiated TLS parameters.
 */
final class TlsInfo
{
    /** @var string */
    private $version;
    /** @var string */
    private $cipherName;
    /** @var int */
    private $cipherBits;
    /** @var string */
    private $cipherVersion;
    /** @var string|null */
    private $alpnProtocol;
    /** @var array|null */
    private $certificates;
    /** @var Certificate[]|null */
    private $parsedCertificates;

    /**
     * Constructs a new instance from a stream socket resource.
     *
     * @param resource $resource Stream socket resource.
     *
     * @return self|null Returns null if TLS is not enabled on the stream socket.
     */
    public static function fromStreamResource($resource): ?self
    {
        if (!\is_resource($resource) || \get_resource_type($resource) !== 'stream') {
            throw new \Error("Expected a valid stream resource");
        }

        $metadata = \stream_get_meta_data($resource)['crypto'] ?? [];
        $tlsContext = \stream_context_get_options($resource)['ssl'] ?? [];

        return empty($metadata) ? null : self::fromMetaData($metadata, $tlsContext);
    }

    /**
     * Constructs a new instance from PHP's internal info.
     *
     * Always pass the info as obtained from PHP as this method might extract additional fields in the future.
     *
     * @param array $cryptoInfo Crypto info obtained via `stream_get_meta_data($socket->getResource())["crypto"]`.
     * @param array $tlsContext Context obtained via `stream_context_get_options($socket->getResource())["ssl"])`.
     *
     * @return self
     */
    public static function fromMetaData(array $cryptoInfo, array $tlsContext): self
    {
        if (isset($tlsContext["peer_certificate"])) {
            $certificates = \array_merge([$tlsContext["peer_certificate"]], $tlsContext["peer_certificate_chain"] ?? []);
        } else {
            $certificates = $tlsContext["peer_certificate_chain"] ?? [];
        }

        return new self(
            $cryptoInfo["protocol"],
            $cryptoInfo["cipher_name"],
            $cryptoInfo["cipher_bits"],
            $cryptoInfo["cipher_version"],
            $cryptoInfo["alpn_protocol"] ?? null,
            empty($certificates) ? null : $certificates
        );
    }

    private function __construct(string $version, string $cipherName, int $cipherBits, string $cipherVersion, ?string $alpnProtocol, ?array $certificates)
    {
        $this->version = $version;
        $this->cipherName = $cipherName;
        $this->cipherBits = $cipherBits;
        $this->cipherVersion = $cipherVersion;
        $this->alpnProtocol = $alpnProtocol;
        $this->certificates = $certificates;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getCipherName(): string
    {
        return $this->cipherName;
    }

    public function getCipherBits(): int
    {
        return $this->cipherBits;
    }

    public function getCipherVersion(): string
    {
        return $this->cipherVersion;
    }

    public function getApplicationLayerProtocol(): ?string
    {
        return $this->alpnProtocol;
    }

    /**
     * @return Certificate[]
     *
     * @throws SocketException If peer certificates were not captured.
     */
    public function getPeerCertificates(): array
    {
        if ($this->certificates === null) {
            throw new SocketException("Peer certificates not captured; use ClientTlsContext::withPeerCapturing() to capture peer certificates");
        }

        if ($this->parsedCertificates === null) {
            $this->parsedCertificates = \array_map(static function ($resource) {
                return new Certificate($resource);
            }, $this->certificates);
        }

        return $this->parsedCertificates;
    }
}
