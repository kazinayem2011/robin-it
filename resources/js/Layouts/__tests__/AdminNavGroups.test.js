import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';

/**
 * The admin's navigation, grouped by the job being done.
 *
 * Every item was already under a heading, but three sat under the wrong one:
 * Customers in Orders, Purchasing and Suppliers in Stock, and the journal and
 * pages in Marketing. The cost was hunting — a customer's record under one
 * heading and their warranty claim under another.
 *
 * This reads the source rather than rendering, because what is asserted is the
 * arrangement itself: which heading each screen lives under. Rendering would
 * only prove the list draws.
 */
describe('admin navigation groups', () => {
    const source = readFileSync('resources/js/Layouts/AdminLayout.jsx', 'utf8');
    const block = source.slice(
        source.indexOf('const NAV_GROUPS'),
        source.indexOf('\n];', source.indexOf('const NAV_GROUPS')),
    );

    /** Group headings, each with the item labels beneath it. */
    const groups = () => {
        const out = [];
        const re = /label: (?:null|'([^']+)')/g;
        let m;

        while ((m = re.exec(block))) {
            const lineStart = block.lastIndexOf('\n', m.index) + 1;
            const indent = m.index - lineStart;

            if (indent <= 8) out.push({ name: m[1] ?? null, items: [] });
            else if (out.length) out[out.length - 1].items.push(m[1]);
        }

        return out;
    };

    const groupOf = (item) =>
        groups().find((g) => g.items.includes(item))?.name ?? null;

    it.each([
        // A customer is a person the shop has, not a thing that happened to an
        // order — and everything else about them lived two headings away.
        ['Customers', 'Customers'],
        ['Messages', 'Customers'],
        ['Warranty & RMA', 'Customers'],
        // The stock cycle: what is held, and what is being bought.
        ['Stock', 'Stock'],
        ['Purchases', 'Stock'],
        // Written once and left up, unlike a campaign that runs and ends.
        ['Tech Journal', 'Content'],
        ['Pages', 'Content'],
        // And the ones that were already right.
        ['Orders & Shipping', 'Orders'],
        ['PC Builder', 'Catalogue'],
        // A filter is declared on a category and inherited by everything under
        // it, so it belongs beside the tree rather than under Setup.
        ['Filters', 'Catalogue'],
        ['Site Settings', 'Setup'],
        /*
         * Beside Settings rather than inside it: Settings is how the shop is
         * configured, and this is what it says. The same person edits both.
         */
        ['Message Templates', 'Setup'],
    ])('files %s under %s', (item, heading) => {
        expect(groupOf(item)).toBe(heading);
    });

    /*
     * Catalogue is ordered by dependency, not alphabetically or by importance.
     * Somebody setting the shop up works down it: a brand shelf points at a
     * brand, a filter is declared on a category, and a product needs all three
     * before it can be entered. Products used to sit first, which read as the
     * important one and left whoever followed it stuck on the Category field.
     */
    it('orders Catalogue by what has to exist first', () => {
        const catalogue = groups().find((g) => g.name === 'Catalogue');

        expect(catalogue.items).toEqual([
            'Brands',
            'Category Tree',
            'Filters',
            'Products',
            'PC Builder',
        ]);
    });

    /* Regrouping must not lose a screen or list one twice. */
    it('keeps every screen, exactly once', () => {
        const items = groups().flatMap((g) => g.items);

        // 32: the stock cycle's seven items became two, the rest tabs.
        expect(items).toHaveLength(32);
        expect(new Set(items).size).toBe(items.length);
    });

    /*
     * Each entry carries the permission deciding whether a member of staff
     * sees it. Moving items between headings must not drop one, or a screen
     * somebody cannot open appears in their sidebar.
     */
    it('leaves every entry with its permission', () => {
        const entries = block.match(/label: '[^']+',\s*\n\s*href:/g) ?? [];
        const abilities = block.match(/ability:/g) ?? [];

        expect(abilities.length).toBe(entries.length);
    });

    /*
     * Seven items for one cycle — Stock & Inventory, Stock Take, Adjustments,
     * Serial Numbers, Notify-me, Purchasing, Suppliers — and the shop had to
     * know which held what. Two now; the rest are tabs inside them.
     */
    it('keeps the stock cycle to Stock and Purchases', () => {
        expect(groups().find((g) => g.name === 'Stock').items).toEqual([
            'Stock',
            'Purchases',
        ]);
        expect(groups().some((g) => g.name === 'Buying')).toBe(false);
    });

    it('keeps the old screens as tabs, none lost', () => {
        const tabs = readFileSync(
            'resources/js/Pages/Admin/Stock/StockTabs.jsx',
            'utf8',
        );

        for (const label of [
            'History',
            'Stock count',
            'Serial numbers',
            'Notify-me',
            'Purchase orders',
            'Suppliers',
        ]) {
            expect(tabs).toContain(`label: '${label}'`);
        }
    });
});
