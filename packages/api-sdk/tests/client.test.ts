import { describe, expect, it } from "vitest";
import {
  createVidyaLmsClient,
  ProblemError,
  isProblem,
  type ApiConfig,
  type ApiProblem,
  type CourseDetail,
  type CourseCategoryPage,
  type FetchLike,
  type ManagedCoursePage,
} from "../src/index.js";

const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

interface Stub {
  fetch: FetchLike;
  calls: Request[];
}

function stubFetch(
  handler: (request: Request) => Response | Promise<Response> = () =>
    new Response(null, { status: 204 }),
): Stub {
  const calls: Request[] = [];
  return {
    calls,
    fetch: async (request) => {
      calls.push(request);
      return handler(request);
    },
  };
}

function prefixConfig(headers: Record<string, string> = {}): ApiConfig {
  return {
    base: "https://learn.example/api/index.php/v1/vidyalms",
    mode: "prefix",
    headers,
  };
}

const QUERY_BASE =
  "https://learn.example/index.php?option=com_vidyalms&task=api.route&path=";

function queryConfig(headers: Record<string, string> = {}): ApiConfig {
  return { base: QUERY_BASE, mode: "query", headers };
}

const courseDetail: CourseDetail = {
  id: 7,
  title: "Intro to PHP",
  slug: "intro-to-php",
  summary: null,
  image: null,
  category_id: null,
  level: null,
  language: null,
  price_mode: "free",
  featured: false,
  published_at: "2026-01-15 10:00:00",
  description: "<p>Welcome</p>",
  visibility: "public",
  state: "published",
  completion_rule: "all_lessons",
  completion_threshold: null,
  price: null,
  // Required by the contract because the server always emits it: null when the
  // course is priced, and a translated reason when its commerce source is not
  // registered on this site. A fixture that omitted it was asserting a shape
  // no response has.
  price_reason: null,
  purchase_url: null,
};

describe("external shop purchase links", () => {
  it.each([prefixConfig(), queryConfig()])("preserves the shop URL using $mode transport", async (config) => {
    const purchaseUrl = "https://shop.example/product/intro-to-php";
    const stub = stubFetch(() => Response.json({ ...courseDetail, purchase_url: purchaseUrl }));
    const client = createVidyaLmsClient(config, stub.fetch);
    const { data } = await client.GET("/courses/{slug}", {
      params: { path: { slug: "intro-to-php" } },
    });
    expect(data?.purchase_url).toBe(purchaseUrl);
    expect(stub.calls).toHaveLength(1);
    expect(stub.calls[0]?.method).toBe("GET");
  });
});

describe("URL building in prefix mode", () => {
  it("appends the core path to the base", async () => {
    const stub = stubFetch(() => Response.json(courseDetail));
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.GET("/courses/{slug}", {
      params: { path: { slug: "intro-to-php" } },
    });

    expect(stub.calls[0]?.url).toBe(
      "https://learn.example/api/index.php/v1/vidyalms/courses/intro-to-php",
    );
  });

  it("carries query strings as ordinary query parameters", async () => {
    const stub = stubFetch(() =>
      Response.json({ items: [], total: 0, page: 2, per_page: 20 }),
    );
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.GET("/courses", {
      params: { query: { page: 2, q: "a b" } },
    });

    const url = new URL(stub.calls[0]?.url ?? "");
    expect(url.pathname).toBe("/api/index.php/v1/vidyalms/courses");
    expect(url.searchParams.get("page")).toBe("2");
    expect(url.searchParams.get("q")).toBe("a b");
  });

  it("types and sends the server-paged course-category discovery contract", async () => {
    const page: CourseCategoryPage = {
      items: [
        {
          id: 12,
          name: "Leadership",
          parent_id: 4,
          label: "Business / Leadership",
          state: "published",
          edit_url: "https://learn.example/admin/categories/12",
        },
      ],
      total: 51,
      page: 3,
      per_page: 20,
      management_url: "https://learn.example/admin/categories",
    };
    const stub = stubFetch(() => Response.json(page));
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.GET("/manage/course-categories", {
      params: {
        query: { q: "lead", sort: "name", order: "desc", page: 3, per_page: 20 },
      },
    });

    const url = new URL(stub.calls[0]?.url ?? "");
    expect(url.pathname).toBe("/api/index.php/v1/vidyalms/manage/course-categories");
    expect(url.searchParams.get("q")).toBe("lead");
    expect(url.searchParams.get("sort")).toBe("name");
    expect(url.searchParams.get("order")).toBe("desc");
    expect(url.searchParams.get("page")).toBe("3");
    expect(url.searchParams.get("per_page")).toBe("20");
  });

  it("sends an exact course-category lookup through the generated path", async () => {
    const stub = stubFetch(() =>
      Response.json({
        id: 12,
        name: "Leadership",
        parent_id: 4,
        label: "Business / Leadership",
        state: "published",
        edit_url: null,
      }),
    );
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.GET("/manage/course-categories/{id}", {
      params: { path: { id: 12 } },
    });

    expect(stub.calls[0]?.url).toBe(
      "https://learn.example/api/index.php/v1/vidyalms/manage/course-categories/12",
    );
  });
});

describe("URL building in query mode", () => {
  it("encodes the whole core path, slashes included, as one query value", async () => {
    const stub = stubFetch(() =>
      Response.json({ course_id: 7, modules: [], enrolment: null }),
    );
    const client = createVidyaLmsClient(queryConfig(), stub.fetch);

    await client.GET("/courses/{id}/curriculum", {
      params: { path: { id: 7 } },
    });

    expect(stub.calls[0]?.url).toBe(
      QUERY_BASE + encodeURIComponent("/courses/7/curriculum"),
    );
    expect(stub.calls[0]?.url).toContain("%2Fcourses%2F7%2Fcurriculum");
  });

  it("folds the core query string into the encoded value", async () => {
    const stub = stubFetch(() =>
      Response.json({ items: [], total: 0, page: 2, per_page: 20 }),
    );
    const client = createVidyaLmsClient(queryConfig(), stub.fetch);

    await client.GET("/courses", {
      params: { query: { page: 2, q: "abc" } },
    });

    expect(stub.calls[0]?.url).toBe(
      QUERY_BASE + encodeURIComponent("/courses?page=2&q=abc"),
    );
  });

  it("preserves method, body and headers across the rewrite", async () => {
    const stub = stubFetch(() =>
      Response.json(
        {
          id: 1,
          quiz_id: 5,
          attempt_number: 1,
          status: "in_progress",
          started_at: "2026-01-15 10:00:00",
        },
        { status: 201 },
      ),
    );
    const client = createVidyaLmsClient(
      queryConfig({ "X-CSRF-Token": "tok123" }),
      stub.fetch,
    );

    await client.POST("/quizzes/{id}/attempts", {
      params: { path: { id: 5 } },
    });

    const request = stub.calls[0];
    expect(request?.url).toBe(
      QUERY_BASE + encodeURIComponent("/quizzes/5/attempts"),
    );
    expect(request?.method).toBe("POST");
    expect(request?.headers.get("X-CSRF-Token")).toBe("tok123");
    expect(request?.headers.get("Idempotency-Key")).toMatch(UUID_PATTERN);
  });

  it("carries the JSON body through the rewrite", async () => {
    const stub = stubFetch();
    const client = createVidyaLmsClient(queryConfig(), stub.fetch);

    await client.POST("/lessons/{id}/progress", {
      params: { path: { id: 42 } },
      body: { enrolment_id: 3, progress_percent: 50 },
    });

    const request = stub.calls[0];
    expect(request?.url).toBe(
      QUERY_BASE + encodeURIComponent("/lessons/42/progress"),
    );
    expect(await request?.json()).toEqual({
      enrolment_id: 3,
      progress_percent: 50,
    });
  });
});

describe("Idempotency-Key", () => {
  it("is generated on POST when the caller passed none", async () => {
    const stub = stubFetch(() =>
      Response.json(
        {
          id: 1,
          user_id: 2,
          course_id: 7,
          entitlement_id: 3,
          status: "active",
          enrolled_at: "2026-01-15 10:00:00",
          completed_at: null,
          progress_percent: 0,
        },
        { status: 201 },
      ),
    );
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.POST("/enrolments", { body: { course_id: 7 } });

    expect(stub.calls[0]?.headers.get("Idempotency-Key")).toMatch(UUID_PATTERN);
  });

  it("is generated on PUT when the caller passed none", async () => {
    const stub = stubFetch();
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.PUT("/attempts/{id}/answers", {
      params: { path: { id: 9 } },
      body: { answers: { "12": "b" } },
    });

    expect(stub.calls[0]?.headers.get("Idempotency-Key")).toMatch(UUID_PATTERN);
  });

  it("keeps a caller-provided key untouched", async () => {
    const stub = stubFetch();
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.PUT("/attempts/{id}/answers", {
      params: { path: { id: 9 } },
      body: { answers: { "12": "b" } },
      headers: { "Idempotency-Key": "caller-key-1" },
    });

    expect(stub.calls[0]?.headers.get("Idempotency-Key")).toBe("caller-key-1");
  });

  it("is absent on GET", async () => {
    const stub = stubFetch(() => Response.json(courseDetail));
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.GET("/courses/{slug}", {
      params: { path: { slug: "intro-to-php" } },
    });

    expect(stub.calls[0]?.headers.has("Idempotency-Key")).toBe(false);
  });
});

describe("config header passthrough", () => {
  it("rides every request in both modes", async () => {
    for (const config of [
      prefixConfig({ "X-WP-Nonce": "nonce9", "X-Extra": "1" }),
      queryConfig({ "X-WP-Nonce": "nonce9", "X-Extra": "1" }),
    ]) {
      const stub = stubFetch(() => Response.json(courseDetail));
      const client = createVidyaLmsClient(config, stub.fetch);

      await client.GET("/courses/{slug}", {
        params: { path: { slug: "intro-to-php" } },
      });

      expect(stub.calls[0]?.headers.get("X-WP-Nonce")).toBe("nonce9");
      expect(stub.calls[0]?.headers.get("X-Extra")).toBe("1");
    }
  });
});

function problemResponse(problem: ApiProblem): Response {
  return new Response(JSON.stringify(problem), {
    status: problem.status,
    headers: { "Content-Type": "application/problem+json" },
  });
}

describe("problem responses", () => {
  it("rejects a 409 with a typed ProblemError", async () => {
    const stub = stubFetch(() =>
      problemResponse({
        type: "https://vidyalms.dev/problems/attempt-limit",
        title: "Attempt limit reached",
        status: 409,
        detail: "All 3 attempts are used.",
      }),
    );
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    let caught: unknown;
    try {
      await client.POST("/quizzes/{id}/attempts", {
        params: { path: { id: 5 } },
      });
    } catch (error) {
      caught = error;
    }

    expect(caught).toBeInstanceOf(ProblemError);
    if (!(caught instanceof ProblemError)) return;
    expect(caught.status).toBe(409);
    expect(caught.problem.title).toBe("Attempt limit reached");
    expect(caught.problem.detail).toBe("All 3 attempts are used.");
    expect(isProblem(caught.problem)).toBe(true);
  });

  it("rejects a 422 carrying the field errors map", async () => {
    const stub = stubFetch(() =>
      problemResponse({
        type: "about:blank",
        title: "Validation failed",
        status: 422,
        errors: { course_id: "Unknown course." },
      }),
    );
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await expect(
      client.POST("/enrolments", { body: { course_id: 999 } }),
    ).rejects.toMatchObject({
      name: "ProblemError",
      status: 422,
      problem: {
        status: 422,
        errors: { course_id: "Unknown course." },
      },
    });
  });

  it("synthesises a problem when the body is not problem+json", async () => {
    const stub = stubFetch(
      () =>
        new Response("<html>Bad gateway</html>", {
          status: 502,
          statusText: "Bad Gateway",
        }),
    );
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await expect(
      client.GET("/courses/{slug}", {
        params: { path: { slug: "intro-to-php" } },
      }),
    ).rejects.toMatchObject({
      status: 502,
      problem: { type: "about:blank", status: 502 },
    });
  });
});

describe("happy path", () => {
  it("returns typed data end to end on a 200", async () => {
    const stub = stubFetch(() => Response.json(courseDetail));
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    const { data, response } = await client.GET("/courses/{slug}", {
      params: { path: { slug: "intro-to-php" } },
    });

    expect(response.status).toBe(200);
    expect(data?.id).toBe(7);
    expect(data?.slug).toBe("intro-to-php");
    expect(data?.state).toBe("published");
    expect(data?.price).toBeNull();
  });
});

describe("management surface", () => {
  it("types the course list with its server-side sort and filter parameters", async () => {
    const page: ManagedCoursePage = { items: [], total: 0, page: 1, per_page: 20 };
    const stub = stubFetch(() => Response.json(page));
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    const { data } = await client.GET("/manage/courses", {
      params: {
        query: { state: "published", sort: "enrolled_count", order: "desc", page: 1 },
      },
    });

    const url = new URL(stub.calls[0]?.url ?? "");
    expect(url.pathname).toBe("/api/index.php/v1/vidyalms/manage/courses");
    expect(url.searchParams.get("state")).toBe("published");
    expect(url.searchParams.get("sort")).toBe("enrolled_count");
    expect(url.searchParams.get("order")).toBe("desc");
    expect(data?.total).toBe(0);
  });

  it("keys PATCH and DELETE mutations like POST and PUT", async () => {
    const stub = stubFetch();
    const client = createVidyaLmsClient(prefixConfig(), stub.fetch);

    await client.PATCH("/manage/courses/{id}", {
      params: { path: { id: 7 } },
      body: { title: "Renamed" },
    });
    await client.DELETE("/manage/questions/{id}", { params: { path: { id: 4 } } });

    expect(stub.calls[0]?.method).toBe("PATCH");
    expect(stub.calls[0]?.headers.get("Idempotency-Key")).toMatch(UUID_PATTERN);
    expect(stub.calls[1]?.method).toBe("DELETE");
    expect(stub.calls[1]?.url).toBe(
      "https://learn.example/api/index.php/v1/vidyalms/manage/questions/4",
    );
    expect(stub.calls[1]?.headers.get("Idempotency-Key")).toMatch(UUID_PATTERN);
  });

  it("folds a nested management path into the query mode value", async () => {
    const stub = stubFetch(() =>
      Response.json({
        items: [],
        total: 0,
        page: 1,
        per_page: 20,
        pending_total: 0,
        graded_total: 0,
      }),
    );
    const client = createVidyaLmsClient(queryConfig(), stub.fetch);

    await client.GET("/manage/courses/{id}/gradebook", {
      params: { path: { id: 7 }, query: { status: "graded", type: "quiz_answer" } },
    });

    expect(stub.calls[0]?.url).toBe(
      QUERY_BASE + encodeURIComponent("/manage/courses/7/gradebook?status=graded&type=quiz_answer"),
    );
  });
});
