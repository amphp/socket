<?php

namespace Amp\Socket;

/**
 * Decides whether {@see DnsConnector} can connect to an address, before the connection is opened.
 *
 * The address is an IP address and a port, or the path of a Unix socket with a null port.
 */
interface ConnectPolicy
{
    /**
     * A denied address is skipped. The connection fails when no address is left.
     */
    public function isAllowed(SocketAddress $address): bool;
}
