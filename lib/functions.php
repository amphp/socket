<?php

namespace Nbsock;

use Amp\Future;

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
        $connector = new Connector(\Amp\getReactor());
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
    $promisor = new Future;
    $promise = connect($authority, $options);
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
        $encryptor = new Encryptor(\Amp\getReactor());
    }

    return $encryptor->enable($stream, $options);
}
