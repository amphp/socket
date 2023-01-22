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

    private readonly \Closure $errorHandler;

    public function __construct(private readonly ?DnsResolver $dnsResolver = null)
    {
        $this->errorHandler = static fn () => true;
    }

    /**
     * Establish a socket connection to the specified URI.
     *
     * @template TAddress of SocketAddress
     *
     * @param TAddress|string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
     * @param ConnectContext|null $context Socket connect context to use when connecting.
     *
     * @return Socket<TAddress>
     *
     * @throws ConnectException
     * @throws CancelledException
     */
    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): Socket {
        $context ??= new ConnectContext;
        $cancellation ??= new NullCancellation;

        if ($uri instanceof SocketAddress) {
            $uriString = match ($uri->getType()) {
                SocketAddressType::Internet => 'tcp://' . $uri->toString(),
                SocketAddressType::Unix => 'unix://' . $uri->toString(),
            };
        } else {
            \assert(\is_string($uri));

            $uriString = $uri;
        }

        $uris = $this->resolve($uriString, $context);

        $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
        $timeout = $context->getConnectTimeout();

        $failures = [];
        foreach ($uris as $builtUri) {
            try {
                $streamContext = \stream_context_create($context->withoutTlsContext()->toStreamContextArray());

                \set_error_handler($this->errorHandler);

                try {
                    $socket = \stream_socket_client($builtUri, $errno, $errstr, flags: $flags, context: $streamContext);
                } finally {
                    \restore_error_handler();
                }

                if (!$socket) {
                    throw new ConnectException(\sprintf(
                        'Connection to %s @ %s failed: (Error #%d) %s%s',
                        $uriString,
                        $builtUri,
                        $errno,
                        $errstr,
                        $failures ? '; previous attempts: ' . \implode($failures) : ''
                    ), $errno);
                }

                \stream_set_blocking($socket, false);

                $deferred = new DeferredFuture;
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
                        $uriString,
                        $builtUri,
                        $timeout,
                        $failures ? '; previous attempts: ' . \implode($failures) : ''
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
                        $uriString,
                        $builtUri,
                        $failures ? '; previous attempts: ' . \implode($failures) : ''
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

                $failures[] = "$uriString @ $builtUri ($reason)";

                continue; // Could not connect to host, try next host in the list.
            }

            /**
             * @psalm-suppress PossiblyUndefinedVariable
             * @var ResourceSocket<TAddress>
             */
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
     * @return non-empty-list<string>
     */
    private function resolve(string $uri, ConnectContext $context): array
    {
        [$scheme, $host, $port] = Internal\parseUri($uri);

        if ($host[0] === '[') {
            $host = \substr($host, 1, -1);
        }

        if ($port === 0 || \inet_pton($host)) {
            // Host is already an IP address or file path.
            return [$uri];
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

        $uris = [];
        foreach ($records as $record) {
            if ($record->getType() === DnsRecord::AAAA) {
                $uris[] = \sprintf('%s://[%s]:%d', $scheme, $record->getValue(), $port);
            } else {
                $uris[] = \sprintf('%s://%s:%d', $scheme, $record->getValue(), $port);
            }
        }

        return $uris;
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
