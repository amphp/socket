<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.

use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use function Amp\asyncCoroutine;
use function Amp\Socket\connect;
use function Amp\Socket\cryptoConnect;
use Amp\Socket\Socket;
use Amp\Uri\Uri;

Loop::run(function () use ($argv) {
    $stdout = new ResourceOutputStream(STDOUT);

    if (count($argv) !== 2) {
        yield $stdout->write("Usage: examples/simple-http-client.php url" . PHP_EOL);
        exit(1);
    }

    $uri = new Uri($argv[1]);
    $host = $uri->getHost();

    if ($uri->getScheme() === "https") {
        /** @var Socket $socket */
        $socket = yield cryptoConnect("tcp://" . $host . ":" . $uri->getPort());
    } else {
        /** @var Socket $socket */
        $socket = yield connect("tcp://" . $host . ":" . $uri->getPort());
    }

    yield $socket->write("GET {$uri} HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");
    yield Amp\ByteStream\pipe($socket, $stdout);
});
