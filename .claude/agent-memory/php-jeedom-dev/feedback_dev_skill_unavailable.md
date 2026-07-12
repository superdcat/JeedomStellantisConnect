---
name: feedback-dev-skill-unavailable
description: The `dev` skill referenced in this sub-agent's own system prompt is not actually invokable via the Skill tool in this harness — proceed directly with the inline methodology instead of retrying.
metadata:
  type: feedback
---

Invoking `Skill(skill: "dev")` fails with `Unknown skill: dev. Did you mean new?` — the `dev` skill is
**not** present in the actual `skills` list surfaced by the system reminder in this harness, even though
this sub-agent's own system prompt claims it is "préchargée dans ton contexte au démarrage". This is a
mismatch between the sub-agent prompt template and the runtime skill registry, not a transient error.

**Why:** confirmed by direct tool-call failure at the start of a UC33 implementation session
(2026-07-12) — the call errored immediately rather than timing out or being denied by permissions.

**How to apply:** don't retry the `Skill` tool call or waste a turn investigating why it's missing. Just
proceed directly with the methodology already spelled out inline in the sub-agent prompt: read
CLAUDE.md → tech spec → functional spec → reformulate acceptance criteria as an observable checklist →
implement in small increments → verify (static/manual reasoning, since `php -l` is unavailable, cf.
[[feedback-no-local-php-verification]]) → self-review → iterate to convergence. Only escalate/ask if
genuinely blocked on a plan ambiguity, not on tooling availability like this.
