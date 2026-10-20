<?php

namespace Steddle\Vcrs;

/**
 * The grade rules of section 4, applied to the facts on one record.
 */
final readonly class Grader
{
    /**
     * @param  array<string, bool>  $informing  per flow version id, whether it states representative, defendant, claim and period
     * @param  bool  $optInDue  whether a record abroad needs its opt-in at the reference date (Annex::optInDue)
     */
    public function __construct(
        private array $informing,
        private Annex $annex,
        private bool $optInDue,
        private Window $window,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public function grade(array $record): Grading
    {
        $identity = $record['identity'];
        $membership = $record['membership'];

        $notCounted = array_values(array_filter([
            $record['status'] !== 'active' ? Reason::NotActive : null,
            ! ($this->informing[$record['flow']] ?? false) ? Reason::UninformedFlow : null,
            $record['duplicate_of'] !== null ? Reason::Duplicate : null,
            $membership['in_class'] === false ? Reason::OutsideClass : null,
            ! $identity['name'] ? Reason::NoName : null,
            ! $this->distinct($record['party'], $identity) ? Reason::NotDistinct : null,
            ! $identity['contact_confirmed'] ? Reason::UnconfirmedContact : null,
            ...$this->optIn($record),
        ]));

        if ($notCounted !== []) {
            return new Grading(Grade::D, $notCounted);
        }

        $notEvidenced = $this->notEvidenced($membership);

        if ($notEvidenced !== []) {
            return new Grading(Grade::C, $notEvidenced);
        }

        $notCorroborated = $this->notCorroborated($membership, $identity['verified']);

        if ($notCorroborated !== []) {
            return new Grading(Grade::B, $notCorroborated);
        }

        return new Grading(Grade::A);
    }

    /**
     * Section 4.6: where the annex binds people only on an opt-in, those abroad, those resident or both, a record that
     * must opt in and holds no opt-in is not counted once the opt-in is due. Residence decides only where one of the two
     * opts in and the other does not.
     *
     * @param  array<string, mixed>  $record
     * @return list<Reason>
     */
    private function optIn(array $record): array
    {
        if ($this->annex->forum === null || (! $this->annex->optInAbroad && ! $this->annex->optInResidents)) {
            return [];
        }

        if (! ($this->annex->optInAbroad && $this->annex->optInResidents) && ! isset($record['residence'])) {
            return [Reason::ResidenceUnknown];
        }

        $mustOptIn = ($this->annex->optInAbroad && $this->annex->optInResidents)
            || ($record['residence']['country'] === $this->annex->forum ? $this->annex->optInResidents : $this->annex->optInAbroad);

        if (! $mustOptIn || ! $this->optInDue) {
            return [];
        }

        return array_values(array_filter([
            ! $this->optedIn($record['opt_in'] ?? null) ? Reason::NotOptedIn : null,
            $this->annex->parallelDeclaration && ! ($record['parallel_declared'] ?? false) ? Reason::NoParallelDeclaration : null,
        ]));
    }

    /**
     * @param  array{on: string, channel: string}|null  $optIn
     */
    private function optedIn(?array $optIn): bool
    {
        $channel = $this->window->channel ?? $this->annex->optInChannel;

        return $optIn !== null
            && ($this->window->opensOn === null || $optIn['on'] >= $this->window->opensOn)
            && ($this->window->closesOn === null || $optIn['on'] <= $this->window->closesOn)
            && ($channel === null || $optIn['channel'] === $channel);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function distinct(string $party, array $identity): bool
    {
        return $party === 'natural'
            ? ($identity['birth_date'] ?? false) === true
            : ($identity['registration_number'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $membership
     * @return list<Reason>
     */
    private function notEvidenced(array $membership): array
    {
        if ($membership['basis'] === 'declared') {
            return [Reason::DeclaredOnly];
        }

        return array_values(array_filter([
            $membership['in_class'] !== true ? Reason::ClassNotEstablished : null,
            match ($membership['basis']) {
                'supplied' => $membership['evidence'] === [] ? Reason::NoEvidence : null,
                'checked' => in_array($membership['checked_against'], [null, ''], true) ? Reason::NoEvidence : null,
            },
        ]));
    }

    /**
     * @param  array<string, mixed>  $membership
     * @param  array<string, mixed>|null  $verified
     * @return list<Reason>
     */
    private function notCorroborated(array $membership, ?array $verified): array
    {
        return array_values(array_filter([
            $membership['basis'] !== 'checked' ? Reason::NotChecked : null,
            $verified === null ? Reason::IdentityNotVerified : null,
            $verified !== null && $verified['assurance'] === 'low' ? Reason::AssuranceTooLow : null,
            $verified !== null && ! $this->annex->recognises($verified['scheme']) ? Reason::SchemeNotRecognised : null,
        ]));
    }
}
