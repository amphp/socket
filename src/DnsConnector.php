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
    private $connectPolicy;

    public function __construct(?Dns\Resolver $resolver = null, ?ConnectPolicy $connectPolicy = null)
    {
        $this->resolver = $resolver;
        $this->connectPolicy = $connectPolicy;
    }

    public function connect(string $uri, ?ConnectContext $context = null, ?CancellationToken $token = null): Promise
    {
        $resolver = $this->resolver;
        $connectPolicy = $this->connectPolicy;

        return call(static function () use ($uri, $context, $token, $resolver, $connectPolicy) {
            $context = $context ?? new ConnectContext;
            $token = $token ?? new NullCancellationToken;
            $attempt = 0;
            $attempts = [];
            $failures = [];

            // PHP and the URI parser read the userinfo differently, so it's rejected when a connect policy is set.
            [$scheme, $host, $port] = Internal\parseUri($uri, $connectPolicy === null);

            if ($host[0] === '[') {
                $host = \substr($host, 1, -1);
            }

            if ($port > 65535) {
                throw new ConnectException(\sprintf('Connection to %s failed: invalid port', $uri));
            }

            if ($port === 0) {
                // Host is a file path, which the connect policy gets as PHP reads it.
                $attempts[] = [$uri, $connectPolicy ? new SocketAddress(\explode('://', $uri, 2)[1]) : null, null];
            } elseif (@\inet_pton($host)) {
                // Host is already an IP address.
                $attempts[] = $connectPolicy ? self::createAttempt($scheme, $host, $port) : [$uri, null, null];
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
                    if ($connectPolicy) {
                        $attempts[] = self::createAttempt($scheme, $record->getValue(), $port);
                    } elseif ($record->getType() === Dns\Record::AAAA) {
                        $attempts[] = [\sprintf('%s://[%s]:%d', $scheme, $record->getValue(), $port), null, null];
                    } else {
                        $attempts[] = [\sprintf('%s://%s:%d', $scheme, $record->getValue(), $port), null, null];
                    }
                }
            }

            $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
            $timeout = $context->getConnectTimeout();

            foreach ($attempts as [$builtUri, $address, $reason]) {
                if ($connectPolicy && $address && !$connectPolicy->isAllowed($address)) {
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
                    $failures[] = "{$uri} @ {$builtUri} ({$reason})";

                    continue; // Skipped addresses don't count as connection attempts.
                }

                try {
                    $streamContext = \stream_context_create($context->withoutTlsContext()->toStreamContextArray());

                    /** @psalm-suppress NullArgument */
                    if (!$socket = @\stream_socket_client($builtUri, $errno, $errstr, null, $flags, $streamContext)) {
                        throw new ConnectException(\sprintf(
                            'Connection to %s failed: [Error #%d] %s%s',
                            $uri,
                            $errno,
                            $errstr,
                            $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
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
                            $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
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
                            $failures ? '; previous attempts: ' . \implode(', ', $failures) : ''
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
             * This is reached if all URIs failed or were skipped, or if the maximum number of attempts is reached.
             *
             * @noinspection PhpUndefinedVariableInspection
             * @psalm-suppress PossiblyUndefinedVariable
             */
            throw $e;
        });
    }

    /**
     * @return array{0: string, 1: SocketAddress|null, 2: string|null} The URI to connect to, the address to check
     *     and the reason to skip it.
     */
    private static function createAttempt(string $scheme, string $host, int $port): array
    {
        if (!@\inet_pton($host)) {
            return [$scheme . '://' . $host . ':' . $port, null, 'invalid IP address'];
        }

        $address = new SocketAddress($host, $port);

        // Connect to the checked address, since PHP can parse the original URI differently.
        return [$scheme . '://' . $address->toString(), $address, null];
    }
}
