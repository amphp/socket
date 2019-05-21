<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Promise;

final class StaticSocketPool implements SocketPool
{
    private $uri;
    private $socketPool;

    public function __construct(string $uri, SocketPool $socketPool)
    {
        $this->uri = $uri;
        $this->socketPool = $socketPool;
    }

    /** @inheritDoc */
    public function checkout(string $uri, CancellationToken $token = null): Promise
    {
        return $this->socketPool->checkout($this->uri, $token);
    }

    /** @inheritDoc */
    public function checkin(ResourceSocket $socket): void
    {
        $this->socketPool->checkin($socket);
    }

    /** @inheritDoc */
    public function clear(ResourceSocket $socket): void
    {
        $this->socketPool->clear($socket);
    }
}
