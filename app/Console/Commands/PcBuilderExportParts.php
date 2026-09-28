<?php

namespace App\Console\Commands;

use App\Support\PcBuilderPartsFile;
use Illuminate\Console\Command;

/**
 * Save this shop's PC Builder parts to database/data/pc-builder-parts.json,
 * so they go live with the next deploy.
 */
class PcBuilderExportParts extends Command
{
    protected $signature = 'pc-builder:export';

    protected $description = 'Save the PC Builder parts list to database/data/pc-builder-parts.json';

    public function handle(): int
    {
        $parts = PcBuilderPartsFile::export();

        if (! is_dir(dirname(PcBuilderPartsFile::path()))) {
            mkdir(dirname(PcBuilderPartsFile::path()), 0755, true);
        }

        file_put_contents(
            PcBuilderPartsFile::path(),
            json_encode($parts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );

        $this->info('Saved '.count($parts).' parts to database/data/pc-builder-parts.json.');

        return self::SUCCESS;
    }
}
