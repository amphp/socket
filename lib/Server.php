<?php

namespace Amp\Socket;

use Amp\Deferred;
use Interop\Async\Loop;

class Server {
    /** @var resource Stream socket server resource. */
    private $socket;
    
    /** @var \SplQueue Queue of pending Deferreds to accept clients. */
    private $queue;
    
    /** @var string Watcher ID. */
    private $watcher;

    /**
     * @param resource $socket A bound socket server resource
     */
    public function __construct($socket) {
        $this->socket = $socket;
        \stream_set_blocking($this->socket, 0);
        
        $this->queue = $queue = new \SplQueue;
        
        $this->watcher = Loop::onReadable($this->socket, static function ($watcher, $socket) use ($queue) {
            while (!$queue->isEmpty()) {
                // Error reporting suppressed since stream_socket_accept() emits E_WARNING on client accept failure.
                $socket = @\stream_socket_accept($socket, 0); // Timeout of 0 to be non-blocking.
    
                if (!$socket) {
                    return; // Accept failed, queue not empty, do not disable watcher.
                }
                
                /** @var \Amp\Deferred $deferred */
                $deferred = $queue->shift();
                $deferred->resolve($socket);
            }
            
            Loop::disable($watcher);
        });
        
        Loop::disable($this->watcher);
    }

    /**
     * Accept new clients
     *
     * @return \Interop\Async\Awaitable<resource>
     */
    public function accept() {
        $this->queue->push($deferred = new Deferred);
        Loop::enable($this->watcher);
        return $deferred->getAwaitable();
    }
    
    /**
     * The server will automatically stop listening if this object
     * is garbage collected. However, socket clients accepted by the
     * server will not be closed just because the server is unloaded.
     * Accepted clients must be manually closed or garbage collected.
     */
    public function __destruct() {
        Loop::cancel($this->watcher);
        
        if (\is_resource($this->socket)) {
            @\fclose($this->socket);
        }
    
        if (!$this->queue->isEmpty()) {
            $exception = new SocketException("The server was unexpectedly closed");
            do {
                /** @var \Amp\Deferred $deferred */
                $deferred = $this->queue->shift();
                $deferred->fail($exception);
            } while (!$this->queue->isEmpty());
        }
    }
}
