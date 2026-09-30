<?php

declare(strict_types=1);

namespace GalahadXVI\OctaneGuard;

use RuntimeException;

/**
 * A fixed operational message that is safe to display in the guard log.
 */
final class GuardException extends RuntimeException
{
}
