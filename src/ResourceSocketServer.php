<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

final class ResourceSocketServer implements SocketServer
{
    /** @var resource|null Stream socket server resource. */
    private $socket;

    private string $watcher;

    private SocketAddress $address;

    /** @var positive-int */
    private int $chunkSize;

    private ?Suspension $acceptor = null;

    private \Closure $cancel;

    /**
     * @param resource $socket A bound socket server resource
     * @param positive-int $chunkSize Chunk size for the input and output stream.
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct($socket, int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE)
    {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        $this->chunkSize = $chunkSize;
        $this->address = SocketAddress::fromLocalResource($socket);

        \stream_set_blocking($this->socket, false);

        $acceptor = &$this->acceptor;
        $this->watcher = EventLoop::onReadable($this->socket, static function (string $watcher, $socket) use (
            &$acceptor,
            $chunkSize
        ): void {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            if (!$client = @\stream_socket_accept($socket, 0)) {  // Timeout of 0 to be non-blocking.
                return; // Accepting client failed.
            }

            \assert($acceptor !== null);

            $acceptor->resume(ResourceSocket::fromServerSocket($client, $chunkSize));
            $acceptor = null;
            EventLoop::disable($watcher);
        });

        $watcher = &$this->watcher;
        $this->cancel = static function (CancelledException $exception) use (&$acceptor, $watcher): void {
            $acceptor?->throw($exception);
            $acceptor = null;
            EventLoop::disable($watcher);
        };

        EventLoop::disable($this->watcher);
    }

    /**
     * Automatically cancels the loop watcher.
     */
    public function __destruct()
    {
        if (!$this->socket) {
            return;
        }

        $this->free();
    }

    private function free(): void
    {
        EventLoop::cancel($this->watcher);

        $this->socket = null;

        $this->acceptor?->resume();
        $this->acceptor = null;
    }

    /**
     * @return ResourceSocket|null
     *
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(?Cancellation $cancellation = null): ?ResourceSocket
    {
        if ($this->acceptor) {
            throw new PendingAcceptError;
        }

        if (!$this->socket) {
            return null; // Resolve with null when server is closed.
        }

        // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
        if ($client = @\stream_socket_accept($this->socket, 0)) { // Timeout of 0 to be non-blocking.
            return ResourceSocket::fromServerSocket($client, $this->chunkSize);
        }

        EventLoop::enable($this->watcher);
        $this->acceptor = EventLoop::createSuspension();

        $id = $cancellation?->subscribe($this->cancel);

        try {
            return $this->acceptor->suspend();
        } finally {
            /** @psalm-suppress PossiblyNullArgument $id is always defined if $cancellation is non-null */
            $cancellation?->unsubscribe($id);
        }
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close(): void
    {
        if ($this->socket) {
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            \fclose($this->socket);
        }

        $this->free();
    }

    /**
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->socket === null;
    }

    /**
     * References the accept watcher.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void
    {
        EventLoop::reference($this->watcher);
    }

    /**
     * Unreferences the accept watcher.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void
    {
        EventLoop::unreference($this->watcher);
    }

    /**
     * @return SocketAddress
     */
    public function getAddress(): SocketAddress
    {
        return $this->address;
    }

    /**
     * Raw stream socket resource.
     *
     * @return resource|null
     */
    public function getResource()
    {
        return $this->socket;
    }
}
