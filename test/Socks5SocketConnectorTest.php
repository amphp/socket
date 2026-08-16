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
}
