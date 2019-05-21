<?php

namespace Amp\Socket\Test;

use Amp\Delayed;
use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;
use function Amp\asyncCall;

class ServerTest extends TestCase
{
    public function testAccept(): void
    {
        Loop::run(function () {
            $server = Socket\listen('127.0.0.1:0');

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
            $server = Socket\listen('127.0.0.1:0', (new Socket\ServerBindContext)->withTlsContext($tlsContext));

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

            $context = (new Socket\ClientConnectContext)->withTlsContext(
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

    public function testSniWorksWithCorrectHostName(): void
    {
        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)
                ->withCertificates(['amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem')]);
            $server = Socket\listen('127.0.0.1:0', (new Socket\ServerBindContext)->withTlsContext($tlsContext));

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

            $context = (new Socket\ClientConnectContext)->withTlsContext(
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

            $server = Socket\listen('127.0.0.1:0', (new Socket\ServerBindContext)->withTlsContext($tlsContext));

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

            $context = (new Socket\ClientConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            $context = (new Socket\ClientConnectContext)->withTlsContext(
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

            $server = Socket\listen('127.0.0.1:0', (new Socket\ServerBindContext)->withTlsContext($tlsContext));

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

            $context = (new Socket\ClientConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
            );

            /** @var Socket\EncryptableSocket $client */
            $client = yield Socket\connect($server->getAddress(), $context);
            yield $client->setupTls();
            yield $client->write('Hello World');

            $context = (new Socket\ClientConnectContext)->withTlsContext(
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
