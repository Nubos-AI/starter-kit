<?php

declare(strict_types=1);

namespace App\DTOs\Formulas;

use App\Enums\Formulas\FormulaErrorCode;

readonly class FormulaErrorValue
{
    public function __construct(
        public FormulaErrorCode $code,
        public ?string $fieldKey = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $fieldKey = $data['field_key'] ?? null;

        return new self(
            code: FormulaErrorCode::from((string) $data['code']),
            fieldKey: $fieldKey === null ? null : (string) $fieldKey,
        );
    }

    /**
     * @return array{code: string, field_key: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'field_key' => $this->fieldKey,
        ];
    }
}
