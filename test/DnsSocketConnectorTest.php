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
            ['tcp://[0:0:0:0:0:0:0:1]:80', '[::1]:80'],
            ['tcp://[::ffff:127.0.0.1]:80', '[::ffff:127.0.0.1]:80'],
            ['tcp://[::FFFF:127.0.0.1]:80', '[::ffff:127.0.0.1]:80'],
            ['tcp://[0:0:0:0:0:ffff:7f00:1]:80', '[::ffff:127.0.0.1]:80'],
            ['udp://[::ffff:127.0.0.1]:53', '[::ffff:127.0.0.1]:53'],
            [new InternetAddress('::ffff:127.0.0.1', 80), '[::ffff:127.0.0.1]:80'],
            [new InternetAddress('127.0.0.1', 80), '127.0.0.1:80'],
        ];
    }

    public function testConnectPolicySeesIpv4MappedRecords(): void
    {
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->method('resolve')
            ->willReturn([new DnsRecord('::ffff:127.0.0.1', DnsRecord::AAAA)]);

        $addresses = [];

        $policy = $this->createPolicy(static function (InternetAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return false;
        });

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to example.com:80 @ tcp://[::ffff:127.0.0.1]:80 failed: denied by the connect policy');

        try {
            (new DnsSocketConnector($resolver, $policy))->connect('example.com:80');
        } finally {
            self::assertSame(['[::ffff:127.0.0.1]:80'], $addresses);
        }
    }

    public function provideEmbeddedIpv4Addresses(): array
    {
        return [
            'mapped loopback' => ['::ffff:127.0.0.1', '::ffff:7f00:1'],
            'mapped loopback in hex' => ['::ffff:7f00:1', '::ffff:7f00:1'],
            'mapped loopback, expanded' => ['0:0:0:0:0:ffff:7f00:1', '::ffff:7f00:1'],
            'mapped private 10/8' => ['::ffff:10.0.0.1', '::ffff:a00:1'],
            'mapped public' => ['::ffff:8.8.8.8', '::ffff:808:808'],
            'compatible loopback' => ['::127.0.0.1', '::7f00:1'],
            'compatible loopback in hex' => ['::7f00:1', '::7f00:1'],
            'compatible loopback, expanded' => ['0:0:0:0:0:0:7f00:1', '::7f00:1'],
            'compatible private 10/8' => ['::10.0.0.1', '::a00:1'],
            'compatible public' => ['::8.8.8.8', '::808:808'],
        ];
    }

    /**
     * @dataProvider provideEmbeddedIpv4Addresses
     */
    public function testConnectPolicySeesAddressesWithEmbeddedIpv4InCanonicalForm(string $ip, string $expected): void
    {
        $addresses = [];

        $policy = $this->createPolicy(static function (InternetAddress $address) use (&$addresses): bool {
            $addresses[] = $address->getAddress();

            return false;
        });

        try {
            (new DnsSocketConnector(connectPolicy: $policy))->connect('tcp://[' . $ip . ']:80');
            self::fail('The connection should have been denied');
        } catch (ConnectException $e) {
            self::assertStringEndsWith('failed: denied by the connect policy', $e->getMessage());
        }

        // The textual form of addresses with an embedded IPv4 address depends on the platform's inet_ntop().
        self::assertSame([\inet_ntop(\inet_pton($expected))], $addresses);
    }

    public function provideUnixPathsWithNulBytes(): array
    {
        return [
            ["unix:///run/amphp.sock\0suffix"],
            ["UNIX:///run/amphp.sock\0suffix"],
            [new UnixAddress("/run/amphp.sock\0suffix")],
            ["unix://\0abstract\0suffix"],
        ];
    }

    /**
     * @dataProvider provideUnixPathsWithNulBytes
     */
    public function testConnectPolicyRejectsNulBytesInUnixPaths(SocketAddress|string $uri): void
    {
        $policy = $this->createMock(ConnectPolicy::class);
        $policy->expects(self::never())
            ->method('isAllowed');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('failed: path contains a NUL byte');

        (new DnsSocketConnector(connectPolicy: $policy))->connect($uri);
    }

    public function testConnectPolicyChecksAbstractUnixSockets(): void
    {
        $addresses = [];

        $policy = $this->createPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = $address;

            return false;
        });

        try {
            (new DnsSocketConnector(connectPolicy: $policy))->connect("unix://\0abstract");
            self::fail('The connection should have been denied');
        } catch (ConnectException $e) {
            self::assertStringEndsWith('failed: denied by the connect policy', $e->getMessage());
        }

        self::assertEquals([new UnixAddress("\0abstract")], $addresses);
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
            self::assertStringContainsString('failed: denied by the connect policy', $e->getMessage());
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
            'Connection to example.com:80 @ tcp://192.0.2.3:80 failed: denied by the connect policy;'
            . ' previous attempts: example.com:80 @ tcp://192.0.2.1:80 (denied by the connect policy),'
            . ' example.com:80 @ tcp://192.0.2.2:80 (denied by the connect policy)'
        );

        $connector = new DnsSocketConnector($this->createResolver('192.0.2.1', '192.0.2.2', '192.0.2.3'), $policy);
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

    public function testConnectPolicyRejectsUserInfo(): void
    {
        $serverA = listen('127.0.0.1:0');
        $serverB = listen('127.0.0.1:0');
        $uri = 'tcp://127.0.0.1:' . $this->getPort($serverA) . '@127.0.0.1:' . $this->getPort($serverB);

        $policy = $this->createMock(ConnectPolicy::class);
        $policy->expects(self::never())
            ->method('isAllowed');

        try {
            (new DnsSocketConnector(connectPolicy: $policy))->connect($uri);
            self::fail('The URI should have been rejected');
        } catch (\Error $e) {
            self::assertSame('Invalid URI: ' . $uri . '; userinfo component not allowed', $e->getMessage());
        }

        foreach ([$serverA, $serverB] as $server) {
            try {
                $server->accept(new TimeoutCancellation(0.1));
                self::fail('No connection should have been opened');
            } catch (CancelledException) {
                $server->close();
            }
        }
    }

    public function testConnectPolicyIsNotCalledForHostnamesWithoutPort(): void
    {
        $policy = $this->createMock(ConnectPolicy::class);
        $policy->expects(self::never())
            ->method('isAllowed');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to localhost:0 failed: port required');

        (new DnsSocketConnector(connectPolicy: $policy))->connect('localhost:0');
    }

    public function testHostnamesRequireAPortWithoutConnectPolicy(): void
    {
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::never())
            ->method('resolve');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to tcp://example.com failed: port required');

        (new DnsSocketConnector($resolver))->connect('tcp://example.com');
    }

    public function provideRecordsWithoutValidAddress(): array
    {
        return [
            [[]],
            [['localhost']],
            [['localhost', '']],
        ];
    }

    /**
     * @dataProvider provideRecordsWithoutValidAddress
     */
    public function testConnectFailsWhenNoRecordIsAnIpAddress(array $records): void
    {
        foreach ([null, $this->createMock(ConnectPolicy::class)] as $policy) {
            try {
                (new DnsSocketConnector($this->createResolver(...$records), $policy))->connect('example.com:80');
                self::fail('The connection should have failed');
            } catch (ConnectException $e) {
                self::assertSame('Connection to example.com:80 failed: DNS returned no valid IP address', $e->getMessage());
            }
        }
    }

    public function provideUrisWithUserInfo(): array
    {
        return [
            ['tcp://127.0.0.1:1@127.0.0.1:2'],
            ['127.0.0.1:1@127.0.0.1:2'],
            ['tcp://user:pass@127.0.0.1:1'],
            ['tcp://user@example.com:80'],
        ];
    }

    /**
     * @dataProvider provideUrisWithUserInfo
     */
    public function testUserInfoIsRejectedWithoutConnectPolicy(string $uri): void
    {
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::never())
            ->method('resolve');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('userinfo component not allowed');

        (new DnsSocketConnector($resolver))->connect($uri);
    }

    public function provideUrisToRebuild(): array
    {
        return [
            ['tcp://[0:0:0:0:0:ffff:7f00:1]:1', 'tcp://[::ffff:127.0.0.1]:1'],
            ['tcp://127.0.0.1:1/path', 'tcp://127.0.0.1:1'],
        ];
    }

    /**
     * @dataProvider provideUrisToRebuild
     */
    public function testConnectsToTheRebuiltUriWithoutConnectPolicy(string $uri, string $expected): void
    {
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage($uri . ' @ ' . $expected);

        (new DnsSocketConnector())->connect($uri, (new ConnectContext)->withConnectTimeout(1));
    }

    public function testNulBytesInUnixPathsAreRejectedWithoutConnectPolicy(): void
    {
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('failed: path contains a NUL byte');

        (new DnsSocketConnector())->connect("unix:///run/amphp.sock\0suffix");
    }

    public function provideInvalidPorts(): array
    {
        return [
            ['127.0.0.1:65536'],
            ['example.com:65536'],
        ];
    }

    /**
     * @dataProvider provideInvalidPorts
     */
    public function testConnectPolicyRejectsInvalidPortsBeforeResolving(string $uri): void
    {
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::never())
            ->method('resolve');

        $policy = $this->createMock(ConnectPolicy::class);
        $policy->expects(self::never())
            ->method('isAllowed');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to ' . $uri . ' failed: invalid port');

        (new DnsSocketConnector($resolver, $policy))->connect($uri);
    }

    /**
     * @dataProvider provideInvalidPorts
     */
    public function testInvalidPortsAreRejectedWithoutConnectPolicy(string $uri): void
    {
        $resolver = $this->createMock(DnsResolver::class);
        $resolver->expects(self::never())
            ->method('resolve');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to ' . $uri . ' failed: invalid port');

        (new DnsSocketConnector($resolver))->connect($uri);
    }

    public function testConnectPolicySkipsRecordsThatAreNotIpAddresses(): void
    {
        $server = listen('127.0.0.1:0');
        $port = $this->getPort($server);
        $addresses = [];

        $policy = $this->createPolicy(static function (InternetAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return true;
        });

        $connector = new DnsSocketConnector($this->createResolver('localhost', '127.0.0.1'), $policy);
        $socket = $connector->connect('example.com:' . $port);

        self::assertSame(['127.0.0.1:' . $port], $addresses);
        self::assertSame('127.0.0.1:' . $port, $socket->getRemoteAddress()->toString());

        $socket->close();
        $server->close();
    }

    public function testConnectPolicyChecksUnixSockets(): void
    {
        $addresses = [];

        $policy = $this->createPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = $address;

            return false;
        });

        try {
            (new DnsSocketConnector(connectPolicy: $policy))->connect('unix:///run/amphp.sock');
            self::fail('The connection should have been denied');
        } catch (ConnectException $e) {
            self::assertSame(
                'Connection to unix:///run/amphp.sock @ unix:///run/amphp.sock failed: denied by the connect policy',
                $e->getMessage(),
            );
        }

        self::assertEquals([new UnixAddress('/run/amphp.sock')], $addresses);
    }

    public function testConnectPolicyConnectsToAllowedUnixSockets(): void
    {
        if (IS_WINDOWS) {
            self::markTestSkipped('Unix sockets are not supported on Windows');
        }

        $path = __DIR__ . '/socket.sock';
        $server = listen('unix://' . $path);
        $allowed = false;
        $addresses = [];

        $policy = $this->createPolicy(static function (SocketAddress $address) use (&$allowed, &$addresses): bool {
            $addresses[] = $address;

            return $allowed;
        });

        $connector = new DnsSocketConnector(connectPolicy: $policy);

        try {
            try {
                $connector->connect('unix://' . $path);
                self::fail('The connection should have been denied');
            } catch (ConnectException $e) {
                self::assertStringEndsWith('failed: denied by the connect policy', $e->getMessage());
            }

            try {
                $server->accept(new TimeoutCancellation(0.1));
                self::fail('No connection should have been opened');
            } catch (CancelledException) {
                $allowed = true;
            }

            $socket = $connector->connect('unix://' . $path);

            self::assertSame(SocketAddressType::Unix, $socket->getRemoteAddress()->getType());
            self::assertEquals([new UnixAddress($path), new UnixAddress($path)], $addresses);

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
