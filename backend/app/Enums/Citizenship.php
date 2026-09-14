<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The citizenship recorded on a resident card (FR-06), as a closed list.
 *
 * It was a free string, which meant «RU», «Россия», «russian» and «РФ» were
 * four different citizenships as far as any query was concerned. The list is
 * short on purpose: the Russian Federation, the CIS states the university
 * actually admits from, and one bucket for everybody else. A residency permit
 * is not a citizenship and the MVP does not model one.
 *
 * The codes are ISO 3166-1 alpha-2, so the column keeps the values it already
 * holds and the seeded data needs no rewriting.
 */
enum Citizenship: string
{
    case Russia = 'RU';
    case Belarus = 'BY';
    case Kazakhstan = 'KZ';
    case Uzbekistan = 'UZ';
    case Kyrgyzstan = 'KG';
    case Tajikistan = 'TJ';
    case Armenia = 'AM';
    case Azerbaijan = 'AZ';
    case Moldova = 'MD';
    case Turkmenistan = 'TM';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Russia => 'Russia',
            self::Belarus => 'Belarus',
            self::Kazakhstan => 'Kazakhstan',
            self::Uzbekistan => 'Uzbekistan',
            self::Kyrgyzstan => 'Kyrgyzstan',
            self::Tajikistan => 'Tajikistan',
            self::Armenia => 'Armenia',
            self::Azerbaijan => 'Azerbaijan',
            self::Moldova => 'Moldova',
            self::Turkmenistan => 'Turkmenistan',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The list the client draws the dropdown from: a code and the name beside
     * it, in the order the cases are declared.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
