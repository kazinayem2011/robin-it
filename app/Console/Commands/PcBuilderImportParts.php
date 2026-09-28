<?php

namespace App\Console\Commands;

use App\Support\PcBuilderPartsFile;
use Illuminate\Console\Command;

/**
 * Make this shop's PC Builder parts match database/data/pc-builder-parts.json.
 *
 * For after a deploy that brought a changed list. The first deploy needs
 * nothing: the migration that creates the parts reads the same file.
 */
class PcBuilderImportParts extends Command
{
    protected $signature = 'pc-builder:import {--force : Do not ask for confirmation}';

    protected $description = 'Replace the PC Builder parts with the list in database/data/pc-builder-parts.json';

    public function handle(): int
    {
        $path = PcBuilderPartsFile::path();

        if (! is_file($path)) {
            $this->error('No database/data/pc-builder-parts.json. Run pc-builder:export where the parts were set up.');

            return self::FAILURE;
        }

        $parts = json_decode((string) file_get_contents($path), true);

        if (! is_array($parts) || $parts === []) {
            $this->error('The parts file is empty or not valid.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Replace this shop\'s PC Builder parts with the '.count($parts).' in the file?', true)) {
            return self::SUCCESS;
        }

        $result = PcBuilderPartsFile::import($parts);

        $this->info("PC Builder now has {$result['parts']} parts.");

        if ($result['missing_categories'] !== []) {
            $this->warn('These categories are not in this shop, so they were left out: '.implode(', ', $result['missing_categories']));
        }

        return self::SUCCESS;
    }
}
