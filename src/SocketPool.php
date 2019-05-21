<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Promise;

/**
 * Allows pooling of connections for stateless protocols.
 */
interface SocketPool
{
    /**
     * Checkout a socket from the specified URI authority.
     *
     * The resulting socket resource should be checked back in via `SocketPool::checkin()` once the calling code is
     * finished with the stream (even if the socket has been closed). Failure to checkin sockets will result in memory
     * leaks and socket queue blockage. Instead of checking the socket in again, it can also be cleared.
     *
     * @param string            $uri A string of the form tcp://example.com:80 or tcp://192.168.1.1:443. An optional
     *     fragment component can be used to differentiate different socket groups connected to the same URI, e.g.
     *     while connecting to an IP but with different SNI hostnames or TLS configurations.
     * @param CancellationToken $token Optional cancellation token to cancel the checkout request.
     *
     * @return Promise<ResourceSocket> Resolves to a EncryptableSocket instance once a connection is available.
     *
     * @throws SocketException
     */
    public function checkout(string $uri, CancellationToken $token = null): Promise;

    /**
     * Return a previously checked-out socket to the pool so it can be reused.
     *
     * @param ResourceSocket $socket Socket instance.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function checkin(ResourceSocket $socket): void;

    /**
     * Remove the specified socket from the pool.
     *
     * @param ResourceSocket $socket Socket instance.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function clear(ResourceSocket $socket): void;
}
