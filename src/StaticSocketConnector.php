<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Connector that connects to a statically defined URI instead of the URI passed to the {@code connect()} call.
 */
final class StaticSocketConnector implements SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    private string $uri;
    private SocketConnector $connector;

    public function __construct(string $uri, SocketConnector $connector)
    {
        $this->uri = $uri;
        $this->connector = $connector;
    }

    public function connect(
        SocketAddress|string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): EncryptableSocket {
        return $this->connector->connect($this->uri, $context, $cancellation);
    }
}
