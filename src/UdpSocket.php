<?php

namespace Amp\Socket;

use Amp\Promise;

interface UdpSocket extends Socket
{
    /**
     * @return Promise<[string $data, string $address]|null> Resolves with null if the UDP socket is closed.
     *
     * @throws PendingReceiveError If a receive request is already pending.
     */
    public function receive(): Promise;

    /**
     * @param string $data
     * @param string $address
     *
     * @return Promise<int> Resolves with the number of bytes written to the socket.
     *
     * @throws SocketException If the UDP socket closes before the data can be sent.
     */
    public function send(string $data, string $address): Promise;
}
