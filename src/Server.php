<?php

namespace Amp\Socket;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

final class Server
{
    /** @var resource|null Stream socket server resource. */
    private $socket;

    /** @var string Watcher ID. */
    private $watcher;

    /** @var SocketAddress Stream socket name */
    private $address;

    /** @var int */
    private $chunkSize;

    /** @var Deferred|null */
    private $acceptor;

    /**
     * Listen for client connections on the specified server address.
     *
     * If you want to accept TLS connections, you have to use `yield $socket->setupTls()` after accepting new clients.
     *
     * @param string           $uri     URI in scheme://host:port format. TCP is assumed if no scheme is present.
     * @param BindContext|null $context Context options for listening.
     *
     * @return Server
     *
     * @throws SocketException If binding to the specified URI failed.
     * @throws \Error If an invalid scheme is given.
     */
    public static function listen(string $uri, ?BindContext $context = null): self
    {
        $context = $context ?? new BindContext;

        $scheme = \strstr($uri, '://', true);

        if ($scheme === false) {
            $uri = 'tcp://' . $uri;
        } elseif (!\in_array($scheme, ['tcp', 'unix'])) {
            throw new \Error('Only tcp and unix schemes allowed for server creation');
        }

        $streamContext = \stream_context_create($context->toStreamContextArray());

        // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
        $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $streamContext);

        if (!$server || $errno) {
            throw new SocketException(\sprintf('Could not create server %s: [Error: #%d] %s', $uri, $errno, $errstr), $errno);
        }

        return new self($server, $context->getChunkSize());
    }

    /**
     * @param resource $socket    A bound socket server resource
     * @param int      $chunkSize Chunk size for the input and output stream.
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
        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use (
            &$acceptor,
            $chunkSize
        ) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            if (!$client = @\stream_socket_accept($socket, 0)) {  // Timeout of 0 to be non-blocking.
                return; // Accepting client failed.
            }

            $deferred = $acceptor;
            $acceptor = null;

            \assert($deferred !== null);

            $deferred->resolve(ResourceSocket::fromServerSocket($client, $chunkSize));

            /** @psalm-suppress RedundantCondition Resolution of the deferred above might accept immediately again */
            if (!$acceptor) {
                Loop::disable($watcher);
            }
        });

        Loop::disable($this->watcher);
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
        Loop::cancel($this->watcher);

        $this->socket = null;

        if ($this->acceptor) {
            $this->acceptor->resolve();
            $this->acceptor = null;
        }
    }

    /**
     * @return Promise<ResourceSocket|null>
     *
     * @throws PendingAcceptError If another accept request is pending.
     */
    public function accept(): Promise
    {
        if ($this->acceptor) {
            throw new PendingAcceptError;
        }

        if (!$this->socket) {
            return new Success; // Resolve with null when server is closed.
        }

        // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
        if ($client = @\stream_socket_accept($this->socket, 0)) { // Timeout of 0 to be non-blocking.
            return new Success(ResourceSocket::fromServerSocket($client, $this->chunkSize));
        }

        $this->acceptor = new Deferred;
        Loop::enable($this->watcher);

        return $this->acceptor->promise();
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
     * @see Loop::reference()
     */
    final public function reference(): void
    {
        Loop::reference($this->watcher);
    }

    /**
     * Unreferences the accept watcher.
     *
     * @see Loop::unreference()
     */
    final public function unreference(): void
    {
        Loop::unreference($this->watcher);
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
    final public function getResource()
    {
        return $this->socket;
    }
}
