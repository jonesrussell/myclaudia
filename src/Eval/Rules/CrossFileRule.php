<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

interface CrossFileRule
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $allFilesBySkill  skill => [parsed file data, ...]
     * @return list<ValidationResult>
     */
    public function validate(array $allFilesBySkill): array;
}
