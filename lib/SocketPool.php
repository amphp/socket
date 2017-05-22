<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Promise;

/**
 * Allows pooling of connections for stateless protocols.
 */
interface SocketPool {
    const OP_IDLE_TIMEOUT = "amp.socket.socketpool.idle-timeout";
    const OP_CONNECT_TIMEOUT = "amp.socket.socketpool.connect-timeout";
    const OP_BINDTO = "amp.socket.socketpool.bindto";

    /**
     * Checkout a socket from the specified URI authority.
     *
     * The resulting socket resource should be checked back in via `SocketPool::checkin()` once the calling code is
     * finished with the stream (even if the socket has been closed). Failure to checkin sockets will result in memory
     * leaks and socket queue blockage. Instead of checking the socket in again, it can also be cleared.
     *
     * @param string            $uri A string of the form tcp://example.com:80 or tcp://192.168.1.1:443
     * @param array             $options Array of options. OP_IDLE_TIMEOUT is ignored and has only effect on the pool
     *     itself.
     * @param CancellationToken $token Optional cancellation token to cancel the checkout request.
     *
     * @return Promise Returns a promise that resolves to a socket once a connection is available
     */
    public function checkout(string $uri, array $options = [], CancellationToken $token = null): Promise;

    /**
     * Return a previously checked-out socket to the pool.
     *
     * @param resource $resource Raw socket resource.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function checkin($resource);

    /**
     * Remove the specified socket from the pool.
     *
     * @param resource $resource Raw socket resource.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function clear($resource);

    /**
     * Gets the number of outstanding checkout requests.
     *
     * @param string $uri A URI as passed to `checkout()`.
     *
     * @return int
     */
    public function getPendingCount(string $uri): int;

    /**
     * Gets the number of currently checked out sockets.
     *
     * @param string $uri A URI as passed to `checkout()`.
     *
     * @return int
     */
    public function getCheckoutCount(string $uri): int;

    /**
     * Set a socket pool option.
     *
     * @param string $option Option name, use the provided constants.
     * @param mixed  $value Option value.
     *
     * @throws \Error If an unknown option is provided.
     */
    public function setOption(string $option, $value);
}
