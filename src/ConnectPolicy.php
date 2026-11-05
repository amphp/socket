<?php declare(strict_types=1);

namespace Amp\Socket;

/**
 * Checks the address of each connection attempt made by {@see DnsSocketConnector}.
 *
 * The address is an {@see InternetAddress}, or a {@see UnixAddress} for Unix sockets.
 */
interface ConnectPolicy
{
    /**
     * Called before the connection is opened. A denied address is skipped and the next one is tried.
     */
    public function isAllowed(SocketAddress $address): bool;
}
