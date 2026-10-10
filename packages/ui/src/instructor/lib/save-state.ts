/*
 * The save tracker lives in shared/: the admin app needs the same thing for
 * the same reason (a retried create must replay rather than execute twice),
 * and two copies of an idempotency-key policy is how the two apps drifted
 * apart in the first place.
 */
export type { SaveTracker } from '@/shared/save-state';
export { newIdempotencyKey, useSaveTracker } from '@/shared/save-state';
