<?php declare(strict_types=1);

namespace Amp\Socket;

/**
 * Checks the IP address of each connection attempt made by {@see DnsSocketConnector}.
 */
interface ConnectPolicy
{
    /**
     * Called before the connection is opened. A denied address is skipped and the next one is tried.
     */
    public function isAllowed(InternetAddress $address): bool;
}
