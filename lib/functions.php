<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Deferred;
use Amp\Dns;
use Amp\Loop;
use Amp\NullCancellationToken;
use Amp\Promise;
use Amp\TimeoutException;
use function Amp\call;

/**
 * Listen for client connections on the specified server address.
 *
 * @param string              $uri
 * @param callable            $handler callable(Socket): mixed
 * @param ServerListenContext $socketContext
 * @param ServerTlsContext    $tlsContext
 *
 * @return Server
 *
 * @see rawListen()
 */
function listen(string $uri, callable $handler, ServerListenContext $socketContext = null, ServerTlsContext $tlsContext = null): Server {
    $socketContext = $socketContext ?? new ServerListenContext;
    $tlsContext = $tlsContext ?? new ServerTlsContext;

    $scheme = \strstr($uri, "://", true);

    if (!\in_array($scheme, ["tcp", "udp", "unix", "udg"])) {
        throw new \Error("Only tcp, udp, unix and udg schemes allowed for server creation");
    }

    $context = \stream_context_create(\array_merge(
        $socketContext->toStreamContextArray(),
        $tlsContext->toStreamContextArray()
    ));

    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

    if (!$server || $errno) {
        throw new SocketException(\sprintf("Could not create server %s: [Error: #%d] %s", $uri, $errno, $errstr));
    }

    return new Server($server, $handler);
}

/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * If a scheme is not specified in the $uri parameter, TCP is assumed. Allowed schemes include:
 * [tcp, udp, unix, udg].
 *
 * @param string                 $uri
 * @param ClientConnectContext   $socketContext
 * @param CancellationToken|null $token
 *
 * @return \Amp\Promise<\Amp\Socket\Socket>
 */
function connect(string $uri, ClientConnectContext $socketContext = null, CancellationToken $token = null): Promise {
    return call(function () use ($uri, $socketContext, $token) {
        $socketContext = $socketContext ?? new ClientConnectContext;
        $token = $token ?? new NullCancellationToken;
        $attempt = 0;
        $uris = [];

        list($scheme, $host, $port) = Internal\parseUri($uri);

        if ($port === 0 || @\inet_pton($host)) {
            // Host is already an IP address or file path.
            $uris = [$uri];
        } else {
            // Host is not an IP address, so resolve the domain name.
            $records = yield Dns\resolve($host);
            foreach ($records as list($ip, $type)) {
                if ($type === Dns\Record::AAAA) {
                    $uris[] = \sprintf("%s://[%s]:%d", $scheme, $ip, $port);
                } else {
                    $uris[] = \sprintf("%s://%s:%d", $scheme, $ip, $port);
                }
            }
        }

        $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
        $timeout = $socketContext->getConnectTimeout();

        foreach ($uris as $builtUri) {
            if ($token) {
                $token->throwIfRequested();
            }

            try {
                $context = \stream_context_create($socketContext->toStreamContextArray());

                if (!$socket = @\stream_socket_client($builtUri, $errno, $errstr, null, $flags, $context)) {
                    throw new ConnectException(\sprintf(
                        "Connection to %s failed: [Error #%d] %s",
                        $uri,
                        $errno,
                        $errstr
                    ));
                }

                \stream_set_blocking($socket, false);

                $deferred = new Deferred;
                $watcher = Loop::onWritable($socket, [$deferred, 'resolve']);

                try {
                    yield Promise\timeout($deferred->promise(), $timeout);
                } finally {
                    Loop::cancel($watcher);
                }

                // The following hack looks like the only way to detect connection refused errors with PHP's stream sockets.
                if (\stream_socket_get_name($socket, true) === false) {
                    \fclose($socket);
                    throw new ConnectException(\sprintf("Connection to %s refused", $uri));
                }
            } catch (\Exception $e) {
                if (++$attempt === $socketContext->getMaxAttempts()) {
                    break;
                }

                continue; // Could not connect to host, try next host in the list.
            }

            return new Socket($socket);
        }

        if ($e instanceof TimeoutException) {
            throw new ConnectException(\sprintf("Connecting to %s failed: timeout exceeded (%d ms)", $uri, $timeout));
        }

        throw $e;
    });
}

/**
 * Asynchronously establish an encrypted TCP connection (non-blocking).
 *
 * Note: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string               $uri
 * @param ClientConnectContext $socketContext
 * @param ServerTlsContext     $tlsContext
 *
 * @return Promise<Socket>
 */
function cryptoConnect(string $uri, ClientConnectContext $socketContext = null, ClientTlsContext $tlsContext = null): Promise {
    return call(function () use ($uri, $socketContext, $tlsContext) {
        $tlsContext = $tlsContext ?? new ClientTlsContext;

        if ($tlsContext->getPeerName() === null) {
            $tlsContext = $tlsContext->withPeerName(\parse_url($uri, PHP_URL_HOST));
        }

        /** @var Socket $socket */
        $socket = yield connect($uri, $socketContext);
        yield $socket->enableCrypto($tlsContext);

        return $socket;
    });
}

/**
 * Returns a pair of connected stream socket resources.
 *
 * @return resource[] Pair of socket resources.
 *
 * @throws \Amp\Socket\SocketException If creating the sockets fails.
 */
function pair(): array {
    if (($sockets = @\stream_socket_pair(\stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)) === false) {
        $message = "Failed to create socket pair.";
        if ($error = \error_get_last()) {
            $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
        }
        throw new SocketException($message);
    }

    return $sockets;
}
