<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use function Amp\Dns\dnsResolver;

final class DnsSocketConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public function __construct(
        private readonly ?DnsResolver $dnsResolver = null,
        private readonly ?ConnectPolicy $connectPolicy = null,
    ) {
    }

    #[\Override]
    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket {
        $context ??= new ConnectContext();
        $cancellation ??= new NullCancellation();

        if ($uri instanceof SocketAddress) {
            $uri = match ($uri->getType()) {
                SocketAddressType::Internet => 'tcp://' . $uri->toString(),
                SocketAddressType::Unix => 'unix://' . $uri->toString(),
            };
        }

        [$scheme, $host, $port] = Internal\parseUri($uri);

        [$addresses, $resolved] = $this->resolve($uri, $host, $port, $context);

        $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
        $timeout = $context->getConnectTimeout();

        $failures = [];
        $lastMessage = '';
        $lastCode = 0;
        $onlyDenied = true;
        foreach ($addresses as $address) {
            // Connect to the resolved address, since PHP can parse the original URI differently.
            $builtUri = $scheme . '://' . $address->toString();

            // Only name the address if it was resolved from a name, otherwise it's the address that was given.
            $authority = $resolved ? $uri . ' @ ' . $builtUri : $builtUri;

            if ($this->connectPolicy?->isAllowed($address) === false) {
                $reason = 'denied by the connect policy';

                $lastMessage = \sprintf(
                    'Connection to %s failed: %s%s',
                    $authority,
                    $reason,
                    $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
                );
                $lastCode = 0;

                $failures[] = "$authority ($reason)";

                continue; // Try the next address.
            }

            try {
                $streamContext = \stream_context_create($context->withoutTlsContext()->toStreamContextArray());

                \set_error_handler(static function (int $errno, string $errstr) use (
                    &$failures,
                    $authority,
                ): never {
                    throw new ConnectException(\sprintf(
                        'Connection to %s failed: (Error #%d) %s%s',
                        $authority,
                        $errno,
                        $errstr,
                        $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
                    ), $errno);
                });

                try {
                    $socket = \stream_socket_client($builtUri, flags: $flags, context: $streamContext);
                } finally {
                    \restore_error_handler();
                }

                \assert($socket !== false); // For Psalm, the error handler will throw for a false return case.

                \stream_set_blocking($socket, false);

                $deferred = new DeferredFuture();
                $id = $cancellation->subscribe($deferred->error(...));
                $watcher = EventLoop::onWritable(
                    $socket,
                    static function (string $watcher) use ($deferred, $id, $cancellation): void {
                        EventLoop::cancel($watcher);
                        $cancellation->unsubscribe($id);
                        $deferred->complete();
                    }
                );

                try {
                    $deferred->getFuture()->await(new TimeoutCancellation($timeout));
                } catch (CancelledException) {
                    $cancellation->throwIfRequested(); // Rethrow if cancelled from user-provided token.

                    throw new ConnectException(\sprintf(
                        'Connecting to %s failed: timeout exceeded (%0.3f s)%s',
                        $authority,
                        $timeout,
                        $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
                    ), Internal\CONNECTION_TIMEOUT); // See ETIMEDOUT in http://www.virtsync.com/c-error-codes-include-errno
                } finally {
                    EventLoop::cancel($watcher);
                    $cancellation->unsubscribe($id);
                }

                // The following hack looks like the only way to detect connection refused errors with PHP's stream sockets.
                /** @psalm-suppress TypeDoesNotContainType */
                if (\stream_socket_get_name($socket, true) === false) {
                    \fclose($socket);
                    throw new ConnectException(\sprintf(
                        'Connection to %s refused%s',
                        $authority,
                        $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
                    ), Internal\CONNECTION_REFUSED); // See ECONNREFUSED in http://www.virtsync.com/c-error-codes-include-errno
                }
            } catch (ConnectException $e) {
                $onlyDenied = false;

                // Includes only error codes used in this file, as error codes on other OS families might be different.
                // In fact, this might show a confusing error message on OS families that return 110 or 111 by itself.
                $knownReasons = [
                    Internal\CONNECTION_BUSY => 'connection busy',
                    Internal\CONNECTION_TIMEOUT => 'connection timeout',
                    Internal\CONNECTION_REFUSED => 'connection refused',
                ];

                $lastMessage = $e->getMessage();
                $lastCode = $e->getCode();

                $reason = $knownReasons[$lastCode] ?? ('Error #' . $lastCode);

                $failures[] = "$authority ($reason)";

                continue; // Could not connect to host, try next host in the list.
            }

            /** @psalm-suppress PossiblyUndefinedVariable */
            return ResourceSocket::fromClientSocket($socket, $context->getTlsContext());
        }

        // Every address failed. Only a denial of every address is final,
        // otherwise a retry could reach an address that failed.
        if ($onlyDenied) {
            throw new ConnectPolicyException($lastMessage);
        }

        throw new ConnectException($lastMessage, $lastCode);
    }

    /**
     * @return array{non-empty-list<SocketAddress>, bool} The addresses to try, in order, and whether they were
     *     resolved from a name.
     */
    private function resolve(string $uri, string $host, int $port, ConnectContext $context): array
    {
        if (\stripos($uri, 'unix://') === 0) {
            // The path is what follows the scheme, as PHP reads it.
            $path = \substr($uri, \strlen('unix://'));

            // The OS ends a path at a NUL byte, so another path than the given one would be used. A leading NUL byte
            // marks an abstract socket instead, whose name is used with its full length, so it may contain NUL bytes.
            if (!\str_starts_with($path, "\0") && \str_contains($path, "\0")) {
                throw new ConnectException(\sprintf('Connection to %s failed: path contains a NUL byte', $uri));
            }

            return [[new UnixAddress($path)], false];
        }

        if ($host[0] === '[') {
            $host = \substr($host, 1, -1);
        }

        if ($port > 65535) {
            throw new ConnectException(\sprintf('Connection to %s failed: invalid port', $uri));
        }

        $address = self::createAddress($host, $port);
        if ($address) {
            // Host is already an IP address.
            return [[$address], false];
        }

        if ($port === 0) {
            throw new ConnectException(\sprintf('Connection to %s failed: port required', $uri));
        }

        $resolver = $this->dnsResolver ?? dnsResolver();

        try {
            // Host is not an IP address, so resolve the domain name.
            $records = $resolver->resolve(
                $host,
                $context->getDnsTypeRestriction() ?? $this->getDnsTypeRestrictionFromBindTo($context)
            );
        } catch (DnsException $exception) {
            throw new ConnectException(
                message: \sprintf('DNS resolution for %s failed: %s', $host, $exception->getMessage()),
                previous: $exception,
            );
        }

        // Usually the faster response should be preferred, but we don't have a reliable way of determining IPv6
        // support, so we always prefer IPv4 here.
        \usort($records, static fn (DnsRecord $a, DnsRecord $b) => $a->getType() - $b->getType());

        $addresses = [];
        foreach ($records as $record) {
            $address = self::createAddress($record->getValue(), $port);
            if ($address) {
                $addresses[] = $address;
            }
        }

        if (!$addresses) {
            throw new ConnectException(\sprintf('Connection to %s failed: DNS returned no valid IP address', $uri));
        }

        return [$addresses, true];
    }

    /**
     * @param int<0, 65535> $port
     *
     * @return InternetAddress|null Returns null if the host is not an IP address.
     */
    private static function createAddress(string $host, int $port): ?InternetAddress
    {
        return \inet_pton($host) ? new InternetAddress($host, $port) : null;
    }

    private function getDnsTypeRestrictionFromBindTo(ConnectContext $context): ?int
    {
        $bindTo = $context->getBindTo();
        if ($bindTo === null) {
            return null;
        }

        if (\str_starts_with($bindTo, '[')) {
            return DnsRecord::AAAA;
        }

        return DnsRecord::A;
    }
}
