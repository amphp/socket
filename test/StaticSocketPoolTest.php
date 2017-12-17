<?php

namespace Amp\Socket\Test;

use Amp\PHPUnit\TestCase;
use Amp\Socket\ClientSocket;
use Amp\Socket\SocketPool;
use Amp\Socket\StaticSocketPool;

class StaticSocketPoolTest extends TestCase {
    public function testCheckout() {
        $underlyingSocketPool = $this->prophesize(SocketPool::class);
        $staticSocketPool = new StaticSocketPool('override-uri', $underlyingSocketPool->reveal());

        $expected = new \Amp\LazyPromise(function () {});
        $underlyingSocketPool->checkout('override-uri', null)->shouldBeCalled()->willReturn($expected);

        $returned = $staticSocketPool->checkout('test-url');

        self::assertEquals($expected, $returned);
    }

    public function testCheckin() {
        $underlyingSocketPool = $this->prophesize(SocketPool::class);
        $staticSocketPool = new StaticSocketPool('override-uri', $underlyingSocketPool->reveal());

        $clientSocket = new ClientSocket(fopen('php://memory', 'rw+'));
        $underlyingSocketPool->checkin($clientSocket)->shouldBeCalled();

        $staticSocketPool->checkin($clientSocket);
    }

    public function testClear() {
        $underlyingSocketPool = $this->prophesize(SocketPool::class);
        $staticSocketPool = new StaticSocketPool('override-uri', $underlyingSocketPool->reveal());

        $clientSocket = new ClientSocket(fopen('php://memory', 'rw+'));
        $underlyingSocketPool->clear($clientSocket)->shouldBeCalled();

        $staticSocketPool->clear($clientSocket);
    }
}
