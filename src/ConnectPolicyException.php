<?php declare(strict_types=1);

namespace Amp\Socket;

/**
 * Thrown by {@see DnsSocketConnector} if the {@see ConnectPolicy} denied every address of the URI.
 *
 * {@see RetrySocketConnector} doesn't retry it, as the same addresses would be denied again.
 */
final class ConnectPolicyException extends ConnectException
{
}
