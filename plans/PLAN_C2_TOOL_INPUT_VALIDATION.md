# Plan — C2: JSON-Schema Validation of Tool Input

**Origin:** [SDK_REVIEW.md](SDK_REVIEW.md) §2 → **C2** (Critical) and `1.0` punch list.
**Status:** Planning only — no code to be written yet.
**Owner:** TBD
**Target release:** `0.x` (preview) → blocker for `1.0`.

---

## 1. Problem Statement

A tool definition carries a JSON Schema in [Tool::$inputSchema](src/Tool/Tool.php:31), but nothing inside the SDK validates a `ToolCall`'s `input` against that schema before the user's `execute` closure runs.

Failure modes today:

1. **Type crash.** Model emits `{"city": 42}` for a `string` parameter; the tool body does `strtolower($input['city'])` → `TypeError`.
2. **Missing required field.** Model omits `city` entirely; tool body does `$input['city']` → `Undefined array key` warning, then a downstream NPE.
3. **Extra / hallucinated fields.** Model emits `{"city": "...", "user_id": "..."}` for a tool that has no auth concept; the tool body silently uses the extra field.
4. **`null` from malformed JSON.** [ToolCall::fromContentPart](src/Tool/ToolCall.php:27) silently `json_decode(..., true)` returns `null` on parse error. `null` is then passed straight into the closure, which signature-typed as `array $input` will TypeError, but if typed `mixed` will run with garbage input. (This overlaps with C3 — see §9.)

Goal: every `ToolCall::input` is validated against the tool's declared `inputSchema` **before** the closure is invoked. On failure, return a structured `ToolResult` carrying the validation error rather than crashing the step.

---

## 2. Scope

**In scope**
- New schema-validation seam called from `ToolPreparer::executeToolCall`.
- New `InvalidToolInputException` under `src/Exceptions/`.
- Failure-channel for validated tool errors (return a `ToolResult` whose `output` is the error, NOT throw, so multi-step loops can let the model recover).
- Tests covering: valid input, type error, missing required, extra-property, null input, schema-less tool (skip validation).
- Docs: `docs/tools.md` section + a note in `SDK_REVIEW.md` punch list once delivered.

**Out of scope (separate tickets)**
- C3 (`JSON_THROW_ON_ERROR` in `ToolCall::fromContentPart`) — interacts with this work but is its own fix.
- M6 (enriching `AIException` with request id / `toArray()`).
- H1 (`finishReason` as enum end-to-end).
- Coercion of model output (e.g., `"42"` → `42`). This plan validates, it does not transform.

---

## 3. Design Decisions

### 3.1 Validator library

The review names two candidates: `opis/json-schema` and `justinrainbow/json-schema`. Recommend **`opis/json-schema`**:

- Active maintenance, draft 2020-12 support, PHP 8 native.
- Better diagnostic format (`ValidationError::error()` returns a tree).
- Permissive license (Apache-2.0) — matches the direction of the package (already pivoted from Apache → GPLv2+; opis is compatible with GPLv2+).
- `justinrainbow/json-schema` is widely used (Composer itself) but only supports up to draft-04 cleanly; the SDK's tool schemas already use draft-07+ shapes (e.g., `additionalProperties: false`).

We will **not** make the dependency hard. To preserve the "minimal core" property called out in C6 (Guzzle removal), introduce the validator behind an interface and keep the concrete `opis` adapter in `require-dev` for tests, while shipping a `NullSchemaValidator` as default. Production users who want validation opt in by installing `opis/json-schema` and wiring the adapter.

**Decision to confirm with maintainer:** should validation be on-by-default (hard require) or opt-in (suggest + null default)?
→ Recommendation: **opt-in via `suggest`**, but ship a one-line wire-up snippet in `docs/tools.md`. Rationale: the SDK's current direction (per review) is to *remove* unused hard deps, not add new ones.

### 3.2 Where validation runs

Two candidate seams:

| Option | Pros | Cons |
| --- | --- | --- |
| **A. Inside `Tool::__invoke`** | Localized; every caller benefits | Couples `Tool` to a validator instance (DI awkward for the static `Tool::define` factory) |
| **B. Inside `ToolPreparer::executeToolCall`** ✅ | Single chokepoint; both `GenerateText` and `StreamText` already route through it; easy to inject validator | Won't catch a user who calls `$tool($input)` directly |

Pick **B**. Add a constructor-injected `SchemaValidator` to `ToolPreparer` (which currently is fully static — see §3.3).

### 3.3 `ToolPreparer` static → instance

Today every method on [ToolPreparer](src/Tool/ToolPreparer.php:13) is `public static`. To inject a validator we need an instance. Options:

1. Add an optional static `$defaultValidator` and a `setDefaultValidator()` — keeps the static API, easy migration. *Smells: global state.*
2. Convert `executeToolCall` to instance method, keep `prepare()` static (it doesn't need DI). Callers in `GenerateText`/`StreamText` change.

Pick **2**. The two callsites are known and small:
- [GenerateText.php:213](src/Core/GenerateText.php:213)
- [StreamText.php:335](src/Core/StreamText.php:335)

`GenerateText` and `StreamText` will accept an optional `ToolPreparer` (default: `new ToolPreparer(new NullSchemaValidator())`) via constructor or builder setter. The procedural facade in `functions.php` passes through.

### 3.4 Failure mode: throw vs. return error result

The review's sketch (§10.5) returns `ToolResult::failure($toolCall, new InvalidToolInputException($errors))`. That matches the multi-step loop's contract: a failed tool is just a message we send back to the model, so it can re-try with a corrected input.

Decision: **return a tool result with a structured error payload, do NOT throw**. The exception type still exists so users who *want* hard-failure can wire `onStepFinish`/`onError` (H1/M10) to escalate.

`ToolResult` currently has no "failure" axis — add one:

```php
class ToolResult {
    public readonly bool $isError;
    public readonly ?array $errorDetails;   // null when isError === false
    ...
}
```

Existing `output` field stays. On failure, `output` carries a model-friendly string (e.g., `"Invalid tool input: city must be a string."`) so the model sees something coherent in the next step.

### 3.5 What if the tool has no `inputSchema`?

`Tool::define(execute: fn(...) => ...)` is valid today with no schema. Behavior: **skip validation**, pass input through. Documented as "best-effort"; users who want strictness MUST supply a schema.

### 3.6 What if the input is not an array?

`Tool::__invoke(array $input, ...)` is typed `array`. If `ToolCall::input` is `null`/scalar/string (because JSON decoding failed — overlap with C3), short-circuit to a validation failure with message `"Tool input must be a JSON object."` rather than letting PHP throw a `TypeError`.

---

## 4. New / Changed Files

### New
| File | Purpose |
| --- | --- |
| `src/Tool/SchemaValidator.php` | Interface: `validate(mixed $input, array $schema): array<string>` (returns list of error messages, empty = ok). |
| `src/Tool/NullSchemaValidator.php` | Default — always returns `[]`. Keeps "core has no validation dep" property. |
| `src/Tool/OpisSchemaValidator.php` | Adapter over `opis/json-schema`. Only autoloadable if the library is installed (no compile-time reference issue; just runtime). |
| `src/Exceptions/InvalidToolInputException.php` | Extends `AIException`. Carries `toolName`, `toolCallId`, `errors[]`, `input`. |
| `tests/Tool/SchemaValidatorTest.php` | Contract tests for both validators (skip Opis test if not installed). |
| `tests/Tool/ToolPreparerValidationTest.php` | Integration: `executeToolCall` with valid / invalid input. |

### Changed
| File | Change |
| --- | --- |
| `src/Tool/ToolPreparer.php` | Constructor `__construct(SchemaValidator $validator)`. `executeToolCall` becomes instance method; calls `$this->validator->validate(...)` before invoking. Build a failure `ToolResult` on errors. (`prepare` stays static.) |
| `src/Tool/ToolResult.php` | Add `bool $isError = false`, `?array $errorDetails = null`. `toMessagePart()` already serializes via `output` — make sure error messages are model-readable. |
| `src/Core/GenerateText.php` | Add optional `ToolPreparer` field, default `new ToolPreparer(new NullSchemaValidator())`. Builder setter `validator(SchemaValidator $v): self` (or `toolPreparer(...)`). Call instance method at line 213. |
| `src/Core/StreamText.php` | Same pattern at line 335. |
| `src/functions.php` | Pass through a new `'schemaValidator'` (or `'toolValidator'`) option key in `generateText` / `streamText`. |
| `composer.json` | Add `"opis/json-schema": "^2.3"` under `suggest`. (Not `require`.) Add the same under `require-dev` so the test suite can exercise the Opis adapter. |
| `docs/tools.md` | New "Input Validation" section: how to enable, what errors look like, model-recovery behavior. |
| `docs/api-reference.md` | Document `SchemaValidator` interface and `toolValidator` option. |

---

## 5. Behavioral Contract

```
ToolCall arrives
   │
   ├─ tool not registered     → return null (unchanged)
   ├─ tool has no execute     → return null (unchanged)
   │
   ├─ tool.inputSchema === null → invoke closure as-is (skip validation)
   │
   └─ tool.inputSchema set
         ├─ input is not array → ToolResult(isError=true, output="Tool input must be a JSON object.")
         ├─ validator returns errors → ToolResult(isError=true,
         │                                         output="Invalid tool input: <first error>",
         │                                         errorDetails=<full list>)
         └─ no errors → invoke closure normally
```

The closure exception path is **unchanged** for now (M10 is a separate ticket).

---

## 6. Public API Impact

- **Additive.** Existing `Tool::define(...)` calls and existing `ToolPreparer::executeToolCall($call, $tools)` static call will still work *only* if we keep a static shim. Plan: keep the static `ToolPreparer::executeToolCall` for one minor version with `@deprecated`, forwarding to a static default instance.
- **`ToolResult` gets two new properties with defaults** — constructor backwards-compatible if we keep them at the end with `= false` / `= null`.
- **No breaking change in `0.x`.** Once we hit `1.0`, drop the static shim.

---

## 7. Test Plan

### Unit
- `NullSchemaValidator` always returns `[]`.
- `OpisSchemaValidator`:
  - valid `{city: "SF"}` against `{type:object, properties:{city:{type:string}}, required:[city]}` → `[]`.
  - missing `city` → 1 error mentioning "city" and "required".
  - `{city: 42}` → 1 error mentioning "string".
  - extra property when `additionalProperties:false` → 1 error.
  - `null` input → error (or short-circuit before reaching validator — assert via `ToolPreparer` test).
- `InvalidToolInputException` carries the fields we expect.

### Integration (`ToolPreparerValidationTest`)
- valid input → closure runs, `ToolResult.isError === false`.
- invalid input → closure does NOT run (use a closure that records a call and assert it wasn't called), `ToolResult.isError === true`.
- non-array input (e.g., `null`) → closure does NOT run, error message is the "must be JSON object" sentinel.
- tool without schema → closure runs even with garbage input.

### End-to-end (`tests/Integration/` — currently doesn't exist; create dir)
- Multi-step loop with a tool whose first call has invalid input, second call (after model "self-corrects" in fixture) is valid. Assert step count, that no exception bubbled, and that the assistant message between steps contains the error string.
- Use a `Mock\LanguageModel` (see review's R8 — out of scope for *this* ticket, but we can stub locally).

### Regression
- Existing [ToolPreparerTest::testExecuteToolCall](tests/Tool/ToolPreparerTest.php:64) and friends must keep passing. They use tools with no schema → skip-validation path covers them.

Coverage target for new code: 100% lines / 100% branches.

---

## 8. Rollout

1. **PR 1** — Add `SchemaValidator` interface, `NullSchemaValidator`, `InvalidToolInputException`, `ToolResult.isError`. Convert `ToolPreparer::executeToolCall` to instance method + static shim. All existing tests still green. *No new dependency.*
2. **PR 2** — Add `OpisSchemaValidator`. Add `opis/json-schema` to `require-dev` and `suggest`. Add validator tests and `ToolPreparerValidationTest`.
3. **PR 3** — Wire builder/facade `validator()` option in `GenerateText`, `StreamText`, `functions.php`. Update `docs/tools.md` and `docs/api-reference.md`.
4. **PR 4** — Add integration test with mock provider exercising the validation-error → model recovery loop.

Each PR independently mergeable. PRs 1-3 are the C2 fix; PR 4 closes the testing gap and overlaps with §7 of the review.

---

## 9. Interactions with Other Review Items

- **C3** (`JSON_THROW_ON_ERROR` in `ToolCall::fromContentPart`): natural pairing. After C3 lands, the "input is not array" branch in §5 becomes nearly unreachable (we'd throw earlier), but keep the guard — defensive against future code paths that build `ToolCall` directly.
- **M6** (Enrich `AIException`): `InvalidToolInputException` should already follow whatever shape M6 standardizes (likely `toArray()`, `getContext()`). Coordinate field names.
- **H1** (`finishReason` enum end-to-end): unrelated, but note that validation failures should NOT change `finishReason` — the step still finishes with `tool-calls` and the model is given a chance to retry.
- **M10** (`onError` wrapping in `StreamTextResult`): once added, errors from invalid tool input should also surface through `onError` if the user has registered one — not just through the `ToolResult.isError` channel.
- **R8** (`Mock\LanguageModel` test double): the integration test in PR 4 is the natural place to introduce a tiny version of this.

---

## 10. Open Questions for Maintainer

1. **Hard-require `opis/json-schema` or keep opt-in?** Recommendation: opt-in via `suggest` + ship `OpisSchemaValidator` adapter so users wire one line. (See §3.1.)
2. **Default validator: `NullSchemaValidator` or `OpisSchemaValidator` if class exists?** Recommendation: `NullSchemaValidator` always, with a static helper `SchemaValidator::default()` that returns Opis when present. Keeps behavior explicit.
3. **`ToolResult` error shape.** Should we add a `ToolResult::failure()` static (as in the review's §10.5 sketch), or keep one constructor with defaults? Recommendation: add `ToolResult::failure(...)` and `ToolResult::success(...)` named constructors — readability wins.
4. **Should validation errors be sent to the model as a string (current plan) or as a structured tool-result object?** The Vercel SDK does the latter via a `isError: true` field on the tool-result message part. Recommendation: match Vercel — set the message-part shape `{type: 'tool-result', toolCallId: ..., result: '...', isError: true}` so downstream wire format stays compatible.
5. **`maxValidationErrors` cap?** A pathological schema could return hundreds of errors. Recommendation: cap at 10 in the user-visible message, full list in `errorDetails`.

---

## 11. Done Criteria

- [ ] All bullets in §4 implemented.
- [ ] `composer test` green on PHP 8.1, 8.2, 8.3, 8.4 (CI from the review's punch list will follow separately).
- [ ] `docs/tools.md` has runnable "Input Validation" example.
- [ ] `SDK_REVIEW.md` punch-list entry for **C2** can be ticked.
- [ ] No new hard runtime dependency added.
- [ ] No regression in existing 24 test files.

— *End of plan.*
