<?php

namespace App\Support;

/**
 * A user's search box text, made safe to put in a LIKE pattern.
 *
 * Not an injection concern — every LIKE in this application passes its value as
 * a bound parameter, so the text can never become SQL. This is about the
 * wildcards *inside* that value: `%` and `_` are LIKE syntax, so searching the
 * admin for "50%" matched every row in the table, and "a_b" matched "axb".
 *
 * ProductService escaped them for the storefront and the four admin search
 * screens did not, which is how the same search behaved differently depending
 * on where you typed it.
 */
class SearchTerm
{
    /**
     * Escape LIKE wildcards so a search for "100%" does not match everything.
     *
     * The backslash goes first, or the escapes added below would be escaped in
     * turn.
     *
     * A note on drivers, because the two behave differently and it matters:
     *
     *   MySQL  — production. Backslash is the default LIKE escape, so `\%`
     *            means a literal percent and the search behaves as written.
     *   SQLite — the test and CI driver. It has no default escape character
     *            unless a query names one with `ESCAPE`, which the query builder
     *            does not emit. An escaped term therefore matches nothing there.
     *
     * The property that actually protects the database holds on both: a search
     * consisting of wildcards cannot return the whole table, and cannot be used
     * to force a full scan. What differs is only the ability to *find* a literal
     * "%" or "_", which works on MySQL and not on SQLite. If SQLite ever becomes
     * a production driver here, these clauses need an explicit ESCAPE and the
     * literal differs per driver (MySQL wants '\\', SQLite wants '\').
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
     * The full `%term%` pattern for a "contains" search, wildcards escaped.
     */
    public static function contains(?string $value): string
    {
        return '%'.self::escape(trim((string) $value)).'%';
    }
}
