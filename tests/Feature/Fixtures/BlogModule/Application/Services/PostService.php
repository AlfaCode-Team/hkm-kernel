<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\BlogModule\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Tests\Feature\Fixtures\BlogModule\API\Contracts\PostServiceContract;
use Tests\Feature\Fixtures\BlogModule\Infrastructure\Persistence\PostRepository;

/** The mandatory transaction shape, so the suite can assert on rollback. */
final class PostService implements PostServiceContract
{
    public function __construct(
        private readonly PostRepository     $repository,
        private readonly TransactionManager $transaction,
        private readonly Identity           $identity,
    ) {}

    public function all(): array
    {
        return $this->repository->all();
    }

    public function find(string $id): ?array
    {
        return $this->repository->find($id);
    }

    public function create(string $title): array
    {
        if ($title === '') {
            throw new ServiceException('post.create.title_required', layer: 'service.post');
        }

        $this->transaction->begin();

        try {
            $id = $this->repository->insert($title);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();

            throw new ServiceException('post.create.failed', layer: 'service.post', previous: $e);
        }

        return ['id' => $id, 'title' => $title, 'author' => $this->identity->userId];
    }

    public function delete(string $id): void
    {
        $this->repository->delete($id);
    }
}
