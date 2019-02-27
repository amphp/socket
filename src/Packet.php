<?php

namespace Amp\Socket;

final class Packet
{
    /** @var string */
    private $data;

    /** @var string */
    private $address;

    /**
     * @param string $data
     * @param string $address
     */
    public function __construct(string $data, string $address)
    {
        \assert($this->isAddressValid($address), "Invalid packet address");

        $this->data = $data;
        $this->address = $address;
    }

    /**
     * @return string
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function getAddress(): string
    {
        if ($this->address[0] === "\0") {
            return '';
        }

        return $this->address;
    }

    /**
     * @param string $data
     *
     * @return self
     */
    public function withData(string $data): self
    {
        $clone = clone $this;
        $clone->data = $data;
        return $clone;
    }

    /**
     * @param string $address
     *
     * @return self
     */
    public function withAddress(string $address): self
    {
        \assert($this->isAddressValid($address), "Invalid packet address");

        $clone = clone $this;
        $clone->address = $address;
        return $clone;
    }

    /**
     * Rough address validation to catch programming mistakes.
     *
     * @param string $address
     *
     * @return bool
     */
    private function isAddressValid(string $address): bool
    {
        $position = \strrpos($address, ':');
        if ($position === false) {
            return ($address[0] ?? '') === "\0"; // udg socket address.
        }

        $ip = \trim(\substr($address, 0, $position), '[]');
        $port = (int) \substr($address, $position + 1);

        return \inet_pton($ip) !== false && $port > 0 && $port < 65536;
    }
}
