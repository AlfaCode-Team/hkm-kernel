<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\BlogModule\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use Tests\Feature\Fixtures\BlogModule\API\Contracts\PostServiceContract;
use Tests\Feature\Fixtures\BlogModule\Infrastructure\Persistence\PostRepository;

/** A plain (non-RequestAware) controller: actions take ($request, ...$params). */
final class PostController
{
    public function __construct(private readonly PostServiceContract $service) {}

    public function index(Request $request): Response
    {
        return Response::json(['posts' => $this->service->all()]);
    }

    /**
     * The static-beats-dynamic case: /posts/me must reach THIS action and never
     * /posts/{id:num}, whatever order the routes were declared in.
     */
    public function mine(Request $request): Response
    {
        return Response::json(['route' => 'mine', 'user' => $request->identity()?->userId ?? '']);
    }

    public function show(Request $request, string $id): Response
    {
        $post = $this->service->find($id);

        return $post === null ? Response::notFound() : Response::json($post);
    }

    public function create(Request $request): Response
    {
        return Response::json($this->service->create((string) $request->input('title', '')), 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->service->delete($id);

        return Response::empty(204);
    }

    /** Exists so the suite can prove a Throwable becomes the framework's envelope. */
    public function boom(Request $request): Response
    {
        throw new \RuntimeException('deliberate explosion');
    }

    /**
     * `{path:path}` — the traversal-guarded type. The action ECHOES the captured
     * value so a test can prove what the matcher actually let through, rather
     * than trusting that it 404'd for the right reason.
     */
    public function file(Request $request, string $path): Response
    {
        return Response::json(['path' => $path]);
    }

    /**
     * Reaches for an INTERNAL binding through the request container, from the
     * project scope. The five access rules say this must throw.
     */
    public function internal(Request $request): Response
    {
        /** @var ModuleContainer $container */
        $container = $request->container();

        $container->makeInScope(PostRepository::class, '__project__');

        return Response::json(['leaked' => true]);
    }
}
