<?php

namespace Amp\Socket;

/**
 * Decides whether {@see DnsConnector} can connect to an IP address, before the connection is opened.
 */
interface ConnectPolicy
{
    /**
     * A denied address is skipped. The connection fails when no address is left.
     */
    public function isAllowed(SocketAddress $address): bool;
}
