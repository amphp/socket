<?php

namespace Amp\Socket;

use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\Success;
use Interop\Async\Loop;

/**
 * Listen for client connections on the specified server $address
 *
 * @param string $address
 * @return resource
 */
function listen(string $address) {
    $queue = isset($options["backlog"]) ? (int) $options["backlog"] : (\defined("SOMAXCONN") ? SOMAXCONN : 128);
    $pem = isset($options["pem"]) ? (string) $options["pem"] : null;
    $passphrase = isset($options["passphrase"]) ? (string) $options["passphrase"] : null;
    $name = isset($options["name"]) ? (string) $options["name"] : null;
    
    $verify = isset($options["verify_peer"]) ? (string) $options["verify_peer"] : true;
    $allowSelfSigned = isset($options["allow_self_signed"]) ? (bool) $options["allow_self_signed"] : false;
    $verifyDepth = isset($options["verify_depth"]) ? (int) $options["verify_depth"] : 10;
    
    $context = [];
    
    $context["socket"] = [
        "backlog" => $queue,
        "ipv6_v6only" => true,
    ];
    
    if (null !== $pem) {
        if (!\file_exists($pem)) {
            throw new \InvalidArgumentException("No file found at given PEM path.");
        }
        
        $context["ssl"] = [
            "verify_peer" => $verify,
            "verify_peer_name" => $verify,
            "allow_self_signed" => $allowSelfSigned,
            "verify_depth" => $verifyDepth,
            "local_cert" => $pem,
            "disable_compression" => true,
            "SNI_enabled" => true,
            "SNI_server_name" => $name,
            "peer_name" => $name,
        ];
        
        if (null !== $passphrase) {
            $context["ssl"]["passphrase"] = $passphrase;
        }
    }
    
    $context = \stream_context_create($context);
    
    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    
    if (!$server || $errno) {
        throw new SocketException(\sprintf("Could not create server %s: [Errno: #%d] %s", $address, $errno, $errstr));
    }
    
    return $server;
}

/**
 * Asynchronously establish a socket connection to the specified URI
 *
 * If a scheme is not specified in the $uri parameter, TCP is assumed. Allowed schemes include:
 * [tcp, udp, unix, udg].
 *
 * @param string $uri
 * @param array $options
 *
 * @return \Interop\Async\Awaitable
 */
function connect(string $uri, array $options = []) {
    return new Coroutine(__doConnect($uri, $options));
}

function __doConnect($uri, array $options) {
    $context = [];

    if (\stripos($uri, "unix://") === 0 || \stripos($uri, "udg://") === 0) {
        list($scheme, $path) = \explode("://", $uri, 2);
        $isUnixSock = true;
        $resolvedUri = "{$scheme}:///" . \ltrim($path, "/");
    } else {
        $isUnixSock = false;
        // TCP/UDP host names are always case-insensitive
        if (!$uriParts = @\parse_url(\strtolower($uri))) {
            throw new \DomainException(
                "Invalid URI: {$uri}"
            );
        }
        
        $scheme = isset($uriParts["scheme"]) ? $uriParts["scheme"] : '';
        $host =   isset($uriParts["host"]) ? $uriParts["host"] : '';
        $port =   isset($uriParts["port"]) ? $uriParts["port"] : 0;
        
        $scheme = empty($scheme) ? "tcp" : $scheme;
        if (!($scheme === "tcp" || $scheme === "udp")) {
            throw new \DomainException(
                "Invalid URI scheme ({$scheme}); tcp, udp, unix or udg scheme expected"
            );
        }

        if (empty($host) || empty($port)) {
            throw new \DomainException(
                "Invalid URI ({$uri}); host and port components required"
            );
        }

        if ($inAddr = @\inet_pton($host)) {
            $isIpv6 = isset($inAddr[15]);
        } else {
            $records = yield \Amp\Dns\resolve($host);
            list($host, $mode) = $records[0];
            $isIpv6 = ($mode === \Amp\Dns\Record::AAAA);
        }

        $resolvedUri = \sprintf($isIpv6 ? "[%s]:%d" : "%s:%d", $host, $port);
    }

    $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
    $timeout = 42; // <--- timeout not applicable for async connects

    $bindTo = empty($options["bind_to"]) ? "" : (string) $options["bind_to"];
    if (!$isUnixSock && $bindTo) {
        $context["socket"]["bindto"] = $bindTo;
    }

    $context = \stream_context_create($context);
    if (!$socket = @\stream_socket_client($resolvedUri, $errno, $errstr, $timeout, $flags, $context)) {
        throw new ConnectException(\sprintf(
            "Connection to %s failed: [Error #%d] %s",
            $uri,
            $errno,
            $errstr
        ));
    }
    
    \stream_set_blocking($socket, 0);
    $timeout = isset($options["timeout"]) ? (int) $options["timeout"] : 30000;
    
    $deferred = new Deferred;
    $watcher = Loop::onWritable($socket, [$deferred, 'resolve']);
    
    $awaitable = $deferred->getAwaitable();

    try {
        yield $timeout > 0 ? \Amp\timeout($awaitable, $timeout) : $awaitable;
    } catch (\Amp\TimeoutException $exception) {
        throw new ConnectException(\sprintf("Connecting to %s failed: timeout exceeded (%d ms)", $uri, $timeout));
    } finally {
        Loop::cancel($watcher);
    }
    
    return $socket;
}

/**
 * Returns a pair of connected unix domain stream socket resources.
 *
 * @return resource[] Pair of socket resources.
 *
 * @throws \Amp\Socket\SocketException If creating the sockets fails.
 */
function pair() {
    if (($sockets = @\stream_socket_pair(\stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)) === false) {
        $message = "Failed to create socket pair.";
        if ($error = \error_get_last()) {
            $message .= sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
        }
        throw new SocketException($message);
    }
    
    return $sockets;
}

/**
 * Asynchronously establish an encrypted TCP connection (non-blocking)
 *
 * NOTE: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string $uri
 * @param array $options
 *
 * @return \Interop\Async\Awaitable
 */
function cryptoConnect(string $uri, array $options = []) {
    return new Coroutine(__doCryptoConnect($uri, $options));
}

function __doCryptoConnect($uri, $options) {
    $socket = yield from __doConnect($uri, $options);
    if (empty($options["peer_name"])) {
        $options["peer_name"] = \parse_url($uri, PHP_URL_HOST);
    }
    yield cryptoEnable($socket, $options);
    return $socket;
}

/**
 * Enable encryption on an existing socket stream
 *
 * @param resource $socket
 * @param array $options
 *
 * @return \Interop\Async\Awaitable
 */
function cryptoEnable($socket, array $options = []) {
    static $caBundleFiles = [];

    // Externalize any bundle inside a Phar, because OpenSSL doesn't support the stream wrapper.
    if (!empty($options["cafile"]) && strpos($options["cafile"], "phar://") === 0) {
        // Yes, this is blocking but way better than just an error.
        if (!isset($caBundleFiles[$options["cafile"]])) {
            $bundleContent = file_get_contents($options["cafile"]);
            $caBundleFile = tempnam(sys_get_temp_dir(), "openssl-ca-bundle-");
            file_put_contents($caBundleFile, $bundleContent);

            register_shutdown_function(function() use ($caBundleFile) {
                @unlink($caBundleFile);
            });

            $caBundleFiles[$options["cafile"]] = $caBundleFile;
        }

        $options["cafile"] = $caBundleFiles[$options["cafile"]];
    }

    if (empty($options["ciphers"])) {
        // See https://wiki.mozilla.org/Security/Server_Side_TLS#Intermediate_compatibility_.28default.29
        // DES ciphers have been explicitly removed from that list

        // TODO: We're using the recommended settings for servers here, we need a good resource for clients.
        // Then we might be able to use a more restrictive list.

        // The following cipher suites have been explicitly disabled, taken from previous configuration:
        // !aNULL:!eNULL:!EXPORT:!DES:!DSS:!3DES:!MD5:!PSK
        $options["ciphers"] = \implode(':', [
            "ECDHE-ECDSA-CHACHA20-POLY1305",
            "ECDHE-RSA-CHACHA20-POLY1305",
            "ECDHE-ECDSA-AES128-GCM-SHA256",
            "ECDHE-RSA-AES128-GCM-SHA256",
            "ECDHE-ECDSA-AES256-GCM-SHA384",
            "ECDHE-RSA-AES256-GCM-SHA384",
            "DHE-RSA-AES128-GCM-SHA256",
            "DHE-RSA-AES256-GCM-SHA384",
            "ECDHE-ECDSA-AES128-SHA256",
            "ECDHE-RSA-AES128-SHA256",
            "ECDHE-ECDSA-AES128-SHA",
            "ECDHE-RSA-AES256-SHA384",
            "ECDHE-RSA-AES128-SHA",
            "ECDHE-ECDSA-AES256-SHA384",
            "ECDHE-ECDSA-AES256-SHA",
            "ECDHE-RSA-AES256-SHA",
            "DHE-RSA-AES128-SHA256",
            "DHE-RSA-AES128-SHA",
            "DHE-RSA-AES256-SHA256",
            "DHE-RSA-AES256-SHA",
            "AES128-GCM-SHA256",
            "AES256-GCM-SHA384",
            "AES128-SHA256",
            "AES256-SHA256",
            "AES128-SHA",
            "AES256-SHA",
            "!aNULL",
            "!eNULL",
            "!EXPORT",
            "!DES",
            "!DSS",
            "!3DES",
            "!MD5",
            "!PSK",
        ]);
    }

    $ctx = \stream_context_get_options($socket);
    if (!empty($ctx['ssl']) && !empty($ctx["ssl"]["_enabled"])) {
        $ctx = $ctx['ssl'];
        $compare = $options;
        unset($ctx['peer_certificate'], $ctx['SNI_server_name']);
        unset($compare['peer_certificate'], $compare['SNI_server_name']);
        if ($ctx == $compare) {
            return new Success($socket);
        } else {
            return \Amp\pipe(cryptoDisable($socket), function($socket) use ($options) {
                return cryptoEnable($socket, $options);
            });
        }
    }

    if (isset($options["crypto_method"])) {
        $method = $options["crypto_method"];
        unset($options["crypto_method"]);
    } else {
        // note that this constant actually means "Any TLS version EXCEPT SSL v2 and v3"
        $method = \STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
    }

    $options["_enabled"] = true; // avoid recursion
    
    \stream_context_set_option($socket, ["ssl" => $options]);

    return __watchCrypto($method, $socket);
}

/**
 * Disable encryption on an existing socket stream
 *
 * @param resource $socket
 *
 * @return \Interop\Async\Awaitable
 */
function cryptoDisable($socket) {
    // note that disabling crypto *ALWAYS* returns false, immediately
    \stream_context_set_option($socket, ["ssl" => ["_enabled" => false]]);
    \stream_socket_enable_crypto($socket, false);
    return new Success($socket);
}

function __watchCrypto($method, $socket) {
    $result = \stream_socket_enable_crypto($socket, $enable = true, $method);
    if ($result === true) {
        return new Success($socket);
    } elseif ($result === false) {
        return new Failure(new CryptoException(
            "Crypto negotiation failed: " . \error_get_last()["message"]
        ));
    } else {
        $deferred = new Deferred;
        $cbData = [$deferred, $method];
        Loop::onReadable($socket, 'Amp\Socket\__onCryptoWatchReadability', $cbData);
        return $deferred->getAwaitable();
    }
}

function __onCryptoWatchReadability($watcherId, $socket, $cbData) {
    /** @var \Amp\Deferred $deferred */
    list($deferred, $method) = $cbData;
    $result = \stream_socket_enable_crypto($socket, $enable = true, $method);
    if ($result === true) {
        Loop::cancel($watcherId);
        $deferred->resolve($socket);
    } elseif ($result === false) {
        Loop::cancel($watcherId);
        $deferred->fail(new CryptoException(
            "Crypto negotiation failed: " . (\feof($socket) ? "Connection reset by peer" : \error_get_last()["message"])
        ));
    }
}