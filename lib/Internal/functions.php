<?php

namespace Amp\Socket\Internal;

use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\CryptoException;
use Amp\Success;
use Kelunik\Certificate\Certificate;
use function Amp\call;

/**
 * Parse an URI into [scheme, host, port].
 *
 * @param string $uri
 *
 * @return array
 *
 * @throws \Error If an invalid URI has been passed.
 *
 * @internal
 */
function parseUri(string $uri): array {
    if (\stripos($uri, "unix://") === 0 || \stripos($uri, "udg://") === 0) {
        list($scheme, $path) = \explode("://", $uri, 2);
        return [$scheme, \ltrim($path, "/"), 0];
    }

    if (!$uriParts = @\parse_url($uri)) {
        throw new \Error(
            "Invalid URI: {$uri}"
        );
    }

    $scheme = $uriParts["scheme"] ?? "tcp";
    $host = $uriParts["host"] ?? "";
    $port = $uriParts["port"] ?? 0;

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
 * Enable encryption on an existing socket stream.
 *
 * @param resource $socket
 * @param array    $options
 * @param bool     $force Forces enabling without prior disabling if already enabled.
 *
 * @return Promise
 *
 * @throws \Error If an invalid options array has been passed.
 *
 * @internal
 */
function enableCrypto($socket, array $options = [], bool $force = false): Promise {
    $ctx = \stream_context_get_options($socket);

    $options["ssl"]["capture_peer_cert"] = true;
    $options["ssl"]["capture_peer_cert_chain"] = true;

    if (!$force && !empty($ctx['ssl']) && !empty($ctx["ssl"]["_enabled"])) {
        $cmp = array_merge($ctx["ssl"], $options["ssl"] ?? []);
        $ctx = $ctx['ssl'];

        // Use weak comparison so the order of the items doesn't matter
        if ($ctx == $cmp) {
            return new Success;
        }

        return call(function () use ($socket, $options) {
            yield disableCrypto($socket);
            return enableCrypto($socket, $options);
        });
    }

    $options["ssl"]["_enabled"] = true; // avoid recursion

    \error_clear_last();

    \stream_context_set_option($socket, $options);
    $result = @\stream_socket_enable_crypto($socket, $enable = true);

    // Yes, that function can return true / false / 0, don't use weak comparisons.
    if ($result === true) {
        try {
            validateCertificateSignatureAlgorithms($socket);
        } catch (CryptoException $e) {
            return new Failure($e);
        }

        return new Success($socket);
    }

    if ($result === false) {
        return new Failure(new CryptoException(
            "Crypto negotiation failed: " . (\error_get_last()["message"] ?? "Unknown error")
        ));
    }

    return call(function () use ($socket) {
        $deferred = new Deferred;

        $watcher = Loop::onReadable($socket, function (string $watcher, $socket, Deferred $deferred) {
            \error_clear_last();
            $result = @\stream_socket_enable_crypto($socket, $enable = true);

            // If $result is 0, just wait for the next invocation
            if ($result === true) {
                try {
                    validateCertificateSignatureAlgorithms($socket);
                } catch (CryptoException $e) {
                    $deferred->fail($e);
                    return;
                }

                $deferred->resolve();
            } elseif ($result === false) {
                $deferred->fail(new CryptoException("Crypto negotiation failed: " . (\feof($socket)
                        ? "Connection reset by peer"
                        : \error_get_last()["message"])));
            }
        }, $deferred);

        try {
            yield $deferred->promise();
        } finally {
            Loop::cancel($watcher);
        }

        return $socket;
    });
}

/**
 * Disable encryption on an existing socket stream.
 *
 * @param resource $socket
 *
 * @return Promise
 *
 * @internal
 */
function disableCrypto($socket): Promise {
    // note that disabling crypto *ALWAYS* returns false, immediately
    \stream_context_set_option($socket, ["ssl" => ["_enabled" => false]]);
    \stream_socket_enable_crypto($socket, false);

    return new Success;
}

function validateCertificateSignatureAlgorithms($socket) {
    // !! NOTE !!
    // We verify the SERVER SENT chain here, no the validated chain. PHP doesn't allow that currently.
    // But it's fine for the use case of checking signature schemes, because OpenSSL will fail for incomplete chains,
    // which means any certificates we don't check must already be in the trust store.

    $options = \stream_context_get_options($socket);

    if ($options["ssl"]["verify_peer"] ?? true) {
        // $certs will contain the peer's certificate twice for clients, but it's not included in the chain for servers
        $certs = array_merge([$options["ssl"]["peer_certificate"]], $options["ssl"]["peer_certificate_chain"]);

        foreach ($certs as $i => $cert) {
            $cert = new Certificate($cert);

            // e.g. RSA-MD5, covers also other types than RSA
            $algs = \explode("-", $cert->getSignatureType());

            if (\count($algs) === 2 && in_array($algs[1], ["SHA1", "MD5"], true) && !isCertificateWithKnownPublicKey($cert)) {
                @\fclose($socket);

                throw new CryptoException(\sprintf(
                    "Peer (%s) provided a certificate using a weak signature scheme: '%s'",
                    $options["ssl"]["peer_name"] ?? "unknown",
                    $cert->getSignatureType()
                ));
            }
        }
    }
}

function isCertificateWithKnownPublicKey(Certificate $certificate) {
    static $certificates;

    if ($certificates === null) {
        $certs = [];
        $path = null;

        $paths = openssl_get_cert_locations();

        foreach (["default_cert_file_env", "default_cert_dir_env"] as $env) {
            if (($value = getenv($env)) && \file_exists($value)) {
                $path = $value;
                break;
            }
        }

        if ($path === null) {
            foreach (["ini_cafile", "ini_capath", "default_cert_file", "default_cert_dir"] as $entry) {
                if (!empty($paths[$entry]) && \file_exists($paths[$entry])) {
                    $path = $paths[$entry];
                    break;
                }
            }
        }

        if ($path !== null) {
            $certPattern = "(-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----)s";

            if (\is_dir($path)) {
                $files = \glob($path . "/*");
            } else {
                $files = [$path];
            }

            foreach ($files as $file) {
                $contents = \file_get_contents($file);
                \preg_match_all($certPattern, $contents, $matches, \PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $cert = @openssl_x509_read($match[0]);
                    $pubKey = openssl_pkey_get_public($cert);
                    $keyPem = openssl_pkey_get_details($pubKey)["key"];

                    $certs[$keyPem] = true;
                }
            }
        }

        // Only set it here, so further attempts fail again if it failed once
        $certificates = $certs;
    }

    // We need to verify public keys, because of cross-signatures like the one for google.com
    // This is fine, because we trust public keys in the certificate store, not signatures

    $cert = @openssl_x509_read($certificate->toPem());
    $pubKey = openssl_pkey_get_public($cert);
    $keyPem = openssl_pkey_get_details($pubKey)["key"];

    return isset($certificates[$keyPem]);
}

/**
 * Normalizes "bindto" options to add a ":0" in case no port is present, otherwise PHP will silently ignore those.
 *
 * @param string|null $bindTo
 *
 * @return string|null
 *
 * @throws \Error If an invalid option has been passed.
 */
function normalizeBindToOption(string $bindTo = null) {
    if ($bindTo === null) {
        // all fine
    } elseif (\preg_match("(\\[([0-9a-f.:]+)\\](:\\d+))", $bindTo ?? "", $match)) {
        list($ip, $port) = $match;

        if (@\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv6 address: {$ip}");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: {$port}");
        }

        return "[{$ip}]:" . ($port ?: 0);
    }

    if (\preg_match("((\\d+\\.\\d+\\.\\d+\\.\\d+)(:\\d+))", $bindTo ?? "", $match)) {
        list($ip, $port) = $match;

        if (@\inet_pton($ip) === false) {
            throw new \Error("Invalid IPv4 address: {$ip}");
        }

        if ($port < 0 || $port > 65535) {
            throw new \Error("Invalid port: {$port}");
        }

        return "{$ip}:" . ($port ?: 0);
    }

    throw new \Error("Invalid bindTo value: {$bindTo}");
}

/**
 * Cleans up return values of stream_socket_get_name.
 *
 * @param string|false $address
 *
 * @return string|null
 */
function cleanupSocketName($address) {
    // https://3v4l.org/5C1lo
    if ($address === false || $address === "\0") {
        return null;
    }

    return $address;
}
