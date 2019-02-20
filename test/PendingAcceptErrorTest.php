<?php

namespace Amp\Socket\Test;

use Amp\Socket\PendingAcceptError;
use PHPUnit\Framework\TestCase;

class PendingAcceptErrorTest extends TestCase
{
    public function constructorParametersProvider()
    {
        $exception = new \Exception('test');
        return [
            [
                null,
                [
                    'The previous accept operation must complete before accept can be called again',
                    0,
                    null
                ]
            ],
            [
                ['message', 1, $exception],
                [
                    'message',
                    1,
                    $exception
                ]
            ],
        ];
    }

    /**
     * @param $params
     * @param $expectedValues
     *
     * @dataProvider constructorParametersProvider
     */
    public function testConstruct($params, $expectedValues)
    {
        $error = $params ? new PendingAcceptError(...$params) : new PendingAcceptError();
        $this->assertSame($expectedValues, [$error->getMessage(), $error->getCode(), $error->getPrevious()]);
    }
}
