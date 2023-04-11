<?php

namespace Ndinhbang\ResponseCache\Middleware;

use BadMethodCallException;
use Closure;
use Illuminate\Cache\RedisTagSet;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class CacheResponse
{
    protected int|null $ttl;

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     * @param string ...$args
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if (!$request->isMethod('get')) {
            return $next($request);
        }

        //
        $key = config('response-cache.prefix', 'rp') . ':' . $this->getRequestHash($request);

        $store = static::store();

        if (!is_null($content = $store->connection()->get($store->getPrefix() . $key))) {
            if ($request->wantsJson()) {
                return \response($content)->header('Content-Type', 'application/json');
            }
            return \response($content);
        }

        $lockWait = config('response-cache.lock_wait', 10);

        return $store->lock($key, $lockWait)
            ->block($lockWait, function () use ($request, $next, $store, $key, $args): Response {
                if (!is_null($content = $store->connection()->get($store->getPrefix() . $key))) {
                    if ($request->wantsJson()) {
                        return \response($content)->header('Content-Type', 'application/json');
                    }
                    return \response($content);
                }

                $ttl = $this->getTTL($args);

                $response = $next($request);

                if (is_null($ttl)) {
                    $store->connection()->set($store->getPrefix() . $key, $response->getContent());
                } else {
                    $store->connection()->setex(
                        $store->getPrefix() . $key, (int)max(1, $ttl), $response->getContent()
                    );
                }
                // We will tag the key, use the tags for removing key later
                $this->tags($this->getTagNames($args, $request->route()))->addEntry($key, !is_null($ttl) ? $ttl : 0);

                return $response;
            });
    }

    /**
     * @param array $names
     * @return \Illuminate\Cache\RedisTagSet
     */
    protected function tags(array $names): RedisTagSet
    {
        return new RedisTagSet(static::store(), $names);
    }

    protected function getRequestHash(Request $request): string
    {
        return rtrim(base64_encode(md5($request->getBaseUrl() . $request->getPathInfo() . $request->getQueryString(), true)), '=');
    }

    protected function getTagNames(array $args, Route $route): array
    {
        $tagNames = $args;

        if (count($args) >= 1 && is_numeric($args[0])) {
            $tagNames = array_slice($args, 1);
        }
        // replace placeholders in tag name with route parameters
        $tagNames = Arr::map($tagNames, fn(string $tag) => Str::of($tag)
            ->replaceMatches('/{([^}]*)}/', function ($match) use ($route) {
                $parameter = $route->parameter(trim($match[1]));
                if ($parameter instanceof Model) {
                    $parameter = $parameter->getRouteKey();
                }
                return $parameter ?? $match[0];
            })
            ->toString()
        );

        return array_filter($tagNames);
    }

    protected function getTTL(array $args): ?int
    {
        if (count($args) >= 1 && is_numeric($args[0])) {
            return (int)$args[0];
        }

        return null;
    }

    /**
     * Returns the store to se for caching.
     *
     * @param bool $lockable
     * @return \Illuminate\Contracts\Cache\Store
     */
    protected static function store(bool $lockable = true): Store
    {
        $repository = cache()->store(config('response-cache.store'));

        if (!$repository->supportsTags()) {
            throw new BadMethodCallException('This cache store does not support tagging.');
        }

        if ($lockable && !$repository->getStore() instanceof LockProvider) {
            $store ??= cache()->getDefaultDriver();

            throw new LogicException("The [$store] cache does not support atomic locks.");
        }

        return $repository->getStore();
    }

}
