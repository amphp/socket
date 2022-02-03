<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

final class ResourceDatagramSocket implements DatagramSocket
{
    public const DEFAULT_LIMIT = 8192;

    /** @var resource|null UDP socket resource. */
    private $socket;

    private string $callbackId;

    private SocketAddress $address;

    private ?Suspension $reader = null;

    /** @var \Closure(CancelledException) */
    private \Closure $cancel;

    private int $limit;

    private int $defaultLimit;

    /**
     * @param resource $socket A bound udp socket resource.
     * @param positive-int $limit Maximum size for received messages.
     *
     * @throws \Error If a stream resource is not given for {@code $socket}.
     */
    public function __construct($socket, int $limit = self::DEFAULT_LIMIT)
    {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        /** @psalm-suppress TypeDoesNotContainType */
        if ($limit < 1) {
            throw new \ValueError('Invalid length limit of ' . $limit . ', must be greater than 0');
        }

        $this->socket = $socket;
        $this->address = SocketAddress::fromLocalResource($socket);
        $this->defaultLimit = $this->limit = &$limit;

        \stream_set_blocking($this->socket, false);
        \stream_set_read_buffer($this->socket, 0);

        $reader = &$this->reader;
        $this->callbackId = EventLoop::onReadable($this->socket, static function (string $callbackId, $socket) use (
            &$reader,
            &$limit
        ): void {
            \assert($reader !== null);

            $data = @\stream_socket_recvfrom($socket, $limit, 0, $address);

            /** @psalm-suppress TypeDoesNotContainType */
            if ($data === false) {
                EventLoop::cancel($callbackId);

                $reader->resume();
            } else {
                EventLoop::disable($callbackId);

                $reader->resume([SocketAddress::fromSocketName($address), $data]);
            }

            $reader = null;
        });

        $callbackId = &$this->callbackId;
        $this->cancel = static function (CancelledException $exception) use (&$reader, $callbackId): void {
            EventLoop::disable($callbackId);

            $reader?->throw($exception);
            $reader = null;
        };

        EventLoop::disable($this->callbackId);
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
     * @param Cancellation|null $cancellation
     * @param positive-int|null $limit If null, the default chunk size is used.
     *
     * @return null|array{SocketAddress, string}
     */
    public function receive(?Cancellation $cancellation = null, ?int $limit = null): ?array
    {
        if ($this->reader) {
            throw new PendingReceiveError;
        }

        $limit ??= $this->defaultLimit;

        if ($limit <= 0) {
            throw new \ValueError('The length limit must be a positive integer, got ' . $limit);
        }

        if (!$this->socket) {
            return null; // Resolve with null when endpoint is closed.
        }

        $this->limit = $limit;
        $this->reader = EventLoop::getSuspension();

        EventLoop::enable($this->callbackId);

        $id = $cancellation?->subscribe($this->cancel);

        try {
            return $this->reader->suspend();
        } finally {
            /** @psalm-suppress PossiblyNullArgument $id is always defined if $cancellation is present */
            $cancellation?->unsubscribe($id);
        }
    }

    public function send(SocketAddress $address, string $data): void
    {
        static $errorHandler;
        $errorHandler ??= static function (int $errno, string $errstr): void {
            throw new SocketException(\sprintf('Could not send datagram packet: %s', $errstr));
        };

        if (!$this->socket) {
            throw new SocketException('The datagram socket is not writable');
        }

        try {
            \set_error_handler($errorHandler);

            $result = \stream_socket_sendto($this->socket, $data, 0, $address->toString());
            /** @psalm-suppress TypeDoesNotContainType */
            if ($result < 0 || $result === false) {
                throw new SocketException('Could not send datagram packet: Unknown error');
            }
        } finally {
            \restore_error_handler();
        }
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
     * References the event loop callback used for being notified about available packets.
     *
     * @see EventLoop::reference()
     */
    public function reference(): void
    {
        if ($this->socket === null) {
            return;
        }

        EventLoop::reference($this->callbackId);
    }

    /**
     * Unreferences the event loop callback used for being notified about available packets.
     *
     * @see EventLoop::unreference()
     */
    public function unreference(): void
    {
        if ($this->socket === null) {
            return;
        }

        EventLoop::unreference($this->callbackId);
    }

    /**
     * Closes the datagram socket and stops receiving data. A pending {@code receive()} will return {@code null}.
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
     * @param positive-int $limit The new default maximum packet size to receive.
     */
    public function setLimit(int $limit): void
    {
        /** @psalm-suppress TypeDoesNotContainType */
        if ($limit <= 0) {
            throw new \ValueError('The chunk length must be a positive integer, got ' . $limit);
        }

        $this->defaultLimit = $limit;
    }

    private function free(): void
    {
        EventLoop::cancel($this->callbackId);

        $this->socket = null;

        $this->reader?->resume();
        $this->reader = null;
    }
}
