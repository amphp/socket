<?php declare(strict_types=1);

namespace Amp\Socket;

/**
 * Checks the address of each connection attempt made by {@see DnsSocketConnector}.
 *
 * The address is an {@see InternetAddress}, or a {@see UnixAddress} for Unix sockets. The connection is made to the
 * address that was checked. IPv6 addresses are in canonical form.
 *
 * IPv4-mapped IPv6 addresses such as ::ffff:127.0.0.1 are passed as they are and reach the IPv4 host, so a policy has
 * to treat them like the IPv4 address. {@see CidrMatcher} does this: 127.0.0.0/8 matches ::ffff:127.0.0.1. Comparing
 * strings does not.
 *
 * Use {@see PublicAddressConnectPolicy} to allow public addresses only.
 */
interface ConnectPolicy
{
    /**
     * Called before the connection is opened. A denied address is skipped and the next one is tried.
     */
    public function isAllowed(SocketAddress $address): bool;
}
