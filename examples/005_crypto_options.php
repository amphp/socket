<?php

require __DIR__ . '/../vendor/autoload.php';

// -- Options use the SSL context option keys available in PHP 5.6 regardless of your PHP version --

$options = [
    'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
    'verify_peer'       => true,
    'peer_name'         => 'www.google.com',
    'peer_fingerprint'  => 'a5e6b2d9ec52e6bc2aa5f18f249c01d403538224',
];

// -------------------------- synchronous ----------------------------------------------------------

if ($sock = Acesync\cryptoConnectSync('www.google.com:443', $options)) {
    fwrite($sock, "GET / HTTP/1.0\r\n\r\n");
    while (!feof($sock)) {
        echo fread($sock, 8192);
    }
    echo "\n\n";
}

// -------------------------- asynchronous ---------------------------------------------------------

(new Alert\ReactorFactory)->select()->run(function($reactor) use ($options) {
    $promise = Acesync\cryptoConnect($reactor, 'www.google.com:443', $options);
    $promise->onResolve(function($error, $sock) use ($reactor) {
        if ($error) {
            echo $error;
        } else {
            stream_set_blocking($sock, true);
            fwrite($sock, "GET / HTTP/1.0\r\n\r\n");
            while (!feof($sock)) {
                echo fread($sock, 8192);
            }
        }
        echo "\n\n";
        $reactor->stop();
    });
});
