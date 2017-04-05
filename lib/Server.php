<?php

namespace Amp\Socket;

use Amp\{ Loop, function wrap };

class Server {
    /** @var resource Stream socket server resource. */
    private $socket;

    /** @var string Watcher ID. */
    private $watcher;

    /** @var bool */
    private $autoClose = true;

    /**
     * @param resource $socket A bound socket server resource
     * @param callable(\Amp\Socket\Socket $socket): mixed Callback invoked when a connection is accepted.Generators
     *     returned will be run as a coroutine. Promise failures will be rethrown to the event loop handler.
     *     @see \Amp\wrap().
     * @param bool $autoClose True to close the stream resource when this object is destroyed, false to leave open.
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct($socket, callable $handler, bool $autoClose = true) {
        if (!\is_resource($socket) || \get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }

        $this->socket = $socket;
        \stream_set_blocking($this->socket, false);
        
        $handler = wrap($handler);

        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use ($handler) {
            // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
            while ($client = @\stream_socket_accept($socket, 0)) { // Timeout of 0 to be non-blocking.
                $handler(new Socket($client));
            }
        });
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close() {
        Loop::cancel($this->watcher);

        if ($this->autoClose && \is_resource($this->socket)) {
            @\fclose($this->socket);
        }
    }

    /**
     * The server will automatically stop listening if this object
     * is garbage collected. However, socket clients accepted by the
     * server will not be closed just because the server is unloaded.
     * Accepted clients must be manually closed or garbage collected.
     */
    public function __destruct() {
        $this->close();
    }
}
