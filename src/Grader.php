<?php

namespace Steddle\Vcrs;

/**
 * The grade rules of section 4, applied to the facts on one record at the reference date.
 */
final readonly class Grader
{
    public function __construct(private Context $context) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public function grade(array $record): Grading
    {
        $identity = $record['identity'];
        $membership = $record['membership'];

        $notCounted = array_values(array_filter([
            $this->inactive($record) ? Reason::NotActive : null,
            ! $this->informed($record) ? Reason::UninformedFlow : null,
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

        $notCorroborated = $this->notCorroborated($record);

        if ($notCorroborated !== []) {
            return new Grading(Grade::B, $notCorroborated);
        }

        return new Grading(Grade::A);
    }

    /**
     * Rule 1: a withdrawal, opt-out or exclusion dated after the reference date leaves the record active at it.
     *
     * @param  array<string, mixed>  $record
     */
    private function inactive(array $record): bool
    {
        return $record['status'] !== 'active' && (! isset($record['status_on']) || $this->context->holds($record['status_on']));
    }

    /**
     * Rule 2 and section 5.1: the record joined through a flow version that informs, or the person confirmed, on or
     * before the reference date, through one that does and was live that day.
     *
     * @param  array<string, mixed>  $record
     */
    private function informed(array $record): bool
    {
        $confirmed = $record['confirmed'] ?? null;

        return $this->context->informs($record['flow'])
            || ($confirmed !== null
                && $this->context->informs($confirmed['flow'])
                && $this->context->live($confirmed['flow'], $confirmed['on'])
                && $this->context->holds($confirmed['on']));
    }

    /**
     * Rule 8 and section 4.6: where the annex binds people abroad, those resident or both only on an opt-in, a record
     * that must opt in is not counted once the opt-in is due without one made in time. Residence decides only where one
     * of the two opts in and the other does not, and only on the date the court fixed for it where it fixed one.
     *
     * @param  array<string, mixed>  $record
     * @return list<Reason>
     */
    private function optIn(array $record): array
    {
        $annex = $this->context->annex;
        $subgroup = $record['subgroup'] ?? null;
        $residents = $this->context->residentsOptIn($subgroup);

        if ($annex->forum === null || (! $annex->optInAbroad && ! $residents) || ! $this->context->inOptInPart($record)) {
            return [];
        }

        $mustOptIn = true;

        if ($this->context->residenceDecides($record)) {
            $residence = $record['residence'] ?? null;

            if ($residence === null || ! $this->context->holds($residence['on']) || ($this->context->residenceOn !== null && $residence['on'] !== $this->context->residenceOn)) {
                return [Reason::ResidenceUnknown];
            }

            $mustOptIn = $residence['country'] === $annex->forum ? $residents : $annex->optInAbroad;
        }

        if (! $mustOptIn || ! $this->context->optInDue($subgroup)) {
            return [];
        }

        return array_values(array_filter([
            ! $this->optedIn($record['opt_in'] ?? null, $subgroup) ? Reason::NotOptedIn : null,
            $annex->parallelDeclaration && ! ($record['parallel_declared'] ?? false) ? Reason::NoParallelDeclaration : null,
        ]));
    }

    /**
     * An opt-in counts when it was made on or before the reference date and falls inside a term that applies to the
     * record, through the channel the court set for that term, or else the annex's. Under an annex without a window the
     * law names the channel, and a term is a deadline alone. Without a term any day counts. The action it names is for Check and the auditor: a reference written another
     * way is no reason to stop counting a person.
     *
     * @param  array{on: string, channel: string, action: string}|null  $optIn
     */
    private function optedIn(?array $optIn, ?string $subgroup): bool
    {
        if ($optIn === null || ! $this->context->holds($optIn['on'])) {
            return false;
        }

        $terms = $this->context->termsFor($subgroup);

        if ($terms === []) {
            return in_array($this->context->annex->optInChannel, [null, $optIn['channel']], true);
        }

        foreach ($terms as $term) {
            $channel = $this->context->annex->optInWindow ? ($term['channel'] ?? $this->context->annex->optInChannel) : $this->context->annex->optInChannel;

            if ($optIn['on'] >= $term['opened_on']
                && ($term['closed_on'] === null || $optIn['on'] <= $term['closed_on'])
                && in_array($channel, [null, $optIn['channel']], true)) {
                return true;
            }
        }

        return false;
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
     * Section 4.4. A verification, a business-register check or an authority dated after the reference date does not
     * count at it.
     *
     * @param  array<string, mixed>  $record
     * @return list<Reason>
     */
    private function notCorroborated(array $record): array
    {
        $identity = $record['identity'];
        $verified = $identity['verified'] !== null && $this->context->holds($identity['verified']['on']) ? $identity['verified'] : null;
        $legal = $record['party'] === 'legal';

        return array_values(array_filter([
            $record['membership']['basis'] !== 'checked' ? Reason::NotChecked : null,
            $verified === null ? Reason::IdentityNotVerified : null,
            $verified !== null && $verified['assurance'] === 'low' ? Reason::AssuranceTooLow : null,
            $verified !== null && ! $this->context->annex->recognises($verified['scheme']) ? Reason::SchemeNotRecognised : null,
            $legal && ! $this->context->holds($identity['register_check']['on'] ?? null) ? Reason::RegisterNotChecked : null,
            $legal && ! $this->context->holds($identity['authority']['on'] ?? null) ? Reason::AuthorityNotChecked : null,
        ]));
    }
}
