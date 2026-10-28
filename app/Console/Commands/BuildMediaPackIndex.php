<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

/**
 * Rebuilds the HC_PACK index the request form searches: one [path, bytes]
 * pair per file, relative to the pack root. The files themselves stay on the
 * shared drive.
 */
class BuildMediaPackIndex extends Command
{
    protected $signature = 'media:pack-index {path : The HC_PACK folder, e.g. "D:\000_HC_PACK_012025"}';

    protected $description = 'Index the HC_PACK folder for media requests';

    public function handle(): int
    {
        $root = rtrim((string) $this->argument('path'), '\\/');

        if (! is_dir($root)) {
            $this->error("Folder not found: {$root}");

            return self::FAILURE;
        }

        $rows = [];

        foreach (Finder::create()->files()->in($root)->ignoreDotFiles(true)->notName(['Thumbs.db', 'desktop.ini', '*.tmp'])->sortByName() as $file) {
            $rows[] = [str_replace('\\', '/', $file->getRelativePathname()), $file->getSize()];
        }

        $target = config('media-requests.pack_index');

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        file_put_contents($target, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(count($rows).' files indexed into '.$target);

        return self::SUCCESS;
    }
}
