<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A policy whose answer has to be recomputed inside the transaction that
 * takes the headroom.
 *
 * The reservation stage's own docblock and the standards page both said the
 * ceiling evaluation and the write of the reserved row happen inside one
 * `DatabasePort::transactional()` call, and both said why: two concurrent runs
 * cannot then read the same headroom, which is the failure a ceiling read
 * outside a transaction has under fan-out. Neither was true. The policy fold
 * is stage two, the reservation is stage six, and the transaction wrapped the
 * insert alone, so a probe policy reading the transaction depth at decide time
 * recorded zero.
 *
 * Re-running the whole chain inside the transaction would be the wrong
 * repair: consent, the site switch and the content scope do not change between
 * two stages of one run, and asking them twice buys nothing and costs a
 * second read each. What has to be recomputed is the small set of policies
 * whose answer depends on a total another concurrent run is also writing.
 *
 * So a policy declares it. The four windowed ceilings do; the eight gates do
 * not; a vendor ceiling reading its own rolling total says so and is included
 * with no core edit.
 */
interface EvaluatedInsideTheReservation
{
}
