import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';

/**
 * Admin tables act through icons, and an icon has to say what it is.
 *
 * The labels went so every table reads the same way and a row of actions
 * stops pushing the columns that carry information sideways. What a label was
 * doing for free — naming the action, and for a screen reader naming the row
 * it acts on — now has to be written down, twice: a title for the pointer and
 * an aria-label for anything reading the page aloud.
 *
 * A column of nine identical glyphs with no names is not a smaller interface,
 * it is an unusable one, so this reads the source and insists.
 */
describe('admin table actions', () => {
    const pages = [
        'Products',
        'Attributes',
        'Brands',
        'Coupons',
        'Stores',
        'Banners',
        'Reviews',
    ];

    const source = (page) =>
        readFileSync(`resources/js/Pages/Admin/${page}.jsx`, 'utf8');

    /** Every icon button (or link) in the file, as its own block. */
    const iconButtons = (text) =>
        text
            .split('className="admin-table-icon-btn')
            .slice(1)
            .map((chunk) =>
                chunk.slice(0, chunk.search(/<\/(button|Link|a)>/)),
            );

    it.each(pages)('%s acts through icon buttons', (page) => {
        expect(iconButtons(source(page)).length).toBeGreaterThan(0);
    });

    it.each(pages)('every icon on %s carries a title', (page) => {
        for (const button of iconButtons(source(page))) {
            expect(button).toMatch(/title=/);
        }
    });

    it.each(pages)('every icon on %s names the row it acts on', (page) => {
        for (const button of iconButtons(source(page))) {
            expect(button).toMatch(/aria-label=/);
        }
    });

    /*
     * The labelled variant is what was replaced. Leaving one behind would put
     * a single wide button in a column of narrow ones, which is the
     * inconsistency the change was made to remove.
     */
    it.each(pages)('%s keeps no labelled action button', (page) => {
        expect(source(page)).not.toMatch(/icon=\{(Edit2|Edit3|Trash2|Copy)\}/);
    });

    /*
     * Except the ones a wrong guess costs. Deleting, removing, suspending,
     * cancelling, sending and receiving carry their word beside the icon —
     * a hover title does not exist on a phone.
     */
    const RISKY =
        /(title|aria-label)=\{?\s*["'`](Delete|Remove|Suspend|Cancel|Send it now|Receive)|\? `(Restore|Suspend) /;

    it.each([
        ...pages,
        'Customers',
        'Staff',
        'Couriers',
        'Campaigns',
        'Pages',
        'Refunds',
        'Suppliers',
        'Purchasing',
        'Expenses',
        'ExpenseCategories',
        'Roles',
        'ProductQuestions',
    ])('the risky actions on %s say what they do', (page) => {
        const risky = iconButtons(source(page)).filter((b) => RISKY.test(b));

        expect(risky.length).toBeGreaterThan(0);
        for (const button of risky) {
            expect(button).toMatch(/has-label/);
            expect(button).toMatch(/<span>[A-Z][a-z]+( now)?<\/span>/);
        }
    });
});
