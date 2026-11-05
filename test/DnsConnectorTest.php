<?php

namespace Amp\Socket\Test;

use Amp\Dns;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Promise;
use Amp\Socket\ConnectContext;
use Amp\Socket\ConnectException;
use Amp\Socket\ConnectPolicy;
use Amp\Socket\DnsConnector;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Server;
use Amp\Socket\SocketAddress;
use Amp\Success;
use Amp\TimeoutException;

class DnsConnectorTest extends AsyncTestCase
{
    public function provideIpLiterals(): array
    {
        return [
            ['127.0.0.1:80', '127.0.0.1:80'],
            ['tcp://127.0.0.1:80', '127.0.0.1:80'],
            ['udp://127.0.0.1:53', '127.0.0.1:53'],
            ['[::1]:80', '[::1]:80'],
            ['tcp://[::ffff:127.0.0.1]:80', '[::ffff:127.0.0.1]:80'],
        ];
    }

    /**
     * @dataProvider provideIpLiterals
     */
    public function testConnectPolicyChecksIpLiterals(string $uri, string $expected): \Generator
    {
        $addresses = [];
        $connectPolicy = $this->createConnectPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return false;
        });

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage($expected . ' failed: denied by the connect policy');

        try {
            yield (new DnsConnector(null, $connectPolicy))->connect($uri);
        } finally {
            $this->assertSame([$expected], $addresses);
        }
    }

    public function testConnectPolicyChecksEachResolvedAddress(): \Generator
    {
        $addresses = [];
        $connectPolicy = $this->createConnectPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return false;
        });

        $resolver = $this->createResolver(
            new Dns\Record('2001:db8::1', Dns\Record::AAAA),
            new Dns\Record('192.0.2.1', Dns\Record::A)
        );

        $this->expectException(ConnectException::class);

        try {
            yield (new DnsConnector($resolver, $connectPolicy))->connect('tcp://example.com:80');
        } finally {
            $this->assertSame(['192.0.2.1:80', '[2001:db8::1]:80'], $addresses);
        }
    }

    public function testDeniedAddressesAreSkipped(): \Generator
    {
        $server = Server::listen('127.0.0.1:0');
        $port = $server->getAddress()->getPort();

        $addresses = [];
        $connectPolicy = $this->createConnectPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return $address->getHost() === '127.0.0.1';
        });

        $resolver = $this->createResolver(
            new Dns\Record('192.0.2.1', Dns\Record::A),
            new Dns\Record('192.0.2.2', Dns\Record::A),
            new Dns\Record('127.0.0.1', Dns\Record::A)
        );

        try {
            /** @var EncryptableSocket $socket */
            $socket = yield (new DnsConnector($resolver, $connectPolicy))->connect(
                'tcp://example.com:' . $port,
                (new ConnectContext)->withMaxAttempts(1)
            );
        } finally {
            $server->close();
        }

        $this->assertSame(['192.0.2.1:' . $port, '192.0.2.2:' . $port, '127.0.0.1:' . $port], $addresses);
        $this->assertSame('127.0.0.1:' . $port, $socket->getRemoteAddress()->toString());
    }

    public function testConnectFailsWhenAllAddressesAreDenied(): \Generator
    {
        $connectPolicy = $this->createConnectPolicy(static function (): bool {
            return false;
        });

        $resolver = $this->createResolver(
            new Dns\Record('192.0.2.1', Dns\Record::A),
            new Dns\Record('192.0.2.2', Dns\Record::A),
            new Dns\Record('192.0.2.3', Dns\Record::A)
        );

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage(
            'Connection to tcp://example.com:80 @ tcp://192.0.2.3:80 failed: denied by the connect policy; previous attempts: '
            . 'tcp://example.com:80 @ tcp://192.0.2.1:80 (denied by the connect policy), '
            . 'tcp://example.com:80 @ tcp://192.0.2.2:80 (denied by the connect policy)'
        );

        yield (new DnsConnector($resolver, $connectPolicy))->connect('tcp://example.com:80');
    }

    public function provideConnectPolicyExceptions(): array
    {
        return [
            [new \RuntimeException('Policy failure')],
            [new ConnectException('Policy failure')],
        ];
    }

    /**
     * @dataProvider provideConnectPolicyExceptions
     */
    public function testConnectPolicyExceptionsAbortTheConnection(\Exception $exception): \Generator
    {
        $server = Server::listen('127.0.0.1:0');
        $port = $server->getAddress()->getPort();

        $addresses = [];
        $connectPolicy = $this->createConnectPolicy(static function (SocketAddress $address) use (&$addresses, $exception): bool {
            $addresses[] = $address->toString();

            throw $exception;
        });

        $resolver = $this->createResolver(
            new Dns\Record('192.0.2.1', Dns\Record::A),
            new Dns\Record('127.0.0.1', Dns\Record::A)
        );

        try {
            yield (new DnsConnector($resolver, $connectPolicy))->connect('tcp://example.com:' . $port);
            $this->fail('The connection should have been aborted');
        } catch (\Exception $e) {
            $this->assertSame($exception, $e);
        }

        $this->assertSame(['192.0.2.1:' . $port], $addresses);

        try {
            yield Promise\timeout($server->accept(), 100);
            $this->fail('No connection should have been opened');
        } catch (TimeoutException $e) {
            $server->close();
        }
    }

    public function testConnectPolicyRejectsUrisWithUserInfo(): \Generator
    {
        $serverA = Server::listen('127.0.0.1:0');
        $serverB = Server::listen('127.0.0.1:0');
        $uri = 'tcp://' . $serverA->getAddress() . '@' . $serverB->getAddress();

        $connectPolicy = $this->createConnectPolicy(function (): bool {
            $this->fail('The connect policy should not be called for a URI with userinfo');
        });

        try {
            yield (new DnsConnector(null, $connectPolicy))->connect($uri);
            $this->fail('The connection should have been rejected');
        } catch (\Error $e) {
            $this->assertSame('Invalid URI: ' . $uri . '; userinfo component not allowed', $e->getMessage());
        }

        foreach ([$serverA, $serverB] as $server) {
            try {
                yield Promise\timeout($server->accept(), 100);
                $this->fail('No connection should have been opened');
            } catch (TimeoutException $e) {
                $server->close();
            }
        }
    }

    public function testInvalidIpAddressesAreSkipped(): \Generator
    {
        $server = Server::listen('127.0.0.1:0');
        $port = $server->getAddress()->getPort();

        $addresses = [];
        $connectPolicy = $this->createConnectPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = $address->toString();

            return true;
        });

        $resolver = $this->createResolver(
            new Dns\Record('localhost', Dns\Record::A),
            new Dns\Record('127.0.0.1', Dns\Record::A)
        );

        try {
            /** @var EncryptableSocket $socket */
            $socket = yield (new DnsConnector($resolver, $connectPolicy))->connect(
                'tcp://example.com:' . $port,
                (new ConnectContext)->withMaxAttempts(1)
            );
        } finally {
            $server->close();
        }

        $this->assertSame(['127.0.0.1:' . $port], $addresses);
        $this->assertSame('127.0.0.1:' . $port, $socket->getRemoteAddress()->toString());
    }

    public function provideInvalidIpAddresses(): array
    {
        return [
            ['localhost', 'Connection to tcp://example.com:80 @ tcp://localhost:80 failed: invalid IP address'],
            ['bad host', 'Connection to tcp://example.com:80 @ tcp://bad host:80 failed: invalid IP address'],
        ];
    }

    /**
     * @dataProvider provideInvalidIpAddresses
     */
    public function testConnectFailsWhenNoAddressIsValid(string $value, string $expected): \Generator
    {
        $connectPolicy = $this->createConnectPolicy(function (): bool {
            $this->fail('The connect policy should not be called with an invalid IP address');
        });

        $resolver = $this->createResolver(new Dns\Record($value, Dns\Record::A));

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage($expected);

        yield (new DnsConnector($resolver, $connectPolicy))->connect('tcp://example.com:80');
    }

    public function provideUrisWithAnInvalidPort(): array
    {
        return [
            ['tcp://127.0.0.1:65536'],
            ['tcp://example.com:65536'],
        ];
    }

    /**
     * @dataProvider provideUrisWithAnInvalidPort
     */
    public function testConnectFailsWhenThePortIsInvalid(string $uri): \Generator
    {
        $connectPolicy = $this->createConnectPolicy(function (): bool {
            $this->fail('The connect policy should not be called with an invalid port');
        });

        $resolver = $this->createMock(Dns\Resolver::class);
        $resolver->expects($this->never())
            ->method('resolve');

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection to ' . $uri . ' failed: invalid port');

        yield (new DnsConnector($resolver, $connectPolicy))->connect($uri);
    }

    public function testConnectPolicyChecksUnixSockets(): \Generator
    {
        if (\stripos(\PHP_OS, 'win') === 0) {
            $this->markTestSkipped('Unix sockets are not supported on Windows');
        }

        $path = \sys_get_temp_dir() . '/amphp-socket-test-' . \getmypid() . '.sock';
        $server = Server::listen('unix://' . $path);

        $addresses = [];
        $connectPolicy = $this->createConnectPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = [$address->getHost(), $address->getPort()];

            return false;
        });

        try {
            try {
                yield (new DnsConnector(null, $connectPolicy))->connect('unix://' . $path);
                $this->fail('The connection should have been denied');
            } catch (ConnectException $e) {
                $this->assertSame('Connection to unix://' . $path . ' @ unix://' . $path . ' failed: denied by the connect policy', $e->getMessage());
            }

            $this->assertSame([[$path, null]], $addresses);

            try {
                yield Promise\timeout($server->accept(), 100);
                $this->fail('No connection should have been opened');
            } catch (TimeoutException $e) {
                // No connection has been opened
            }
        } finally {
            $server->close();
            @\unlink($path);
        }
    }

    public function testConnectPolicyCanAllowUnixSockets(): \Generator
    {
        if (\stripos(\PHP_OS, 'win') === 0) {
            $this->markTestSkipped('Unix sockets are not supported on Windows');
        }

        $path = \sys_get_temp_dir() . '/amphp-socket-test-' . \getmypid() . '.sock';
        $server = Server::listen('unix://' . $path);

        $addresses = [];
        $connectPolicy = $this->createConnectPolicy(static function (SocketAddress $address) use (&$addresses): bool {
            $addresses[] = [$address->getHost(), $address->getPort()];

            return true;
        });

        try {
            $socket = yield (new DnsConnector(null, $connectPolicy))->connect('unix://' . $path);
        } finally {
            $server->close();
            @\unlink($path);
        }

        $this->assertInstanceOf(EncryptableSocket::class, $socket);
        $this->assertSame([[$path, null]], $addresses);
    }

    private function createConnectPolicy(callable $isAllowed): ConnectPolicy
    {
        return new class($isAllowed) implements ConnectPolicy {
            private $isAllowed;

            public function __construct(callable $isAllowed)
            {
                $this->isAllowed = $isAllowed;
            }

            public function isAllowed(SocketAddress $address): bool
            {
                return ($this->isAllowed)($address);
            }
        };
    }

    private function createResolver(Dns\Record ...$records): Dns\Resolver
    {
        $resolver = $this->createMock(Dns\Resolver::class);
        $resolver->method('resolve')
            ->willReturn(new Success($records));

        return $resolver;
    }
}
