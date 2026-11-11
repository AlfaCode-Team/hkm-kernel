<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector;

/**
 * The only comparisons a vector search can filter on.
 *
 * `Eq`, `In` and the four range operators — and deliberately NOT `Ne`, `NotIn`,
 * `Not`, `Or`, or a text match. The rule is the one the document port follows:
 * an operator belongs here only if every store that could sit behind the port
 * gives it the SAME meaning, and for the negations they do not. Measured on
 * PostgreSQL 18.6 over four records — status "open", status "paid", no `status`
 * at all, and `status: null` — "not paid" gave THREE different answers from one
 * database: `(p->>'status') <> 'paid'` matched 1 record (SQL's `<>` is
 * three-valued and drops the missing one and the null), `p->'status' <>
 * '"paid"'::jsonb` matched 2 (it keeps the explicit null, drops the missing),
 * and `IS DISTINCT FROM` matched 3. A store that implements "not equal" as "not a
 * match" gives the third. Several meanings for "everything not yet paid" is how a
 * feature passes every test and misbehaves in production.
 *
 * There is a clean way to express a negation with one meaning everywhere:
 * store the field positively (`paid: false`) and filter `Eq`.
 *
 * Every operator shares one rule, stated on {@see VectorFilter}: a record with no
 * such field NEVER matches.
 */
enum VectorFilterOperator: string
{
    case Eq  = 'eq';
    case In  = 'in';
    case Gt  = 'gt';
    case Gte = 'gte';
    case Lt  = 'lt';
    case Lte = 'lte';

    public function isRange(): bool
    {
        return match ($this) {
            self::Gt, self::Gte, self::Lt, self::Lte => true,
            default => false,
        };
    }
}
