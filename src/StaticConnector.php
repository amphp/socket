<?php

namespace Amp\Socket;

use Amp\Cancellation;

/**
 * Connector that connects to a statically defined URI instead of the URI passed to the {@code connect()} call.
 */
final class StaticConnector implements Connector
{
    private string $uri;
    private Connector $connector;

    public function __construct(string $uri, Connector $connector)
    {
        $this->uri = $uri;
        $this->connector = $connector;
    }

    public function connect(
        string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): EncryptableSocket {
        return $this->connector->connect($this->uri, $context, $cancellation);
    }
}
