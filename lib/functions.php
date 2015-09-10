<?php

namespace Amp\Socket;

use Amp\Failure;
use Amp\Success;

/**
 * Listen for client connections on the specified server $address
 *
 * @param string $address
 * @return resource
 */
function listen($address) {
    $flags = \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN;
    if (!$socket = @\stream_socket_server($address, $errno, $errstr, $flags)) {
        throw new SocketException(
            \sprintf(
                "Failed binding socket on %s: [Err# %s] %s",
                $address,
                $errno,
                $errstr
            )
        );
    }

    return $socket;
}

/**
 * Asynchronously establish a socket connection to the specified URI
 *
 * If a scheme is not specified in the $uri parameter, TCP is assumed. Allowed schemes include:
 * [tcp, udp, unix, udg].
 *
 * @param string $uri
 * @param array $options
 * @return \Amp\Promise
 */
function connect($uri, array $options = []) {
    return \Amp\resolve(__doConnect($uri, $options));
}

function __doConnect($uri, array $options) {
    if (\stripos($uri, "unix://") === 0 || \stripos($uri, "udg://") === 0) {
        list($scheme, $path) = explode("://", $uri, 2);
        $isUnixSock = true;
        $resolvedUri = "{$scheme}:///" . \ltrim($path, "/");
    } else {
        $isUnixSock = false;
        // TCP/UDP host names are always case-insensitive
        if (!$uriParts = @\parse_url(strtolower($uri))) {
            throw new \DomainException(
                "Invalid URI: {$uri}"
            );
        }

        // $scheme, $host, $port, $path
        \extract($uriParts);
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
            $records = (yield \Amp\Dns\resolve($host));
            list($host, $mode) = $records[0];
            $isIpv6 = ($mode === \Amp\Dns\Record::AAAA);
        }

        $resolvedUri = $isIpv6 ? "[{$host}]:{$port}" : "{$host}:{$port}";
    }

    $flags = \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT;
    $timeout = 42; // <--- timeout not applicable for async connects
    if (PHP_VERSION_ID < 50600 && $scheme === "tcp") {
        // Prior to PHP 5.6 the SNI_server_name only registers if assigned to the stream
        // context at the time the socket is first connected (NOT with stream_socket_enable_crypto()).
        // So we always add the necessary ctx option here along with our own custom SNI_nb_hack
        // key to communicate our intent to the CryptoBroker if it's subsequently used
        $contextOptions = ["ssl" => [
            "SNI_server_name" => $host,
            "SNI_nb_hack" => true,
        ]];
    } else {
        $contextOptions = [];
    }

    $bindTo = empty($options["bind_to"]) ? "" : (string) $options["bind_to"];
    if (!$isUnixSock && $bindTo) {
        $contextOptions["socket"]["bindto"] = $bindTo;
    }

    $ctx = \stream_context_create($contextOptions);
    if (!$socket = @\stream_socket_client($resolvedUri, $errno, $errstr, $timeout, $flags, $ctx)) {
        throw new ConnectException(\sprintf(
            "Connection to %s failed: [Error #%d] %s",
            $uri,
            $errno,
            $errstr
        ));
    }

    \stream_set_blocking($socket, false);
    $promisor = new \Amp\Deferred;
    $promise = $promisor->promise();
    $watcherId = \Amp\onWritable($socket, [$promisor, "succeed"]);
    $timeout = empty($options["timeout"]) ? 30000 : $options["timeout"];

    try {
        yield ($timeout > 0) ? \Amp\timeout($promise, $timeout) : $promise;
        \Amp\cancel($watcherId);
        yield new \Amp\CoroutineResult($socket);
    } catch (\Amp\TimeoutException $e) {
        \Amp\cancel($watcherId);
        throw new ConnectException(
            "Connection to {$uri} failed: timeout exceeded ({$timeout} ms)",
            0,
            $e
        );
    }
}

/**
 * Asynchronously establish an encrypted TCP connection (non-blocking)
 *
 * NOTE: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string $authority
 * @param array $options
 * @return \Amp\Promise
 */
function cryptoConnect($uri, array $options = []) {
    return \Amp\resolve(__doCryptoConnect($uri, $options));
}

function __doCryptoConnect($uri, $options) {
    $socket = (yield \Amp\resolve(__doConnect($uri, $options)));
    if (empty($options["peer_name"])) {
        $options["peer_name"] = parse_url($uri, PHP_URL_HOST);
    }
    yield cryptoEnable($socket, $options);
    yield new \Amp\CoroutineResult($socket);
}

/**
 * Enable encryption on an existing socket stream
 *
 * @param resource $socket
 * @param array $options
 * @return \Amp\Promise
 */
function cryptoEnable($socket, array $options = []) {
    $isLegacy = (PHP_VERSION_ID < 50600);
    if ($isLegacy) {
        // For pre-5.6 we always manually verify names in userland
        // using the captured peer certificate.
        $options["capture_peer_cert"] = true;
        $options["verify_peer"] = isset($options["verify_peer"]) ? $options["verify_peer"] : true;
        if (isset($options["CN_match"])) {
            $peerName = $options["CN_match"];
            $options["peer_name"] = $peerName;
            unset($options["CN_match"]);
        }
        if (empty($options["cafile"])) {
            $options["cafile"] = __DIR__ . "/../var/ca-bundle.crt";
        }
    }

    $options["SNI_nb_hack"] = false;
    if (empty($options["ciphers"])) {
        $options["ciphers"] = \implode(':', [
            "ECDHE-RSA-AES128-GCM-SHA256",
            "ECDHE-ECDSA-AES128-GCM-SHA256",
            "ECDHE-RSA-AES256-GCM-SHA384",
            "ECDHE-ECDSA-AES256-GCM-SHA384",
            "DHE-RSA-AES128-GCM-SHA256",
            "DHE-DSS-AES128-GCM-SHA256",
            "kEDH+AESGCM",
            "ECDHE-RSA-AES128-SHA256",
            "ECDHE-ECDSA-AES128-SHA256",
            "ECDHE-RSA-AES128-SHA",
            "ECDHE-ECDSA-AES128-SHA",
            "ECDHE-RSA-AES256-SHA384",
            "ECDHE-ECDSA-AES256-SHA384",
            "ECDHE-RSA-AES256-SHA",
            "ECDHE-ECDSA-AES256-SHA",
            "DHE-RSA-AES128-SHA256",
            "DHE-RSA-AES128-SHA",
            "DHE-DSS-AES128-SHA256",
            "DHE-RSA-AES256-SHA256",
            "DHE-DSS-AES256-SHA",
            "DHE-RSA-AES256-SHA",
            "AES128-GCM-SHA256",
            "AES256-GCM-SHA384",
            "ECDHE-RSA-RC4-SHA",
            "ECDHE-ECDSA-RC4-SHA",
            "AES128",
            "AES256",
            "RC4-SHA",
            "HIGH",
            "!aNULL",
            "!eNULL",
            "!EXPORT",
            "!DES",
            "!3DES",
            "!MD5",
            "!PSK"
        ]);
    }

    if (isset($options["crypto_method"])) {
        $method = $options["crypto_method"];
        unset($options["crypto_method"]);
    } elseif (PHP_VERSION_ID >= 50600 && PHP_VERSION_ID < 50606) {
        $method = \STREAM_CRYPTO_METHOD_TLS_CLIENT;
    } else {
        $method = \STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
    }

    \stream_context_set_option($socket, ["ssl"=> $options]);
    $enable = true;

    return $isLegacy
        ? \Amp\resolve(__watchCryptoLegacy($enable, $method, $socket))
        : __watchCrypto($enable, $method, $socket)
    ;
}

/**
 * Disable encryption on an existing socket stream
 *
 * @param resource $socket
 * @return \Amp\Promise
 */
function cryptoDisable($socket) {
    return __watchCrypto($enable = false, $method = null, $socket);
}

function __watchCrypto($enable, $method, $socket) {
    $result = \stream_socket_enable_crypto($socket, $enable, $method);
    if ($result === true) {
        return new Success($socket);
    } elseif ($result === false) {
        return new Failure(new CryptoException(
            "Crypto negotiation failed: " . \error_get_last()["message"]
        ));
    } else {
        $promisor = new \Amp\Deferred;
        $cbData = [$promisor, $enable, $method];
        \Amp\onReadable($socket, '\Amp\Socket\__onCryptoWatchReadability', $options = ["cb_data" => $cbData]);
        return $promisor->promise();
    }
}

function __onCryptoWatchReadability($watcherId, $socket, $cbData) {
    list($promisor, $enable, $method) = $cbData;
    $result = \stream_socket_enable_crypto($socket, $enable, $method);
    if ($result === true) {
        \Amp\cancel($watcherId);
        $promisor->succeed($socket);
    } elseif ($result === false) {
        \Amp\cancel($watcherId);
        $promisor->fail(new CryptoException(
            "Crypto negotiation failed: " . \error_get_last()["message"]
        ));
    }
}

function __watchCryptoLegacy($enable, $method, $socket) {
    yield __watchCrypto($enable, $method, $socket);
    $cert = \stream_context_get_options($socket)["ssl"]["peer_certificate"];
    $options = \stream_context_get_options($socket)["ssl"];
    $peerFingerprint = isset($options["peer_fingerprint"])
        ? $options["peer_fingerprint"]
        : null
    ;
    if ($peerFingerprint && !__verifyFingerprint($peerFingerprint, $cert)) {
        throw new CryptoException(
            "Peer fingerprint verification failed"
        );
    }
    $peerName = isset($options["peer_name"])
        ? $options["peer_name"]
        : null
    ;
    if ($peerName && !__verifyPeerName($peerName, $cert)) {
        throw new CryptoException(
            "Peer name verification failed"
        );
    }

    yield new \Amp\CoroutineResult($socket);
}

function __verifyFingerprint($peerFingerprint, $cert) {
    if (\is_string($peerFingerprint)) {
        $peerFingerprint = [$peerFingerprint];
    } elseif (!\is_array($peerFingerprint)) {
        throw new CryptoException(
            "Invalid peer_fingerprint; string or array required"
        );
    }

    if (!\openssl_x509_export($cert, $str, false)) {
        throw new CryptoException(
            "Failed exporting peer cert for fingerprint verification"
        );
    }

    if (!\preg_match("/-+BEGIN CERTIFICATE-+(.+)-+END CERTIFICATE-+/s", $str, $matches)) {
        throw new CryptoException(
            "Failed parsing cert PEM for fingerprint verification"
        );
    }

    $pem = $matches[1];
    $pem = \base64_decode($pem);

    foreach ($peerFingerprint as $expectedFingerprint) {
        $algo = (\strlen($expectedFingerprint) === 40) ? 'sha1' : 'md5';
        $actualFingerprint = \openssl_digest($pem, $algo);
        if ($expectedFingerprint === $actualFingerprint) {
            return;
        }
    }

    throw new CryptoException(
        "Peer fingerprint(s) did not match"
    );
}

function __verifyPeerName($peerName, $cert) {
    $certInfo = \openssl_x509_parse($cert);
    if (__matchesWildcardName($peerName, $certInfo["subject"]["CN"])) {
        return true;
    }

    if (empty($certInfo["extensions"]["subjectAltName"])) {
        return false;
    }

    $subjectAltNames = array_map("trim", explode(",", $certInfo["extensions"]["subjectAltName"]));

    foreach ($subjectAltNames as $san) {
        if (\stripos($san, "DNS:") !== 0) {
            continue;
        }
        $sanName = substr($san, 4);

        if (__matchesWildcardName($peerName, $sanName)) {
            return true;
        }
    }

    return false;
}

function __matchesWildcardName($peerName, $certName) {
    if (\strcasecmp($peerName, $certName) === 0) {
        return true;
    }
    if (!(\stripos($certName, "*.") === 0 && \stripos($peerName, "."))) {
        return false;
    }
    $certName = \substr($certName, 2);
    $peerName = explode(".", $peerName);
    unset($peerName[0]);
    $peerName = implode(".", $peerName);

    return ($peerName == $certName);
}
