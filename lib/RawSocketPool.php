<?php

namespace Amp\Socket;

use Amp\Loop;
use Amp\Promise;
use Amp\Struct;
use Amp\Success;
use function Amp\call;

/** @internal */
final class SocketPoolStruct {
    use Struct;

    public $id;
    public $uri;
    public $resource;
    public $isAvailable;
    public $idleWatcher;
    public $idleTimeout;
}

final class RawSocketPool implements SocketPool {
    private $sockets = [];
    private $socketIdUriMap = [];
    private $pendingCount = [];

    private $options = [
        self::OP_IDLE_TIMEOUT => 10000,
        self::OP_CONNECT_TIMEOUT => 10000,
        self::OP_BINDTO => "",
    ];

    private function normalizeUri(string $uri): string {
        return stripos($uri, 'unix://') === 0 ? $uri : strtolower($uri);
    }

    /** @inheritdoc */
    public function checkout(string $uri, array $options = []): Promise {
        $uri = $this->normalizeUri($uri);

        unset($options[self::OP_IDLE_TIMEOUT]);
        $options = array_merge($this->options, $options);

        if (empty($this->sockets[$uri])) {
            return $this->checkoutNewSocket($uri, $options);
        }

        foreach ($this->sockets[$uri] as $socketId => $socket) {
            if (!$socket->isAvailable) {
                continue;
            } elseif (!\is_resource($socket->resource) || \feof($socket->resource)) {
                unset($this->sockets[$uri][$socketId]);
                continue;
            } elseif (($bindTo = @\stream_context_get_options($socket->resource)['socket']['bindto'])) {
                if ($bindTo !== $options[self::OP_BINDTO]) {
                    continue;
                }
            }

            $socket->isAvailable = false;

            if (isset($socket->idleWatcher)) {
                Loop::disable($socket->idleWatcher);
            }

            return new Success($socket->resource);
        }

        return $this->checkoutNewSocket($uri, $options);
    }

    private function checkoutNewSocket(string $uri, array $options): Promise {
        return call(function () use ($uri, $options) {
            $this->pendingCount[$uri] = ($this->pendingCount[$uri] ?? 0) + 1;

            $rawSocket = yield rawConnect($uri, $options);
            $socketId = (int) $rawSocket;

            $socket = new SocketPoolStruct;
            $socket->id = $socketId;
            $socket->uri = $uri;
            $socket->resource = $rawSocket;
            $socket->isAvailable = false;
            $socket->idleTimeout = $this->options[self::OP_IDLE_TIMEOUT];

            $this->sockets[$uri][$socketId] = $socket;
            $this->socketIdUriMap[$socketId] = $uri;

            if (--$this->pendingCount[$uri] === 0) {
                unset($this->pendingCount[$uri]);
            }

            return $rawSocket;
        });
    }

    /** @inheritdoc */
    public function clear($resource) {
        $socketId = (int) $resource;

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                sprintf('Unknown socket: %s', $resource)
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
    public function checkin($resource) {
        $socketId = (int) $resource;

        if (!isset($this->socketIdUriMap[$socketId])) {
            throw new \Error(
                sprintf('Unknown socket: %s', $resource)
            );
        }

        $uri = $this->socketIdUriMap[$socketId];

        if (!is_resource($resource) || feof($resource)) {
            $this->clear($resource);
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

    /**
     * Gets the number of outstanding checkout requests.
     *
     * @param string $uri A URI as passed to `checkout()`.
     *
     * @return int
     */
    public function getPendingCount(string $uri): int {
        $uri = $this->normalizeUri($uri);

        return $this->pendingCount[$uri] ?? null;
    }

    /**
     * Gets the number of currently checked out sockets.
     *
     * @param string $uri A URI as passed to `checkout()`.
     *
     * @return int
     */
    public function getCheckoutCount(string $uri): int {
        $uri = $this->normalizeUri($uri);

        return \count($this->sockets[$uri] ?? []);
    }

    /** @inheritdoc */
    public function setOption(string $option, $value) {
        switch ($option) {
            case self::OP_CONNECT_TIMEOUT:
                $this->options[self::OP_CONNECT_TIMEOUT] = (int) $value;
                break;

            case self::OP_IDLE_TIMEOUT:
                $this->options[self::OP_IDLE_TIMEOUT] = (int) $value;
                break;

            case self::OP_BINDTO:
                $this->options[self::OP_BINDTO] = $value;
                break;

            default:
                throw new \Error(
                    sprintf('Unknown option: %s', $option)
                );
        }
    }
}
