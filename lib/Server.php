<?php declare(strict_types = 1);

namespace Amp\Socket;

use Amp\Deferred;
use Interop\Async\{ Awaitable, Loop };

class Server {
    /** @var resource Stream socket server resource. */
    private $socket;
    
    /** @var \SplQueue Queue of pending Deferreds to accept clients. */
    private $queue;
    
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
    public function __construct($socket, bool $autoClose = true) {
        if (!\is_resource($socket) ||\get_resource_type($socket) !== 'stream') {
            throw new \Error('Invalid resource given to constructor!');
        }
    
        $this->socket = $socket;
        \stream_set_blocking($this->socket, false);
        
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
    public function accept(): Awaitable {
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
        // defer this, else the Loop::disable() inside onReadable may be invalid
        $watcher = $this->watcher;
        Loop::defer(static function() use ($watcher) {
            Loop::cancel($watcher);
        });
        
        if (\is_resource($this->socket)) {
            if ($this->autoClose) {
                @\fclose($this->socket);
            }
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
