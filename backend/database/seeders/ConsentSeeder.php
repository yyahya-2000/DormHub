<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConsentDocument;
use App\Enums\RoleCode;
use App\Models\ConsentRecord;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * FR-35 on the development stand: the three states a consent can be in, so
 * that the personal account has something to draw and the withdrawal has
 * something to act on.
 *
 * Every record below is generated (C-05). The addresses are from 192.0.2.0/24,
 * the documentation range of RFC 5737, which cannot belong to anyone.
 *
 * The three states, one resident each where there are enough residents:
 *
 *   - consent in force at the revision that is current — the ordinary case;
 *   - consent in force at a revision that has been superseded, which makes the
 *     person pending again without any rule saying so;
 *   - consent given and withdrawn, which is what the optional notification
 *     categories are silenced by.
 *
 * The seeder is idempotent: a resident who already has a record of a document
 * is left alone, because a second run must not produce a second «current»
 * consent — the partial unique index would refuse it, and rightly.
 */
class ConsentSeeder extends Seeder
{
    /**
     * A revision that once stood and no longer does. It names a file that is
     * deliberately absent from `resources/consent`: this seeder is describing
     * a stand, not proving a text, and the application reads the *current*
     * revision to decide pendingness — it never reads a superseded body unless
     * somebody asks for that record specifically.
     */
    private const SUPERSEDED_REVISION = '2026-06-01';

    public function run(): void
    {
        $residents = $this->residents();

        if ($residents === []) {
            return;
        }

        $document = ConsentDocument::ResidentPersonalData;
        $current = (string) config('dormitory.consent.revisions.'.$document->value);

        $states = [
            ['revision' => $current, 'revoked' => false],
            ['revision' => self::SUPERSEDED_REVISION, 'revoked' => false],
            ['revision' => $current, 'revoked' => true],
        ];

        foreach ($residents as $index => $resident) {
            $state = $states[$index % count($states)];

            $exists = ConsentRecord::query()
                ->where('user_id', $resident->getKey())
                ->where('document_code', $document->value)
                ->exists();

            if ($exists) {
                continue;
            }

            $acceptedAt = now()->subDays(30 - $index);

            ConsentRecord::query()->create([
                'user_id' => $resident->getKey(),
                'document_code' => $document->value,
                'document_revision' => $state['revision'],
                'accepted_at' => $acceptedAt,
                'revoked_at' => $state['revoked'] ? $acceptedAt->copy()->addDays(7) : null,
                'ip_address' => sprintf('192.0.2.%d', 10 + $index),
            ]);
        }
    }

    /**
     * @return list<User>
     */
    private function residents(): array
    {
        $role = Role::query()->where('code', RoleCode::Resident->value)->first();

        if ($role === null) {
            return [];
        }

        return User::query()
            ->whereHas('roleGrants', fn ($query) => $query->where('role_id', $role->getKey()))
            ->orderBy('id')
            ->get()
            ->all();
    }
}
