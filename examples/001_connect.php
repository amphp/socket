<?php

require __DIR__ . '/../vendor/autoload.php';

$sock = Nbsock\connect('www.google.com:80')->wait();
fwrite($sock, "GET / HTTP/1.0\r\n\r\n");
while (!feof($sock)) {
    echo fread($sock, 8192);
}
echo "\n\n";
