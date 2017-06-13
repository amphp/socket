<?php

namespace Amp\Socket\Internal;

use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\CryptoException;
use Amp\Success;
use function Amp\call;

/**
 * Parse an URI into [scheme, host, port].
 *
 * @param string $uri
 *
 * @return array
 *
 * @throws \Error If an invalid URI has been passed.
 *
 * @internal
 */
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

/**
 * Enable encryption on an existing socket stream.
 *
 * @param resource $socket
 * @param array    $options
 *
 * @return Promise
 *
 * @throws \Error If an invalid options array has been passed.
 *
 * @internal
 */
function enableCrypto($socket, array $options): Promise {
    if (!isset($options["ssl"]["crypto_method"])) {
        throw new \Error("'crypto_method' option is a required parameter");
    }

    $ctx = \stream_context_get_options($socket);

    if (!empty($ctx['ssl']) && !empty($ctx["ssl"]["_enabled"])) {
        $cmp = $options["ssl"];
        $ctx = $ctx['ssl'];

        unset(
            $ctx['peer_certificate'],
            $cmp['peer_certificate'],
            $ctx['peer_certificate_chain'],
            $cmp['peer_certificate_chain'],
            $ctx['SNI_server_name'],
            $cmp['SNI_server_name'],
            $ctx['_enabled'],
            $cmp['_enabled']
        );

        // Use weak comparison so the order of the items doesn't matter
        if ($ctx == $cmp) {
            return new Success;
        }

        return call(function () use ($socket, $options) {
            yield disableCrypto($socket);
            return enableCrypto($socket, $options);
        });
    }

    $options["ssl"]["_enabled"] = true; // avoid recursion

    \error_clear_last();

    \stream_context_set_option($socket, $options);
    $result = \stream_socket_enable_crypto($socket, $enable = true, $options["ssl"]["crypto_method"]);

    // Yes, that function can return true / false / 0, don't use weak comparisons.
    if ($result === true) {
        return new Success($socket);
    } elseif ($result === false) {
        return new Failure(new CryptoException(
            "Crypto negotiation failed: " . (\error_get_last()["message"] ?? "Unknown error")
        ));
    }

    $deferred = new Deferred;

    Loop::onReadable($socket, 'Amp\Socket\Internal\onCryptoWatchReadability', [$deferred, $options]);

    return $deferred->promise();
}

/**
 * Disable encryption on an existing socket stream.
 *
 * @param resource $socket
 *
 * @return Promise
 *
 * @internal
 */
function disableCrypto($socket): Promise {
    // note that disabling crypto *ALWAYS* returns false, immediately
    \stream_context_set_option($socket, ["ssl" => ["_enabled" => false]]);
    \stream_socket_enable_crypto($socket, false);

    return new Success;
}

/**
 * Watches for crypto readability to wait for a successful TLS handshake.
 *
 * @param string   $watcherId
 * @param resource $socket
 * @param array    $data
 */
function onCryptoWatchReadability($watcherId, $socket, $data) {
    /** @var Deferred $deferred */
    /** @var array $options */
    list($deferred, $options) = $data;

    $result = \stream_socket_enable_crypto($socket, $enable = true, $options["ssl"]["crypto_method"]);

    // If $result is 0, just wait for the next invocation
    if ($result === true) {
        Loop::cancel($watcherId);
        $deferred->resolve();
    } elseif ($result === false) {
        Loop::cancel($watcherId);
        $deferred->fail(new CryptoException("Crypto negotiation failed: " . (\feof($socket)
                ? "Connection reset by peer"
                : \error_get_last()["message"])));
    }
}

/**
 * Normalizes "bindto" options to add a ":0" in case no port is present, otherwise PHP will silently ignore those.
 *
 * @param string|null $bindTo
 *
 * @return string|null
 *
 * @throws \Error If an invalid option has been passed.
 */
function normalizeBindToOption(string $bindTo = null) {
    if ($bindTo === null) {
        // all fine
    } elseif (\preg_match("(\\[([0-9a-f.:]+)\\](:\\d+))", $bindTo ?? "", $match)) {
        list($ip, $port) = $match;

        if (@\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv6 address: {$ip}");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: {$port}");
        }

        return "[{$ip}]:" . ($port ?: 0);
    }

    if (\preg_match("((\\d+\\.\\d+\\.\\d+\\.\\d+)(:\\d+))", $bindTo ?? "", $match)) {
        list($ip, $port) = $match;

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
