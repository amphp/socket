<?php

namespace Amp\Socket;

use Amp\Promise;

interface UdpSocket extends Socket
{
    /**
     * @return Promise<Packet|null> Resolves with null if the datagram is closed.
     *
     * @throws PendingReceiveError If a receive request is already pending.
     */
    public function receive(): Promise;

    /**
     * @param Packet $packet
     *
     * @return int Number of bytes written to the socket.
     *
     * @throws SocketException If the datagram closes before the data can be sent.
     */
    public function send(Packet $packet): int;
}
