<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Exceptions\MandatoryNotificationCategoryException;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * FR-34, second criterion. The settings behind the switch, read and written.
 *
 * Two rules live here and nowhere else.
 *
 * **A mandatory category has no switch.** An attempt to turn one off is
 * refused rather than ignored, because ignoring it would answer the settings
 * screen with «saved» and then keep sending: the person would believe they had
 * switched something off. The refusal names the category.
 *
 * **The default is on, and stays unwritten.** `enabledFor()` treats a missing
 * row as consent to receive, so a person who never opens the settings receives
 * everything, and the table holds only the decisions people actually took.
 */
final readonly class NotificationPreferences
{
    /**
     * Whether this person is to receive this category at all.
     *
     * A mandatory category is answered without looking at the table: there is
     * no row that could switch it off, and the database refuses to hold one.
     */
    public function enabledFor(User $user, NotificationCategory $category): bool
    {
        if ($category->isMandatory()) {
            return true;
        }

        return ! in_array($category, $this->mutedFor($user), true);
    }

    /**
     * The categories this person has switched off.
     *
     * Read through the relation so that a caller which has already loaded it —
     * the dispatch does, once per recipient — asks the database nothing.
     *
     * @return list<NotificationCategory>
     */
    public function mutedFor(User $user): array
    {
        if (! $user->relationLoaded('notificationPreferences')) {
            $user->load('notificationPreferences');
        }

        return $user->notificationPreferences
            ->reject(fn (NotificationPreference $preference): bool => (bool) $preference->enabled)
            ->map(fn (NotificationPreference $preference): NotificationCategory => $preference->category)
            ->values()
            ->all();
    }

    /**
     * Every category with the state it is in for this person — the shape the
     * settings screen is drawn from, and the reason it is built from the enum
     * rather than from the table: a category nobody has decided about still
     * has to appear, switched on.
     *
     * @return list<array{category: NotificationCategory, enabled: bool, mandatory: bool}>
     */
    public function stateFor(User $user): array
    {
        $muted = $this->mutedFor($user);

        return array_map(
            fn (NotificationCategory $category): array => [
                'category' => $category,
                'enabled' => ! in_array($category, $muted, true),
                'mandatory' => $category->isMandatory(),
            ],
            NotificationCategory::cases(),
        );
    }

    /**
     * Records the decisions of one settings screen.
     *
     * The whole map is refused if any single entry names a mandatory category,
     * before anything is written: a half-applied settings save is worse than a
     * refused one, because the person is shown neither what they asked for nor
     * what they had.
     *
     * @param  array<string, bool>  $choices  category value => whether to receive it
     *
     * @throws MandatoryNotificationCategoryException
     */
    public function apply(User $user, array $choices): void
    {
        $decisions = [];

        foreach ($choices as $value => $enabled) {
            $category = NotificationCategory::from((string) $value);

            if ($category->isMandatory() && ! $enabled) {
                throw new MandatoryNotificationCategoryException($category);
            }

            $decisions[] = [
                'user_id' => $user->getKey(),
                'category' => $category->value,
                'enabled' => (bool) $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($decisions === []) {
            return;
        }

        /*
         * An upsert on the pair the unique index covers, so two settings
         * screens saving at once produce one row per category and not a
         * duplicate-key failure on whichever arrived second.
         */
        NotificationPreference::query()->upsert(
            $decisions,
            ['user_id', 'category'],
            ['enabled', 'updated_at'],
        );

        $user->unsetRelation('notificationPreferences');
    }
}
