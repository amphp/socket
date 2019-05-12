<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Promise;

final class StaticSocketPool implements SocketPool
{
    private $uri;
    private $socketPool;

    public function __construct(string $uri, SocketPool $socketPool = null)
    {
        $this->uri = $uri;
        $this->socketPool = $socketPool ?? new BasicSocketPool;
    }

    /** @inheritdoc */
    public function checkout(string $uri, CancellationToken $token = null): Promise
    {
        return $this->socketPool->checkout($this->uri, $token);
    }

    /** @inheritdoc */
    public function checkin(EncryptableSocket $socket): void
    {
        $this->socketPool->checkin($socket);
    }

    /** @inheritdoc */
    public function clear(EncryptableSocket $socket): void
    {
        $this->socketPool->clear($socket);
    }
}
