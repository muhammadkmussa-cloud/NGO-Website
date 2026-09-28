<?php

namespace App\Models\Concerns;

use Illuminate\Support\Carbon;

trait ApiSerializable
{
    /**
     * Serializes timestamps exactly like FastAPI/Pydantic: naive ISO-8601 without timezone suffix,
     * so JS Date parsing behaves identically to the previous API.
     */
    protected function iso(?Carbon $date): ?string
    {
        return $date ? $date->format('Y-m-d\TH:i:s') : null;
    }

    abstract public function toApiArray(): array;
}
