<?php

namespace Amp\Socket\Test;

use Amp\Loop;
use Amp\Socket;
use PHPUnit\Framework\TestCase;

class SocketTest extends TestCase {
    public function testReadAndClose() {
        Loop::run(function () {
            $data = "Testing\n";

            list($serverSock, $clientSock) = Socket\pair();

            \fwrite($serverSock, $data);
            \fclose($serverSock);

            $client = new Socket\ClientSocket($clientSock);

            while (($chunk = yield $client->read()) !== null) {
                $this->assertSame($data, $chunk);
            }
        });
    }
}
