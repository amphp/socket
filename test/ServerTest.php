<?php

namespace Amp\Socket\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\Server;
use function Amp\ByteStream\buffer;
use function Amp\defer;
use function Amp\delay;

class ServerTest extends AsyncTestCase
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
            $this->assertMatchesRegularExpression('(\[::1\]:\d+)', (string) $socket->getAddress());
        } catch (Socket\SocketException $e) {
            if ($e->getMessage() === 'Could not create server tcp://[::1]:0: [Error: #0] Cannot assign requested address') {
                $this->markTestSkipped('Missing IPv6 support');
            }

            throw $e;
        }
    }

    public function testAccept(): void
    {
        $server = Server::listen('127.0.0.1:0');

        defer(function () use ($server): void {
            while ($socket = $server->accept()) {
                $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
            }
        });

        $socket = Socket\connect($server->getAddress());

        delay(1);

        $socket->close();
        $server->close();
    }

    public function testTls(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)
            ->withDefaultCertificate(new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'));
        $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        defer(function () use ($server): void {
            while ($socket = $server->accept()) {
                defer(function () use ($socket): void {
                    $socket->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                    $this->assertSame('Hello World', $socket->read());
                    $socket->write('test');
                    $socket->close();
                });
            }
        });

        $context = (new Socket\ConnectContext)->withTlsContext(
            (new Socket\ClientTlsContext('amphp.org'))
                ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
        );

        try {
            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');

            $this->assertSame('test', buffer($client));
        } finally {
            $server->close();
        }
    }

    public function testSniWorksWithCorrectHostName(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)
            ->withCertificates(['amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem')]);
        $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        defer(function () use ($server): void {
            /** @var Socket\EncryptableSocket $socket */
            while ($socket = $server->accept()) {
                defer(function () use ($socket): void {
                    $socket->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                    $this->assertSame('Hello World', $socket->read());
                    $socket->write('test');
                });
            }
        });

        $context = (new Socket\ConnectContext)->withTlsContext(
            (new Socket\ClientTlsContext('amphp.org'))
                ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
        );

        try {
            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');

            $this->assertSame('test', $client->read());
        } finally {
            $server->close();
        }
    }

    public function testSniWorksWithMultipleCertificates(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)->withCertificates([
            'amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'),
            'www.amphp.org' => new Socket\Certificate(__DIR__ . '/tls/www.amphp.org.pem'),
        ]);

        $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        defer(function () use ($server): void {
            /** @var Socket\ResourceSocket $socket */
            while ($socket = $server->accept()) {
                defer(function () use ($socket): void {
                    $socket->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                    $this->assertSame('Hello World', $socket->read());
                    $socket->write('test');
                });
            }
        });

        $context = (new Socket\ConnectContext)->withTlsContext(
            (new Socket\ClientTlsContext('amphp.org'))
                ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
        );

        try {
            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('www.amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/www.amphp.org.crt')
            );

            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');

            delay(1);
        } finally {
            $server->close();
        }
    }

    public function testSniWorksWithMultipleCertificatesAndDifferentFilesForCertAndKey(): void
    {
        if (\PHP_VERSION_ID < 70200) {
            $this->markTestSkipped('This test requires PHP 7.2 or higher.');
        }

        $tlsContext = (new Socket\ServerTlsContext)->withCertificates([
            'amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.crt', __DIR__ . '/tls/amphp.org.key'),
            'www.amphp.org' => new Socket\Certificate(__DIR__ . '/tls/www.amphp.org.crt', __DIR__ . '/tls/www.amphp.org.key'),
        ]);

        $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        defer(function () use ($server): void {
            /** @var Socket\ResourceSocket $socket */
            while ($socket = $server->accept()) {
                defer(function () use ($socket): void {
                    $socket->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                    $this->assertSame('Hello World', $socket->read());
                    $socket->write('test');
                });
            }
        });

        $context = (new Socket\ConnectContext)->withTlsContext(
            (new Socket\ClientTlsContext('amphp.org'))
                ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
        );

        try {
            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('www.amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/www.amphp.org.crt')
            );

            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');

            delay(1);
        } finally {
            $server->close();
        }
    }
}
