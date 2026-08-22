<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\Future;
use Amp\PHPUnit\AsyncTestCase;
use function Amp\async;

class Socks5SocketConnectorTest extends AsyncTestCase
{
    /**
     * @return array{Future<void>, Socket} The pending tunnel operation and the server side of the connection.
     */
    private function tunnelWithNoAuth(string $target): array
    {
        [$client, $server] = createSocketPair();

        $future = async(fn () => Socks5SocketConnector::tunnel(
            $client,
            $target,
            username: null,
            password: null,
            cancellation: null,
        ));

        $server->read(); // VER, NMETHODS, METHODS
        $server->write("\x05\x00"); // VER, METHOD (no authentication)

        return [$future, $server];
    }

    public function testUnsupportedReplyAddressType(): void
    {
        [$future, $server] = $this->tunnelWithNoAuth('tcp://example.com:80');

        $server->read(); // connect request
        $server->write("\x05\x00\x00\x99"); // VER, REP (success), RSV, ATYP (unsupported)

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Unsupported SOCKS5 address type: 153');

        $future->await();
    }

    public function testNullPortRejected(): void
    {
        [$future] = $this->tunnelWithNoAuth('tcp://example.com');

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Port is null!');

        $future->await();
    }

    public function testHostExceedingMaximumLengthRejected(): void
    {
        [$future] = $this->tunnelWithNoAuth('tcp://' . \str_repeat('a', 256) . ':80');

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Host exceeds maximum length of 255 bytes');

        $future->await();
    }

    public function testTargetWithoutSchemeIsTreatedAsTcpDomainName(): void
    {
        [$future, $server] = $this->tunnelWithNoAuth('example.com:80');

        $request = $server->read();

        self::assertSame("\x05\x01\x00\x03", \substr($request, 0, 4)); // VER, CMD (connect), RSV, ATYP (domain)
        $length = \ord($request[4]);
        self::assertSame('example.com', \substr($request, 5, $length));
        self::assertSame([1 => 80], \unpack('n', \substr($request, 5 + $length, 2)));

        $server->write("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00"); // success reply with IPv4 bound address

        $future->await();
    }

    public function testIpv4AddressTargetWithoutScheme(): void
    {
        [$future, $server] = $this->tunnelWithNoAuth('1.2.3.4:80');

        $request = $server->read();

        self::assertSame("\x05\x01\x00\x01", \substr($request, 0, 4)); // VER, CMD (connect), RSV, ATYP (IPv4)
        self::assertSame(\inet_pton('1.2.3.4'), \substr($request, 4, 4));
        self::assertSame([1 => 80], \unpack('n', \substr($request, 8, 2)));

        $server->write("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00"); // success reply with IPv4 bound address

        $future->await();
    }

    public function testIpv6AddressTarget(): void
    {
        [$future, $server] = $this->tunnelWithNoAuth('tcp://[2001:db8::1]:443');

        $request = $server->read();

        self::assertSame("\x05\x01\x00\x04", \substr($request, 0, 4)); // VER, CMD (connect), RSV, ATYP (IPv6)
        self::assertSame(\inet_pton('2001:db8::1'), \substr($request, 4, 16));
        self::assertSame([1 => 443], \unpack('n', \substr($request, 20, 2)));

        $server->write("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00"); // success reply with IPv4 bound address

        $future->await();
    }

    public function testIpv6AddressTargetWithoutScheme(): void
    {
        [$future, $server] = $this->tunnelWithNoAuth('[::1]:80');

        $request = $server->read();

        self::assertSame("\x05\x01\x00\x04", \substr($request, 0, 4)); // VER, CMD (connect), RSV, ATYP (IPv6)
        self::assertSame(\inet_pton('::1'), \substr($request, 4, 16));
        self::assertSame([1 => 80], \unpack('n', \substr($request, 20, 2)));

        $server->write("\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00"); // success reply with IPv4 bound address

        $future->await();
    }

    public function testInvalidTargetThrowsSocketException(): void
    {
        [$client] = createSocketPair();

        $future = async(fn () => Socks5SocketConnector::tunnel(
            $client,
            'tcp://exa mple:80',
            username: null,
            password: null,
            cancellation: null,
        ));

        $this->expectException(SocketException::class);
        $this->expectExceptionMessage('Invalid SOCKS5 target');

        $future->await();
    }
}
