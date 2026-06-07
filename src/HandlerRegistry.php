<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel;

class HandlerRegistry
{
    private array $global = [];

    private array $groups = [];

    public function addGlobal(string $handlerClass): void
    {
        $this->global[] = $handlerClass;
    }

    public function add(string $group, string $handlerClass): void
    {
        $this->groups[$group][] = $handlerClass;
    }

    public function forGroup(?string $group): array
    {
        $handlers = $this->global;

        if ($group !== null && isset($this->groups[$group])) {
            $handlers = array_merge($handlers, $this->groups[$group]);
        }

        return $handlers;
    }

    /** @return string[] */
    public function handlersForGroup(string $group): array
    {
        return $this->groups[$group] ?? [];
    }

    public function hasGroup(string $group): bool
    {
        return isset($this->groups[$group]);
    }

    public function all(): array
    {
        return ['_global' => $this->global] + $this->groups;
    }
}
