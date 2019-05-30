<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\CancelledException;
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
     * leaks and socket queue blockage. Instead of checking the socket in again, it can also be cleared to prevent
     * re-use.
     *
     * @param string                 $uri URI in scheme://host:port format. TCP is assumed if no scheme is present. An
     *     optional fragment component can be used to differentiate different socket groups connected to the same URI.
     *     Connections to the same host with a different ConnectContext must use separate socket groups internally to
     *     prevent TLS negotiation with the wrong peer name or other TLS settings.
     * @param ConnectContext         $context Socket connect context to use when connecting.
     * @param CancellationToken|null $token Optional cancellation token to cancel the checkout request.
     *
     * @return Promise<EncryptableSocket> Resolves to an EncryptableSocket instance once a connection is available.
     *
     * @throws SocketException
     * @throws CancelledException
     */
    public function checkout(
        string $uri,
        ConnectContext $context = null,
        CancellationToken $token = null
    ): Promise;

    /**
     * Return a previously checked-out socket to the pool so it can be reused.
     *
     * @param EncryptableSocket $socket Socket instance.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function checkin(EncryptableSocket $socket): void;

    /**
     * Remove the specified socket from the pool.
     *
     * @param EncryptableSocket $socket Socket instance.
     *
     * @throws \Error If the provided resource is unknown to the pool.
     */
    public function clear(EncryptableSocket $socket): void;
}
