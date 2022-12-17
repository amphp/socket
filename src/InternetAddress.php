<?php declare(strict_types=1);

namespace Amp\Socket;

final class InternetAddress implements SocketAddress
{
    /**
     * @throws SocketException
     */
    public static function fromString(string $address): self
    {
        $colon = \strrpos($address, ':');
        if ($colon === false) {
            throw new \ValueError('Missing port in address: ' . $address);
        }

        $ip = \substr($address, 0, $colon);
        $port = \substr($address, $colon + 1);

        /** @psalm-suppress ArgumentTypeCoercion */
        return new self($ip, (int) $port);
    }

    private string $binaryAddress;

    private string $textualAddress;

    /** @var int<0, 65535> */
    private int $port;

    /**
     * @param int<0, 65535> $port
     *
     * @throws SocketException If an invalid address is given.
     */
    public function __construct(string $address, int $port)
    {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($port < 0 || $port > 65535) {
            throw new \ValueError('Port number must be an integer between 0 and 65535; got ' . $port);
        }

        if (\strrpos($address, ':')) {
            $address = \trim($address, '[]');
        }

        \set_error_handler(static fn () => throw new SocketException('Invalid address: ' . $address));

        try {
            $binaryAddress = \inet_pton($address);
            if ($binaryAddress === false) {
                throw new SocketException('Invalid address: ' . $address);
            }

            $this->binaryAddress = $binaryAddress;
        } finally {
            \restore_error_handler();
        }

        $this->textualAddress = \inet_ntop($binaryAddress);
        $this->port = $port;
    }

    public function getType(): SocketAddressType
    {
        return SocketAddressType::Internet;
    }

    public function getAddress(): string
    {
        return $this->textualAddress;
    }

    public function getAddressBytes(): string
    {
        return $this->binaryAddress;
    }

    public function getVersion(): InternetAddressVersion
    {
        if (\strlen($this->binaryAddress) === 4) {
            return InternetAddressVersion::IPv4;
        }

        return InternetAddressVersion::IPv6;
    }

    /**
     * @return int<0, 65535>
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return non-empty-string <address>:<port> formatted string.
     */
    public function toString(): string
    {
        if ($this->getVersion() === InternetAddressVersion::IPv6) {
            return '[' . $this->textualAddress . ']' . ':' . $this->port;
        }

        return $this->textualAddress . ':' . $this->port;
    }

    /**
     * @see toString
     */
    public function __toString(): string
    {
        return $this->toString();
    }
}
