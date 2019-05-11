<?php

namespace Amp\Socket;

use Amp\CancellationToken;
use Amp\Promise;

interface Connector
{
    /**
     * Asynchronously establish a socket connection to the specified URI.
     *
     * @param string                 $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
     * @param ClientConnectContext   $context Socket connect context to use when connecting.
     * @param CancellationToken|null $token
     *
     * @return Promise<ClientSocket>
     *
     * @throws SocketException
     */
    public function connect(string $uri, ClientConnectContext $context = null, CancellationToken $token = null): Promise;
}
