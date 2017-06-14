<?php

namespace Amp\Socket;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use function Amp\Socket\Internal\cleanupSocketName;

class Server {
    /** @var resource Stream socket server resource. */
    private $socket;

    /** @var string Watcher ID. */
    private $watcher;

    /** @var string|null Stream socket name */
    private $address;

    /** @var int */
    private $chunkSize;

    /** @var Deferred */
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
        $this->address = cleanupSocketName(@\stream_socket_get_name($this->socket, false));

        \stream_set_blocking($this->socket, false);

        $this->watcher = Loop::onReadable($this->socket, function ($watcher, $socket) {
            $acceptor = $this->acceptor;
            $this->acceptor = null;

            // Always disabling is safe, other clients get still accepted within the same tick, because accept tries an
            // immediate accept.
            Loop::disable($watcher);

            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            $acceptor->resolve(new ServerSocket(@\stream_socket_accept($socket, 0), $this->chunkSize)); // Timeout of 0 to be non-blocking.
        });

        Loop::disable($this->watcher);
    }

    public function accept(): Promise {
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
        Loop::cancel($this->watcher);

        if ($this->acceptor) {
            $this->acceptor->resolve(null);
            $this->acceptor = null;
        }

        if ($this->socket) {
            \fclose($this->socket);
        }

        $this->socket = null;
    }

    public function getAddress() {
        return $this->address;
    }
}
