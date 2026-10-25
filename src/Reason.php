<?php

namespace Steddle\Vcrs;

/**
 * Why a record holds the grade it holds: for grade D the rule of section 4.1 that applies,
 * for C and B what keeps it from the next grade.
 */
enum Reason: string
{
    case NotActive = 'not_active';
    case UninformedFlow = 'uninformed_flow';
    case Duplicate = 'duplicate';
    case OutsideClass = 'outside_class';
    case NoName = 'no_name';
    case NotDistinct = 'not_distinct';
    case UnconfirmedContact = 'unconfirmed_contact';
    case ResidenceUnknown = 'residence_unknown';
    case NotOptedIn = 'not_opted_in';
    case NoParallelDeclaration = 'no_parallel_declaration';

    case DeclaredOnly = 'declared_only';
    case ClassNotEstablished = 'class_not_established';
    case NoEvidence = 'no_evidence';

    case NotChecked = 'not_checked';
    case IdentityNotVerified = 'identity_not_verified';
    case AssuranceTooLow = 'assurance_too_low';
    case SchemeNotRecognised = 'scheme_not_recognised';
    case RegisterNotChecked = 'register_not_checked';
    case AuthorityNotChecked = 'authority_not_checked';
}
