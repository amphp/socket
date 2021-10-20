<?php

namespace Amp\Socket\Test;

use Amp\ByteStream;
use Amp\PHPUnit\AsyncTestCase;
use Amp\Socket;
use Amp\Socket\Server;
use Revolt\EventLoop;
use function Amp\delay;

class TlsFragmentationTest extends AsyncTestCase
{
    public function testTls(): void
    {
        if (\PHP_VERSION_ID < 70215) {
            self::markTestSkipped('Your PHP version is affected by PHP bug 77390');
        }

        if (\PHP_VERSION_ID >= 70300 && \PHP_VERSION_ID < 70303) {
            self::markTestSkipped('Your PHP version is affected by PHP bug 77390');
        }

        $proxyServer = Server::listen('127.0.0.1:0');

        $tlsContext = (new Socket\ServerTlsContext)
            ->withDefaultCertificate(new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'));
        $server = Server::listen('127.0.0.1:0', (new Socket\BindContext)->withTlsContext($tlsContext));

        // Proxy to apply chunking of single bytes
        EventLoop::queue(function () use ($proxyServer, $server): void {
            /** @var Socket\ResourceSocket $proxyClient */
            while ($proxyClient = $proxyServer->accept()) {
                EventLoop::queue(function () use ($proxyClient, $server): void {
                    $proxyUpstream = Socket\connect($server->getAddress());

                    $this->pipe($proxyClient, $proxyUpstream);
                    $this->pipe($proxyUpstream, $proxyClient);
                });
            }
        });

        EventLoop::queue(function () use ($server): void {
            /** @var Socket\ResourceSocket $client */
            while ($client = $server->accept()) {
                EventLoop::queue(function () use ($client): void {
                    $client->setupTls();
                    $this->assertInstanceOf(Socket\ResourceSocket::class, $client);
                    $this->assertSame('Hello World', $this->read($client, 11));
                    $client->write('test');
                    $client->close();
                });
            }
        });

        $context = (new Socket\ConnectContext())->withTlsContext(
            (new Socket\ClientTlsContext('amphp.org'))
            ->withCaFile(__DIR__ . '/tls/amphp.org.crt')
        );

        /** @var Socket\ResourceSocket $client */
        $client = Socket\connect($proxyServer->getAddress(), $context);
        $client->setupTls();
        $client->write('Hello World');

        self::assertSame('test', $this->read($client, 4));

        delay(0.1);

        $proxyServer->close();
        $server->close();
    }

    private function pipe(ByteStream\InputStream $source, ByteStream\OutputStream $destination): void
    {
        EventLoop::queue(static function () use ($source, $destination): void {
            while (($chunk = $source->read()) !== null) {
                foreach (\str_split($chunk) as $byte) {
                    $destination->write($byte);
                    delay(0.001);
                }
            }

            $destination->end();
        });
    }

    private function read(ByteStream\InputStream $source, int $minLength): string
    {
        $buffer = '';

        while (null !== $chunk = $source->read()) {
            $buffer .= $chunk;

            if (\strlen($buffer) >= $minLength) {
                return $buffer;
            }
        }

        throw new \RuntimeException("Stream ended prior to {$minLength} bytes being read.");
    }
}
