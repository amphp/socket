<?php

namespace Acesync;

class ConnectorStruct {
    public $uri;
    public $scheme;
    public $host;
    public $port;
    public $resolvedAddress;
    public $socket;
    public $connectWatcher;
    public $timeoutWatcher;
    public $future;
    public $options;
}
