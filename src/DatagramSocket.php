<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Revolt\EventLoop;

interface DatagramSocket
{
    /**
     * @return array{SocketAddress, string}|null Resolves with null if the socket is closed.
     *
     * @throws PendingReceiveError If a receive request is already pending.
     */
    public function receive(?Cancellation $cancellation = null): ?array;

    /**
     * @param SocketAddress $address
     * @param string $data
     *
     * @return int Returns with the number of bytes written to the socket.
     *
     * @throws SocketException If the UDP socket closes before the data can be sent.
     */
    public function send(SocketAddress $address, string $data): int;

    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource();

    /**
     * References the receive watcher.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void;

    /**
     * Unreferences the receive watcher.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void;

    /**
     * Closes the datagram socket and stops receiving data. Any pending read is resolved with null.
     */
    public function close(): void;
    /**
     * @return bool
     */
    public function isClosed(): bool;

    /**
     * @return SocketAddress
     */
    public function getAddress(): SocketAddress;

    /**
     * @param int $chunkSize The new maximum packet size to receive.
     */
    public function setChunkSize(int $chunkSize): void;
}
