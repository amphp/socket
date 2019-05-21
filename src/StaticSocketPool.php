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
    public function checkout(
        string $uri,
        ClientConnectContext $context = null,
        CancellationToken $token = null
    ): Promise {
        return $this->socketPool->checkout($this->uri, $context, $token);
    }

    /** @inheritDoc */
    public function checkin(EncryptableSocket $socket): void
    {
        $this->socketPool->checkin($socket);
    }

    /** @inheritDoc */
    public function clear(EncryptableSocket $socket): void
    {
        $this->socketPool->clear($socket);
    }
}
