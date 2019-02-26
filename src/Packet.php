<?php

namespace Amp\Socket;

class Packet
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
        return $this->address;
    }
}
