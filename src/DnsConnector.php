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

final class DnsConnector implements Connector
{
    private $resolver;

    public function __construct(?Dns\Resolver $resolver = null)
    {
        $this->resolver = $resolver;
    }

    public function connect(string $uri, ?ConnectContext $context = null, ?CancellationToken $token = null): Promise
    {
        $resolver = $this->resolver;

        return call(static function () use ($uri, $context, $token, $resolver) {
            $context = $context ?? new ConnectContext;
            $token = $token ?? new NullCancellationToken;
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
                // Host is not an IP address, so resolve the domain name.
                $records = yield ($resolver ?? Dns\resolver())->resolve($host, $context->getDnsTypeRestriction());

                // Usually the faster response should be preferred, but we don't have a reliable way of determining IPv6
                // support, so we always prefer IPv4 here.
                \usort($records, static function (Dns\Record $a, Dns\Record $b) {
                    return $a->getType() - $b->getType();
                });

                foreach ($records as $record) {
                    /** @var Dns\Record $record */
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

                    $deferred = new Deferred;
                    $watcher = Loop::onWritable($socket, static function () use ($deferred) {
                        $deferred->resolve();
                    });

                    $id = $token->subscribe([$deferred, 'fail']);

                    try {
                        yield Promise\timeout($deferred->promise(), $timeout);
                    } catch (TimeoutException $e) {
                        throw new ConnectException(\sprintf(
                            'Connecting to %s failed: timeout exceeded (%d ms)%s',
                            $uri,
                            $timeout,
                            $failures ? '; previous attempts: ' . \implode($failures) : ''
                        ), 110); // See ETIMEDOUT in http://www.virtsync.com/c-error-codes-include-errno
                    } finally {
                        Loop::cancel($watcher);
                        $token->unsubscribe($id);
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

                return ResourceSocket::fromClientSocket($socket, $context->getTlsContext());
            }

            /**
             * This is reached if either all URIs failed or the maximum number of attempts is reached.
             *
             * @noinspection PhpUndefinedVariableInspection
             * @psalm-suppress PossiblyUndefinedVariable
             */
            throw $e;
        });
    }
}
