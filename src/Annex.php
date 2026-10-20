<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;

final readonly class Annex
{
    /**
     * @param  string|null  $forum  the country whose courts hear the action, as ISO 3166-1 alpha-2
     * @param  bool|'directive'  $optInAbroad  whether a person resident outside the forum is represented only on an express opt-in, or 'directive' where only in an action under the forum's transposition of Directive (EU) 2020/1828
     * @param  bool  $optInWindow  whether people abroad opt in during a window the court sets after the representative is appointed
     * @param  string|null  $optInChannel  the channel the law names for an opt-in, which the court's own channel replaces (Window), or null where the law names none
     * @param  bool  $parallelDeclarationAbroad  whether that person must also declare they are not represented elsewhere on the same claim
     * @param  list<string>  $schemes  identification schemes, beside eidas, that count as recognised for grade A
     */
    public function __construct(
        public string $code,
        public string $title,
        public ?string $forum,
        public bool|string $optInAbroad,
        public bool $optInWindow,
        public ?string $optInChannel,
        public bool $parallelDeclarationAbroad,
        public array $schemes,
    ) {}

    /**
     * The core alone: eidas is the one recognised scheme.
     */
    public static function core(): self
    {
        return new self('core', 'Core', null, false, false, null, false, []);
    }

    /**
     * Section 4.6: the annex as it applies to one action, which the export states is or is not under Directive (EU) 2020/1828.
     */
    public function forAction(bool $directive): self
    {
        $optInAbroad = $this->optInAbroad === 'directive' ? $directive : $this->optInAbroad;

        return new self($this->code, $this->title, $this->forum, $optInAbroad, $this->optInWindow, $this->optInChannel, $this->parallelDeclarationAbroad, $this->schemes);
    }

    /**
     * Section 4.6: whether a record abroad needs its opt-in at the reference date. With a window,
     * only once it has closed; a null closing day means none has closed, or the court applied opt-out abroad.
     */
    public function optInDue(?string $closedOn, ?string $referenceDate): bool
    {
        if (! $this->optInAbroad) {
            return false;
        }

        return ! $this->optInWindow || ($closedOn !== null && $referenceDate !== null && $referenceDate > $closedOn);
    }

    public static function load(string $code): self
    {
        $path = dirname(__DIR__, 2)."/vcrs/annexes/{$code}.json";

        if (! preg_match('/^[a-z]{2}$/', $code) || ! is_file($path)) {
            throw new InvalidArgumentException("No annex '{$code}' in vcrs/annexes.");
        }

        $annex = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return new self(
            $annex['code'],
            $annex['title'],
            $annex['forum'],
            $annex['opt_in_abroad'],
            $annex['opt_in_window'],
            $annex['opt_in_channel'],
            $annex['parallel_declaration_abroad'],
            $annex['schemes'],
        );
    }

    public function recognises(string $scheme): bool
    {
        return $scheme === 'eidas' || in_array($scheme, $this->schemes, true);
    }
}
