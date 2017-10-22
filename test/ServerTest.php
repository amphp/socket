<?php

namespace Amp\Socket\Test;

use Amp\Delayed;
use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;
use function Amp\asyncCall;

class ServerTest extends TestCase {
    public function testAccept() {
        Loop::run(function () {
            $server = Socket\listen("127.0.0.1:0");

            asyncCall(function () use ($server) {
                while ($socket = yield $server->accept()) {
                    $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                }
            });

            yield Socket\connect($server->getAddress());

            Loop::delay(100, [$server, 'close']);
        });
    }

    public function testTls() {
        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)
                ->withDefaultCertificate(new Socket\Certificate(__DIR__ . "/tls/amphp.org.sha256.pem"));
            $server = Socket\listen("127.0.0.1:0", null, $tlsContext);

            asyncCall(function () use ($server) {
                /** @var Socket\ServerSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->enableCrypto();
                        $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                        $this->assertSame("Hello World", yield $socket->read());
                        $socket->write("test");
                    });
                }
            });

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("amphp.org")
                ->withCaFile(__DIR__ . "/tls/ca.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($server->getAddress(), null, $context);
            yield $client->write("Hello World");

            $this->assertSame("test", yield $client->read());

            $server->close();

            Loop::stop();
        });
    }

    public function provideBlacklistedDigests() {
        return [
            ["sha1"],
            ["md5"],
        ];
    }

    /** @dataProvider provideBlacklistedDigests */
    public function testTlsRejectsDigests(string $digest) {
        Loop::run(function () use ($digest) {
            try {
                $tlsContext = (new Socket\ServerTlsContext)
                    ->withDefaultCertificate(new Socket\Certificate(__DIR__ . "/tls/amphp.org.{$digest}.pem"));
                $server = Socket\listen("127.0.0.1:0", null, $tlsContext);

                asyncCall(function () use ($server) {
                    /** @var Socket\ServerSocket $socket */
                    while ($socket = yield $server->accept()) {
                        asyncCall(function () use ($socket) {
                            yield $socket->enableCrypto();
                        });
                    }
                });

                $context = (new Socket\ClientTlsContext)
                    ->withPeerName("amphp.org")
                    ->withCaFile(__DIR__ . "/tls/ca.crt");

                $this->expectException(Socket\CryptoException::class);
                $this->expectExceptionMessage("provided a certificate using a weak signature scheme");

                /** @var Socket\ClientSocket $client */
                yield Socket\cryptoConnect($server->getAddress(), null, $context);
            } finally {
                $server->close();
            }
        });
    }

    public function testSniWorksWithCorrectHostName() {
        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)
                ->withCertificates(["amphp.org" => new Socket\Certificate(__DIR__ . "/tls/amphp.org.sha256.pem")]);
            $server = Socket\listen("127.0.0.1:0", null, $tlsContext);

            asyncCall(function () use ($server) {
                /** @var Socket\ServerSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->enableCrypto();
                        $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                        $this->assertSame("Hello World", yield $socket->read());
                        $socket->write("test");
                    });
                }
            });

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("amphp.org")
                ->withCaFile(__DIR__ . "/tls/ca.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($server->getAddress(), null, $context);
            yield $client->write("Hello World");

            $this->assertSame("test", yield $client->read());

            $server->close();

            Loop::stop();
        });
    }

    public function testSniWorksWithMultipleCertificates() {
        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)->withCertificates([
                "amphp.org" => new Socket\Certificate(__DIR__ . "/tls/amphp.org.sha256.pem"),
                "www.amphp.org" => new Socket\Certificate(__DIR__ . "/tls/www.amphp.org.sha256.pem"),
            ]);

            $server = Socket\listen("127.0.0.1:0", null, $tlsContext);

            asyncCall(function () use ($server) {
                /** @var Socket\ServerSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->enableCrypto();
                        $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                        $this->assertSame("Hello World", yield $socket->read());
                        $socket->write("test");
                    });
                }
            });

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("amphp.org")
                ->withCaFile(__DIR__ . "/tls/ca.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($server->getAddress(), null, $context);
            yield $client->write("Hello World");

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("www.amphp.org")
                ->withCaFile(__DIR__ . "/tls/ca.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($server->getAddress(), null, $context);
            yield $client->write("Hello World");

            yield new Delayed(1);
            $server->close();
            Loop::stop();
        });
    }

    public function testSniWorksWithMultipleCertificatesAndDifferentFilesForCertAndKey() {
        if (\PHP_VERSION_ID < 70200) {
            $this->markTestSkipped("This test requires PHP 7.2 or higher.");
        }

        Loop::run(function () {
            $tlsContext = (new Socket\ServerTlsContext)->withCertificates([
                "amphp.org" => new Socket\Certificate(__DIR__ . "/tls/amphp.org.crt", __DIR__ . "/tls/amphp.org.key"),
                "www.amphp.org" => new Socket\Certificate(__DIR__ . "/tls/www.amphp.org.crt", __DIR__ . "/tls/www.amphp.org.key"),
            ]);

            $server = Socket\listen("127.0.0.1:0", null, $tlsContext);

            asyncCall(function () use ($server) {
                /** @var Socket\ServerSocket $socket */
                while ($socket = yield $server->accept()) {
                    asyncCall(function () use ($socket) {
                        yield $socket->enableCrypto();
                        $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                        $this->assertSame("Hello World", yield $socket->read());
                        $socket->write("test");
                    });
                }
            });

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("amphp.org")
                ->withCaFile(__DIR__ . "/tls/amphp.org.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($server->getAddress(), null, $context);
            yield $client->write("Hello World");

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("www.amphp.org")
                ->withCaFile(__DIR__ . "/tls/www.amphp.org.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($server->getAddress(), null, $context);
            yield $client->write("Hello World");

            yield new Delayed(1);
            $server->close();
            Loop::stop();
        });
    }
}
