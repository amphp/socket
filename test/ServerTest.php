<?php

namespace Amp\Socket\Test;

use Amp\Coroutine;
use Amp\Loop;
use Amp\Socket;
use Amp\Socket\Server;

class ServerTest extends \PHPUnit_Framework_TestCase {
    public function testAccept() {
        Loop::run(function () {
            $isRunning = true;
            $server = function () use (&$isRunning) {
                $serverSock = Socket\listen("tcp://127.0.0.1:12345");
                $server = new Server($serverSock);
                while ($isRunning) {
                    $clientSock = (yield $server->accept());
                    $this->assertInternalType('resource', $clientSock);
                }
            };
            $coroutine = new Coroutine($server());
            \Amp\Promise\rethrow($coroutine);
            
            $client = (yield Socket\connect("tcp://127.0.0.1:12345"));
            $isRunning = false;
            
            Loop::delay(100, [Loop::class, 'stop']);
        });
    }
}
