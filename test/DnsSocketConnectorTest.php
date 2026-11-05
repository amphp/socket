<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\CancelledException;
use Amp\Dns\DnsRecord;
use Amp\Dns\DnsResolver;
use Amp\PHPUnit\AsyncTestCase;
use Amp\TimeoutCancellation;
use const Amp\Process\IS_WINDOWS;

class DnsSocketConnectorTest extends AsyncTestCase
{
    public function provideIpLiterals(): array
    {
        return [
            ['127.0.0.1:80', '127.0.0.1:80'],
            ['tcp://127.0.0.1:80', '127.0.0.1:80'],
            ['udp://127.0.0.1:53', '127.0.0.1:53'],
            ['[::1]:80', '[::1]:80'],
            ['tcp://[::ffff:127.0.0.1]:80', '[::ffff:127.0.0.1]:80'],
            [new InternetAddress('127.0.0.1', 80), '127.0.0.1:80'],
        ];
    }

    /**
     * @dataProvider provideIpLiterals
     */
    public function testConnectPolicyChecksIpLiterals(SocketAddress|string $uri, string $expected): void
    {
        $addresses = [];

        $policy = $this->createPolicy(static function (InternetAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return false;
        });

        try {
            (new DnsSocketConnector(connectPolicy: $policy))->connect($uri);
            self::fail('The connection should have been denied');
        } catch (ConnectException $e) {
            self::assertStringContainsString('skipped: denied by the connect policy', $e->getMessage());
        }

        self::assertSame([$expected], $addresses);
    }

    public function testConnectPolicySkipsDeniedAddresses(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->getPort($server);
        $addresses = [];

        $policy = $this->createPolicy(static function (InternetAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return $address->getAddress() === '127.0.0.1';
        });

        $connector = new DnsSocketConnector($this->createResolver('192.0.2.1', '127.0.0.1'), $policy);
        $socket = $connector->connect('example.com:' . $port, (new ConnectContext)->withConnectTimeout(1));

        self::assertSame(['192.0.2.1:' . $port, '127.0.0.1:' . $port], $addresses);
        self::assertSame('127.0.0.1:' . $port, $socket->getRemoteAddress()->toString());

        $socket->close();
        $server->close();
    }

    public function testConnectPolicyDenialIsListedInPreviousAttempts(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->getPort($server);
        $server->close();

        $policy = $this->createPolicy(static fn (InternetAddress $address): bool => $address->getAddress() === '127.0.0.1');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('previous attempts: example.com:' . $port . ' @ tcp://192.0.2.1:' . $port . ' (denied by the connect policy)');

        $connector = new DnsSocketConnector($this->createResolver('192.0.2.1', '127.0.0.1'), $policy);
        $connector->connect('example.com:' . $port, (new ConnectContext)->withConnectTimeout(1));
    }

    public function testConnectFailsWhenThePolicyDeniesAllAddresses(): void
    {
        $policy = $this->createPolicy(static fn (InternetAddress $address): bool => false);

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage(
            'Connection to example.com:80 @ tcp://192.0.2.2:80 skipped: denied by the connect policy;'
            . ' previous attempts: example.com:80 @ tcp://192.0.2.1:80 (denied by the connect policy)'
        );

        $connector = new DnsSocketConnector($this->createResolver('192.0.2.1', '192.0.2.2'), $policy);
        $connector->connect('example.com:80', (new ConnectContext)->withConnectTimeout(1));
    }

    public function testConnectPolicyExceptionsAbortTheConnection(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->getPort($server);
        $addresses = [];

        $policy = $this->createPolicy(static function (InternetAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            throw new ConnectException('Policy failure');
        });

        $connector = new DnsSocketConnector($this->createResolver('192.0.2.1', '127.0.0.1'), $policy);

        try {
            $connector->connect('example.com:' . $port);
            self::fail('The connection should have been aborted');
        } catch (ConnectException $e) {
            self::assertSame('Policy failure', $e->getMessage());
        }

        self::assertSame(['192.0.2.1:' . $port], $addresses);

        try {
            $server->accept(new TimeoutCancellation(0.1));
            self::fail('No connection should have been opened');
        } catch (CancelledException) {
            $server->close();
        }
    }

    public function testConnectPolicyChecksTheAddressThatIsConnected(): void
    {
        $serverA = listen('127.0.0.1:0');
        $serverB = listen('127.0.0.1:0');
        $portA = $this->getPort($serverA);
        $portB = $this->getPort($serverB);
        $addresses = [];

        $policy = $this->createPolicy(static function (InternetAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return true;
        });

        $connector = new DnsSocketConnector(connectPolicy: $policy);
        $socket = $connector->connect('tcp://127.0.0.1:' . $portA . '@127.0.0.1:' . $portB);

        self::assertSame(['127.0.0.1:' . $portB], $addresses);
        self::assertSame('127.0.0.1:' . $portB, $socket->getRemoteAddress()->toString());

        $socket->close();
        $serverA->close();
        $serverB->close();
    }

    public function testConnectPolicySkipsInvalidIpAddresses(): void
    {
        $policy = $this->createMock(ConnectPolicy::class);
        $policy->expects(self::never())
            ->method('isAllowed');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to localhost:0 @ localhost:0 skipped: invalid IP address');

        (new DnsSocketConnector(connectPolicy: $policy))->connect('localhost:0');
    }

    public function testConnectPolicyIsNotUsedForUnixSockets(): void
    {
        if (IS_WINDOWS) {
            self::markTestSkipped('Unix sockets are not supported on Windows');
        }

        $path = __DIR__ . '/socket.sock';
        $server = listen('unix://' . $path);

        try {
            $policy = $this->createMock(ConnectPolicy::class);
            $policy->expects(self::never())
                ->method('isAllowed');

            $socket = (new DnsSocketConnector(connectPolicy: $policy))->connect('unix://' . $path);

            self::assertSame(SocketAddressType::Unix, $socket->getRemoteAddress()->getType());

            $socket->close();
        } finally {
            $server->close();
            @\unlink($path);
        }
    }

    private function getPort(ServerSocket $server): int
    {
        $address = $server->getAddress();
        \assert($address instanceof InternetAddress);

        return $address->getPort();
    }

    private function createResolver(string ...$addresses): DnsResolver
    {
        $records = \array_map(static fn (string $address) => new DnsRecord($address, DnsRecord::A), $addresses);

        $resolver = $this->createMock(DnsResolver::class);
        $resolver->method('resolve')
            ->willReturn($records);

        return $resolver;
    }

    private function createPolicy(\Closure $isAllowed): ConnectPolicy
    {
        $policy = $this->createMock(ConnectPolicy::class);
        $policy->method('isAllowed')
            ->willReturnCallback($isAllowed);

        return $policy;
    }
}
