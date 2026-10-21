<?php

namespace Steddle\Vcrs;

use InvalidArgumentException;

final readonly class Annex
{
    /**
     * @param  string|null  $forum  the country whose courts hear the action, as ISO 3166-1 alpha-2
     * @param  bool|'directive'  $optInAbroad  whether a person resident outside the forum is represented only on an express opt-in, or 'directive' where only in an action under the forum's transposition of Directive (EU) 2020/1828
     * @param  bool|'action'  $optInResidents  whether a person resident in the forum is likewise represented only on an express opt-in, or 'action' where the court or an approved agreement chooses it for each action
     * @param  bool  $optInWindow  whether people opt in during a window the court sets after the representative is appointed
     * @param  string|null  $optInChannel  the channel the law names for an opt-in, which the court's own channel replaces (Window), or null where the law names none
     * @param  bool  $parallelDeclaration  whether every opt-in must also declare that the person is not represented elsewhere on the same claim
     * @param  list<string>  $schemes  identification schemes, beside eidas, that count as recognised for grade A
     */
    public function __construct(
        public string $code,
        public string $title,
        public ?string $forum,
        public bool|string $optInAbroad,
        public bool|string $optInResidents,
        public bool $optInWindow,
        public ?string $optInChannel,
        public bool $parallelDeclaration,
        public array $schemes,
    ) {}

    /**
     * The core alone: eidas is the one recognised scheme.
     */
    public static function core(): self
    {
        return new self('core', 'Core', null, false, false, false, null, false, []);
    }

    /**
     * Section 4.6: the annex as it applies to one action, as the export states it: under Directive (EU) 2020/1828 or not,
     * and, where the annex leaves it to the action, whether residents opt in. An action that leaves the latter unstated
     * binds residents on an opt-in, the stricter reading; Check reports the gap.
     */
    public function forAction(bool $directive, ?bool $residents = null): self
    {
        $optInAbroad = $this->optInAbroad === 'directive' ? $directive : $this->optInAbroad;
        $optInResidents = $this->optInResidents === 'action' ? ($residents ?? true) : $this->optInResidents;

        return new self($this->code, $this->title, $this->forum, $optInAbroad, $optInResidents, $this->optInWindow, $this->optInChannel, $this->parallelDeclaration, $this->schemes);
    }

    /**
     * Section 4.6: whether a record that must opt in needs its opt-in at the reference date. With a window,
     * only once it has closed; a null closing day means none has closed, or the court applied opt-out abroad.
     */
    public function optInDue(?string $closedOn, ?string $referenceDate): bool
    {
        if (! $this->optInAbroad && ! $this->optInResidents) {
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
            $annex['opt_in_residents'],
            $annex['opt_in_window'],
            $annex['opt_in_channel'],
            $annex['parallel_declaration'],
            $annex['schemes'],
        );
    }

    public function recognises(string $scheme): bool
    {
        return $scheme === 'eidas' || in_array($scheme, $this->schemes, true);
    }
}
