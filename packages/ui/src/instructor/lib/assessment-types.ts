import type { components } from '@vidyalms/api-sdk';

/*
 * The wave 3b shapes, named once.
 *
 * The SDK's convenience aliases are generated for the shapes the apps
 * handled before this wave, so these are read straight off `components`
 * rather than added to that list: the SDK is another package's artefact and
 * this file is the workbench's own vocabulary. Every name here is exactly
 * the component schema name, so a reader can find the schema from the type
 * without a lookup table.
 */

type Schemas = components['schemas'];

/* Quiz policy, and the schema the settings form is drawn from. */
export type QuizPolicy = Schemas['QuizPolicy'];
export type ItemSessionControl = Schemas['ItemSessionControl'];
export type ReviewVisibility = Schemas['ReviewVisibility'];
export type ReviewVisibilityPhase = Schemas['ReviewVisibilityPhase'];
export type QuizSettingsSchema = Schemas['QuizSettingsSchema'];
export type SettingsGroup = Schemas['SettingsGroup'];
export type SettingsField = Schemas['SettingsField'];
export type SettingsOption = Schemas['SettingsOption'];

/* The slot tree. */
export type QuizSlotTree = Schemas['QuizSlotTree'];
export type QuizSlot = Schemas['QuizSlot'];
export type QuizSlotInput = Schemas['QuizSlotInput'];
export type SlotSelection = Schemas['SlotSelection'];
export type SlotOrdering = Schemas['SlotOrdering'];
export type SlotPool = Schemas['SlotPool'];
export type PoolQuery = Schemas['PoolQuery'];
export type PublicationWarning = Schemas['PublicationWarning'];
/**
 * A provider key with its translated label, as the slot tree serves the
 * three provider-keyed slot fields. No key on the editing screen is typed
 * from memory, so a newly registered strategy or predicate appears with no
 * client change.
 */
export type ProviderOption = Schemas['ProviderOption'];
export type AddQuizSlotRequest = Schemas['AddQuizSlotRequest'];
export type UpdateQuizSlotRequest = Schemas['UpdateQuizSlotRequest'];

/* The bank. */
export type QuestionCategory = Schemas['QuestionCategory'];
export type CreateQuestionCategoryRequest = Schemas['CreateQuestionCategoryRequest'];
export type UpdateQuestionCategoryRequest = Schemas['UpdateQuestionCategoryRequest'];
export type BankEntry = Schemas['BankEntry'];
export type BankEntryPage = Schemas['BankEntryPage'];
export type BankEntryVersion = Schemas['BankEntryVersion'];
export type BankEntryUsage = Schemas['BankEntryUsage'];
export type CreateBankEntryRequest = Schemas['CreateBankEntryRequest'];
export type UpdateBankEntryRequest = Schemas['UpdateBankEntryRequest'];
export type CreateBankEntryVersionRequest = Schemas['CreateBankEntryVersionRequest'];
export type LearningObjective = Schemas['LearningObjective'];
export type LearningObjectivePage = Schemas['LearningObjectivePage'];
export type CreateLearningObjectiveRequest = Schemas['CreateLearningObjectiveRequest'];

/* Stimuli. */
export type ManagedStimulus = Schemas['ManagedStimulus'];
export type StimulusRequest = Schemas['StimulusRequest'];

/* Rubrics and rubric grading. */
export type Rubric = Schemas['Rubric'];
export type RubricPage = Schemas['RubricPage'];
export type RubricVersion = Schemas['RubricVersion'];
export type RubricCriterion = Schemas['RubricCriterion'];
export type RubricLevel = Schemas['RubricLevel'];
export type CreateRubricRequest = Schemas['CreateRubricRequest'];
export type UpdateRubricRequest = Schemas['UpdateRubricRequest'];
export type RubricVersionRequest = Schemas['RubricVersionRequest'];
export type RubricCriterionInput = Schemas['RubricCriterionInput'];
export type GradeCriterionInput = Schemas['GradeCriterionInput'];
export type GradeCriterion = Schemas['GradeCriterion'];

/* Regrade and item analysis. */
export type RegradeRequest = Schemas['RegradeRequest'];
export type RegradeResult = Schemas['RegradeResult'];
export type RegradeAttemptChange = Schemas['RegradeAttemptChange'];
export type ItemAnalysisReport = Schemas['ItemAnalysisReport'];
export type ItemAnalysisRow = Schemas['ItemAnalysisRow'];
export type ItemDistractorRow = Schemas['ItemDistractorRow'];

/* Integrity evidence. */
export type IntegrityEvent = Schemas['IntegrityEvent'];
export type IntegrityEventPage = Schemas['IntegrityEventPage'];

/** The four owner scopes a bank folder, an entry or a rubric can sit in. */
export type OwnerScope = QuestionCategory['owner_scope'];

/** The three states of a bank entry; nothing on this surface deletes one. */
export type BankEntryStatus = BankEntry['status'];

/** The four regrade policies, by the names a migrating teacher already knows. */
export type RegradePolicy = RegradeRequest['policy'];
