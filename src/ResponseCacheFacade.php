<?php

namespace Ndinhbang\ResponseCache;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ndinhbang\ResponseCache\Skeleton\SkeletonClass
 */
class ResponseCacheFacade extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'response-cache';
    }
}
