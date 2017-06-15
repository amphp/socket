<?php

namespace Amp\Socket;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;

class Server {
    /** @var resource Stream socket server resource. */
    private $socket;

    /** @var string Watcher ID. */
    private $watcher;

    /** @var string|null Stream socket name */
    private $address;

    /** @var int */
    private $chunkSize;

    /** @var Deferred|null */
    private $acceptor;

    /**
     * @param resource $socket A bound socket server resource
     * @param int      $chunkSize Chunk size for the input and output stream.
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct($socket, int $chunkSize = 65536) {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        $this->chunkSize = $chunkSize;
        $this->address = Internal\cleanupSocketName(@\stream_socket_get_name($this->socket, false));

        \stream_set_blocking($this->socket, false);

        $acceptor = &$this->acceptor;
        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use (&$acceptor, $chunkSize) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            if (!$client = @\stream_socket_accept($socket, 0)) {  // Timeout of 0 to be non-blocking.
                return; // Accepting client failed.
            }

            $deferred = $acceptor;
            $acceptor = null;
            $deferred->resolve(new ServerSocket($client, $chunkSize));

            if (!$acceptor) {
                Loop::disable($watcher);
            }
        });

        Loop::disable($this->watcher);
    }

    /**
     * Automatically cancels the loop watcher.
     */
    public function __destruct() {
        if (!$this->socket) {
            return;
        }

        $this->free();
    }

    /**
     * @return \Amp\Promise<ServerSocket|null>
     *
     * @throws \Amp\Socket\PendingAcceptError If another accept request is pending.
     */
    public function accept(): Promise {
        if ($this->acceptor) {
            throw new PendingAcceptError;
        }

        if (!$this->socket) {
            return new Success; // Resolve with null when server is closed.
        }

        // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
        if ($client = @\stream_socket_accept($this->socket, 0)) { // Timeout of 0 to be non-blocking.
            return new Success(new ServerSocket($client, $this->chunkSize));
        }

        $this->acceptor = new Deferred;
        Loop::enable($this->watcher);

        return $this->acceptor->promise();
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close() {
        if ($this->socket) {
            \fclose($this->socket);
        }

        $this->free();
    }

    /**
     * @return string|null
     */
    public function getAddress() {
        return $this->address;
    }

    private function free() {
        Loop::cancel($this->watcher);

        $this->socket = null;

        if ($this->acceptor) {
            $this->acceptor->resolve();
            $this->acceptor = null;
        }
    }
}
