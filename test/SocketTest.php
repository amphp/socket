<?php

namespace Amp\Socket\Test;

use Amp\Socket\Socket;
use AsyncInterop\Loop;

class SocketTest extends \PHPUnit_Framework_TestCase {
    /**
     * @dataProvider provideInvalidLengthParameters
     * @expectedException \TypeError
     */
    public function testReadFailsOnInvalidLengthParameter($badLen) {
        Loop::execute(\Amp\wrap(function () use ($badLen) {
            list($serverSock, $clientSock) = \Amp\Socket\pair();
            $client = new Socket($clientSock);
            yield $client->read($badLen);
        }));
    }

    public function provideInvalidLengthParameters() {
        return [
            [-1],
            [0],
            [[]],
            [new \StdClass],
        ];
    }
}
