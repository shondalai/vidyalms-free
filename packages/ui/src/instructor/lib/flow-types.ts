import type { components } from '@vidyalms/api-sdk';

/*
 * The wave 4a shapes, named once.
 *
 * Read straight off `components` for the same reason lib/assessment-types.ts
 * is: the SDK's convenience aliases are another package's artefact, and this
 * file is the workbench's own vocabulary. Every name here is exactly the
 * component schema name, so a reader can find the schema from the type
 * without a lookup table.
 */

type Schemas = components['schemas'];

/* Availability: the palette, the tree, the preview and the grants. */
export type AvailabilityCatalogue = Schemas['AvailabilityCatalogue'];
export type AvailabilityConditionInfo = Schemas['AvailabilityConditionInfo'];
export type ScheduleAnchorInfo = Schemas['ScheduleAnchorInfo'];
export type AvailabilityPresetInfo = Schemas['AvailabilityPresetInfo'];
export type AvailabilityRule = Schemas['AvailabilityRule'];
export type AvailabilityNode = Schemas['AvailabilityNode'];
export type AvailabilityLeaf = Schemas['AvailabilityLeaf'];
export type AvailabilityWarning = Schemas['AvailabilityWarning'];
export type AvailabilityPreview = Schemas['AvailabilityPreview'];
export type AvailabilityPreviewLeaf = Schemas['AvailabilityPreviewLeaf'];
export type AvailabilityOutcome = Schemas['AvailabilityOutcome'];
export type AvailabilityGrant = Schemas['AvailabilityGrant'];
export type AvailabilityGrantRequest = Schemas['AvailabilityGrantRequest'];
export type SetAvailabilityRuleRequest = Schemas['SetAvailabilityRuleRequest'];

/** The four surfaces one grammar serves; a leaf outside its own is refused at save. */
export type AvailabilitySurface = 'content_gating' | 'notification_rule' | 'award_criteria' | 'enrolment_gating';
export type AvailabilitySubjectType = 'course' | 'module' | 'lesson' | 'quiz' | 'assignment';

/* Cohorts: runs of one course, never copies of it. */
export type Cohort = Schemas['Cohort'];
export type CohortDetail = Schemas['CohortDetail'];
export type CohortPage = Schemas['CohortPage'];
export type CreateCohortRequest = Schemas['CreateCohortRequest'];
export type UpdateCohortRequest = Schemas['UpdateCohortRequest'];
export type CohortSchedule = Schemas['CohortSchedule'];
export type CohortScheduleEntry = Schemas['CohortScheduleEntry'];
export type CohortResolvedDates = Schemas['CohortResolvedDates'];
export type CohortMember = Schemas['CohortMember'];
export type CohortMemberPage = Schemas['CohortMemberPage'];
export type AddCohortMembersRequest = Schemas['AddCohortMembersRequest'];
export type AddCohortMembersResult = Schemas['AddCohortMembersResult'];
export type UpdateCohortMemberRequest = Schemas['UpdateCohortMemberRequest'];
export type MoveCohortMemberRequest = Schemas['MoveCohortMemberRequest'];
export type CohortMoveResult = Schemas['CohortMoveResult'];
export type DateOverride = Schemas['DateOverride'];
export type DateOverridePage = Schemas['DateOverridePage'];
export type DateOverrideRequest = Schemas['DateOverrideRequest'];

/* Notifications: trigger, audience, schedule, channels, category, template. */
export type NotificationCatalogue = Schemas['NotificationCatalogue'];
export type NotificationTriggerInfo = Schemas['NotificationTriggerInfo'];
export type NotificationAudienceInfo = Schemas['NotificationAudienceInfo'];
export type NotificationChannelInfo = Schemas['NotificationChannelInfo'];
export type NotificationCategoryInfo = Schemas['NotificationCategoryInfo'];
export type NotificationScheduleKindInfo = Schemas['NotificationScheduleKindInfo'];
export type NotificationRule = Schemas['NotificationRule'];
export type NotificationRulePage = Schemas['NotificationRulePage'];
export type NotificationRuleRequest = Schemas['NotificationRuleRequest'];
export type NotificationSchedule = Schemas['NotificationSchedule'];
export type NotificationQueueRow = Schemas['NotificationQueueRow'];
export type NotificationQueuePage = Schemas['NotificationQueuePage'];
export type NotificationTemplate = Schemas['NotificationTemplate'];
export type NotificationTemplatePage = Schemas['NotificationTemplatePage'];
export type NotificationPolicyMatrix = Schemas['NotificationPolicyMatrix'];
export type NotificationPolicyCell = Schemas['NotificationPolicyCell'];
export type NotificationSuppression = Schemas['NotificationSuppression'];
export type NotificationSuppressionPage = Schemas['NotificationSuppressionPage'];
export type NotificationConsent = Schemas['NotificationConsent'];
export type NotificationConsentPage = Schemas['NotificationConsentPage'];

/* Registry-driven provider configuration, at a scope. */
export type RegistryProviderList = Schemas['RegistryProviderList'];
export type RegistryProvider = Schemas['RegistryProvider'];
export type ProviderSettingsRequest = Schemas['ProviderSettingsRequest'];
