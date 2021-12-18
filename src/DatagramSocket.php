<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosableStream;
use Amp\ByteStream\ResourceStream;
use Amp\Cancellation;

interface DatagramSocket extends ClosableStream, ResourceStream
{
    /**
     * @param positive-int|null $limit Read at most $limit bytes from the datagram socket. {@code null} uses an
     *     implementation defined limit.
     *
     * @return array{SocketAddress, string}|null Returns {@code null} if the socket is closed.
     *
     * @throws PendingReceiveError If a reception request is already pending.
     */
    public function receive(?Cancellation $cancellation = null, ?int $limit = null): ?array;

    /**
     * @param SocketAddress $address
     * @param string $data
     *
     * @throws SocketException If the UDP socket closes before the data can be sent or the payload is too large.
     */
    public function send(SocketAddress $address, string $data): void;

    public function getAddress(): SocketAddress;
}
