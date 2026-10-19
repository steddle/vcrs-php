<?php

namespace Steddle\Vcrs;

enum Grade: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public function rank(): int
    {
        return match ($this) {
            self::A => 3,
            self::B => 2,
            self::C => 1,
            self::D => 0,
        };
    }

    public function atLeast(self $grade): bool
    {
        return $this->rank() >= $grade->rank();
    }

    /**
     * @return list<self>
     */
    public static function counted(): array
    {
        return [self::A, self::B, self::C];
    }
}
