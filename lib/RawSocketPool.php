<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\CancelledException;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Struct;
use Amp\Success;
use function Amp\call;

final class RawSocketPool implements SocketPool {
    private $sockets = [];
    private $socketIdUriMap = [];
    private $pendingCount = [];

    private $connectTimeout;
    private $idleTimeout;
    private $bindTo;

    public function __construct(int $connectTimeout = 10000, int $idleTimeout = 10000, string $bindTo = null) {
        $this->connectTimeout = $connectTimeout;
        $this->idleTimeout = $idleTimeout;
        $this->bindTo = $bindTo;
    }

    private function normalizeUri(string $uri): string {
        // TODO: Use proper normalization
        return stripos($uri, 'unix://') === 0 ? $uri : strtolower($uri);
    }

    /** @inheritdoc */
    public function checkout(string $uri, ClientSocketContext $socketContext = null, CancellationToken $token = null): Promise {
        // A request might already be cancelled before we reach the checkout, so do not even attempt to checkout in that
        // case. The weird logic is required to throw the token's exception instead of creating a new one.
        if ($token && $token->isRequested()) {
            try {
                $token->throwIfRequested();
            } catch (CancelledException $e) {
                return new Failure($e);
            }
        }

        $uri = $this->normalizeUri($uri);

        if (empty($this->sockets[$uri])) {
            return $this->checkoutNewSocket($uri, $socketContext, $token);
        }

        foreach ($this->sockets[$uri] as $socketId => $socket) {
            if (!$socket->isAvailable) {
                continue;
            } elseif (!\is_resource($socket->resource) || \feof($socket->resource)) {
                $this->clear($socket->resource);
                continue;
            }

            $socket->isAvailable = false;

            if ($socket->idleWatcher !== null) {
                Loop::disable($socket->idleWatcher);
            }

            return new Success($socket->resource);
        }

        return $this->checkoutNewSocket($uri, $socketContext, $token);
    }

    private function checkoutNewSocket(string $uri, ClientSocketContext $socketContext = null, CancellationToken $token = null): Promise {
        return call(function () use ($uri, $socketContext, $token) {
            $this->pendingCount[$uri] = ($this->pendingCount[$uri] ?? 0) + 1;

            try {
                $rawSocket = yield rawConnect($uri, $socketContext, $token);
            } finally {
                if (--$this->pendingCount[$uri] === 0) {
                    unset($this->pendingCount[$uri]);
                }
            }

            $socketId = (int) $rawSocket;

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
            $socket->resource = $rawSocket;
            $socket->isAvailable = false;

            $this->sockets[$uri][$socketId] = $socket;
            $this->socketIdUriMap[$socketId] = $uri;

            return $rawSocket;
        });
    }

    /** @inheritdoc */
    public function clear($socket) {
        $socketId = (int) $socket;

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                sprintf('Unknown socket: %s', $socket)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];
        $socket = $this->sockets[$uri][$socketId];

        if ($socket->idleWatcher) {
            Loop::cancel($socket->idleWatcher);
        }

        unset(
            $this->sockets[$uri][$socketId],
            $this->socketIdUriMap[$socketId]
        );

        if (empty($this->sockets[$uri])) {
            unset($this->sockets[$uri]);
        }
    }

    /** @inheritdoc */
    public function checkin($socket) {
        $socketId = (int) $socket;

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                \sprintf('Unknown socket: %d', $socketId)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];

        if (!\is_resource($socket) || \feof($socket)) {
            $this->clear($socket);
            return;
        }

        $socket = $this->sockets[$uri][$socketId];
        $socket->isAvailable = true;

        if (isset($socket->idleWatcher)) {
            Loop::enable($socket->idleWatcher);
        } else {
            $socket->idleWatcher = Loop::delay($socket->idleTimeout, function () use ($socket) {
                $this->clear($socket->resource);
            });

            Loop::unreference($socket->idleWatcher);
        }
    }
}
