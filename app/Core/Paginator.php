<?php
declare(strict_types=1);

namespace HeleXa\Core;

/**
 * Offset pagination. The page number is clamped server-side so a crafted
 * ?page=-1 or ?page=999999999 cannot produce a broken query.
 */
final class Paginator
{
    public readonly int $currentPage;
    public readonly int $lastPage;

    public function __construct(
        public readonly int $total,
        public readonly int $perPage,
        int $requestedPage,
        private readonly string $basePath = '',
        private readonly array $query = []
    ) {
        $this->lastPage    = max(1, (int) ceil($total / max(1, $perPage)));
        $this->currentPage = max(1, min($requestedPage, $this->lastPage));
    }

    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function url(int $page): string
    {
        $query = array_filter(
            array_merge($this->query, ['page' => $page]),
            static fn ($v): bool => $v !== null && $v !== ''
        );
        return $this->basePath . '?' . http_build_query($query);
    }

    /** A short window of page numbers around the current one. */
    public function window(int $radius = 2): array
    {
        $start = max(1, $this->currentPage - $radius);
        $end   = min($this->lastPage, $this->currentPage + $radius);
        return range($start, $end);
    }
}
