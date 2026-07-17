---
name: feedback-no-local-php-verification
description: No `php` binary exists in this dev environment (Windows/Git Bash) — use a Python-based structural sanity check on the diff instead of `php -l`, and write scripts to a file rather than inline heredoc.
metadata:
  type: feedback
---

`php -v`/`php -l` are unavailable in this repo's dev shell (Git Bash on Windows) — confirmed via
`which php` returning nothing. CLAUDE.md already flags this and says to rely on CI, but CI only runs on
push/PR, so there's no fast local signal during an editing loop.

**Why:** for a PHP file this large (`core/class/stellantis.class.php`, ~3000+ lines), eyeballing brace/
paren balance after an Edit is error-prone, and the project has a documented history of a `*/`-inside-a-
docblock bug breaking prod undetected until CI (cf. `php-cron-expr-in-docblock-pitfall` in the main
project's Claude memory).

**How to apply:** `python` (not `python3`/`py` — those resolve to broken Windows Store shims in this
environment, `python` resolves to the real `C:\Python314\python.exe`) is available and can be used to
write a small script that strips PHP `//`/`#`/`/* */` comments and `'...'`/`"..."` string literals (with
backslash-escape handling), then counts `{}`/`()`/`[]` balance and tracks a running depth to catch a
close-before-matching-open. This catches the exact class of bug (`*/` closing early, unbalanced
brackets) that `php -l` would catch, without needing a PHP install. **Write the script to a file with the
`Write` tool first, then run it with Bash** — passing it inline via a Bash heredoc mangles backslash
escapes (`'\\'` inside the command string gets collapsed to `'\'`, causing a Python `SyntaxError`) because
of how the Bash tool's command parameter handles escaping. This is a supplement to reading the diff
carefully, not a replacement — it doesn't catch semantic bugs, only structural/lexical ones.

**Generalizes beyond Python** (confirmed UC76): the same backslash-collapsing hits **any** inline script
text passed as a Bash tool command argument — reproduced it with an inline `perl -e '...s/\\t/\t/g...'`
meant to convert literal two-char `\t`/`\n` escape sequences (written into a scratch file via `Write`) into
real tab/CRLF bytes for a tab-indented Jeedom template; the double backslashes silently became single
backslashes before reaching perl, so the substitution ran on the wrong pattern and produced garbled output
that had to be reverted (`git checkout --`) and redone. **Fix that actually worked**: never put escape-
sequence *text* (`\t`, `\n`, `\\`) into a Bash command string at all — write the **real** tab/newline
characters directly into a scratch file via the `Write` tool (the tool call's own string decoding handles
those correctly, unlike a nested Bash-command string), then do purely *byte-level* file-to-file operations
(`sed 's/^/\t\t\t\t/'` to flat-prepend real tab characters already typed literally in the sed script, or a
`perl -0777` script that reads two files and splices their raw bytes with `s/\r\n/\n/;s/\n/\r\n/` for line-
ending normalization) — no escape-sequence interpretation is needed mid-pipeline because the bytes are
already correct on disk. See [[feedback-edit-tool-tab-indented-files]] for the full recipe this produced.
