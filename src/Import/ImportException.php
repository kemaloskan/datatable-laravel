<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Import;

use RuntimeException;

/** The uploaded file cannot be read: wrong type, damaged, or beyond a limit. The message is safe to show. */
final class ImportException extends RuntimeException
{
}
