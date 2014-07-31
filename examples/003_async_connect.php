<?php

require __DIR__ . '/../vendor/autoload.php';

(new Alert\ReactorFactory)->select()->run(function($reactor) {
    $promise = Acesync\connect($reactor, 'www.google.com:80');
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
