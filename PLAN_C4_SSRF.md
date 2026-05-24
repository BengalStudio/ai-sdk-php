# Plan — C4: SSRF Protection for User/File-Message URLs

**Status:** Proposed
**Author:** Maintainer (planning only — no code changes yet)
**Date:** 2026-05-24
**Tracks:** [SDK_REVIEW.md](SDK_REVIEW.md) — Critical item **C4**

---

## 1. Background & Threat Model

### 1.1 The vulnerable surface

[functions.php:413-452](src/functions.php) — `convertUserMessageContent(UIMessage $uiMessage)` walks UI-message parts of type `file` and passes `$part['url']` straight into the model-message structure:

```php
if (str_starts_with($mediaType, 'image/')) {
    $content[] = [
        'type'     => 'image',
        'image'    => $part['url'] ?? '',
        'mimeType' => $mediaType,
    ];
} else {
    $content[] = [
        'type'     => 'file',
        'data'     => $part['url'] ?? '',
        'mimeType' => $mediaType,
    ];
}
```

The `UIMessage` is constructed from arbitrary user input ([functions.php:367-401](src/functions.php), typical caller: `$request->get_json_params()` in the WordPress REST callback shown in the docblock). The URL is then forwarded to the provider, which fetches it server-side. Most multimodal providers (OpenAI, Anthropic, Google) fetch image/file URLs from their own infrastructure, but some SDKs (e.g., self-hosted models, vision tools) may resolve and fetch from the *application server*. Either way, the application is the one that *chose* to forward the URL.

### 1.2 Attacker capabilities

A user that controls one UI-message `parts[].url` can:

| Class                | Example payload                              | Impact                                                    |
| -------------------- | -------------------------------------------- | --------------------------------------------------------- |
| Cloud metadata       | `http://169.254.169.254/latest/meta-data/`   | Steal IAM creds / cloud secrets                           |
| Loopback / RFC1918   | `http://127.0.0.1:6379/`, `http://10.0.0.5/` | Probe internal services (Redis, ES, k8s API, admin UIs)   |
| Link-local IPv6      | `http://[fe80::1]/`                          | Same as above on IPv6                                     |
| Non-HTTP schemes     | `file:///etc/passwd`, `gopher://...`         | Local file read / protocol smuggling                      |
| Credential leak      | `https://user:token@evil.tld/`               | Send tokens to attacker (if SDK or provider logs URL)     |
| DoS via giant blob   | `https://victim/100GB.bin`                   | Provider downloads → bill explosion, OOM, slow request    |
| DoS via giant data:  | `data:image/png;base64,<10MB>`               | Memory pressure inside our process                        |
| DNS rebinding        | `http://rebind.evil.tld/`                    | Bypass IP allow-list at fetch time (out of scope, see §9) |

### 1.3 Why the agent flagged this as Critical

The SDK is marketed for WordPress plugins (see [docs/](docs/) and `COMPOSER_AUDIT_REVIEW.md`). A WP plugin that wires `useChat()` → REST endpoint → `convertToModelMessages(...)` will trust whatever the browser sends. **Zero guard is unacceptable for that audience.**

---

## 2. Scope

### In scope (this plan)
- Validate every URL that originates from user-controlled UI messages **before** it leaves the SDK boundary, with safe defaults.
- Provide an opt-in / opt-out / customize API.
- Cap inline `data:` URI payload size.
- Define a typed exception and document the threat.

### Out of scope (separate work, may follow-up)
- DNS rebinding mitigation that requires control of the HTTP client (the SDK does not own one yet — see C6/H4). Documented as a known limitation in §9.
- Validating URLs in directly-constructed `Message::user([...])` payloads when the caller bypasses `convertToModelMessages()`. Documented as caller responsibility; same guard is reusable by callers.
- Guarding tool-result URLs returned by user-defined tools. (Tool *input* is C2; tool *output* trust is the caller's domain.)

---

## 3. Design Overview

Introduce a small, single-purpose **`UrlGuard`** value object that encapsulates the SSRF policy. `convertUserMessageContent()` consults it for every file/image URL; on rejection, throw a typed exception with the offending URL + reason.

```
UIMessage (untrusted)
    │
    ▼
convertToModelMessages($msgs, ?UrlGuard $guard = null)   ← guard injected or defaulted
    │
    ▼
convertUserMessageContent($uiMessage, UrlGuard $guard)
    │
    │  for each file/image part:
    │      $guard->validate($url, $mediaType)            ← throws on violation
    ▼
Message[]  (safe to hand to model)
```

Default behavior (no argument): `UrlGuard::strict()`. Callers that intentionally want to allow private targets (e.g., self-hosted dev) pass `UrlGuard::permissive()` or build a custom policy. Callers that want to skip validation entirely pass `UrlGuard::disabled()` and **opt into the threat**.

---

## 4. New Module: `Prompt\UrlGuard`

### 4.1 Files to add

| Path                                                  | Purpose                                              |
| ----------------------------------------------------- | ---------------------------------------------------- |
| `src/Prompt/UrlGuard.php`                             | Policy object + `validate()` entry point             |
| `src/Prompt/UrlGuardPolicy.php` *(or inline)*         | Immutable policy DTO (schemes, host rules, caps)     |
| `src/Exceptions/UnsafeUrlException.php`               | Typed exception extending `AIException`              |
| `tests/Prompt/UrlGuardTest.php`                       | Unit coverage of every rule                          |
| `docs/security.md`                                    | Threat model + how to configure the guard           |

### 4.2 Public surface (sketch — not committed)

```php
final class UrlGuard
{
    public static function strict(): self;        // default for convertToModelMessages
    public static function permissive(): self;    // https + http, no private-IP guard
    public static function disabled(): self;      // returns a no-op guard

    public function withAllowedSchemes(string ...$schemes): self;
    public function withAllowedHosts(string ...$hosts): self;         // exact or *.domain.tld
    public function withDeniedHosts(string ...$hosts): self;
    public function withMaxDataUriBytes(int $bytes): self;
    public function withMaxUrlLength(int $chars): self;
    public function withResolveDns(bool $resolve): self;              // opt-in DNS check

    /** @throws UnsafeUrlException */
    public function validate(string $url, ?string $mediaType = null): void;
}
```

`UrlGuard` is immutable; every `with*()` returns a new instance.

---

## 5. Validation Rules (Default `strict()`)

In order — first failure throws.

1. **URL length cap.** Default `8 KiB`. Cheap DoS guard against megabyte URLs.
2. **`parse_url` success.** Reject anything PHP can't parse.
3. **Scheme allow-list.** Default: `https`, `data`. (No `http`, no `file`, no `gopher`, no `ftp`, no `javascript`.)
4. **No userinfo.** Reject URLs with `user:pass@` — prevents credential leakage and host-confusion attacks.
5. **`data:` URI specifics.**
   - MIME type, if declared, must match `mediaType` argument (defense in depth).
   - Base64 payload length ≤ `maxDataUriBytes` (default `8 MiB`).
6. **Host present** for non-`data:` schemes.
7. **Host classification.** Resolve host to one or more IPs (literal or DNS). For each resolved IP:
   - Reject IPv4 in: `0.0.0.0/8`, `10.0.0.0/8`, `127.0.0.0/8`, `169.254.0.0/16` (link-local + AWS/Azure/GCP metadata), `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10` (CGNAT), `198.18.0.0/15`, `224.0.0.0/4` (multicast), `240.0.0.0/4` (reserved), broadcast `255.255.255.255`.
   - Reject IPv6: `::1`, `::`, `fc00::/7` (ULA), `fe80::/10` (link-local), `ff00::/8` (multicast), IPv4-mapped (`::ffff:0:0/96`) — re-classified by the embedded IPv4.
   - When `resolveDns` is **off** (default), only block IP **literals** in this class. Hostnames pass this rule. *Reason:* DNS resolution adds latency and the SDK is not yet an HTTP client; we punt DNS-rebinding mitigation to a follow-up (see §9).
   - When `resolveDns` is **on** (opt-in), call `gethostbynamel()` / `dns_get_record(AAAA)` and apply the IP classification to every returned address.
8. **Host allow/deny lists.** If `allowedHosts` is non-empty, host must match (exact or `*.suffix`). Always-enforced `deniedHosts` overrides allow.

`permissive()` differs only in step 3 (allow `http` and `https`) and step 7 (skip IP classification entirely).

`disabled()` short-circuits to a no-op.

---

## 6. Integration Points

### 6.1 `functions.php`

1. **`convertToModelMessages(array $uiMessages, ?UrlGuard $guard = null): array`** — new optional second argument. Default: `UrlGuard::strict()` (constructed lazily when `null`).
2. **`convertUserMessageContent(UIMessage $uiMessage, UrlGuard $guard): string|array|null`** — guard becomes a required parameter (internal function; signature change is fine).
3. Call `$guard->validate($part['url'] ?? '', $mediaType)` immediately before pushing the `image` / `file` content array.

### 6.2 Builder seams (deferred but designed-for)

`GenerateText`, `StreamText`, `GenerateObject`, `StreamObject` do **not** receive a guard in this change. Callers that build `Message[]` themselves are out of scope per §2. If we want to add a setter later (`->urlGuard($guard)`), the policy object is already reusable.

### 6.3 No HTTP client involvement

The guard is purely static URL inspection (plus optional DNS lookup). It does not fetch the URL itself. This keeps the change small and lets the future HTTP layer (R4 in the review) handle DNS-pinning separately.

---

## 7. Exception

```php
final class UnsafeUrlException extends AIException
{
    public function __construct(
        public readonly string $url,
        public readonly string $reason,           // e.g. "scheme not allowed: http"
        public readonly ?string $ruleId = null,   // e.g. "scheme_allowlist", "private_ip"
    ) {
        parent::__construct("Unsafe URL rejected: {$reason}");
    }
}
```

Sits under `BengalStudio\AI\Exceptions`, extends the existing `AIException` base for catch-all compatibility. Carries enough context for callers to log/translate the rejection without re-parsing the message string.

---

## 8. Tests

`tests/Prompt/UrlGuardTest.php`, organized by rule:

- **Schemes:** accepts `https://x/y`, `data:image/png;base64,AAAA`; rejects `http://`, `file:///etc/passwd`, `gopher://`, `javascript:alert(1)`, empty string.
- **URL length:** accepts at the cap, rejects one byte over.
- **userinfo:** rejects `https://user:pass@host/`.
- **data: payload cap:** accepts ≤ cap, rejects > cap. Mismatched MIME inside `data:` vs. argument is rejected.
- **IP literals:** rejects `http://127.0.0.1/` (also when scheme is `https://`), `https://169.254.169.254/`, `https://[::1]/`, `https://[fe80::1]/`, IPv4-in-IPv6 form, every CIDR boundary above.
- **Public IP literal:** accepts `https://1.1.1.1/`.
- **Hostnames (no DNS):** accepts `https://example.com/`.
- **Hostnames (DNS on):** stub DNS resolver (inject `Closure` for testability) and assert host that resolves to `127.0.0.1` is rejected.
- **Allow/deny lists:** `*.example.com` matches `cdn.example.com` but not `evil.com`; deny overrides allow.
- **Custom factory chain:** `UrlGuard::strict()->withAllowedHosts('*.cdn.example.com')` accepts only that host.
- **`disabled()`:** never throws even on `file:///`.

`tests/Core/ConvertToModelMessagesTest.php` additions:

- Default-guard rejects a `file` part whose URL is `http://169.254.169.254/`.
- Passing `UrlGuard::disabled()` lets it through.
- Existing tests (including the `data:image/png;base64,abc123` test at line 174) continue to pass — confirm `data:` stays in the default allow-list.

No new integration tests required (the path is pure transformation).

---

## 9. Documented Limitations (Required by C4: "document or guard")

In `docs/security.md`:

1. **DNS rebinding** is not mitigated by static URL inspection. Two robust mitigations are open:
   - Run the SDK behind an HTTP client that pins DNS at connect time (planned with R4 in [SDK_REVIEW.md](SDK_REVIEW.md)).
   - Use a network-level egress filter (e.g., disallow outbound to RFC1918 / metadata IPs at the firewall).
2. **Provider-side fetch.** When the URL is forwarded to a remote provider (OpenAI, Anthropic, etc.), *their* infrastructure fetches it. Our guard prevents the URL from being sent in the first place, which is the right place to enforce policy — but downstream provider behavior is out of our control.
3. **Bypass via direct `Message::user([...])` construction.** Callers who bypass `convertToModelMessages()` must call `UrlGuard::strict()->validate(...)` themselves or accept the risk.
4. **`http://` is not allowed by default.** Callers that need plain HTTP (e.g., LAN-hosted CDN behind TLS-termination proxy) must opt into `permissive()` or build a custom policy.

---

## 10. Backward Compatibility

| Change                                                                           | BC impact                                                                       |
| -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| `convertToModelMessages()` adds an optional second argument                      | None — additive.                                                                |
| `convertUserMessageContent()` adds a required second argument                    | None for public API — it's `@internal` (see [functions.php:411](src/functions.php)). |
| Default behavior rejects `http://` and private IPs in `file` parts               | **Breaking for any caller currently relying on those.** Mitigation: release notes call this out; opt-out is one line (`UrlGuard::disabled()`). Pre-1.0 is the right window. |
| New `UnsafeUrlException` extends `AIException`                                   | None — extends existing base.                                                   |

This belongs in `0.x` before `1.0` so the strict default is established before the API freezes.

---

## 11. Implementation Sequence (for the follow-up coding task)

Suggested order — each step is independently green:

1. Add `UnsafeUrlException`.
2. Add `UrlGuard` with `strict() / permissive() / disabled()` factories and the rule engine. Unit-test every rule.
3. Wire `UrlGuard` into `convertUserMessageContent()` and add optional second argument to `convertToModelMessages()`. Update existing tests as needed (the `data:image/png;base64,abc123` case stays green).
4. Add `docs/security.md` covering the threat model, the rules, configuration recipes, and the documented limitations from §9.
5. Add a `CHANGELOG.md` entry under "Unreleased" describing the breaking-default. *(This also helps unblock the missing-CHANGELOG punch-list item from SDK_REVIEW.md §11.)*

---

## 12. Acceptance Criteria

- [ ] `convertToModelMessages()` rejects `http://169.254.169.254/...`, `http://127.0.0.1/...`, `http://10.0.0.5/...`, `file:///etc/passwd`, `https://user:pass@host/`, oversize `data:` URIs — by default, with no caller changes.
- [ ] `convertToModelMessages()` still accepts `https://cdn.example.com/img.png` and `data:image/png;base64,AAA` by default.
- [ ] Caller can pass a custom `UrlGuard` to allow/deny specific hosts.
- [ ] Caller can pass `UrlGuard::disabled()` to skip all validation.
- [ ] `UnsafeUrlException` is thrown with the URL, a stable rule id, and a human-readable reason.
- [ ] `docs/security.md` covers threat model + DNS-rebinding limitation + opt-out recipe.
- [ ] All existing tests still pass; new `UrlGuardTest` exercises every rule + every boundary CIDR.

— *End of plan.*
