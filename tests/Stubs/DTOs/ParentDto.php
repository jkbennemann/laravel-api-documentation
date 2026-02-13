<?php

declare(strict_types=1);

namespace JkBennemann\LaravelApiDocumentation\Tests\Stubs\DTOs;

use Carbon\Carbon;

class ParentDto
{
    /**
     * @param  ChildDto[]  $children
     */
    public function __construct(
        public readonly string $title,
        public readonly ChildDto $mainChild,
        public readonly ?ChildDto $optionalChild,
        public readonly array $children,
        public readonly Carbon $createdAt,
        public readonly int $basePrice,
        public readonly int $taxAmount,
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'main_child' => $this->mainChild->toArray(),
            'optional_child' => $this->optionalChild?->toArray(),
            'children' => array_map(fn (ChildDto $child) => $child->toArray(), $this->children),
            'created_at' => $this->createdAt->toIso8601String(),
            'formatted_label' => sprintf('Item: %s', $this->title),
            'child_count' => count($this->children),
            'total_price' => $this->basePrice + $this->taxAmount,
            'has_children' => count($this->children) > 0,
        ];
    }
}
