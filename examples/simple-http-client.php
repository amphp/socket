<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.

use Amp\ByteStream\ResourceOutputStream;
use Amp\Loop;
use Amp\Socket\Socket;
use Amp\Uri\Uri;
use function Amp\Socket\connect;
use function Amp\Socket\cryptoConnect;

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

    while (null !== $chunk = yield $socket->read()) {
        yield $stdout->write($chunk);
    }

    // If the promise returned from `read()` resolves to `null`, the socket closed and we're done.
    // In this case you can also use `yield Amp\ByteStream\pipe($socket, $stdout)` instead of the while loop,
    // but we want to demonstate the `read()` method here.
});
