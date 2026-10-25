<?php

namespace Steddle\Vcrs;

final readonly class Annex
{
    /**
     * @param  string|null  $forum  the country whose courts hear the action, as ISO 3166-1 alpha-2
     * @param  bool|'directive'  $optInAbroad  whether a person resident outside the forum is represented only on an express opt-in, or 'directive' where only in an action under the forum's transposition of Directive (EU) 2020/1828
     * @param  bool|'action'  $optInResidents  whether a person resident in the forum is likewise represented only on an express opt-in, or 'action' where the court or an approved agreement chooses it for each action
     * @param  bool  $optInWindow  whether the opt-in falls due only once the terms the court sets after appointing the representative have closed; without a window it is due from the start
     * @param  string|null  $optInChannel  the channel the law names for an opt-in, which a term's own channel replaces, or null where the law names none
     * @param  bool|'directive'  $parallelDeclaration  whether every opt-in must also declare that the person is not represented elsewhere on the same claim, or 'directive' where only in an action under the Directive
     * @param  list<string>  $schemes  identification schemes, beside eidas, that count as recognised for grade A
     * @param  string|null  $optInPart  where a claim falls under two laws, the part whose law the annex's opt-in rules state
     */
    public function __construct(
        public string $code,
        public string $title,
        public ?string $forum,
        public bool|string $optInAbroad,
        public bool|string $optInResidents,
        public bool $optInWindow,
        public ?string $optInChannel,
        public bool|string $parallelDeclaration,
        public array $schemes,
        public ?string $optInPart = null,
    ) {}

    /**
     * The core alone: eidas is the one recognised scheme.
     */
    public static function core(): self
    {
        return new self('core', 'Core', null, false, false, false, null, false, []);
    }

    /**
     * Section 4.6: the annex as it applies to an action under Directive (EU) 2020/1828 or not.
     */
    public function forAction(bool $directive): self
    {
        return new self(
            $this->code,
            $this->title,
            $this->forum,
            $this->optInAbroad === 'directive' ? $directive : $this->optInAbroad,
            $this->optInResidents,
            $this->optInWindow,
            $this->optInChannel,
            $this->parallelDeclaration === 'directive' ? $directive : $this->parallelDeclaration,
            $this->schemes,
            $this->optInPart,
        );
    }

    /**
     * @throws InvalidRegister
     */
    public static function load(string $code): self
    {
        $path = Vcrs::standard("annexes/{$code}.json");

        if (! preg_match('/^[a-z]{2}$/', $code) || ! is_file($path)) {
            throw new InvalidRegister(['/annex: VCRS '.Vcrs::VERSION." holds no annex '{$code}'."]);
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
            $annex['opt_in_part'] ?? null,
        );
    }

    public function recognises(string $scheme): bool
    {
        return $scheme === 'eidas' || in_array($scheme, $this->schemes, true);
    }
}
