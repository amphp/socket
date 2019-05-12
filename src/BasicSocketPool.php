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

final class BasicSocketPool implements SocketPool
{
    private const ALLOWED_SCHEMES = [
        'tcp' => null,
        'udp' => null,
        'unix' => null,
        'udg' => null,
    ];

    private $sockets = [];
    private $socketIdCacheKeyMap = [];
    private $pendingCount = [];

    private $idleTimeout;
    private $connectContext;

    public function __construct(int $idleTimeout = 10000, ClientConnectContext $connectContext = null)
    {
        $this->idleTimeout = $idleTimeout;
        $this->connectContext = $connectContext ?? new ClientConnectContext;
    }

    /** @inheritdoc */
    public function checkout(string $uri, CancellationToken $token = null): Promise
    {
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

        $cacheKey = $uri . ($fragment !== null ? '#' . $fragment : '');

        if (empty($this->sockets[$cacheKey])) {
            return $this->checkoutNewSocket($uri, $cacheKey, $token);
        }

        foreach ($this->sockets[$cacheKey] as $socketId => $socket) {
            if (!$socket->isAvailable) {
                continue;
            }

            if (!\is_resource($socket->resource) || \feof($socket->resource)) {
                $this->clearFromId((int) $socket->resource);
                continue;
            }

            $socket->isAvailable = false;

            if ($socket->idleWatcher !== null) {
                Loop::disable($socket->idleWatcher);
            }

            return new Success(new ClientSocket($socket->resource));
        }

        return $this->checkoutNewSocket($uri, $cacheKey, $token);
    }

    /** @inheritdoc */
    public function clear(EncryptableSocket $socket): void
    {
        $this->clearFromId((int) $socket->getResource());
    }

    /** @inheritdoc */
    public function checkin(EncryptableSocket $socket): void
    {
        $socketId = (int) $socket->getResource();

        if (!isset($this->socketIdCacheKeyMap[$socketId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $socketId)
            );
        }

        $cacheKey = $this->socketIdCacheKeyMap[$socketId];

        $resource = $socket->getResource();

        if (!\is_resource($resource) || \feof($resource)) {
            $this->clearFromId((int) $resource);
            return;
        }

        $socket = $this->sockets[$cacheKey][$socketId];
        $socket->isAvailable = true;

        if (isset($socket->idleWatcher)) {
            Loop::enable($socket->idleWatcher);
        } else {
            $socket->idleWatcher = Loop::delay($this->idleTimeout, function () use ($socket) {
                $this->clearFromId((int) $socket->resource);
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

    private function checkoutNewSocket(string $uri, string $cacheKey, CancellationToken $token = null): Promise
    {
        return call(function () use ($uri, $cacheKey, $token) {
            $this->pendingCount[$uri] = ($this->pendingCount[$uri] ?? 0) + 1;

            try {
                /** @var EncryptableSocket $rawSocket */
                $rawSocket = yield connect($uri, $this->connectContext, $token);
            } finally {
                if (--$this->pendingCount[$uri] === 0) {
                    unset($this->pendingCount[$uri]);
                }
            }

            $socketId = (int) $rawSocket->getResource();

            $socket = new class {
                use Struct;

                public $id;
                public $uri;
                public $resource;
                public $isAvailable;
                public $idleWatcher;
            };

            $socket->id = $socketId;
            $socket->uri = $uri;
            $socket->resource = $rawSocket->getResource();
            $socket->isAvailable = false;

            $this->sockets[$cacheKey][$socketId] = $socket;
            $this->socketIdCacheKeyMap[$socketId] = $cacheKey;

            return $rawSocket;
        });
    }

    /**
     * @param int $socketId
     */
    private function clearFromId(int $socketId): void
    {
        if (!isset($this->socketIdCacheKeyMap[$socketId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $socketId)
            );
        }

        $cacheKey = $this->socketIdCacheKeyMap[$socketId];
        $socket = $this->sockets[$cacheKey][$socketId];

        if ($socket->idleWatcher) {
            Loop::cancel($socket->idleWatcher);
        }

        unset(
            $this->sockets[$cacheKey][$socketId],
            $this->socketIdCacheKeyMap[$socketId]
        );

        if (empty($this->sockets[$cacheKey])) {
            unset($this->sockets[$cacheKey]);
        }
    }
}
