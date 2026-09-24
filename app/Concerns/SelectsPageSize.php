<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Support\Pagination\PageSize;
use Livewire\Attributes\Url;

/**
 * Gives a paginated list Redmine's "表示件数" choice. Use with
 * WithPagination and render `<x-per-page-select>`; the chosen size lives in
 * the URL and only counts when it is one of the `per_page_options`.
 */
trait SelectsPageSize
{
    #[Url(as: 'per_page')]
    public ?int $perPage = null;

    /**
     * The chosen size, else $default — the first per_page_options value when
     * none is given (Redmine's per_page_option).
     */
    public function pageSize(?int $default = null): int
    {
        return PageSize::resolve($this->perPage, $default ?? PageSize::defaultSize());
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }
}
