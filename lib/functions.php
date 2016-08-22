<?php declare(strict_types = 1);

namespace Amp\Socket;

use Amp\{ Coroutine, Deferred, Failure, Success };
use Interop\Async\{ Awaitable, Loop };

/**
 * Listen for client connections on the specified server $address
 *
 * @param string $uri
 * @return resource
 */
function listen(string $uri, array $options = []) {
    $queue = (int) ($options["backlog"] ?? (\defined("SOMAXCONN") ? SOMAXCONN : 128));
    $pem = (string) ($options["pem"] ?? "");
    $passphrase = (string) ($options["passphrase"] ?? "");
    $name = (string) ($options["name"] ?? "");
    
    $verify = (bool) ($options["verify_peer"] ?? true);
    $allowSelfSigned = (bool) ($options["allow_self_signed"] ?? false);
    $verifyDepth = (int) ($options["verify_depth"] ?? 10);
    
    $context = [];
    
    $context["socket"] = [
        "backlog" => $queue,
        "ipv6_v6only" => true,
    ];

	$scheme = strstr($uri, "://", true);
	if (!in_array($scheme, ["tcp", "udp", "unix", "udg"])) {
		throw new \Error("Only tcp, udp, unix and udg schemes allowed for server creation");
	}

    if ($pem !== "") {
    	/* listen() is not returning an Awaitable - hence a blocking file_exists() for the rare case where we start an encrypted server socket */
    	if (!\file_exists($pem)) {
    		throw new SocketException("PEM file $pem for creating server does not exist");
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
        
        if ($passphrase !== "") {
            $context["ssl"]["passphrase"] = $passphrase;
        }
    }
    
    $context = \stream_context_create($context);

    // Error reporting suppressed since stream_socket_server() emits an E_WARNING on failure (checked below).
    $server = @\stream_socket_server($uri, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    
    if (!$server || $errno) {
        throw new SocketException(\sprintf("Could not create server %s: [Errno: #%d] %s", $uri, $errno, $errstr));
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
 * @return \Interop\Async\Awaitable<resource>
 */
function connect(string $uri, array $options = []): Awaitable {
    return new Coroutine(__doConnect($uri, $options));
}

function __doConnect(string $uri, array $options): \Generator {
	list($scheme, $host, $port) = __parseUri($uri);

	$context = [];

	$uris = [];

	if ($port === 0 || @\inet_pton($uri)) {
		// Host is already an IP address or file path.
		$uris = [$uri];
	} else {
		// Host is not an IP address, so resolve the domain name.
		$records = yield \Amp\Dns\resolve($host);
		foreach ($records as $record) {
			if ($record[1] === \Amp\Dns\Record::AAAA) {
				$uris[] = \sprintf("%s://[%s]:%d", $scheme, $record[0], $port);
			} else {
				$uris[] = \sprintf("%s://%s:%d", $scheme, $record[0], $port);
			}
		}
	}

	$flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
	$timeout = 42; // <--- timeout not applicable for async connects

	foreach ($uris as $builtUri) {
		try {
			$context = \stream_context_create($context);
			if (!$socket = @\stream_socket_client($builtUri, $errno, $errstr, $timeout, $flags, $context)) {
				throw new ConnectException(\sprintf(
					"Connection to %s failed: [Error #%d] %s",
					$uri,
					$errno,
					$errstr
				));
			}

			\stream_set_blocking($socket, false);
			$timeout = (int) ($options["timeout"] ?? 10000);

			$deferred = new Deferred;
			$watcher = Loop::onWritable($socket, [$deferred, 'resolve']);

			$awaitable = $deferred->getAwaitable();

			yield $timeout > 0 ? \Amp\timeout($awaitable, $timeout) : $awaitable;
		} catch (\Exception $e) {
			continue; // Could not connect to host, try next host in the list.
		} finally {
			if (isset($watcher)) {
				Loop::cancel($watcher);
			}
		}

		return $socket;
	}

	if ($socket) {
		throw new ConnectException(\sprintf("Connecting to %s failed: timeout exceeded (%d ms)", $uri, $timeout));
	} else {
		throw $e;
	}
}

function __parseUri(string $uri): array {
    if (\stripos($uri, "unix://") === 0 || \stripos($uri, "udg://") === 0) {
        list($scheme, $path) = \explode("://", $uri, 2);
        return [$scheme, \ltrim($path, "/"), 0];
    }
    
    // TCP/UDP host names are always case-insensitive
    if (!$uriParts = @\parse_url(\strtolower($uri))) {
        throw new \Error(
            "Invalid URI: {$uri}"
        );
    }
    
    $scheme = $uriParts["scheme"] ?? "tcp";
    $host =   $uriParts["host"] ?? "";
    $port =   $uriParts["port"] ?? 0;
    
    if (!($scheme === "tcp" || $scheme === "udp")) {
        throw new \Error(
            "Invalid URI scheme ({$scheme}); tcp, udp, unix or udg scheme expected"
        );
    }
    
    if (empty($host) || empty($port)) {
        throw new \Error(
            "Invalid URI ({$uri}); host and port components required"
        );
    }
    
    if (\strpos($host, ":") !== false) { // IPv6 address
        $host = \sprintf("[%s]", \trim($host, "[]"));
    }
    
    return [$scheme, $host, $port];
}

/**
 * Returns a pair of connected unix domain stream socket resources.
 *
 * @return resource[] Pair of socket resources.
 *
 * @throws \Amp\Socket\SocketException If creating the sockets fails.
 */
function pair(): array {
    if (($sockets = @\stream_socket_pair(\stripos(PHP_OS, "win") === 0 ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)) === false) {
        $message = "Failed to create socket pair.";
        if ($error = \error_get_last()) {
            $message .= \sprintf(" Errno: %d; %s", $error["type"], $error["message"]);
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
function cryptoConnect(string $uri, array $options = []): Awaitable {
    return new Coroutine(__doCryptoConnect($uri, $options));
}

function __doCryptoConnect(string $uri, array $options): \Generator {
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
function cryptoEnable($socket, array $options = []): Awaitable {
    static $caBundleFiles = [];

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

/**
 * Disable encryption on an existing socket stream
 *
 * @param resource $socket
 *
 * @return \Interop\Async\Awaitable
 */
function cryptoDisable($socket): Awaitable {
    // note that disabling crypto *ALWAYS* returns false, immediately
    \stream_context_set_option($socket, ["ssl" => ["_enabled" => false]]);
    \stream_socket_enable_crypto($socket, false);
    return new Success($socket);
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