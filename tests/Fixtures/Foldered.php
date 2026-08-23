<?php

namespace EduLazaro\Larameter\Tests\Fixtures;

/**
 * What laracrate's HasFolders brings to a model: a usage() of its own, for disk space
 * per collection.
 *
 * Here because it caught a real one. The credits door was called usage() until a model
 * that had both traits refused to compile, which is the sort of thing no unit test of
 * a package on its own can find. Reproduced rather than depended on.
 */
trait Foldered
{
    /**
     * Bytes held in a collection.
     *
     * @param string $collection
     * @return int
     */
    public function usage(string $collection): int
    {
        return 0;
    }
}
