<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

final class DatagramSocket
{
    public const DEFAULT_CHUNK_SIZE = 8192;

    /**
     * Create a new Datagram (UDP server) on the specified server address.
     *
     * @param string $uri URI in scheme://host:port format. UDP is assumed if no scheme is present.
     * @param BindContext|null $context Context options for listening.
     *
     * @return DatagramSocket
     *
     * @throws SocketException If binding to the specified URI failed.
     * @throws \Error If an invalid scheme is given.
     */
    public static function bind(string $uri, ?BindContext $context = null): self
    {
        $context = $context ?? new BindContext;

        $scheme = \strstr($uri, '://', true);

        if ($scheme === false) {
            $uri = 'udp://' . $uri;
        } elseif ($scheme !== 'udp') {
            throw new \Error('Only udp scheme allowed for datagram creation');
        }

        $streamContext = \stream_context_create($context->toStreamContextArray());

        // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
        $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $streamContext);

        if (!$server || $errno) {
            throw new SocketException(
                \sprintf('Could not create datagram %s: [Error: #%d] %s', $uri, $errno, $errstr),
                $errno
            );
        }

        return new self($server, $context->getChunkSize());
    }

    /** @var resource|null UDP socket resource. */
    private $socket;

    private string $watcher;

    private SocketAddress $address;

    private ?Suspension $reader = null;

    private \Closure $cancel;

    private int $chunkSize;

    /**
     * @param resource $socket A bound udp socket resource
     * @param int $chunkSize Maximum chunk size for the
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct($socket, int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        $this->address = SocketAddress::fromLocalResource($socket);
        $this->chunkSize = &$chunkSize;

        \stream_set_blocking($this->socket, false);

        $reader = &$this->reader;
        $this->watcher = EventLoop::onReadable($this->socket, static function (string $watcher, $socket) use (
            &$reader,
            &$chunkSize
        ): void {
            \assert($reader !== null);

            $data = @\stream_socket_recvfrom($socket, $chunkSize, 0, $address);

            /** @psalm-suppress TypeDoesNotContainType */
            if ($data === false) {
                EventLoop::cancel($watcher);
                $reader->resume();
                $reader = null;
                return;
            }

            $reader->resume([SocketAddress::fromSocketName($address), $data]);
            $reader = null;
            EventLoop::disable($watcher);
        });

        $watcher = &$this->watcher;
        $this->cancel = static function (CancelledException $exception) use (&$reader, $watcher): void {
            $reader?->throw($exception);
            $reader = null;
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

    /**
     * @return array{SocketAddress, string}|null Resolves with null if the socket is closed.
     *
     * @throws PendingReceiveError If a receive request is already pending.
     */
    public function receive(?Cancellation $cancellation = null): ?array
    {
        if ($this->reader) {
            throw new PendingReceiveError;
        }

        if (!$this->socket) {
            return null; // Resolve with null when endpoint is closed.
        }

        EventLoop::enable($this->watcher);
        $this->reader = EventLoop::createSuspension();

        $id = $cancellation?->subscribe($this->cancel);

        try {
            return $this->reader->suspend();
        } finally {
            $cancellation?->unsubscribe($id);
        }
    }

    /**
     * @param SocketAddress $address
     * @param string $data
     *
     * @return int Returns with the number of bytes written to the socket.
     *
     * @throws SocketException If the UDP socket closes before the data can be sent.
     */
    public function send(SocketAddress $address, string $data): int
    {
        static $errorHandler;
        $errorHandler ??= static function (int $errno, string $errstr): void {
            throw new SocketException(\sprintf('Could not send packet on endpoint: %s', $errstr));
        };

        if (!$this->socket) {
            throw new SocketException('The endpoint is not writable');
        }

        try {
            \set_error_handler($errorHandler);

            $result = \stream_socket_sendto($this->socket, $data, 0, $address->toString());
            /** @psalm-suppress TypeDoesNotContainType */
            if ($result < 0 || $result === false) {
                throw new SocketException('Could not send packet on endpoint: Unknown error');
            }
        } finally {
            \restore_error_handler();
        }

        return $result;
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

    /**
     * References the receive watcher.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void
    {
        if ($this->socket === null) {
            return;
        }

        EventLoop::reference($this->watcher);
    }

    /**
     * Unreferences the receive watcher.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void
    {
        if ($this->socket === null) {
            return;
        }

        EventLoop::unreference($this->watcher);
    }

    /**
     * Closes the datagram socket and stops receiving data. Any pending read is resolved with null.
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
     * @return SocketAddress
     */
    public function getAddress(): SocketAddress
    {
        return $this->address;
    }

    /**
     * @param int $chunkSize The new maximum packet size to receive.
     */
    public function setChunkSize(int $chunkSize): void
    {
        $this->chunkSize = $chunkSize;
    }

    private function free(): void
    {
        EventLoop::cancel($this->watcher);

        $this->socket = null;

        $this->reader?->resume();
        $this->reader = null;
    }
}
