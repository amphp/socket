<?php

namespace Amp\Socket;

use Amp\Cancellation;
use Amp\CancelledException;

interface SocketConnector
{
    /**
     * Establish a socket connection to the specified URI.
     *
     * @param string $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
     * @param ConnectContext|null $context Socket connect context to use when connecting.
     * @param Cancellation|null $cancellation
     *
     * @return EncryptableSocket
     *
     * @throws ConnectException
     * @throws CancelledException
     */
    public function connect(
        string $uri,
        ?ConnectContext $context = null,
        ?Cancellation $cancellation = null
    ): EncryptableSocket;
}
