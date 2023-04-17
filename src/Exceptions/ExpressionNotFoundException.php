<?php declare(strict_types=1);

namespace Somnambulist\Components\CTEBuilder\Exceptions;

use Assert\Assertion;
use Assert\InvalidArgumentException;

class ExpressionNotFoundException extends InvalidArgumentException
{

    public static function aliasNotFound(string $alias): self
    {
        return new self(
            sprintf('CTE with alias "%s" could not be found', $alias),
            Assertion::INVALID_KEY_EXISTS,
            'commonTableExpressions',
            $alias
        );
    }
}
