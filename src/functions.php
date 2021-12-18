<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use League\Uri\UriString;
use Revolt\EventLoop;

/**
 * Listen for client connections on the specified server address.
 *
 * If you want to accept TLS connections, you have to use `yield $socket->setupTls()` after accepting new clients.
 *
 * @param string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param BindContext|null $context Context options for listening.
 * @param positive-int $chunkSize Chunk size for the accepted sockets.
 *
 * @return ResourceSocketServer
 *
 * @throws SocketException If binding to the specified URI failed.
 */
function listen(
    string $uri,
    ?BindContext $context = null,
    int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE
): ResourceSocketServer {
    $context = $context ?? new BindContext;

    $scheme = \strstr($uri, '://', true);

    if ($scheme === false) {
        $uri = 'tcp://' . $uri;
    } elseif (!\in_array($scheme, ['tcp', 'unix'])) {
        throw new \Error('Only tcp and unix schemes allowed for server creation');
    }

    $streamContext = \stream_context_create($context->toStreamContextArray());

    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $streamContext);

    if (!$server || $errno) {
        throw new SocketException(\sprintf(
            'Could not create server %s: [Error: #%d] %s',
            $uri,
            $errno,
            $errstr
        ), $errno);
    }

    return new ResourceSocketServer($server, $chunkSize);
}

/**
 * Create a new Datagram (UDP server) on the specified server address.
 *
 * @param string $uri URI in scheme://host:port format. UDP is assumed if no scheme is present.
 * @param BindContext|null $context Context options for listening.
 * @param positive-int $limit Maximum size for received messages.
 *
 * @return ResourceDatagramSocket
 *
 * @throws SocketException If binding to the specified URI failed.
 */
function bindDatagram(
    string $uri,
    ?BindContext $context = null,
    int $limit = ResourceDatagramSocket::DEFAULT_LIMIT
): ResourceDatagramSocket {
    $context = $context ?? new BindContext;

    $scheme = \strstr($uri, '://', true);

    if ($scheme === false) {
        $uri = 'udp://' . $uri;
    } elseif ($scheme !== 'udp') {
        throw new \Error('Only udp scheme allowed for datagram creation');
    }

    $streamContext = \stream_context_create($context->toStreamContextArray());

    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND, $streamContext);

    if (!$server || $errno) {
        throw new SocketException(
            \sprintf('Could not create datagram %s: [Error: #%d] %s', $uri, $errno, $errstr),
            $errno
        );
    }

    return new ResourceDatagramSocket($server, $limit);
}

/**
 * Set or access the global SocketConnector instance.
 *
 * @param SocketConnector|null $connector
 *
 * @return SocketConnector
 */
function socketConnector(?SocketConnector $connector = null): SocketConnector
{
    static $map;
    $map ??= new \WeakMap();
    $driver = EventLoop::getDriver();

    if ($connector) {
        return $map[$driver] = $connector;
    }

    return $map[$driver] ??= new DnsSocketConnector();
}

/**
 * Establish a socket connection to the specified URI.
 *
 * @param string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ConnectContext|null $context Socket connect context to use when connecting.
 * @param Cancellation|null $cancellation
 *
 * @return EncryptableSocket
 *
 * @throws ConnectException
 * @throws CancelledException
 */
function connect(string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): EncryptableSocket
{
    return socketConnector()->connect($uri, $context, $cancellation);
}

/**
 * Establish a socket connection to the specified URI and enable TLS.
 *
 * @param string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ConnectContext|null $context Socket connect context to use when connecting.
 * @param Cancellation|null $cancellation
 *
 * @return EncryptableSocket
 *
 * @throws ConnectException
 * @throws TlsException
 * @throws CancelledException
 */
function connectTls(string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): EncryptableSocket
{
    $context ??= new ConnectContext();
    $tlsContext = $context->getTlsContext() ?? new ClientTlsContext('');

    if ($tlsContext->getPeerName() === '') {
        $hostname = '';
        if (\str_contains($uri, 'tcp://')) {
            $hostname = UriString::parse($uri)['host'] ?? '';
        } elseif (!\str_contains($uri, '://')) {
            $hostname = UriString::parse('tcp://' . $uri)['host'] ?? '';
        }

        $tlsContext = $tlsContext->withPeerName($hostname);
    }

    $socket = socketConnector()->connect($uri, $context->withTlsContext($tlsContext), $cancellation);
    $socket->setupTls($cancellation);

    return $socket;
}

/**
 * Returns a pair of connected stream socket resources.
 *
 * @param positive-int $chunkSize
 *
 * @return array{ResourceSocket, ResourceSocket} Pair of socket resources.
 *
 * @throws SocketException If creating the sockets fails.
 */
function createSocketPair(int $chunkSize = ResourceSocket::DEFAULT_CHUNK_SIZE): array
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
