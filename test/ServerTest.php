<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;

class ServerTest extends TestCase {
    public function testAccept() {
        Loop::run(function () {
            $server = Socket\listen("tcp://127.0.0.1:0", function ($socket) {
                $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
            });

            yield Socket\connect($server->getAddress());

            Loop::delay(100, [$server, 'close']);
        });
    }

    public function testTls() {
        Loop::run(function () {
            $server = Socket\listen("tcp://127.0.0.1:0", function (Socket\ServerSocket $socket) {
                yield $socket->enableCrypto();
                $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                $this->assertSame("Hello World", yield $socket->read());
                $socket->write("test");
            }, null, (new Socket\ServerTlsContext)->withDefaultCertificate(__DIR__ . "/tls/amphp.org.pem"));

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("amphp.org")
                ->withCaFile(__DIR__ . "/tls/amphp.org.crt");

            /** @var Socket\ClientSocket $client */
            $client = yield Socket\cryptoConnect($server->getAddress(), null, $context);
            yield $client->write("Hello World");

            $this->assertSame("test", yield $client->read());

            $server->close();

            Loop::stop();
        });
    }

    public function testSniWorksWithCorrectHostName() {
        Loop::run(function () {
            $server = Socket\listen("tcp://127.0.0.1:0", function (Socket\ServerSocket $socket) {
                yield $socket->enableCrypto();
                $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                $this->assertSame("Hello World", yield $socket->read());
                $socket->write("test");
            }, null, (new Socket\ServerTlsContext)->withCertificates(["amphp.org" => __DIR__ . "/tls/amphp.org.pem"]));

            $context = (new Socket\ClientTlsContext)
                ->withPeerName("amphp.org")
                ->withCaFile(__DIR__ . "/tls/amphp.org.crt");

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
            $server = Socket\listen("tcp://127.0.0.1:0", function (Socket\ServerSocket $socket) {
                yield $socket->enableCrypto();
                $this->assertInstanceOf(Socket\ServerSocket::class, $socket);
                $this->assertSame("Hello World", yield $socket->read());
            }, null, (new Socket\ServerTlsContext)->withCertificates([
                "amphp.org" => __DIR__ . "/tls/amphp.org.pem",
                "www.amphp.org" => __DIR__ . "/tls/www.amphp.org.pem",
            ]));

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

            $server->close();

            Loop::stop();
        });
    }
}
