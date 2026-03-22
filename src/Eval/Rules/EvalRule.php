<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

interface EvalRule
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<ValidationResult>
     */
    public function validate(array $data, string $file): array;
}
