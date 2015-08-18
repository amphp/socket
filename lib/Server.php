<?php

namespace Amp\Socket;

use Amp as amp;

class Server {
    private $state;

    /**
     * @param resource A bound socket server resource
     */
    public function __construct($socket) {
        \stream_set_blocking($socket, false);
        $this->state = $state = new \StdClass;
        $state->promisor = new amp\Deferred;
        $state->socket = $socket;
        $state->watcherId = amp\onReadable($socket, static function () use ($state) {
            if ($client = \stream_socket_accept($state->socket, $timeout = 0)) {
                \stream_set_blocking($client, false);
                $promisor = $state->promisor;
                $state->promisor = new amp\Deferred;
                $promisor->succeed(new Client($client));
            }
        });
    }

    /**
     * Accept new clients
     *
     * @return \Amp\Promise<Amp\Socket\Client>
     */
    public function accept() {
        return $this->state->promisor->promise();
    }
    
    public function stop() {
        amp\cancel($this->state->watcherId);
    }

    /**
     * The server will automatically stop listening if this object
     * is garbage collected. However, socket clients accepted by the
     * server will not be closed just because the server is unloaded.
     * Accepted clients must be manually closed or garbage collected.
     */
    public function __destruct() {
        amp\cancel($this->state->watcherId);
    }
}
