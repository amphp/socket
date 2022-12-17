<?php declare(strict_types=1);

namespace Amp\Socket;

interface SocketServerFactory
{
    /**
     * @throws SocketException
     */
    public function listen(SocketAddress $address, ?BindContext $bindContext = null): SocketServer;
}
