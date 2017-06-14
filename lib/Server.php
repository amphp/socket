<?php

namespace Amp\Socket;

use Amp\Loop;
use function Amp\asyncCoroutine;
use function Amp\Socket\Internal\cleanupSocketName;

class Server {
    /** @var resource Stream socket server resource. */
    private $socket;

    /** @var string Watcher ID. */
    private $watcher;

    /** @var string|null Stream socket name */
    private $address;

    /**
     * @param resource $socket A bound socket server resource
     * @param callable(\Amp\Socket\Socket $socket): mixed Callback invoked when a connection is accepted. Generators
     *     returned will be run as a coroutine. Promise failures will be rethrown to the event loop handler.
     *     {@see \Amp\asyncCoroutine()}.
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct($socket, callable $handler, int $chunkSize = 65536) {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        \stream_set_blocking($this->socket, false);

        $this->address = cleanupSocketName(@\stream_socket_get_name($this->socket, false));

        $handler = asyncCoroutine($handler);

        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use ($handler, $chunkSize) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            while ($client = @\stream_socket_accept($socket, 0)) { // Timeout of 0 to be non-blocking.
                $handler(new ServerSocket($client, $chunkSize));
            }
        });
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close() {
        Loop::cancel($this->watcher);

        if ($this->socket) {
            \fclose($this->socket);
        }

        $this->socket = null;
    }

    public function getAddress() {
        return $this->address;
    }
}
