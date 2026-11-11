<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Allows connections to public IP addresses only, for example to guard against server-side request forgery.
 *
 * Denies Unix sockets and every address in a range that isn't reachable over the public internet, or that embeds an
 * IPv4 address that could be private. This includes NAT64, so connections to IPv4-only hosts fail on IPv6-only networks
 * that depend on it.
 *
 * Only special-purpose ranges are known to this class. Internal services on public addresses have to be added as
 * additional denied ranges.
 */
final class PublicAddressConnectPolicy implements ConnectPolicy
{
    use ForbidCloning;
    use ForbidSerialization;

    /**
     * Based on the IANA special-purpose address registries, which supersede the tables in RFC 6890:
     * https://www.iana.org/assignments/iana-ipv4-special-registry/
     * https://www.iana.org/assignments/iana-ipv6-special-registry/
     *
     * Multicast, site-local, IPv4-compatible and IPv4-translated addresses aren't listed there, but they don't belong
     * to public hosts either, so they're denied too.
     */
    private const NON_PUBLIC_RANGES = [
        // IPv4, which also covers the IPv4-mapped form of these addresses, see CidrMatcher.
        '0.0.0.0/8',       // "This" network: https://www.rfc-editor.org/rfc/rfc791#section-3.2
        '10.0.0.0/8',      // Private use: https://www.rfc-editor.org/rfc/rfc1918
        '100.64.0.0/10',   // Shared address space: https://www.rfc-editor.org/rfc/rfc6598
        '127.0.0.0/8',     // Loopback: https://www.rfc-editor.org/rfc/rfc1122#section-3.2.1.3
        '169.254.0.0/16',  // Link-local: https://www.rfc-editor.org/rfc/rfc3927
        '172.16.0.0/12',   // Private use: https://www.rfc-editor.org/rfc/rfc1918
        '192.0.0.0/24',    // IETF protocol assignments: https://www.rfc-editor.org/rfc/rfc6890#section-2.1
        '192.0.2.0/24',    // Documentation: https://www.rfc-editor.org/rfc/rfc5737
        '192.88.99.0/24',  // 6to4 relay anycast, deprecated: https://www.rfc-editor.org/rfc/rfc7526
        '192.168.0.0/16',  // Private use: https://www.rfc-editor.org/rfc/rfc1918
        '198.18.0.0/15',   // Benchmarking: https://www.rfc-editor.org/rfc/rfc2544
        '198.51.100.0/24', // Documentation: https://www.rfc-editor.org/rfc/rfc5737
        '203.0.113.0/24',  // Documentation: https://www.rfc-editor.org/rfc/rfc5737
        '224.0.0.0/4',     // Multicast: https://www.rfc-editor.org/rfc/rfc5771
        '240.0.0.0/4',     // Reserved, including broadcast: https://www.rfc-editor.org/rfc/rfc1112#section-4

        // IPv6
        // IPv4-compatible, deprecated, which also covers :: and ::1: https://www.rfc-editor.org/rfc/rfc4291#section-2.5.5.1
        '::/96',
        '::ffff:0:0:0/96', // IPv4-translated: https://www.rfc-editor.org/rfc/rfc2765#section-2.1
        '64:ff9b::/96',    // NAT64 well-known prefix: https://www.rfc-editor.org/rfc/rfc6052#section-2.1
        '64:ff9b:1::/48',  // NAT64 local-use prefix: https://www.rfc-editor.org/rfc/rfc8215
        '100::/64',        // Discard-only: https://www.rfc-editor.org/rfc/rfc6666
        '100:0:0:1::/64',  // Dummy prefix: https://www.rfc-editor.org/rfc/rfc9780
        '2001::/23',       // IETF protocol assignments, including Teredo: https://www.rfc-editor.org/rfc/rfc2928
        '2001:db8::/32',   // Documentation: https://www.rfc-editor.org/rfc/rfc3849
        '2002::/16',       // 6to4: https://www.rfc-editor.org/rfc/rfc3056
        '3fff::/20',       // Documentation: https://www.rfc-editor.org/rfc/rfc9637
        '5f00::/16',       // Segment routing: https://www.rfc-editor.org/rfc/rfc9602
        'fc00::/7',        // Unique local: https://www.rfc-editor.org/rfc/rfc4193
        'fe80::/10',       // Link-local: https://www.rfc-editor.org/rfc/rfc4291#section-2.5.6
        'fec0::/10',       // Site-local, deprecated: https://www.rfc-editor.org/rfc/rfc3879
        'ff00::/8',        // Multicast: https://www.rfc-editor.org/rfc/rfc4291#section-2.7
    ];

    /** @var list<CidrMatcher> */
    private readonly array $deniedRanges;

    /**
     * @param list<string> $additionalDeniedRanges Further IP addresses or ranges in CIDR notation to deny.
     *
     * @throws \ValueError If a range is invalid.
     */
    public function __construct(array $additionalDeniedRanges = [])
    {
        $this->deniedRanges = \array_map(
            static fn (string $range) => new CidrMatcher($range),
            [...self::NON_PUBLIC_RANGES, ...$additionalDeniedRanges],
        );
    }

    #[\Override]
    public function isAllowed(SocketAddress $address): bool
    {
        if (!$address instanceof InternetAddress) {
            return false;
        }

        foreach ($this->deniedRanges as $range) {
            if ($range->matchAddress($address)) {
                return false;
            }
        }

        return true;
    }
}
