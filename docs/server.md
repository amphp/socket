---
title: Server
permalink: /server
---
`amphp/socket` allows listening for incoming TCP connections as well as connections via Unix domain sockets. It defaults to secure TLS settings if you decide to enable TLS.

## Listening

To listen on a port or unix domain socket, you can use `Amp\Socket\Server::listen()`. It's a wrapper around `stream_socket_server` that gives useful error message on failures via exceptions.

```php
/**
 * Listen for client connections on the specified server address.
 *
 * If you want to accept TLS connections, you have to use `yield $socket->setupTls()` after accepting new clients.
 *
 * @param string              $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param BindContext $socketContext Context options for listening.
 *
 * @return Server
 *
 * @throws SocketException If binding to the specified URI failed.
 */
function listen(string $uri, BindContext $socketContext = null): Server {
    /* ... */
}
```

## Controlling the `Server`

### Accepting Connections

Once you're listening, you can accept clients using `Server::accept()`. It returns a `Promise` that returns once a new client has been accepted. It's usually called within a `while` loop:

```php
$server = Server::listen("tcp://127.0.0.1:1337");

while ($client = yield $server->accept()) {
    // do something with $client, which is a ResourceSocket instance
    
    // you shouldn't yield here, because that will wait for the yielded promise
    // before accepting another client, see below.
}
```

### Handling Connections

It's best to handle clients in their own coroutine, while letting the server accept all clients as soon as there are new clients.

```php
use Amp\Loop;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\Server;

Loop::run(function () {
    $clientHandler = function (EncryptableSocket $socket) {
        $address = $socket->getRemoteAddress();
        $ip = $address->getHost();
        $port = $address->getPort();

        echo "Accepted connection from {$address}." . PHP_EOL;

        $body = "Hey, your IP is {$ip} and your local port used is {$port}.";
        $bodyLength = \strlen($body);

        yield $socket->end("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: {$bodyLength}\r\n\r\n{$body}");
    };
    
    $server = Server::listen("127.0.0.1:0");

    echo "Listening for new connections on " . $server->getAddress() . " ..." . PHP_EOL;
    echo "Open your browser and visit http://" . $server->getAddress() . "/" . PHP_EOL;

    while ($socket = yield $server->accept()) {
        Amp\asyncCall($clientHandler, $socket);
    }
});
```

### Closing Connections

Once you're done with a client, you can close the connection using `Socket::close()`. If you want to wait for all data to be successfully written before closing the connection, you can use `Socket::end()` with or without a final data chunk. For an example, look at the section above.

## Server Address

Sometimes you don't know the address the server is listening on, e.g. because you listed to `tcp://127.0.0.1:0`, which assigns a random free port. You can use `Server::getAddress()` to get the address the server is bound to.

## Sending Data

`ResourceSocket` implements `OutputStream`, so everything from [`amphp/byte-stream`](https://amphp.org/byte-stream/#outputstream) applies.

## Receiving Data

`ResourceSocket` implements `InputStream`, so everything from [`amphp/byte-stream`](https://amphp.org/byte-stream/#inputstream) applies.

## Server Shutdown

Once you're done with the server socket, you should close the socket. That means, the server won't listen on the specified location anymore. Use `Server::close()` to close the server socket.

## TLS

As already mentioned in the documentation for `Amp\Socket\Server::listen()`, you need to enable TLS manually after accepting connections. For a TLS server socket, you listen on the `tcp://` protocol on a specified address. After accepting clients you call `$socket->setupTls()` where `$socket` is the socket returned from `Server::accept()`.

Any data transmitted before `Socket::setupTls()` resolves successfully will be transmitted in clear text. Don't attempt to read from the socket or write to it manually. Doing so will read the raw TLS handshake data that's supposed to be read by OpenSSL.
