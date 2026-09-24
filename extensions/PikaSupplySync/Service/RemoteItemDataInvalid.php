<?php
declare(strict_types=1);

namespace Pika\LocalExtensions\PikaSupplySync\Service;

use RuntimeException;

/** Explicit remote product-field validation only; never dependency or identity failures. */
final class RemoteItemDataInvalid extends RuntimeException
{
}
