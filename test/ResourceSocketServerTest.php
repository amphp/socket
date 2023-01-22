<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ByteStream;
use Amp\CancelledException;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Process\Process;
use Amp\Socket;
use Amp\TimeoutCancellation;
use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

class ResourceSocketServerTest extends AsyncTestCase
{
    public function testListenInvalidScheme(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Invalid URI scheme (invalid); tcp, udp, unix or udg scheme expected');

        Socket\listen("invalid://127.0.0.1:0");
    }

    public function testListenStreamSocketServerError(): void
    {
        $this->expectException(Socket\SocketException::class);
        $this->expectExceptionMessage('Invalid address: error');

        Socket\listen('error');
    }

    public function testListenIPv6(): void
    {
        try {
            $socket = Socket\listen('[::1]:0');
            self::assertMatchesRegularExpression('(\[::1]:\d+)', (string) $socket->getAddress());
        } catch (Socket\SocketException $e) {
            if ($e->getMessage() === 'Could not create server tcp://[::1]:0: [Error: #0] Cannot assign requested address') {
                self::markTestSkipped('Missing IPv6 support');
            }

            throw $e;
        }
    }

    public function testAccept(): void
    {
        $server = Socket\listen('127.0.0.1:0');

        async(function () use ($server): void {
            while ($socket = $server->accept()) {
                $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
            }
        });

        $socket = Socket\connect($server->getAddress());

        delay(0.001);

        $socket->close();
        $server->close();
    }

    public function testTls(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)
            ->withDefaultCertificate(new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'));
        $server = Socket\listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        self::assertEquals(
            $tlsContext->toStreamContextArray(),
            ServerTlsContext::fromServerResource($server->getResource())->toStreamContextArray()
        );

        async(function () use ($server): void {
            while ($socket = $server->accept()) {
                async(function () use ($socket): void {
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

            self::assertSame('test', buffer($client));
        } finally {
            $server->close();
        }
    }

    public function testSniWorksWithCorrectHostName(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)
            ->withCertificates(['amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem')]);
        $server = Socket\listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        async(function () use ($server): void {
            /** @var Socket\Socket $socket */
            while ($socket = $server->accept()) {
                async(function () use ($socket): void {
                    $socket->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                    $this->assertSame('Hello World', buffer($socket));
                    $socket->write('test');
                    $socket->end();
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
            $client->end();

            self::assertSame('test', buffer($client));
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

        $server = Socket\listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        async(function () use ($server): void {
            /** @var Socket\ResourceSocket $socket */
            while ($socket = $server->accept()) {
                async(function () use ($socket): void {
                    $socket->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                    $this->assertSame('Hello World', buffer($socket));
                    $socket->write('test');
                    $socket->end();
                });
            }
        });

        try {
            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withoutPeerVerification()
                    ->withPeerFingerprint($this->createFingerprintFromFile(__DIR__ . '/tls/amphp.org.crt'))
            );

            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');
            $client->end();
            self::assertSame('test', buffer($client));

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('www.amphp.org'))
                    ->withoutPeerVerification()
                    ->withPeerFingerprint($this->createFingerprintFromFile(__DIR__ . '/tls/www.amphp.org.crt'))
            );

            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');
            $client->end();
            self::assertSame('test', buffer($client));
        } finally {
            $server->close();
        }
    }

    public function testPeerFingerprintMatchFailure(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)
            ->withDefaultCertificate(new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'));

        $server = Socket\listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        async(function () use ($server): void {
            /** @var Socket\ResourceSocket $socket */
            while ($socket = $server->accept()) {
                async($socket->setupTls(...))->ignore();
            }
        });

        try {
            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('www.amphp.org'))
                    ->withoutPeerVerification()
                    ->withPeerFingerprint(\sha1('invalid-certificate-contents'))
            );

            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();

            $this->fail('Call to setupTls should have failed');
        } catch (TlsException $exception) {
            self::assertStringContainsString('peer_fingerprint match failure', $exception->getMessage());
        } finally {
            $server->close();
        }
    }

    public function testSniWorksWithMultipleCertificatesAndDifferentFilesForCertAndKey(): void
    {
        $tlsContext = (new Socket\ServerTlsContext)->withCertificates([
            'amphp.org' => new Socket\Certificate(__DIR__ . '/tls/amphp.org.crt', __DIR__ . '/tls/amphp.org.key'),
            'www.amphp.org' => new Socket\Certificate(
                __DIR__ . '/tls/www.amphp.org.crt',
                __DIR__ . '/tls/www.amphp.org.key'
            ),
        ]);

        $server = Socket\listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        async(function () use ($server): void {
            /** @var Socket\ResourceSocket $socket */
            while ($socket = $server->accept()) {
                async(function () use ($socket): void {
                    $socket->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $socket);
                    $this->assertSame('Hello World', $socket->read());
                    $socket->write('test');
                    $socket->end();
                });
            }
        });

        try {
            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
            );

            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');
            $client->end();
            self::assertSame('test', buffer($client));

            $context = (new Socket\ConnectContext)->withTlsContext(
                (new Socket\ClientTlsContext('www.amphp.org'))
                    ->withCaFile(__DIR__ . '/tls/www.amphp.org.crt')
            );

            $client = Socket\connect($server->getAddress(), $context);
            $client->setupTls();
            $client->write('Hello World');
            $client->end();
            self::assertSame('test', buffer($client));
        } finally {
            $server->close();
        }
    }

    public function testCancelThenAccept(): void
    {
        $server = Socket\listen('127.0.0.1:0');

        try {
            $server->accept(new TimeoutCancellation(0.01));
            $this->fail('The accept should have been cancelled');
        } catch (CancelledException) {
        }

        $future = async(fn () => Socket\connect($server->getAddress()));

        $serverSocket = $server->accept();
        $clientSocket = $future->await();

        $data = 'test';
        $serverSocket->write($data);
        self::assertSame($data, $clientSocket->read());
    }

    private function createFingerprintFromFile(string $filename): string
    {
        $process = Process::start([
            'openssl',
            'x509',
            '-fingerprint',
            '-in',
            $filename,
            '-noout'
        ]);

        $process->join();

        $output = ByteStream\buffer($process->getStdout());

        if (\preg_match('[^SHA1 Fingerprint=(?<fingerprint>(?:[A-F0-9]{2}:){19}[A-F0-9]{2})]', $output, $matches)) {
            return \str_replace(':', '', $matches['fingerprint']);
        }

        if (\preg_match('[^SHA256 Fingerprint=(?<fingerprint>(?:[A-F0-9]{2}:){31}[A-F0-9]{2})]', $output, $matches)) {
            return \str_replace(':', '', $matches['fingerprint']);
        }

        $this->fail('Could not read certificate fingerprint file ' . $filename . '; openssl output: ' . $output);
    }
}
