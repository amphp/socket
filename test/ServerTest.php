<?php

namespace Amp\Socket\Test;

use Amp\Delayed;
use Amp\Loop;
use Amp\Socket;
use Amp\Socket\Server;
use PHPUnit\Framework\TestCase;
use function Amp\asyncCall;
use function Amp\ByteStream\buffer;

class ServerTest extends TestCase
{
    public function testListenInvalidScheme(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Only tcp and unix schemes allowed for server creation');

        Server::listen("invalid://127.0.0.1:0");
    }

    public function testListenStreamSocketServerError(): void
    {
        $this->expectException(Socket\SocketException::class);
        $this->expectExceptionMessageMatches('/Could not create server .*: \[Error: #.*\] .*$/');

        Server::listen('error');
    }

    public function testListenIPv6(): void
    {
        try {
            $socket = Server::listen('[::1]:0');
            $this->assertRegExp('(\[::1\]:\d+)', (string) $socket->getAddress());
        } catch (Socket\SocketException $e) {
            if ($e->getMessage() === 'Could not create server tcp://[::1]:0: [Error: #0] Cannot assign requested address') {
                $this->markTestSkipped('Missing IPv6 support');
            }

            throw $e;
        }
    }

    public function testAccept(): void
    {
        Loop::run(function () {
            $server = Server::listen('127.0.0.1:0');

            asyncCall(function () use ($server) {
                while ($socket = yield $server->accept()) {
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                }
            });

            yield Socket\connect($server->getAddress());

            Loop::delay(100, [$server, 'close']);
        });
    }

    public function testTls(): void
    {
        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)
                ->withDefaultCertificate(new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'));
            $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

            asyncCall(function () use ($server) {
                /** @var Socket\ResourceSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->setupTls();
                        $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                        $this->assertSame('Hello World', yield $socket->read());
                        $socket->write('test');
                        $socket->close();
                    });
                }
            });

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            $this->assertSame('test', yield buffer($client));

            $server->close();

            Loop::stop();
        });
    }

    public function testSniWorksWithCorrectHostName(): void
    {
        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)
                ->withCertificates(['amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem')]);
            $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

            asyncCall(function () use ($server) {
                /** @var Socket\EncryptableSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->setupTls();
                        $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                        $this->assertSame('Hello World', yield $socket->read());
                        $socket->write('test');
                    });
                }
            });

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            $this->assertSame('test', yield $client->read());

            $server->close();

            Loop::stop();
        });
    }

    public function testSniWorksWithMultipleCertificates(): void
    {
        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)->withCertificates([
                'amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'),
                'www.amphp.org' => new Socket\Certificate(__DIR__ . '/tls/www.amphp.org.pem'),
            ]);

            $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

            asyncCall(function () use ($server) {
                /** @var Socket\ResourceSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->setupTls();
                        $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                        $this->assertSame('Hello World', yield $socket->read());
                        $socket->write('test');
                    });
                }
            });

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('www.amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/www.amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            yield new Delayed(1);
            $server->close();
            Loop::stop();
        });
    }

    public function testSniWorksWithMultipleCertificatesAndDifferentFilesForCertAndKey(): void
    {
        if (\PHP_VERSION_ID < 70200) {
            $this->markTestSkipped('This test requires PHP 7.2 or higher.');
        }

        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)->withCertificates([
                'amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.crt', __DIR__ . '/tls/amphp.org.key'),
                'www.amphp.org' => new Socket\Certificate(__DIR__ . '/tls/www.amphp.org.crt', __DIR__ . '/tls/www.amphp.org.key'),
            ]);

            $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

            asyncCall(function () use ($server) {
                /** @var Socket\ResourceSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->setupTls();
                        $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                        $this->assertSame('Hello World', yield $socket->read());
                        $socket->write('test');
                    });
                }
            });

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('www.amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/www.amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            yield new Delayed(1);
            $server->close();
            Loop::stop();
        });
    }
}
