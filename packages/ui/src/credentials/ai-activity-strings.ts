import { translate as uiText } from '@/shared/i18n';
/*
 * The learner's AI activity copy.
 *
 * Its own file rather than a section of the credential strings, because the two
 * pages answer different questions and a shared object would grow one heading
 * that has to serve both. Plain and factual, and no em-dashes or en-dashes.
 *
 * One rule of tone runs through it. This page is a person reading what was done
 * with their own data, so every line names a fact rather than reassuring: which
 * helper ran, who ran it, which spans left the site, and when the record is
 * deleted. Nothing here says "nothing to worry about".
 */
export const aiActivityStrings = {
    region: uiText("vidyalms.ui.ff83386f4a106cd1", "My AI activity"),
    title: uiText("vidyalms.ui.e7dd1e2909ad490a", "AI activity on my data"),
    intro:
        uiText("vidyalms.ui.237eede8b69e512c", "Every AI run that used your data, whichever helper started it. It lists the spans that were ") +
        uiText("vidyalms.ui.ff26aef2bfb567eb", "sent, taken from the record of what actually left the site rather than from a description."),
    asOf: (when: string) =>
        uiText("vidyalms.ui.50867491725df006", "This answer was true at {p0}. Records are deleted on their own schedule, so it can change.", { p0: when }),
    empty: {
        title: uiText("vidyalms.ui.649ab0d1e6e5175a", "No AI run has used your data."),
        body: uiText("vidyalms.ui.9407bd01744a6780", "When one does, it appears here with what was sent and how long the record is kept."),
    },
    loading: uiText("vidyalms.ui.62a51fcdc2135ec1", "Loading your AI activity"),
    error: uiText("vidyalms.ui.cee857d51350d56d", "Your AI activity could not load."),
    signedOut: {
        title: uiText("vidyalms.ui.b0910cfd3abd0058", "Sign in to see your AI activity."),
        body: uiText("vidyalms.ui.b844eef1c69cb863", "This page answers for one signed-in person and for nobody else."),
    },
    entry: {
        unnamedTask: uiText("vidyalms.ui.86a259611cc1943c", "A helper that is no longer installed"),
        when: uiText("vidyalms.ui.cf9c7aa24a26aac4", "When"),
        invokedBy: uiText("vidyalms.ui.0eb0a4c8e7d79d1d", "Started by"),
        invokedByAutomation: uiText("vidyalms.ui.1efb7525a40779d1", "An automation on this site"),
        invokedByUnknown: uiText("vidyalms.ui.b37c7879f6c359f6", "Not recorded"),
        model: uiText("vidyalms.ui.5e2c614c23f02239", "Model"),
        noModel: uiText("vidyalms.ui.2ba56db43f7f3d4e", "No model was involved. This run was computed on this site."),
        roleSubject: uiText("vidyalms.ui.89084b50725f5c7a", "Your data was sent"),
        roleContributor: uiText("vidyalms.ui.ea018f3d0e08e116", "Material you wrote was sent"),
        preview: uiText("vidyalms.ui.dcd22865fb322bd9", "This was a preview. Nothing was sent to any model."),
        state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
        // What became of the output, which is a different fact from the run's
        // own state: a run that finished and whose every draft a reviewer threw
        // away read as "succeeded" and said nothing about the throwing away.
        outcome: uiText("vidyalms.ui.2e240314da3608d2", "What was done with it"),
        outcomes: {
            accepted: uiText("vidyalms.ui.b075fad52b4d03e1", "A person accepted it and it was written into this site."),
            discarded: uiText("vidyalms.ui.791feddcfe0d9e23", "A person read it and discarded it. Nothing was written."),
            pending: uiText("vidyalms.ui.492ed171de514bf6", "A person has not decided about it yet."),
            none: uiText("vidyalms.ui.04215c6b1b1808e5", "It produced nothing to accept or discard."),
        },
        retainedUntil: (when: string) => uiText("vidyalms.ui.7a1897fc94e993c5", "Kept until {p0}.", { p0: when }),
        fieldsTitle: uiText("vidyalms.ui.cb2415e721957151", "What was sent"),
        fieldsEmpty: uiText("vidyalms.ui.a3c239d1ed661e02", "This run did not record what was sent."),
        fieldsNone: uiText("vidyalms.ui.69e6f3bc63827960", "Nothing left the site on this run."),
    },
    pagination: {
        label: uiText("vidyalms.ui.261aaf9767b2384b", "AI activity pages"),
    },
} as const;
