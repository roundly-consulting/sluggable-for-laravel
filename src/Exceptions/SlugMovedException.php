<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Reflector;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * A route was bound with a retired slug: render a redirect to the same route with the current
 * slug. Control flow, not an error — never reported.
 *
 * Route binding does not know the parameter name, so the exception does not claim it: render()
 * finds the one parameter whose raw value is the old slug and whose binding class is the target's
 * class, and rebuilds the URL from the route's raw parameters with only that one replaced. Zero or
 * several matches → a plain 404, never a guess. Nothing but the matched segment comes from input,
 * so this cannot be turned into an open redirect.
 */
final class SlugMovedException extends SluggableException implements ShouldntReport
{
    public function __construct(
        public readonly Model $target,
        public readonly string $oldSlug,
        public readonly string $newSlug,
        public readonly int $status = 301,
    ) {
        parent::__construct(sprintf('The slug of [%s] moved.', $target::class));
    }

    public function render(Request $request): Response
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return $this->notFound($request);
        }

        $parameter = $this->locateParameter($route);

        if ($parameter === null) {
            return $this->notFound($request);
        }

        $parameters = $route->originalParameters();
        $parameters[$parameter] = $this->newSlug;

        $url = app('url')->toRoute($route, $parameters, true);
        $query = $request->getQueryString();

        return new RedirectResponse($query === null ? $url : $url.'?'.$query, $this->status);
    }

    private function locateParameter(Route $route): ?string
    {
        $matches = [];

        foreach ($route->originalParameters() as $name => $raw) {
            if ($raw !== $this->oldSlug) {
                continue;
            }

            $class = $this->bindingClass($route, (string) $name);

            if ($class !== null && $this->target instanceof $class) {
                $matches[] = (string) $name;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** The class a route parameter binds to: the action's type hint, else an explicit Route::model(). */
    private function bindingClass(Route $route, string $name): ?string
    {
        foreach ($route->signatureParameters(['subClass' => UrlRoutable::class]) as $parameter) {
            if ($parameter->getName() === $name) {
                $class = Reflector::getParameterClassName($parameter);

                if ($class !== null) {
                    return $class;
                }
            }
        }

        $binder = app(Router::class)->getBindingCallback($name);

        if ($binder instanceof Closure) {
            $class = (new ReflectionFunction($binder))->getStaticVariables()['class'] ?? null;

            return is_string($class) ? $class : null;
        }

        return null;
    }

    private function notFound(Request $request): Response
    {
        $handler = app(ExceptionHandler::class);

        try {
            return $handler->render($request, new NotFoundHttpException);
        } catch (Throwable) {
            return new Response('', 404);
        }
    }
}
