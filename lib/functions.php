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
 * If you want to accept TLS connections, you have to use `yield $socket->enableCrypto()` after accepting new clients.
 *
 * @param string              $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ServerListenContext $socketContext Context options for listening.
 * @param ServerTlsContext    $tlsContext Context options for TLS connections.
 *
 * @return Server
 *
 * @throws SocketException If binding to the specified URI failed.
 */
function listen(string $uri, ServerListenContext $socketContext = null, ServerTlsContext $tlsContext = null): Server {
    $socketContext = $socketContext ?? new ServerListenContext;
    $tlsContext = $tlsContext ?? new ServerTlsContext;

    $scheme = \strstr($uri, "://", true);

    if ($scheme === false) {
        $scheme = "tcp";
    }

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
        throw new SocketException(\sprintf("Could not create server %s: [Error: #%d] %s", $uri, $errno, $errstr), $errno);
    }

    return new Server($server, 65536);
}

/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * @param string                 $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ClientConnectContext   $socketContext Socket connect context to use when connecting.
 * @param CancellationToken|null $token
 *
 * @return Promise<\Amp\Socket\ClientSocket>
 */
function connect(string $uri, ClientConnectContext $socketContext = null, CancellationToken $token = null): Promise {
    return call(function () use ($uri, $socketContext, $token) {
        $socketContext = $socketContext ?? new ClientConnectContext;
        $token = $token ?? new NullCancellationToken;
        $attempt = 0;
        $uris = [];

        list($scheme, $host, $port) = Internal\parseUri($uri);

        if ($host[0] === '[') {
            $host = substr($host, 1, -1);
        }
        
        if ($port === 0 || @\inet_pton($host)) {
            // Host is already an IP address or file path.
            $uris = [$uri];
        } else {
            // Host is not an IP address, so resolve the domain name.
            $records = yield Dns\resolve($host, $socketContext->getDnsTypeRestriction());
            foreach ($records as $record) {
                /** @var Dns\Record $record */
                if ($record->getType() === Dns\Record::AAAA) {
                    $uris[] = \sprintf("%s://[%s]:%d", $scheme, $record->getValue(), $port);
                } else {
                    $uris[] = \sprintf("%s://%s:%d", $scheme, $record->getValue(), $port);
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
                    ), $errno);
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

            return new ClientSocket($socket);
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
 * @param ClientTlsContext     $tlsContext
 * @param CancellationToken    $token
 *
 * @return Promise<ClientSocket>
 */
function cryptoConnect(
    string $uri,
    ClientConnectContext $socketContext = null,
    ClientTlsContext $tlsContext = null,
    CancellationToken $token = null
): Promise {
    return call(function () use ($uri, $socketContext, $tlsContext, $token) {
        $tlsContext = $tlsContext ?? new ClientTlsContext;

        if ($tlsContext->getPeerName() === null) {
            $tlsContext = $tlsContext->withPeerName(\parse_url($uri, PHP_URL_HOST));
        }

        /** @var ClientSocket $socket */
        $socket = yield connect($uri, $socketContext, $token);

        $promise = $socket->enableCrypto($tlsContext);

        if ($token) {
            $deferred = new Deferred;
            $id = $token->subscribe([$deferred, "fail"]);

            $promise->onResolve(function ($exception) use ($id, $token, $deferred) {
                if ($token->isRequested()) {
                    return;
                }

                $token->unsubscribe($id);

                if ($exception) {
                    $deferred->fail($exception);
                    return;
                }

                $deferred->resolve();
            });

            $promise = $deferred->promise();
        }

        try {
            yield $promise;
        } catch (\Throwable $exception) {
            $socket->close();
            throw $exception;
        }

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
