<?php

namespace Amp\Socket;

use Amp\Promise;

interface UdpSocket extends Socket
{
    /**
     * @return Promise<Packet>
     */
    public function receive(): Promise;

    /**
     * @param Packet $packet
     *
     * @return Promise<int>
     */
    public function send(Packet $packet): Promise;

    /**
     * @return string|null
     */
    public function getAddress();
}
