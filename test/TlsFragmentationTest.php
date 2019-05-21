<?php

namespace Amp\Socket\Test;

use Amp\ByteStream;
use Amp\Delayed;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Socket;
use function Amp\asyncCall;

class TlsFragmentationTest extends TestCase
{
    public function testTls(): void
    {
        if (\PHP_VERSION_ID < 70215) {
            $this->markTestSkipped('Your PHP version is affected by PHP bug 77390');
        }

        if (\PHP_VERSION_ID >= 70300 && \PHP_VERSION_ID < 70303) {
            $this->markTestSkipped('Your PHP version is affected by PHP bug 77390');
        }

        Loop::run(function () {
            $proxyServer = Socket\listen('127.0.0.1:0');

            $tlsContext = (new Socket\ServerTlsContext)
                ->withDefaultCertificate(new Socket\Certificate(__DIR__ . '/tls/amphp.org.pem'));
            $server = Socket\listen('127.0.0.1:0', (new Socket\ServerBindContext)->withTlsContext($tlsContext));

            // Proxy to apply chunking of single bytes
            asyncCall(function () use ($proxyServer, $server) {
                /** @var Socket\EncryptableServerSocket $proxyClient */
                while ($proxyClient = yield $proxyServer->accept()) {
                    asyncCall(function () use ($proxyClient, $server) {
                        $proxyUpstream = yield Socket\connect($server->getAddress());

                        $this->pipe($proxyClient, $proxyUpstream);
                        $this->pipe($proxyUpstream, $proxyClient);
                    });
                }
            });

            asyncCall(function () use ($server) {
                /** @var Socket\EncryptableServerSocket $client */
                while ($client = yield $server->accept()) {
                    asyncCall(function () use ($client) {
                        yield $client->setupTls();
                        $this->assertInstanceOf(Socket\EncryptableServerSocket::class, $client);
                        $this->assertSame('Hello World', yield from $this->read($client, 11));
                        $client->write('test');
                    });
                }
            });

            $context = (new Socket\ClientTlsContext('amphp.org'))
                ->withCaFile(__DIR__ . '/tls/amphp.org.crt');

            /** @var Socket\EncryptableClientSocket $client */
            $client = yield Socket\connect($proxyServer->getAddress());
            yield $client->setupTls($context);
            yield $client->write('Hello World');

            $this->assertSame('test', yield from $this->read($client, 4));

            $server->close();

            Loop::stop();
        });
    }

    private function pipe(ByteStream\InputStream $source, ByteStream\OutputStream $destination): void
    {
        asyncCall(static function () use ($source, $destination): \Generator {
            while (($chunk = yield $source->read()) !== null) {
                foreach (\str_split($chunk) as $byte) {
                    yield $destination->write($byte);
                    yield new Delayed(1);
                }
            }
        });
    }

    private function read(ByteStream\InputStream $source, int $minLength)
    {
        $buffer = '';

        while (null !== $chunk = yield $source->read()) {
            $buffer .= $chunk;

            if (\strlen($buffer) >= $minLength) {
                return $buffer;
            }
        }

        throw new \RuntimeException("Stream ended prior to {$minLength} bytes being read.");
    }
}
