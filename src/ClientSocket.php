<?php

namespace Amp\Socket;

use Amp\ByteStream\ClosedException;
use Amp\Failure;
use Amp\Promise;

class ClientSocket extends ResourceSocket
{
    private $tlsContext;

    public function __construct(
        $resource,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        ?ClientTlsContext $tlsContext = null
    ) {
        parent::__construct($resource, $chunkSize);

        $this->tlsContext = $tlsContext;
    }

    /**
     * @inheritdoc
     */
    final public function enableCrypto(): Promise
    {
        if (($resource = $this->getResource()) === null) {
            return new Failure(new ClosedException("The socket has been closed"));
        }

        $tlsContext = $this->tlsContext ?? new ClientTlsContext;

        return Internal\enableCrypto($resource, $tlsContext->toStreamContextArray());
    }
}
