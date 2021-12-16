<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use Revolt\EventLoop;

/**
 * Listen for client connections on the specified server address.
 *
 * If you want to accept TLS connections, you have to use `yield $socket->setupTls()` after accepting new clients.
 *
 * @param string           $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param BindContext|null $context Context options for listening.
 *
 * @return Server
 *
 * @throws SocketException If binding to the specified URI failed.
 * @throws \Error If an invalid scheme is given.
 * @see Server::listen()
 *
 * @deprecated Use Server::listen() instead.
 */
function listen(string $uri, ?BindContext $context = null): Server
{
    return Server::listen($uri, $context);
}

/**
 * Set or access the global socket Connector instance.
 *
 * @param Connector|null $connector
 *
 * @return Connector
 */
function connector(?Connector $connector = null): Connector
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($connector) {
        return $map[$driver] = $connector;
    }

    return $map[$driver] ??= new DnsConnector();
}

/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * @param string                 $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ConnectContext|null    $context Socket connect context to use when connecting.
 * @param Cancellation|null $cancellation
 *
 * @return EncryptableSocket
 *
 * @throws ConnectException
 * @throws CancelledException
 */
function connect(string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): EncryptableSocket
{
    return connector()->connect($uri, $context, $cancellation);
}

/**
 * Returns a pair of connected stream socket resources.
 *
 * @return ResourceSocket[] Pair of socket resources.
 *
 * @throws SocketException If creating the sockets fails.
 */
function createPair(int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE): array
{
    try {
        \set_error_handler(static function (int $errno, string $errstr): void {
            throw new SocketException(\sprintf('Failed to create socket pair.  Errno: %d; %s', $errno, $errstr));
        });

        $sockets = \stream_socket_pair(
            \PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($sockets === false) {
            throw new SocketException('Failed to create socket pair.');
        }
    } finally {
        \restore_error_handler();
    }

    return [
        ResourceSocket::fromClientSocket($sockets[0], chunkSize: $chunkSize),
        ResourceSocket::fromClientSocket($sockets[1], chunkSize: $chunkSize),
    ];
}

/**
 * @see https://wiki.openssl.org/index.php/Manual:OPENSSL_VERSION_NUMBER(3)
 * @return bool
 */
function hasTlsAlpnSupport(): bool
{
    return \defined('OPENSSL_VERSION_NUMBER') && \OPENSSL_VERSION_NUMBER >= 0x10002000;
}

function hasTlsSecurityLevelSupport(): bool
{
    return \defined('OPENSSL_VERSION_NUMBER') && \OPENSSL_VERSION_NUMBER >= 0x10100000;
}
