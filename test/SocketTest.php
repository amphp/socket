<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket\Socket;

class SocketTest extends \PHPUnit_Framework_TestCase {
    public function testReadAndClose() {
        Loop::run(function () {
            $data = "Testing\n";
            list($serverSock, $clientSock) = \Amp\Socket\pair();
            \fwrite($serverSock, $data);
            \fclose($serverSock);
            $client = new Socket($clientSock);

            while (yield $client->advance()) {
                $this->assertSame($data, $client->getChunk());
            }
        });
    }
}
