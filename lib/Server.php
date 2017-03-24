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
     * @param bool $autoClose True to close the stream resource when this object is destroyed, false to leave open.
     *
     * @throws \Error If a stream resource is not given for $socket.
     */
    public function __construct($socket, callable $handler, bool $autoClose = true) {
        if (!\is_resource($socket) ||\get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }
    
        $this->socket = $socket;
        \stream_set_blocking($this->socket, false);
        
        $handler = wrap($handler);

        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use ($handler) {
            do {
                // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
                $client = @\stream_socket_accept($socket, 0); // Timeout of 0 to be non-blocking.

                if (!$client) {
                    return; // No clients remaining.
                }

                $handler(new Socket($client));
            } while (true);
        });
    }

    /**
     * Closes the server and stops accepting connections. Any socket clients accepted will not be closed.
     */
    public function close() {
        Loop::cancel($this->watcher);

        if (\is_resource($this->socket)) {
            if ($this->autoClose) {
                @\fclose($this->socket);
            }
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
