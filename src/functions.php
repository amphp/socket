<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Loop;
use Amp\Promise;

const LOOP_CONNECTOR_IDENTIFIER = Connector::class;

/**
 * Listen for client connections on the specified server address.
 *
 * If you want to accept TLS connections, you have to use `yield $socket->setupTls()` after accepting new clients.
 *
 * @param string            $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ServerBindContext $context Context options for listening.
 *
 * @return Server
 *
 * @throws SocketException If binding to the specified URI failed.
 * @throws \Error If an invalid scheme is given.
 */
function listen(string $uri, ServerBindContext $context = null): Server
{
    $context = $context ?? new ServerBindContext;

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
        throw new SocketException(\sprintf('Could not create server %s: [Error: #%d] %s', $uri, $errno, $errstr), $errno);
    }

    return new Server($server, $context->getChunkSize());
}

/**
 * Create a new Datagram (UDP server) on the specified server address.
 *
 * @param string            $uri URI in scheme://host:port format. UDP is assumed if no scheme is present.
 * @param ServerBindContext $context Context options for listening.
 *
 * @return DatagramSocket
 *
 * @throws SocketException If binding to the specified URI failed.
 * @throws \Error If an invalid scheme is given.
 */
function bindDatagramSocket(string $uri, ServerBindContext $context = null): DatagramSocket
{
    $context = $context ?? new ServerBindContext;

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
        throw new SocketException(\sprintf('Could not create datagram %s: [Error: #%d] %s', $uri, $errno, $errstr), $errno);
    }

    return new DatagramSocket($server, $context->getChunkSize());
}

/**
 * Set or access the global socket Connector instance.
 *
 * @param Connector|null $connector
 *
 * @return Connector
 */
function connector(Connector $connector = null): Connector
{
    if ($connector === null) {
        if ($connector = Loop::getState(LOOP_CONNECTOR_IDENTIFIER)) {
            return $connector;
        }

        $connector = new DnsConnector;
    }

    Loop::setState(LOOP_CONNECTOR_IDENTIFIER, $connector);

    return $connector;
}

/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * @param string                 $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ClientConnectContext   $context Socket connect context to use when connecting.
 * @param CancellationToken|null $token
 *
 * @return Promise<EncryptableClientSocket>
 *
 * @throws SocketException
 */
function connect(string $uri, ClientConnectContext $context = null, CancellationToken $token = null): Promise
{
    return connector()->connect($uri, $context, $token);
}

/**
 * Returns a pair of connected stream socket resources.
 *
 * @return resource[] Pair of socket resources.
 *
 * @throws SocketException If creating the sockets fails.
 */
function pair(): array
{
    if (($sockets = @\stream_socket_pair(\stripos(PHP_OS, 'win') === 0 ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)) === false) {
        $message = 'Failed to create socket pair.';
        if ($error = \error_get_last()) {
            $message .= \sprintf(' Errno: %d; %s', $error['type'], $error['message']);
        }
        throw new SocketException($message);
    }

    return $sockets;
}
