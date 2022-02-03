<?php

namespace Amp\Socket\Internal;

use Amp\Cancellation;
use Amp\NullCancellation;
use Amp\Socket\TlsException;
use League\Uri\UriString;
use Revolt\EventLoop;

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
function parseUri(string $uri): array
{
    if (\stripos($uri, 'unix://') === 0 || \stripos($uri, 'udg://') === 0) {
        [$scheme, $path] = \explode('://', $uri, 2);
        return [$scheme, \ltrim($path, '/'), 0];
    }

    if (!\str_contains($uri, '://')) {
        // Set a default scheme of tcp if none was given.
        $uri = 'tcp://' . $uri;
    }

    try {
        $uriParts = UriString::parse($uri);
    } catch (\Exception $exception) {
        throw new \Error("Invalid URI: $uri", 0, $exception);
    }

    $scheme = $uriParts['scheme'];
    $host = $uriParts['host'] ?? '';
    $port = $uriParts['port'] ?? 0;

    if (!\in_array($scheme, ['tcp', 'udp', 'unix', 'udg'], true)) {
        throw new \Error(
            "Invalid URI scheme ($scheme); tcp, udp, unix or udg scheme expected"
        );
    }

    if ($host === '' || $port === 0) {
        throw new \Error(
            "Invalid URI: $uri; host and port components required"
        );
    }

    if (\str_contains($host, ':')) { // IPv6 address
        $host = \sprintf('[%s]', \trim($host, '[]'));
    }

    return [$scheme, $host, $port];
}

/**
 * Enable encryption on an existing socket stream.
 *
 * @param resource $socket
 * @param array $options
 * @param Cancellation|null $cancellation
 *
 * @return void
 *
 * @internal
 */
function setupTls($socket, array $options, ?Cancellation $cancellation): void
{
    $cancellation ??= new NullCancellation;

    if (isset(\stream_get_meta_data($socket)['crypto'])) {
        throw new TlsException("Can't setup TLS, because it has already been set up");
    }

    \error_clear_last();
    \stream_context_set_option($socket, $options);

    try {
        \set_error_handler(static function (int $errno, string $errstr) {
            throw new TlsException('TLS negotiation failed: ' . $errstr);
        });

        $result = \stream_socket_enable_crypto($socket, enable: true);
        if ($result === false) {
            throw new TlsException('TLS negotiation failed: Unknown error');
        }
    } finally {
        \restore_error_handler();
    }

    // Yes, that function can return true / false / 0, don't use weak comparisons.
    if ($result === true) {
        /** @psalm-suppress InvalidReturnStatement */
        return;
    }

    while (true) {
        $cancellation->throwIfRequested();

        $suspension = EventLoop::getSuspension();

        // Watcher is guaranteed to be created, because we throw above if cancellation has already been requested
        $cancellationId = $cancellation->subscribe(static function ($e) use ($suspension, &$callbackId) {
            EventLoop::cancel($callbackId);

            $suspension->throw($e);
        });

        $callbackId = EventLoop::onReadable($socket, static function () use (
            $suspension,
            $cancellation,
            $cancellationId,
        ): void {
            $cancellation->unsubscribe($cancellationId);

            $suspension->resume();
        });

        try {
            $suspension->suspend();
        } finally {
            EventLoop::cancel($callbackId);
        }

        try {
            \set_error_handler(static function (int $errno, string $errstr) use ($socket) {
                if (\feof($socket)) {
                    $errstr = 'Connection reset by peer';
                }

                throw new TlsException('TLS negotiation failed: ' . $errstr);
            });

            $result = \stream_socket_enable_crypto($socket, enable: true);
            if ($result === false) {
                $message = \feof($socket) ? 'Connection reset by peer' : 'Unknown error';
                throw new TlsException('TLS negotiation failed: ' . $message);
            }
        } finally {
            \restore_error_handler();
        }

        // If $result is 0, just wait for the next invocation
        if ($result === true) {
            break;
        }
    }
}

/**
 * Disable encryption on an existing socket stream.
 *
 * @param resource $socket
 *
 * @return void
 *
 * @internal
 * @psalm-suppress InvalidReturnType
 */
function shutdownTls($socket): void
{
    // note that disabling crypto *ALWAYS* returns false, immediately
    // don't set _enabled to false, TLS can be setup only once
    @\stream_socket_enable_crypto($socket, enable: false);
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
function normalizeBindToOption(string $bindTo = null): ?string
{
    if ($bindTo === null) {
        return null;
    }

    if (\preg_match("/\\[(?P<ip>[0-9a-f:]+)](:(?P<port>\\d+))?$/", $bindTo, $match)) {
        $ip = $match['ip'];
        $port = $match['port'] ?? 0;

        if (@\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv6 address: $ip");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: $port");
        }

        return "[$ip]:$port";
    }

    if (\preg_match("/(?P<ip>\\d+\\.\\d+\\.\\d+\\.\\d+)(:(?P<port>\\d+))?$/", $bindTo, $match)) {
        $ip = $match['ip'];
        $port = $match['port'] ?? 0;

        if (@\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv4 address: $ip");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: $port");
        }

        return "$ip:$port";
    }

    throw new \Error("Invalid bindTo value: $bindTo");
}
