<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;

interface DatagramSocket extends ClosableStream, ResourceStream
{
    /**
     * @param positive-int $limit Read at most $limit bytes from the datagram socket.
     *
     * @return array{SocketAddress, string}|null Resolves with null if the socket is closed.
     *
     * @throws PendingReceiveError If a receive request is already pending.
     */
    public function receive(?Cancellation $cancellation = null, int $limit = \PHP_INT_MAX): ?array;

    /**
     * @param SocketAddress $address
     * @param string $data
     *
     * @throws SocketException If the UDP socket closes before the data can be sent.
     */
    public function send(SocketAddress $address, string $data): void;

    /**
     * @return SocketAddress
     */
    public function getAddress(): SocketAddress;
}
