<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\CancelledException;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Struct;
use Amp\Success;
use League\Uri;
use function Amp\call;

/**
 * SocketPool implementation that doesn't impose any limits on concurrent open connections.
 */
final class UnlimitedSocketPool implements SocketPool
{
    private const ALLOWED_SCHEMES = [
        'tcp' => null,
        'unix' => null,
    ];

    /** @var object[][] */
    private $sockets = [];
    /** @var string[] */
    private $objectIdCacheKeyMap = [];
    /** @var int[] */
    private $pendingCount = [];
    /** @var int */
    private $idleTimeout;
    /** @var Connector */
    private $connector;

    public function __construct(int $idleTimeout = 10000, ?Connector $connector = null)
    {
        $this->idleTimeout = $idleTimeout;
        $this->connector = $connector ?? connector();
    }

    /** @inheritdoc */
    public function checkout(
        string $uri,
        ConnectContext $context = null,
        CancellationToken $token = null
    ): Promise {
        // A request might already be cancelled before we reach the checkout, so do not even attempt to checkout in that
        // case. The weird logic is required to throw the token's exception instead of creating a new one.
        if ($token && $token->isRequested()) {
            try {
                $token->throwIfRequested();
            } catch (CancelledException $e) {
                return new Failure($e);
            }
        }

        [$uri, $fragment] = $this->normalizeUri($uri);

        $cacheKey = $uri;

        if ($context && ($tlsContext = $context->getTlsContext())) {
            $cacheKey .= ' + ' . \serialize($tlsContext->toStreamContextArray());
        }

        if ($fragment !== null) {
            $cacheKey .= ' # ' . $fragment;
        }

        if (empty($this->sockets[$cacheKey])) {
            return $this->checkoutNewSocket($uri, $cacheKey, $context, $token);
        }

        foreach ($this->sockets[$cacheKey] as $socketId => $socket) {
            if (!$socket->isAvailable) {
                continue;
            }

            if ($socket->object instanceof ResourceSocket) {
                $resource = $socket->object->getResource();

                if (!$resource || !\is_resource($resource) || \feof($resource)) {
                    $this->clearFromId(\spl_object_hash($socket->object));
                    continue;
                }
            } elseif ($socket->object->isClosed()) {
                $this->clearFromId(\spl_object_hash($socket->object));
                continue;
            }

            $socket->isAvailable = false;

            if ($socket->idleWatcher !== null) {
                Loop::disable($socket->idleWatcher);
            }

            return new Success($socket->object);
        }

        return $this->checkoutNewSocket($uri, $cacheKey, $context, $token);
    }

    /** @inheritdoc */
    public function clear(EncryptableSocket $socket): void
    {
        $this->clearFromId(\spl_object_hash($socket));
    }

    /** @inheritdoc */
    public function checkin(EncryptableSocket $socket): void
    {
        $objectId = \spl_object_hash($socket);

        if (!isset($this->objectIdCacheKeyMap[$objectId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $objectId)
            );
        }

        $cacheKey = $this->objectIdCacheKeyMap[$objectId];

        if ($socket instanceof ResourceSocket) {
            $resource = $socket->getResource();

            if (!$resource || !\is_resource($resource) || \feof($resource)) {
                $this->clearFromId(\spl_object_hash($socket));
                return;
            }
        } elseif ($socket->isClosed()) {
            $this->clearFromId(\spl_object_hash($socket));
            return;
        }

        $socket = $this->sockets[$cacheKey][$objectId];
        $socket->isAvailable = true;

        if (isset($socket->idleWatcher)) {
            Loop::enable($socket->idleWatcher);
        } else {
            $socket->idleWatcher = Loop::delay($this->idleTimeout, function () use ($socket) {
                $this->clearFromId(\spl_object_hash($socket->object));
            });

            Loop::unreference($socket->idleWatcher);
        }
    }

    /**
     * @param string $uri
     *
     * @return array
     *
     * @throws SocketException
     */
    private function normalizeUri(string $uri): array
    {
        if (\stripos($uri, 'unix://') === 0) {
            return \explode('#', $uri) + [null, null];
        }

        try {
            $parts = Uri\parse($uri);
        } catch (\Exception $exception) {
            throw new SocketException('Could not parse URI', 0, $exception);
        }

        if ($parts['scheme'] === null) {
            throw new SocketException('Invalid URI for socket pool; no scheme given');
        }

        $port = $parts['port'] ?? 0;

        if ($port === 0 || $parts['host'] === null) {
            throw new SocketException('Invalid URI for socket pool; missing host or port');
        }

        $scheme = \strtolower($parts['scheme']);
        $host = \strtolower($parts['host']);

        if (!\array_key_exists($scheme, self::ALLOWED_SCHEMES)) {
            throw new SocketException(\sprintf(
                "Invalid URI for socket pool; '%s' scheme not allowed - scheme must be one of %s",
                $scheme,
                \implode(', ', \array_keys(self::ALLOWED_SCHEMES))
            ));
        }

        if ($parts['query'] !== null) {
            throw new SocketException('Invalid URI for socket pool; query component not allowed');
        }

        if ($parts['path'] !== '') {
            throw new SocketException('Invalid URI for socket pool; path component must be empty');
        }

        if ($parts['user'] !== null) {
            throw new SocketException('Invalid URI for socket pool; user component not allowed');
        }

        return [$scheme . '://' . $host . ':' . $port, $parts['fragment']];
    }

    private function checkoutNewSocket(
        string $uri,
        string $cacheKey,
        ConnectContext $connectContext = null,
        CancellationToken $token = null
    ): Promise {
        return call(function () use ($uri, $cacheKey, $connectContext, $token) {
            $this->pendingCount[$uri] = ($this->pendingCount[$uri] ?? 0) + 1;

            try {
                /** @var EncryptableSocket $socket */
                $socket = yield $this->connector->connect($uri, $connectContext, $token);
            } finally {
                if (--$this->pendingCount[$uri] === 0) {
                    unset($this->pendingCount[$uri]);
                }
            }

            /** @psalm-suppress MissingConstructor */
            $socketEntry = new class {
                use Struct;

                /** @var string */
                public $uri;
                /** @var EncryptableSocket */
                public $object;
                /** @var bool */
                public $isAvailable;
                /** @var string|null */
                public $idleWatcher;
            };

            $socketEntry->uri = $uri;
            $socketEntry->isAvailable = false;
            $socketEntry->object = $socket;

            $objectId = \spl_object_hash($socket);
            $this->sockets[$cacheKey][$objectId] = $socketEntry;
            $this->objectIdCacheKeyMap[$objectId] = $cacheKey;

            return $socket;
        });
    }

    private function clearFromId(string $objectId): void
    {
        if (!isset($this->objectIdCacheKeyMap[$objectId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $objectId)
            );
        }

        $cacheKey = $this->objectIdCacheKeyMap[$objectId];
        $socket = $this->sockets[$cacheKey][$objectId];

        if ($socket->idleWatcher) {
            Loop::cancel($socket->idleWatcher);
        }

        unset(
            $this->sockets[$cacheKey][$objectId],
            $this->objectIdCacheKeyMap[$objectId]
        );

        if (empty($this->sockets[$cacheKey])) {
            unset($this->sockets[$cacheKey]);
        }
    }
}
