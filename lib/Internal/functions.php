<?php

namespace Amp\Socket\Internal;

use Amp\CancellationToken;
use Amp\Deferred;
use Amp\Dns;
use Amp\Loop;
use Amp\NullCancellationToken;
use Amp\Promise;
use Amp\Socket\ClientSocketContext;
use Amp\Socket\ConnectException;
use Amp\Socket\CryptoException;
use Amp\Socket\TlsContext;
use Amp\TimeoutException;
use function Amp\Socket\enableCrypto;

/** @internal */
function connect(string $uri, ClientSocketContext $socketContext = null, CancellationToken $token = null): \Generator {
    $socketContext = $socketContext ?? new ClientSocketContext;
    $token = $token ?? new NullCancellationToken;
    $attempt = 0;
    $uris = [];

    list($scheme, $host, $port) = parseUri($uri);

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

        return $socket;
    }

    if ($e instanceof TimeoutException) {
        throw new ConnectException(\sprintf("Connecting to %s failed: timeout exceeded (%d ms)", $uri, $timeout));
    }

    throw $e;
}

/** @internal */
function cryptoConnect(string $uri, ClientSocketContext $socketContext = null, TlsContext $tlsContext = null): \Generator {
    $tlsContext = $tlsContext ?? new TlsContext;

    if ($tlsContext->getPeerName() === null) {
        $tlsContext->withPeerName(\parse_url($uri, PHP_URL_HOST));
    }

    $socket = yield from connect($uri, $socketContext);
    yield enableCrypto($socket, $tlsContext);

    return $socket;
}

/** @internal */
function parseUri(string $uri): array {
    if (\stripos($uri, "unix://") === 0 || \stripos($uri, "udg://") === 0) {
        list($scheme, $path) = \explode("://", $uri, 2);
        return [$scheme, \ltrim($path, "/"), 0];
    }

    if (!$uriParts = @\parse_url($uri)) {
        throw new \Error(
            "Invalid URI: {$uri}"
        );
    }

    $scheme = $uriParts["scheme"] ?? "tcp";
    $host = $uriParts["host"] ?? "";
    $port = $uriParts["port"] ?? 0;

    if (!($scheme === "tcp" || $scheme === "udp")) {
        throw new \Error(
            "Invalid URI scheme ({$scheme}); tcp, udp, unix or udg scheme expected"
        );
    }

    if (empty($host) || empty($port)) {
        throw new \Error(
            "Invalid URI ({$uri}); host and port components required"
        );
    }

    if (\strpos($host, ":") !== false) { // IPv6 address
        $host = \sprintf("[%s]", \trim($host, "[]"));
    }

    return [$scheme, $host, $port];
}

/** @internal */
function onCryptoWatchReadability($watcherId, $socket, $data) {
    /** @var Deferred $deferred */
    /** @var TlsContext $tlsContext */
    list($deferred, $tlsContext) = $data;

    $cryptoMethod = $tlsContext->toStreamCryptoMethod(TlsContext::CLIENT);
    $result = \stream_socket_enable_crypto($socket, $enable = true, $cryptoMethod);

    if ($result === true) {
        Loop::cancel($watcherId);
        $deferred->resolve($socket);
    } else if ($result === false) {
        Loop::cancel($watcherId);
        $deferred->fail(new CryptoException("Crypto negotiation failed: " . (\feof($socket)
                ? "Connection reset by peer"
                : \error_get_last()["message"])));
    }
}

function normalizeBindToOption(string $bindTo = null) {
    if ($bindTo === null) {
        // all fine
    } else if (\preg_match("(\\[([0-9a-f.:]+)\\](:\\d+))", $bindTo ?? "", $match)) {
        list ($ip, $port) = $match;

        if (@\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv6 address: {$ip}");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: {$port}");
        }

        return "[{$ip}]:" . ($port ?: 0);
    }

    if (\preg_match("((\\d+\\.\\d+\\.\\d+\\.\\d+)(:\\d+))", $bindTo ?? "", $match)) {
        list ($ip, $port) = $match;

        if (@\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv4 address: {$ip}");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: {$port}");
        }

        return "{$ip}:" . ($port ?: 0);
    }

    throw new \Error("Invalid bindTo value: {$bindTo}");
}
