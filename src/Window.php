<?php

namespace Steddle\Vcrs;

/**
 * The window the court set for people abroad to opt in (section 4.6), as the export states it.
 */
final readonly class Window
{
    public function __construct(
        public ?string $opensOn,
        public ?string $closesOn,
        public ?string $channel,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the export, or a vector's context
     */
    public static function from(array $data): self
    {
        return new self($data['opt_in_opened_on'] ?? null, $data['opt_in_closed_on'] ?? null, $data['opt_in_channel'] ?? null);
    }
}
