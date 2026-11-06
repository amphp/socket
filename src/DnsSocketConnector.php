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

        $attempts = $this->resolve($uri, $context);

        $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
        $timeout = $context->getConnectTimeout();

        $failures = [];
        foreach ($attempts as [$builtUri, $address, $reason]) {
            if ($address && !$this->connectPolicy?->isAllowed($address)) {
                $reason = 'denied by the connect policy';
            }

            if ($reason !== null) {
                $e = new ConnectException(\sprintf(
                    'Connection to %s @ %s failed: %s%s',
                    $uri,
                    $builtUri,
                    $reason,
                    $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
                ));

                $failures[] = "$uri @ $builtUri ($reason)";

                continue; // Try the next address.
            }

            try {
                $streamContext = \stream_context_create($context->withoutTlsContext()->toStreamContextArray());

                \set_error_handler(static function (int $errno, string $errstr) use (
                    &$failures,
                    $uri,
                    $builtUri,
                ): never {
                    $authority = $uri === $builtUri ? $builtUri : $uri . ' @ ' . $builtUri;

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
                        'Connecting to %s @ %s failed: timeout exceeded (%0.3f s)%s',
                        $uri,
                        $builtUri,
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
                        'Connection to %s @ %s refused%s',
                        $uri,
                        $builtUri,
                        $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
                    ), Internal\CONNECTION_REFUSED); // See ECONNREFUSED in http://www.virtsync.com/c-error-codes-include-errno
                }
            } catch (ConnectException $e) {
                // Includes only error codes used in this file, as error codes on other OS families might be different.
                // In fact, this might show a confusing error message on OS families that return 110 or 111 by itself.
                $knownReasons = [
                    Internal\CONNECTION_BUSY => 'connection busy',
                    Internal\CONNECTION_TIMEOUT => 'connection timeout',
                    Internal\CONNECTION_REFUSED => 'connection refused',
                ];

                $code = $e->getCode();
                $reason = $knownReasons[$code] ?? ('Error #' . $code);

                $failures[] = "$uri @ $builtUri ($reason)";

                continue; // Could not connect to host, try next host in the list.
            }

            /** @psalm-suppress PossiblyUndefinedVariable */
            return ResourceSocket::fromClientSocket($socket, $context->getTlsContext());
        }

        /**
         * This is reached if either all URIs failed or the maximum number of attempts is reached.
         *
         * @noinspection PhpUndefinedVariableInspection
         * @psalm-suppress UndefinedVariable
         */
        throw $e;
    }

    /**
     * @return non-empty-list<array{string, SocketAddress|null, string|null}> For each connection attempt, the URI to
     *     connect to, the address to check with the connect policy, and the reason to skip the attempt.
     */
    private function resolve(string $uri, ConnectContext $context): array
    {
        // PHP and the URI parser read the userinfo differently, so it's rejected when a connect policy is set.
        [$scheme, $host, $port] = Internal\parseUri($uri, $this->connectPolicy === null);

        if ($this->connectPolicy && \stripos($uri, 'unix://') === 0) {
            // The path is what follows the scheme, as PHP reads it.
            return [[$uri, new UnixAddress(\substr($uri, \strlen('unix://'))), null]];
        }

        if ($host[0] === '[') {
            $host = \substr($host, 1, -1);
        }

        if ($port > 65535) {
            throw new ConnectException(\sprintf('Connection to %s failed: invalid port', $uri));
        }

        if ($port === 0 || \inet_pton($host)) {
            // Host is already an IP address or file path.
            return [$this->connectPolicy ? self::createAttempt($scheme, $host, $port) : [$uri, null, null]];
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

        $attempts = [];
        foreach ($records as $record) {
            if ($this->connectPolicy) {
                $attempts[] = self::createAttempt($scheme, $record->getValue(), $port);
            } elseif ($record->getType() === DnsRecord::AAAA) {
                $attempts[] = [\sprintf('%s://[%s]:%d', $scheme, $record->getValue(), $port), null, null];
            } else {
                $attempts[] = [\sprintf('%s://%s:%d', $scheme, $record->getValue(), $port), null, null];
            }
        }

        return $attempts;
    }

    /**
     * @param int<0, 65535> $port
     *
     * @return array{string, InternetAddress|null, string|null}
     */
    private static function createAttempt(string $scheme, string $host, int $port): array
    {
        if (!\inet_pton($host)) {
            return [$scheme . '://' . $host . ':' . $port, null, 'invalid IP address'];
        }

        $address = new InternetAddress($host, $port);

        // Connect to the checked address, since PHP can parse the original URI differently.
        return [$scheme . '://' . $address->toString(), $address, null];
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
