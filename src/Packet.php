<?php

namespace Amp\Socket;

class Packet
{
    /** @var string */
    private $data;

    /** @var string */
    private $address;

    /** @var int */
    private $port;

    /**
     * @param string $data
     * @param string $address
     * @param int    $port
     */
    public function __construct(string $data, string $address, int $port)
    {
        $this->data = $data;
        $this->address = $address;
        $this->port = $port;
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
        return $this->address;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }
}
