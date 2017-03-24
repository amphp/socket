<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;

class ServerTest extends \PHPUnit_Framework_TestCase {
    public function testAccept() {
        Loop::run(function () {
            $server = Socket\listen("tcp://127.0.0.1:12345", function ($socket) {
                $this->assertInstanceOf(Socket\Socket::class, $socket);
            });

            $client = yield Socket\connect("tcp://127.0.0.1:12345");

            Loop::delay(100, [$server, 'close']);
        });
    }
}
