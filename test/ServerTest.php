<?php declare(strict_types = 1);

namespace Amp\Socket\Test;

use Amp\Coroutine;
use Amp\Socket;
use Amp\Socket\Server;
use Interop\Async\Loop;

class ServerTest extends \PHPUnit_Framework_TestCase {
    public function testAccept() {
        \Amp\execute(function () {
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
            \Amp\rethrow($coroutine);
            
            $client = (yield Socket\connect("tcp://127.0.0.1:12345"));
            $isRunning = false;
            
            Loop::delay(100, [Loop::class, 'stop']);
        });
    }
}
