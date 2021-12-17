<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Revolt\EventLoop;

interface SocketServer
{
    /**
     * @return EncryptableSocket|null
     *
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?EncryptableSocket;

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close(): void;

    /**
     * @return bool
     */
    public function isClosed(): bool;

    /**
     * References the accept watcher.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void;

    /**
     * Unreferences the accept watcher.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void;

    /**
     * @return SocketAddress
     */
    public function getAddress(): SocketAddress;

    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource();
}
