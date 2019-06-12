---
title: Client
permalink: /client
---
`amphp/socket` allows clients to connect to servers via TCP, UDP, or Unix domain sockets.

## Connecting

You can establish a socket connection to a specified URI by using `Amp\Socket\connect`. It will automatically take care of resolving DNS names and will try other IPs if a connection fails and multiple IPs are available via DNS.

```php
/**
 * Asynchronously establish a socket connection to the specified URI.
 *
 * @param string                 $uri URI in scheme://host:port format. TCP is assumed if no scheme is present.
 * @param ConnectContext|null    $socketContext Socket connect context to use when connecting.
 * @param CancellationToken|null $token
 *
 * @return Promise<\Amp\Socket\EncryptableSocket>
 */
function connect(
    string $uri,
    ConnectContext $socketContext = null,
    CancellationToken $token = null
): Promise {
    /* ... */
}
```

### TLS

If you want to connect via TLS, you need use `Amp\Socket\connect()` and then call `$socket->setupTls()` on the returned socket.

## Sending Data

`EncryptableSocket` implements `OutputStream`, so everything from [`amphp/byte-stream`](https://amphp.org/byte-stream/#outputstream) applies.

## Receiving Data

`EncryptableSocket` implements `InputStream`, so everything from [`amphp/byte-stream`](https://amphp.org/byte-stream/#inputstream) applies.
