<?php

namespace Nbsock;

use Amp\Deferred;

/**
 * Asynchronously establish a TCP connection (non-blocking)
 *
 * NOTE: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string $authority
 * @param array $options
 * @return \Amp\Promise
 */
function connect($authority, array $options = []) {
    static $connector;
    if (empty($connector)) {
        $connector = new Connector(\Amp\reactor());
    }

    return $connector->connect($authority, $options);
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
function cryptoConnect($authority, array $options = []) {
    $promisor = new Deferred;
    $promise = connect($authority, $options);
    if (!isset($options["peer_name"])) {
        $options["peer_name"] = parse_url($authority, PHP_URL_HOST);
    }
    $promise->when(function($error, $result) use ($promisor, $options) {
        if ($error) {
            $promisor->fail($error);
        } else {
            $promisor->succeed(encrypt($result, $options));
        }
    });

    return $promisor->promise();
}

/**
 * Asynchronously enable SSL/TLS encryption on an already-connected TCP socket stream (non-blocking)
 *
 * @param resource $stream
 * @param array $options
 * @return \Amp\Promise
 */
function encrypt($stream, array $options = []) {
    static $encryptor;
    if (empty($encryptor)) {
        $encryptor = new Encryptor(\Amp\reactor());
    }

    return $encryptor->enable($stream, $options);
}
