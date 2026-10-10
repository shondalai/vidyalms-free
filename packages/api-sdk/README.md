# @vidyalms/api-sdk

Typed TypeScript client for the VidyaLMS /v1 contract. The repository's
`openapi.yaml` is the source of truth: the types in `src/generated/schema.d.ts`
are generated from it with openapi-typescript, and the client in `src/client.ts`
is a thin layer over openapi-fetch typed by those paths. The generated file is
committed, so consumers build without regenerating.

Used by the React apps in `packages/ui`. Apps never hand-write fetch calls;
every HTTP request goes through this client.

## Commerce boundary

EasyCommerce on Joomla and WooCommerce on WordPress handle checkout, orders, payments,
invoices, refunds and subscriptions. The SDK exposes learning access and catalogue
price/purchase-link operations, with no native billing or payment-gateway operations.
Notification feedback and general webhooks remain available.

## Scripts

```bash
npm run generate   # refresh src/generated/schema.d.ts from ../../openapi.yaml
npm run build      # tsc to dist/ (schema.d.ts is copied alongside)
npm test           # vitest, no network
npm run lint       # eslint
```

After any change to `openapi.yaml`, run `npm run generate` and commit the
refreshed `src/generated/schema.d.ts` together with the contract change.
Never edit the generated file by hand.

## Creating a client

`createVidyaLmsClient(config)` takes the `api` object of the mount contract
exactly as the host adapter serialised it into `data-vy-config`:

```ts
import { createVidyaLmsClient } from "@vidyalms/api-sdk";

const mount = document.getElementById("vidyalms-player");
const config = JSON.parse(mount.dataset.vyConfig);

const api = createVidyaLmsClient(config.api);
```

`config.api` has three fields:

| Field     | Meaning                                                          |
| --------- | ---------------------------------------------------------------- |
| `base`    | The URL stem the host wants requests sent to.                    |
| `mode`    | `"prefix"` or `"query"`, see below.                              |
| `headers` | Auth headers the host needs on every request.                    |

### Mode "prefix"

The core path is appended to the base. This is the WordPress mount and the
Joomla API application:

```ts
const api = createVidyaLmsClient({
  base: "https://example.com/wp-json/vidyalms/v1",
  mode: "prefix",
  headers: { "X-WP-Nonce": wpNonce },
});

await api.GET("/lessons/{id}", { params: { path: { id: 42 } } });
// GET https://example.com/wp-json/vidyalms/v1/lessons/42
```

### Mode "query"

The whole core path, its own query string included, becomes a single
URI-encoded query value appended to a base that already ends with its query
key. The Joomla site application proxies the API through a component task URL
this way:

```ts
const api = createVidyaLmsClient({
  base: "https://example.com/index.php?option=com_vidyalms&task=api.route&path=",
  mode: "query",
  headers: { "X-CSRF-Token": csrfToken },
});

await api.GET("/courses", { params: { query: { page: 2 } } });
// GET https://example.com/index.php?option=com_vidyalms&task=api.route&path=%2Fcourses%3Fpage%3D2
```

## Calls, errors, idempotency

The client is an openapi-fetch instance typed by the generated `paths`, so
paths, parameters, request bodies and response shapes are all checked at
compile time:

```ts
const { data } = await api.GET("/courses/{slug}", {
  params: { path: { slug: "intro-to-php" } },
});
// data is CourseDetail

await api.POST("/lessons/{id}/progress", {
  params: { path: { id: 42 } },
  body: { enrolment_id: 3, progress_percent: 50, seconds_delta: 15 },
});
```

Behaviour on top of plain openapi-fetch:

- Every non-2xx response rejects with a `ProblemError` carrying the parsed
  RFC 9457 problem document as `error.problem` (an `ApiProblem`: `type`,
  `title`, `status`, plus optional `detail`, `errors`, `retry_after`). When
  the body is not problem+json, a minimal problem is synthesised from the
  HTTP status, so `error.problem.status` is always safe to branch on.
- Every mutating call (POST, PUT) gets an `Idempotency-Key` header generated
  with `crypto.randomUUID()`. Pass your own to pin a key across retries:

```ts
import { ProblemError } from "@vidyalms/api-sdk";

try {
  await api.PUT("/attempts/{id}/answers", {
    params: { path: { id: attemptId } },
    body: { answers },
    headers: { "Idempotency-Key": autosaveKey },
  });
} catch (error) {
  if (error instanceof ProblemError && error.problem.status === 409) {
    // attempt already submitted
  }
}
```

- `config.headers` ride on every request in both modes.

## Exported types

`createVidyaLmsClient`, `VidyaLmsClient`, `ApiConfig`, `FetchLike`,
`ProblemError`, `ApiProblem`, `isProblem`, plus the contract schemas under
clean names: `Course`, `CourseDetail`, `CataloguePage`, `Price`,
`Curriculum`, `CurriculumSection`, `CurriculumLesson`, `CurriculumEnrolment`,
`Lesson`, `LessonProgress`, `LessonViewProgress`, `LessonQuizSummary`,
`LessonQuizLastAttempt`, `HeartbeatRequest`, `MyProgress`,
`CourseProgressSummary`, `EnrolRequest`, `Enrolment`, `MyEnrolment`,
`AttemptStarted`, `Attempt`, `AttemptView`, `AttemptQuestion`,
`SaveAnswersRequest`, `SubmitAssignmentRequest`, `SubmissionFileInput`,
`Submission`, `GradeSubmissionRequest`, `Certificate`,
`CertificateVerification` and `Problem`. The raw `paths`, `components` and
`operations` interfaces are exported too.

For tests, `createVidyaLmsClient(config, fetchImpl)` accepts a second
argument replacing `globalThis.fetch`; the SDK's own suite runs entirely
against stubbed fetches.
