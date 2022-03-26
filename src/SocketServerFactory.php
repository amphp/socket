<?php

namespace Amp\Socket;

interface SocketServerFactory
{
    /**
     * @throws SocketException
     */
    public function listen(SocketAddress $address, ?BindContext $bindContext = null): SocketServer;
}
