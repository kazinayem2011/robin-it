<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * "Did you mean …?" for a search that found nothing.
 *
 * Search matches the words typed, as typed, so "Vivobok" found nothing and the
 * shopper was left at an empty page with a laptop of that name on the shelf.
 * Each word the shop does not know is swapped for the closest one it does —
 * from the names of its products, brands and categories — and the result is
 * offered only if it would actually find something.
 */
class SearchSpelling
{
    private const CACHE_KEY = 'search.vocabulary';

    /** Most corrections tried before giving up, however many words were typed. */
    private const MAX_TRIES = 12;

    /**
     * The corrected search, or null when there is nothing better to offer.
     *
     * Each unknown word has a few near candidates. A tie used to go to the
     * commoner word, so "Cycel" became "Cyber" — two letters off, like
     * "Cycle" — and the result found nothing. The combinations are tried in
     * order, nearest first, and the first that finds something is offered.
     */
    public static function suggest(string $query, callable $findsSomething): ?string
    {
        $words = preg_split('/\s+/', trim($query)) ?: [];
        $vocabulary = self::vocabulary();

        // Per word: the choices, best first. A word left alone has one.
        $choices = array_map(function (string $word) use ($vocabulary) {
            $key = mb_strtolower($word);

            // Short words and known ones are left alone: "i5" or "HP" is not
            // a typo, and a three-letter guess is more often wrong than right.
            if (mb_strlen($key) < 4 || isset($vocabulary[$key])) {
                return [[$word, 0]];
            }

            return self::nearest($key, $vocabulary) ?: [[$word, 0]];
        }, $words);

        // Every combination, ranked by how far it strays from what was typed.
        $combinations = [[[], 0]];
        foreach ($choices as $options) {
            $next = [];
            foreach ($combinations as [$picked, $cost]) {
                foreach ($options as [$word, $distance]) {
                    $next[] = [[...$picked, $word], $cost + $distance];
                }
            }
            usort($next, fn ($a, $b) => $a[1] <=> $b[1]);
            $combinations = array_slice($next, 0, self::MAX_TRIES);
        }

        foreach ($combinations as [$picked, $cost]) {
            if ($cost === 0) {
                continue; // nothing changed
            }

            $suggestion = implode(' ', $picked);

            if ($findsSomething($suggestion)) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * The known words nearest to this one, within a letter or two: nearest
     * first, and between equals the one the catalogue uses most.
     *
     * @param  array<string, array{word:string, count:int}>  $vocabulary
     * @return list<array{0:string, 1:int}> word and distance
     */
    private static function nearest(string $word, array $vocabulary): array
    {
        // One slip in a four-letter word, two in anything longer — two
        // letters swapped ("Cycel") is two slips, and the commonest typo.
        $allowed = mb_strlen($word) <= 4 ? 1 : 2;
        $found = [];

        foreach ($vocabulary as $key => $entry) {
            if (abs(strlen($key) - strlen($word)) > $allowed) {
                continue;
            }

            $distance = levenshtein($word, $key);

            if ($distance <= $allowed) {
                $found[] = [$entry['word'], $distance, $entry['count']];
            }
        }

        usort($found, fn ($a, $b) => [$a[1], -$a[2]] <=> [$b[1], -$b[2]]);

        return array_map(fn ($f) => [$f[0], $f[1]], array_slice($found, 0, 4));
    }

    /**
     * Every word in the names a shopper might be looking for, as the shop
     * writes it, with how often it appears.
     *
     * @return array<string, array{word:string, count:int}>
     */
    public static function vocabulary(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, function () {
            $names = Product::where('is_active', true)->pluck('name')
                ->merge(Brand::pluck('name'))
                ->merge(Category::where('is_active', true)->pluck('name'));

            $vocabulary = [];

            foreach ($names as $name) {
                foreach (preg_split('/[^\p{L}\p{N}]+/u', (string) $name) ?: [] as $word) {
                    $key = mb_strtolower($word);

                    if (mb_strlen($key) < 3) {
                        continue;
                    }

                    $vocabulary[$key] ??= ['word' => $word, 'count' => 0];
                    $vocabulary[$key]['count']++;
                }
            }

            return $vocabulary;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
