<?php

require __DIR__ . '/../vendor/autoload.php';

// This is a dump HTTP client demonstrating rejection of bad TLS configs.

use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Socket\ClientSocket;
use Amp\Socket\CryptoException;
use Amp\Uri\Uri;
use function Amp\Socket\cryptoConnect;

Loop::run(function () use ($argv) {
    $stdout = new ResourceOutputStream(STDOUT);

    $uris = [
        "https://expired.badssl.com/",
        "https://wrong.host.badssl.com/",
        "https://self-signed.badssl.com/",
        "https://untrusted-root.badssl.com/",
        "https://revoked.badssl.com/",
        "https://pinning-test.badssl.com/",
        "https://no-common-name.badssl.com/",
        "https://no-subject.badssl.com/",
        "https://incomplete-chain.badssl.com/",
        "https://sha1-intermediate.badssl.com/",
        "https://sha256.badssl.com/",
        "https://sha384.badssl.com/",
        "https://sha512.badssl.com/",
        "https://1000-sans.badssl.com/",
        "https://10000-sans.badssl.com/",
        "https://ecc256.badssl.com/",
        "https://ecc384.badssl.com/",
        "https://rsa2048.badssl.com/",
        "https://rsa8192.badssl.com/",
        "https://cbc.badssl.com/",
        "https://rc4-md5.badssl.com/",
        "https://rc4.badssl.com/",
        "https://3des.badssl.com/",
        "https://null.badssl.com/",
        "https://dh480.badssl.com/",
        "https://dh512.badssl.com/",
        "https://dh1024.badssl.com/",
        "https://dh2048.badssl.com/",
        "https://dh-small-subgroup.badssl.com/",
        "https://dh-composite.badssl.com/",
        "https://sha1-2016.badssl.com/",
        "https://sha1-2017.badssl.com/",
    ];

    foreach ($uris as $uri) {
        $uri = new Uri($uri);
        $host = $uri->getHost();
        $buffer = "";

        try {
            /** @var ClientSocket $socket */
            $socket = yield cryptoConnect("tcp://" . $host . ":" . $uri->getPort());

            yield $socket->write("GET {$uri} HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");

            while (null !== $chunk = yield $socket->read()) {
                $buffer .= $chunk;

                if (strpos($buffer, "\r\n") !== false) {
                    yield $stdout->write("{$host}: " . strstr($buffer, "\r\n", true) . PHP_EOL);
                    $socket->close();
                    break;
                }
            }
        } catch (CryptoException $exception) {
            yield $stdout->write("{$host}: FAIL" . PHP_EOL);

            // For getting error messages:
            // yield $stdout->write("{$host}: " . strstr(strtr($exception->getMessage(), "\n", " "), "OpenSSL Error messages:", false) . PHP_EOL);
        }
    }
});
