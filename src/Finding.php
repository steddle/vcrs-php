<?php

namespace Steddle\Vcrs;

/**
 * What a check finds in an export that is valid against its schema (section 11.6). An export that is not consistent,
 * or does not verify, meets no level; any other finding withholds the level whose condition it names.
 */
enum Finding: string
{
    case DuplicateRecordId = 'duplicate_record_id';
    case DuplicateFlowId = 'duplicate_flow_id';
    case DuplicateTermsId = 'duplicate_terms_id';
    case UnknownFlow = 'unknown_flow';
    case UnknownTerms = 'unknown_terms';
    case UnknownOriginal = 'unknown_original';
    case DuplicateOfItself = 'duplicate_of_itself';
    case OriginalIsDuplicate = 'original_is_duplicate';
    case OriginalIsLater = 'original_is_later';
    case UnmarkedDuplicates = 'unmarked_duplicates';
    case JoinedAfterReferenceDate = 'joined_after_reference_date';
    case JoinedOutsideFlow = 'joined_outside_flow';
    case ConfirmedBeforeJoining = 'confirmed_before_joining';
    case FlowDatesInverted = 'flow_dates_inverted';
    case DenominatorInverted = 'denominator_inverted';
    case ClaimsInverted = 'claims_inverted';
    case ResidentsUnstated = 'residents_unstated';
    case OptInTermsWithoutOptIn = 'opt_in_terms_without_opt_in';
    case TermChannelWithoutWindow = 'term_channel_without_window';
    case OptInTermInverted = 'opt_in_term_inverted';
    case UnknownSubgroup = 'unknown_subgroup';
    case PartsUnstated = 'parts_unstated';
    case UnknownPart = 'unknown_part';
    case AnchoredBeforeReferenceDate = 'anchored_before_reference_date';

    case GradeDiffers = 'grade_differs';
    case DigestDiffers = 'digest_differs';
    case RootDiffers = 'root_differs';
    case ManifestDiffers = 'manifest_differs';

    case NotAnchored = 'not_anchored';
    case FlowNotCaptured = 'flow_not_captured';
    case ResidenceUnsourced = 'residence_unsourced';
    case OptInNamesOtherAction = 'opt_in_names_other_action';
    case PartWithoutDenominator = 'part_without_denominator';
    case FlowWithoutDigest = 'flow_without_digest';
    case TermsWithoutDigest = 'terms_without_digest';
    case RecordWithoutDigest = 'record_without_digest';
    case NoFacets = 'no_facets';
    case FacetValueUndeclared = 'facet_value_undeclared';
    case FlowNotWitnessed = 'flow_not_witnessed';
    case SubgroupsUndeclared = 'subgroups_undeclared';
    case SubgroupUndeclared = 'subgroup_undeclared';
    case SubgroupWithoutDenominator = 'subgroup_without_denominator';

    /**
     * consistent: the export can be read one way only. verified: what it states, it computes. A condition: one of the
     * section 10 conditions a facts export can show.
     */
    public function outcome(): string
    {
        return match (true) {
            in_array($this, [self::GradeDiffers, self::DigestDiffers, self::RootDiffers, self::ManifestDiffers], true) => 'verified',
            $this->condition() !== null => 'condition',
            default => 'consistent',
        };
    }

    public function condition(): ?string
    {
        return match ($this) {
            self::NotAnchored => 'snapshot_anchored',
            self::FlowNotCaptured => 'flows_captured',
            self::ResidenceUnsourced => 'residence_sourced',
            self::OptInNamesOtherAction => 'opt_ins_named',
            self::PartWithoutDenominator => 'parts_sized',
            self::FlowWithoutDigest => 'flow_digests',
            self::TermsWithoutDigest => 'terms_digests',
            self::RecordWithoutDigest => 'record_digests',
            self::NoFacets, self::FacetValueUndeclared => 'cross_section',
            self::FlowNotWitnessed => 'flows_witnessed',
            self::SubgroupsUndeclared, self::SubgroupUndeclared, self::SubgroupWithoutDenominator => 'subgroups',
            default => null,
        };
    }
}
