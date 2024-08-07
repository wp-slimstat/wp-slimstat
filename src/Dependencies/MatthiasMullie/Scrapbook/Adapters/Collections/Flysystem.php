<?php

namespace SlimStat\Dependencies\MatthiasMullie\Scrapbook\Adapters\Collections;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteFile;
use SlimStat\Dependencies\MatthiasMullie\Scrapbook\Adapters\Flysystem as Adapter;

/**
 * Flysystem 1.x and 2.x adapter for a subset of data, in a subfolder.
 *
 * @author Matthias Mullie <scrapbook@mullie.eu>
 * @copyright Copyright (c) 2014, Matthias Mullie. All rights reserved
 * @license LICENSE MIT
 */
class Flysystem extends Adapter
{
    /**
     * @var string
     */
    protected $collection;

    /**
     * @param string $collection
     */
    public function __construct(Filesystem $filesystem, $collection)
    {
        parent::__construct($filesystem);
        $this->collection = $collection;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $files = $this->filesystem->listContents($this->collection);
        foreach ($files as $file) {
            try {
                if ('dir' === $file['type']) {
                    if (1 === $this->version) {
                        $this->filesystem->deleteDir($file['path']);
                    } else {
                        $this->filesystem->deleteDirectory($file['path']);
                    }
                } else {
                    $this->filesystem->delete($file['path']);
                }
            } catch (FileNotFoundException $e) {
                // v1.x
                // don't care if we failed to unlink something, might have
                // been deleted by another process in the meantime...
            } catch (UnableToDeleteFile $e) {
                // v2.x
                // don't care if we failed to unlink something, might have
                // been deleted by another process in the meantime...
            }
        }

        return true;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function path($key)
    {
        return $this->collection.'/'.parent::path($key);
    }
}
