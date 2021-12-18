<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Dns;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

final class DnsSocketConnector implements SocketConnector
{
    private ?Dns\Resolver $resolver;

    public function __construct(?Dns\Resolver $resolver = null)
    {
        $this->resolver = $resolver;
    }

    public function connect(
        string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): EncryptableSocket {
        $context ??= new ConnectContext;
        $cancellation ??= new NullCancellation;
        $attempt = 0;
        $uris = [];
        $failures = [];

        [$scheme, $host, $port] = Internal\parseUri($uri);

        if ($host[0] === '[') {
            $host = \substr($host, 1, -1);
        }

        if ($port === 0 || @\inet_pton($host)) {
            // Host is already an IP address or file path.
            $uris = [$uri];
        } else {
            $resolver = $this->resolver ?? Dns\resolver();

            // Host is not an IP address, so resolve the domain name.
            $records = $resolver->resolve($host, $context->getDnsTypeRestriction());

            // Usually the faster response should be preferred, but we don't have a reliable way of determining IPv6
            // support, so we always prefer IPv4 here.
            \usort($records, static function (Dns\Record $a, Dns\Record $b) {
                return $a->getType() - $b->getType();
            });

            foreach ($records as $record) {
                if ($record->getType() === Dns\Record::AAAA) {
                    $uris[] = \sprintf('%s://[%s]:%d', $scheme, $record->getValue(), $port);
                } else {
                    $uris[] = \sprintf('%s://%s:%d', $scheme, $record->getValue(), $port);
                }
            }
        }

        $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
        $timeout = $context->getConnectTimeout();

        foreach ($uris as $builtUri) {
            try {
                $streamContext = \stream_context_create($context->withoutTlsContext()->toStreamContextArray());

                /** @psalm-suppress NullArgument */
                if (!$socket = @\stream_socket_client($builtUri, $errno, $errstr, null, $flags, $streamContext)) {
                    throw new ConnectException(\sprintf(
                        'Connection to %s failed: [Error #%d] %s%s',
                        $uri,
                        $errno,
                        $errstr,
                        $failures ? '; previous attempts: ' . \implode($failures) : ''
                    ), $errno);
                }

                \stream_set_blocking($socket, false);

                $deferred = new DeferredFuture;
                $id = $cancellation->subscribe(\Closure::fromCallable([$deferred, 'error']));
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
                        $uri,
                        $timeout,
                        $failures ? '; previous attempts: ' . \implode($failures) : ''
                    ), 110); // See ETIMEDOUT in http://www.virtsync.com/c-error-codes-include-errno
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
                        $uri,
                        $failures ? '; previous attempts: ' . \implode($failures) : ''
                    ), 111); // See ECONNREFUSED in http://www.virtsync.com/c-error-codes-include-errno
                }
            } catch (ConnectException $e) {
                // Includes only error codes used in this file, as error codes on other OS families might be different.
                // In fact, this might show a confusing error message on OS families that return 110 or 111 by itself.
                $knownReasons = [
                    110 => 'connection timeout',
                    111 => 'connection refused',
                ];

                $code = $e->getCode();
                $reason = $knownReasons[$code] ?? ('Error #' . $code);

                if (++$attempt === $context->getMaxAttempts()) {
                    break;
                }

                $failures[] = "{$uri} ({$reason})";

                continue; // Could not connect to host, try next host in the list.
            }

            /** @psalm-suppress PossiblyUndefinedVariable */
            return ResourceSocket::fromClientSocket($socket, $context->getTlsContext());
        }

        /**
         * This is reached if either all URIs failed or the maximum number of attempts is reached.
         *
         * @noinspection PhpUndefinedVariableInspection
         * @psalm-suppress PossiblyUndefinedVariable
         */
        throw $e;
    }
}
