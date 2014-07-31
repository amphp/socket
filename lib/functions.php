<?php

namespace Acesync;

use Alert\Reactor,
    Alert\NativeReactor,
    After\Deferred;

/**
 * Asynchronously establish a TCP connection (non-blocking)
 *
 * NOTE: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param \Alert\Reactor $reactor
 * @param string $authority
 * @param array $options
 * @return \After\Promise
 */
function connect(Reactor $reactor, $authority, array $options = []) {
    static $connector;
    $connector = $connector ?: new Connector($reactor);

    return $connector->connect($authority, $options);
}

/**
 * Synchronously establish a TCP connection (blocking)
 *
 * NOTE: The returned socket stream will be set to blocking mode.
 *
 * @param string $authority
 * @param array $options
 * @return resource
 */
function connectSync($authority, array $options = []) {
    static $reactor, $connector;
    $reactor = $reactor ?: new NativeReactor;
    $connector = $connector ?: new Connector($reactor);
    $retval = null;

    $reactor->run(function($reactor) use ($connector, $authority, $options, &$retval) {
        $connector->connect($authority, $options)
            ->onResolve(function($error, $result) use ($reactor, &$retval) {
                $reactor->stop();
                if ($error) {
                    trigger_error($error->getMessage(), E_USER_WARNING);
                } else {
                    stream_set_blocking($result, true);
                    $retval = $result;
                }
            })
        ;
    });

    return $retval;
}

/**
 * Asynchronously establish an encrypted TCP connection (non-blocking)
 *
 * NOTE: Once resolved the socket stream will already be set to non-blocking mode.
 *
 * @param \Alert\Reactor $reactor
 * @param string $authority
 * @param array $options
 * @return \After\Promise
 */
function cryptoConnect(Reactor $reactor, $authority, array $options = []) {
    static $connector, $encryptor;
    $connector = $connector ?: new Connector($reactor);
    $encryptor = $encryptor ?: new Encryptor($reactor);
    $deferred = new Deferred;
    $promise = $connector->connect($authority, $options);
    $promise->onResolve(function($error, $result) use ($encryptor, $deferred, $options) {
        if ($error) {
            $deferred->fail($error);
        } else {
            $deferred->succeed($encryptor->enable($result, $options));
        }
    });

    return $deferred->promise();
}

/**
 * Synchronously establish an encrypted TCP connection (blocking)
 *
 * NOTE: The returned socket stream will be set to blocking mode.
 *
 * @param string $authority
 * @param array $options
 * @return resource
 */
function cryptoConnectSync($authority, array $options = []) {
    static $reactor, $encryptor;
    $reactor = $reactor ?: new NativeReactor;
    $encryptor = $encryptor ?: new Encryptor($reactor);
    $retval = null;

    if (!$socket = connectSync($authority, $options)) {
        return $retval;
    }

    stream_set_blocking($socket, false);

    $reactor->run(function($reactor) use ($encryptor, $socket, $options, &$retval) {
        $promise = $encryptor->enable($socket, $options);
        $promise->onResolve(function($error, $result) use ($reactor, &$retval) {
            $reactor->stop();
            if ($error) {
                trigger_error($error, E_USER_WARNING);
            } else {
                stream_set_blocking($result, true);
                $retval = $result;
            }
        });
    });

    return $retval;
}
