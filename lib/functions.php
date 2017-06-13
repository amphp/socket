<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Success;
use function Amp\call;

/**
 * Listen for client connections on the specified server address.
 *
 * @param string              $uri
 * @param callable            $handler callable(Socket): mixed
 * @param ServerSocketContext $socketContext
 * @param TlsContext          $tlsContext
 *
 * @return Server
 *
 * @see rawListen()
 */
function listen(string $uri, callable $handler, ServerSocketContext $socketContext = null, TlsContext $tlsContext = null): Server {
    return new Server(rawListen($uri, $socketContext, $tlsContext), $handler);
}

/**
 * Listen for client connections on the specified server address.
 *
 * @param string              $uri
 * @param ServerSocketContext $socketContext
 * @param TlsContext          $tlsContext
 *
 * @return resource
 *
 * @see listen()
 */
function rawListen(string $uri, ServerSocketContext $socketContext = null, TlsContext $tlsContext = null) {
    $scheme = strstr($uri, "://", true);

    if (!in_array($scheme, ["tcp", "udp", "unix", "udg"])) {
        throw new \Error("Only tcp, udp, unix and udg schemes allowed for server creation");
    }

    $context = \stream_context_create(array_merge(
        $socketContext->toStreamContextArray(),
        $tlsContext->toStreamContextArray(TlsContext::SERVER)
    ));

    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

    if (!$server || $errno) {
        throw new SocketException(\sprintf("Could not create server %s: [Error: #%d] %s", $uri, $errno, $errstr));
    }

    return $server;
}

/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * If a scheme is not specified in the $uri parameter, TCP is assumed. Allowed schemes include:
 * [tcp, udp, unix, udg].
 *
 * @param string                 $uri
 * @param ClientSocketContext    $socketContext
 * @param CancellationToken|null $token
 *
 * @return \Amp\Promise<\Amp\Socket\Socket>
 */
function connect(string $uri, ClientSocketContext $socketContext = null, CancellationToken $token = null): Promise {
    return call(function () use ($uri, $socketContext, $token) {
        $socket = yield rawConnect($uri, $socketContext, $token);
        return new Socket($socket);
    });
}

/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * If a scheme is not specified in the $uri parameter, TCP is assumed. Allowed schemes include:
 * [tcp, udp, unix, udg].
 *
 * @param string                 $uri
 * @param ClientSocketContext    $socketContext
 * @param CancellationToken|null $token
 *
 * @return \Amp\Promise<resource>
 */
function rawConnect(string $uri, ClientSocketContext $socketContext, CancellationToken $token = null): Promise {
    return new Coroutine(Internal\connect($uri, $socketContext, $token));
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

/**
 * Asynchronously establish an encrypted TCP connection (non-blocking).
 *
 * Note: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string              $uri
 * @param ClientSocketContext $socketContext
 * @param TlsContext          $tlsContext
 *
 * @return Promise<Socket>
 */
function cryptoConnect(string $uri, ClientSocketContext $socketContext = null, TlsContext $tlsContext = null): Promise {
    return call(function () use ($uri, $socketContext, $tlsContext) {
        $socket = yield rawCryptoConnect($uri, $socketContext, $tlsContext);
        return new Socket($socket);
    });
}

/**
 * Asynchronously establish an encrypted TCP connection (non-blocking).
 *
 * Note: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string              $uri
 * @param ClientSocketContext $socketContext
 * @param TlsContext          $tlsContext
 *
 * @return Promise<resource>
 */
function rawCryptoConnect(string $uri, ClientSocketContext $socketContext = null, TlsContext $tlsContext = null): Promise {
    return new Coroutine(Internal\cryptoConnect($uri, $socketContext, $tlsContext));
}

/**
 * Enable encryption on an existing socket stream.
 *
 * @param resource   $socket
 * @param TlsContext $tlsContext
 *
 * @return Promise
 */
function enableCrypto($socket, TlsContext $tlsContext = null): Promise {
    $ctx = \stream_context_get_options($socket);

    if (!empty($ctx['ssl']) && !empty($ctx["ssl"]["_enabled"])) {
        $compare = $tlsContext->toStreamContextArray(TlsContext::CLIENT);
        $ctx = $ctx['ssl'];

        unset(
            $ctx['peer_certificate'],
            $ctx['peer_certificate_chain'],
            $ctx['SNI_server_name'],
            $ctx['_enabled']
        );

        if ($ctx == $compare) {
            return new Success($socket);
        }

        return call(function () use ($socket, $tlsContext) {
            $socket = yield disableCrypto($socket);
            return enableCrypto($socket, $tlsContext);
        });
    }

    $options["_enabled"] = true; // avoid recursion

    \stream_context_set_option($socket, $tlsContext->toStreamContextArray(TlsContext::CLIENT));
    $result = \stream_socket_enable_crypto($socket, $enable = true, $tlsContext->toStreamCryptoMethod(TlsContext::CLIENT));

    if ($result === true) {
        return new Success($socket);
    } elseif ($result === false) {
        return new Failure(new CryptoException(
            "Crypto negotiation failed: " . \error_get_last()["message"]
        ));
    }

    $deferred = new Deferred;

    Loop::onReadable($socket, 'Amp\Socket\Internal\onCryptoWatchReadability', [$deferred, $tlsContext]);

    return $deferred->promise();
}

/**
 * Disable encryption on an existing socket stream.
 *
 * @param resource $socket
 *
 * @return Promise
 */
function disableCrypto($socket): Promise {
    // note that disabling crypto *ALWAYS* returns false, immediately
    \stream_context_set_option($socket, ["ssl" => ["_enabled" => false]]);
    \stream_socket_enable_crypto($socket, false);
    return new Success($socket);
}
