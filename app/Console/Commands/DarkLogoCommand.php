<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Support\BrandDetails;
use App\Support\DarkLogo;
use Illuminate\Console\Command;

/**
 * Draw the dark-background copy of the shop's logo.
 *
 * The settings screen does this whenever a logo is saved, so this exists for
 * the one case that never passes through it: a shop whose logo was uploaded
 * before any of this existed. Its twin is missing, and until somebody happens
 * to open Settings and press Save the strapline stays unreadable on the
 * footer, the admin sidebar and the dark theme.
 *
 * Safe to run repeatedly, and safe to run on a shop that does not need it: it
 * only ever writes the conventional `-dark.png` beside the logo, so it cannot
 * touch a mark an admin uploaded into site_logo_dark.
 */
class DarkLogoCommand extends Command
{
    protected $signature = 'brand:dark-logo {--force : Redraw even when a twin is already there}';

    protected $description = "Draw the dark-background copy of the shop's logo";

    public function handle(): int
    {
        $override = trim((string) SiteSetting::get('site_logo_dark', ''));

        if ($override !== '') {
            $this->info("An uploaded dark logo is set ({$override}); leaving it alone.");

            return self::SUCCESS;
        }

        $logo = BrandDetails::logoWebPath();

        if ($logo === null || $logo === '') {
            $this->warn('No logo is configured, so there is nothing to convert.');

            return self::SUCCESS;
        }

        $twin = DarkLogo::twinFor($logo);

        if ($twin === null) {
            $this->warn("A {$logo} cannot carry transparency, so no dark copy can be drawn.");
            $this->line('Upload a PNG, or set one under Settings → Logo for dark backgrounds.');

            return self::FAILURE;
        }

        if (! $this->option('force') && is_file(public_path(ltrim($twin, '/')))) {
            $this->info("Already drawn: {$twin}  (use --force to redraw)");

            return self::SUCCESS;
        }

        $written = DarkLogo::generate($logo);

        if ($written === null) {
            $this->error("Could not draw a dark copy of {$logo}.");
            $this->line('The logo may already be light, or the file may be unreadable.');

            return self::FAILURE;
        }

        $this->info("Drawn: {$written}");
        $this->line('Only the dark lettering was lifted; the brand colours are unchanged.');

        return self::SUCCESS;
    }
}
