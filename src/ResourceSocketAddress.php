<?php

namespace Amp\Socket;

final class ResourceSocketAddress
{
    /**
     * @param resource $resource
     *
     * @throws SocketException
     */
    public static function fromPeer($resource): SocketAddress
    {
        $name = @\stream_socket_get_name($resource, true);

        /** @psalm-suppress TypeDoesNotContainType */
        if ($name === false || $name === "\0") {
            return self::fromLocal($resource);
        }

        return self::fromString($name);
    }

    /**
     * @param resource $resource
     *
     * @throws SocketException
     */
    public static function fromLocal($resource): SocketAddress
    {
        $wantPeer = false;

        do {
            $name = @\stream_socket_get_name($resource, $wantPeer);

            /** @psalm-suppress RedundantCondition */
            if ($name !== false && $name !== "\0") {
                return self::fromString($name);
            }
        } while ($wantPeer = !$wantPeer);

        return new UnixAddress('');
    }

    /**
     * @throws SocketException
     */
    public static function fromString(string $name): SocketAddress
    {
        if (\preg_match("/\\[(?P<ip>[0-9a-f:]+)](:(?P<port>\\d+))$/", $name, $match)) {
            /** @psalm-suppress ArgumentTypeCoercion */
            return new InternetAddress($match['ip'], (int) $match['port']);
        }

        if (\preg_match("/(?P<ip>\\d+\\.\\d+\\.\\d+\\.\\d+)(:(?P<port>\\d+))$/", $name, $match)) {
            /** @psalm-suppress ArgumentTypeCoercion */
            return new InternetAddress($match['ip'], (int) $match['port']);
        }

        return new UnixAddress($name);
    }

    private function __construct()
    {
        // private to avoid objects
    }
}
