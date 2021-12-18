<?php

namespace Amp\Socket;

use PHPUnit\Framework\TestCase;

class PendingAcceptErrorTest extends TestCase
{
    public function constructorParametersProvider(): array
    {
        $exception = new \Exception('test');
        return [
            [
                null,
                [
                    'The previous accept operation must complete before accept can be called again',
                    0,
                    null,
                ],
            ],
            [
                ['message', 1, $exception],
                [
                    'message',
                    1,
                    $exception,
                ],
            ],
        ];
    }

    /**
     * @param $params
     * @param $expectedValues
     *
     * @dataProvider constructorParametersProvider
     */
    public function testConstruct($params, $expectedValues): void
    {
        $error = $params ? new PendingAcceptError(...$params) : new PendingAcceptError();
        self::assertSame($expectedValues, [$error->getMessage(), $error->getCode(), $error->getPrevious()]);
    }
}
