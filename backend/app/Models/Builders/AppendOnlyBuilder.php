<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The query builder of an append-only table (§4.4.4).
 *
 * A model's `updating` and `deleting` hooks guard the single-record path —
 * `$entry->update(...)`, `$entry->delete()` — and only that path. A mass
 * update goes straight from the builder to SQL: `AuditLog::query()->update()`
 * fires no model event, loads no record and would have gone through, with
 * nothing but the database grant between it and the log. That is one level
 * where §4.4.4 asks for two.
 *
 * This builder closes the gap. Everything it refuses, the application role is
 * refused in the database too, so a mistake has to get past both; and what
 * neither this class nor the model can see — `DB::table('audit_logs')`, raw
 * SQL, TRUNCATE — is exactly what the revoked grant is there for.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        throw $this->refuse('a mass update');
    }

    /**
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw $this->refuse('an upsert');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw $this->refuse('an increment');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw $this->refuse('a decrement');
    }

    public function delete(): mixed
    {
        throw $this->refuse('a mass delete');
    }

    public function forceDelete(): mixed
    {
        throw $this->refuse('a mass delete');
    }

    /**
     * Not declared by the parent — it reaches the underlying query builder
     * through `__call`, which is precisely why it has to be named here.
     */
    public function truncate(): void
    {
        throw $this->refuse('a truncate');
    }

    private function refuse(string $operation): ImmutableRecordException
    {
        return ImmutableRecordException::for($this->getModel()::class, $operation);
    }
}
