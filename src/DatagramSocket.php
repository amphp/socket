<?php

namespace Amp\Socket;

use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

class DatagramSocket
{
    const DEFAULT_CHUNK_SIZE = 8192;

    /** @var resource UDP socket resource. */
    private $socket;

    /** @var string Watcher ID. */
    private $watcher;

    /** @var string|null Stream socket name */
    private $address;

    /** @var Deferred|null */
    private $reader;

    /**
     * @param resource $socket A bound udp socket resource
     * @param int      $chunkSize Maximum chunk size for the
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct($socket, int $chunkSize = self::DEFAULT_CHUNK_SIZE)
    {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        $this->address = Internal\cleanupSocketName(@\stream_socket_get_name($this->socket, false));

        \stream_set_blocking($this->socket, false);

        $reader = &$this->reader;
        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use (&$reader, $chunkSize) {
            $deferred = $reader;
            $reader = null;

            $data = @\stream_socket_recvfrom($socket, $chunkSize, 0, $address);

            if ($data === false) {
                Loop::cancel($watcher);
                $deferred->resolve();
                return;
            }

            $deferred->resolve([Internal\cleanupSocketName($address), $data]);

            if (!$reader) {
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

    /**
     * @return Promise<[string $address, string $data]|null> Resolves with null if the socket is closed.
     *
     * @throws PendingReceiveError If a receive request is already pending.
     */
    public function receive(): Promise
    {
        if ($this->reader) {
            throw new PendingReceiveError;
        }

        if (!$this->socket) {
            return new Success; // Resolve with null when endpoint is closed.
        }

        $this->reader = new Deferred;
        Loop::enable($this->watcher);

        return $this->reader->promise();
    }

    /**
     * @param string $address
     * @param string $data
     *
     * @return Promise<int> Resolves with the number of bytes written to the socket.
     *
     * @throws SocketException If the UDP socket closes before the data can be sent.
     */
    public function send(string $address, string $data): Promise
    {
        \assert($this->isAddressValid($address), "Invalid packet address");

        if (!$this->socket) {
            return new Failure(new SocketException('The endpoint is not writable'));
        }

        $result = @\stream_socket_sendto($this->socket, $data, 0, $address);

        if ($result < 0 || $result === false) {
            $error = \error_get_last();
            return new Failure(new SocketException('Could not send packet on endpoint: ' . $error['message']));
        }

        return new Success($result);
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

    /**
     * References the receive watcher.
     *
     * @see Loop::reference()
     */
    final public function reference(): void
    {
        Loop::reference($this->watcher);
    }

    /**
     * Unreferences the receive watcher.
     *
     * @see Loop::unreference()
     */
    final public function unreference(): void
    {
        Loop::unreference($this->watcher);
    }

    /**
     * Closes the datagram socket and stops receiving data. Any pending read is resolved with null.
     */
    public function close(): void
    {
        if ($this->socket) {
            \fclose($this->socket);
        }

        $this->free();
    }

    /**
     * @return string|null
     */
    public function getAddress(): ?string
    {
        return $this->address;
    }

    private function free(): void
    {
        Loop::cancel($this->watcher);

        $this->socket = null;

        if ($this->reader) {
            $this->reader->resolve();
            $this->reader = null;
        }
    }

    /**
     * Rough address validation to catch programming mistakes.
     *
     * @param string $address
     *
     * @return bool
     */
    private function isAddressValid(string $address): bool
    {
        $position = \strrpos($address, ':');
        if ($position === false) {
            return ($address[0] ?? '') === "\0"; // udg socket address.
        }

        $ip = \trim(\substr($address, 0, $position), '[]');
        $port = (int) \substr($address, $position + 1);

        return \inet_pton($ip) !== false && $port > 0 && $port < 65536;
    }
}
