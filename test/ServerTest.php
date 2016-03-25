<?php

namespace Amp\Socket\Test;

use Amp as amp;
use Amp\Socket as socket;

class ServerTest extends \PHPUnit_Framework_TestCase {

    protected function setUp() {
        if (amp\info()["state"]) {
            amp\stop();
        }
    }

    public function testAccept() {
        amp\run(function () {
            $isRunning = true;
            $server = static function () use (&$isRunning) {
                $serverSock = socket\listen("tcp://127.0.0.1:12345");
                $server = new socket\Server($serverSock);
                while ($isRunning) {
                    $clientSock = (yield $server->accept());
                }
                $server->stop();
            };
            amp\resolve($server());
            $client = (yield socket\connect("tcp://127.0.0.1:12345"));
            $isRunning = false;
            amp\once('\Amp\stop', 100);
        });
    }
}
