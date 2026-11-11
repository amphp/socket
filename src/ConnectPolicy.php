<?php declare(strict_types=1);

namespace Amp\Socket;

/**
 * Checks the address of each connection attempt made by {@see DnsSocketConnector}.
 *
 * The address is an {@see InternetAddress}, or a {@see UnixAddress} for Unix sockets. The connection is made to the
 * address that was checked.
 *
 * The textual form of IP addresses comes from inet_ntop(), so IPv6 addresses are lowercase and zero-compressed, and the
 * same address always has the same form on one platform. How an embedded IPv4 address is written depends on the C
 * library though: ::7f00:1 is ::127.0.0.1 with glibc and on macOS, but ::7f00:1 with musl. Match addresses with
 * {@see CidrMatcher} or compare {@see InternetAddress::getAddressBytes()} instead of comparing strings.
 *
 * IPv4-mapped IPv6 addresses such as ::ffff:127.0.0.1 are passed as they are and reach the IPv4 host, so a policy has
 * to treat them like the IPv4 address. {@see CidrMatcher} does this: 127.0.0.0/8 matches ::ffff:127.0.0.1. Comparing
 * strings does not.
 *
 * PHP shortens Unix socket paths that don't fit into sun_path (108 bytes on Linux, 104 on macOS and BSD) and connects
 * to the shortened path, which is a prefix of the checked one. The connect fails with an exception afterwards, but the
 * socket at the shortened path has already received the connection. Check Unix sockets with an allow-list of the
 * exact paths that may be used, as these are short enough to exist. A deny-list of exact paths can be bypassed.
 *
 * Only {@see DnsSocketConnector} enforces the policy. With a proxy such as {@see Socks5SocketConnector}, the proxy
 * resolves and connects to the target, so a policy on the connector used to reach the proxy only checks the address of
 * the proxy. Restrict the targets at the proxy instead.
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
