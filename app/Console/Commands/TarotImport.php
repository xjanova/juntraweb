<?php

namespace App\Console\Commands;

use App\Services\TarotImporter;
use Illuminate\Console\Command;

class TarotImport extends Command
{
    protected $signature = 'tarot:import {--source= : Override the shared-disk source directory (defaults to Thaiprompt on same server)}';

    protected $description = 'Import all 78 card images from Thaiprompt-Affiliate — resolves current filenames live from the Thaiprompt juntra API (rotation-proof) and links them to local tarot_cards rows';

    public function handle(TarotImporter $importer): int
    {
        $source = $this->option('source') ?: $importer->defaultSourcePath();
        $this->info("Importing from: $source");

        $report = $importer->importFromPath($source);

        $this->table(['Metric', 'Value'], [
            ['Source',          $report['source']],
            ['Imported',        $report['imported']],
            ['Updated DB rows', $report['updated']],
            ['Skipped missing', $report['skipped_missing']],
            ['Errors',          count($report['errors'])],
        ]);

        if (!empty($report['errors'])) {
            $this->warn('Errors / warnings:');
            foreach ($report['errors'] as $err) {
                $this->line("  - $err");
            }
        }

        return $report['imported'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
