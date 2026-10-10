import type { ConditionTarget } from '@/condition-editors';
import type { AuthoringCurriculum } from '@vidyalms/api-sdk';

/*
 * What a condition in this course can point at, resolved to the titles an
 * author recognises.
 *
 * Two things are decided here rather than in the editors. An ARCHIVED item
 * is not offered, because a restriction naming one can never be satisfied
 * and an archived target is a missing target at evaluation time; a stored
 * leaf that names one still shows what it names, because the author has to
 * see it. And whether a target carries a PASS MARK is answered from the
 * linked quiz, which is what decides whether the pass and fail completion
 * states are offered at all.
 */

export function conditionTargets(curriculum: AuthoringCurriculum | null | undefined): ConditionTarget[] {
    if (curriculum === null || curriculum === undefined) {
        return [];
    }
    const targets: ConditionTarget[] = [];
    for (const module of curriculum.modules) {
        if (module.state === 'archived') {
            continue;
        }
        targets.push({ kind: 'module', id: module.id, title: module.title });
        for (const lesson of module.lessons) {
            if (lesson.state === 'archived') {
                continue;
            }
            const quiz = lesson.quiz;
            const hasPassMark = quiz !== null && quiz !== undefined && (quiz.pass_mark ?? 0) > 0;
            targets.push({ kind: 'lesson', id: lesson.id, title: lesson.title, hasPassMark });
            if (quiz !== null && quiz !== undefined) {
                targets.push({
                    kind: 'quiz',
                    id: quiz.id,
                    title: quiz.title,
                    hasPassMark,
                });
            }
        }
    }
    return targets;
}
