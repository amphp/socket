<?php

namespace Amp\Socket;

final class SocketAddress
{
    /** @var string */
    private $host;

    /** @var int|null */
    private $port;

    /**
     * @param resource $resource
     *
     * @return self
     */
    public static function fromPeerResource($resource): self
    {
        $name = @\stream_socket_get_name($resource, true);

        /** @psalm-suppress TypeDoesNotContainType */
        if ($name === false || $name === "\0") {
            return self::fromLocalResource($resource);
        }

        return self::fromSocketName($name);
    }

    /**
     * @param resource $resource
     *
     * @return self
     */
    public static function fromLocalResource($resource): self
    {
        $wantPeer = false;

        do {
            $name = @\stream_socket_get_name($resource, $wantPeer);

            /** @psalm-suppress RedundantCondition */
            if ($name !== false && $name !== "\0") {
                return self::fromSocketName($name);
            }
        } while ($wantPeer = !$wantPeer);

        return new self('');
    }

    /**
     * @param string $name
     *
     * @return self
     */
    public static function fromSocketName(string $name): self
    {
        if ($portStartPos = \strrpos($name, ':')) {
            $host = \substr($name, 0, $portStartPos);
            $port = (int) \substr($name, $portStartPos + 1);
            return new self($host, $port);
        }

        return new self($name);
    }

    /**
     * @param string   $host
     * @param int|null $port
     */
    public function __construct(string $host, ?int $port = null)
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \Error('Port number must be null or an integer between 1 and 65535');
        }

        if (\strrpos($host, ':')) {
            $host = \trim($host, '[]');
        }

        $this->host = $host;
        $this->port = $port;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * @return string host:port formatted string.
     */
    public function toString(): string
    {
        $host = $this->host;

        if (\strrpos($host, ':')) {
            $host = '[' . $host . ']';
        }

        if ($this->port === null) {
            return $host;
        }

        return $host . ':' . $this->port;
    }

    /**
     * @see toString
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
