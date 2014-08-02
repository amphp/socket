<?php

namespace Acesync;

use After\Future;

/**
 * Asynchronously establish a TCP connection (non-blocking)
 *
 * NOTE: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string $authority
 * @param array $options
 * @return \After\Promise
 */
function connect($authority, array $options = []) {
    static $connector;
    $connector = $connector ?: new Connector(\Alert\reactor());

    return $connector->connect($authority, $options);
}

/**
 * Asynchronously establish an encrypted TCP connection (non-blocking)
 *
 * NOTE: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param string $authority
 * @param array $options
 * @return \After\Promise
 */
function cryptoConnect($authority, array $options = []) {
    $future = new Future;
    $promise = connect($authority, $options);
    $promise->when(function($error, $result) use ($future, $options) {
        if ($error) {
            $future->fail($error);
        } else {
            $future->succeed(encrypt($result, $options));
        }
    });

    return $future->promise();
}

/**
 * Asynchronously enable SSL/TLS encryption on an already-connected TCP socket stream (non-blocking)
 *
 * @param resource $stream
 * @param array $options
 * @return \After\Promise
 */
function encrypt($stream, array $options = []) {
    static $encryptor;
    $encryptor = $encryptor ?: new Encryptor(\Alert\reactor());

    return $encryptor->enable($stream, $options);
}
