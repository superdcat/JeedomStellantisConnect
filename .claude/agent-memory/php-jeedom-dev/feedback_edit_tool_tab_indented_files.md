---
name: feedback-edit-tool-tab-indented-files
description: Edit tool old_string match fails silently on tab-indented Jeedom desktop/php templates — fall back to a Python script that derives real indentation from the file (via cat -A / repr) instead of retyping whitespace by hand.
metadata:
  type: feedback
---

Jeedom's `desktop/php/*.php` templates (e.g. `stellantis.php`) are indented with **tabs**, not spaces
(confirmed via `sed -n '<range>p' file | cat -A` showing `^I` markers), and use **CRLF** line endings.
`core/class/stellantis.class.php`, by contrast, uses 2-space indentation. When constructing a multi-line
`old_string`/`new_string` for the `Edit` tool by re-typing content seen in a `Read` result, the retyped
whitespace does not reliably reproduce the file's actual tabs — the `Edit` call then fails with "String to
replace not found in file" even though the visible text looks identical, because `Read`'s cat -n rendering
doesn't visually distinguish tabs from spaces.

**Why:** wasted several failed `Edit` attempts trying to insert a new HTML block into
`desktop/php/stellantis.php` (UC73 form fields) before recognizing the root cause was tab vs. space
mismatch, not a wrong anchor.

**How to apply:** when an `Edit` on a `desktop/php/*.php` (or any tab-indented) file fails to match despite
the anchor text looking present (confirmed via `Grep`), don't keep retrying `Edit` with re-typed
indentation — switch to a `Write`-then-`Bash python` approach: read the file in Python
(`io.open(path, encoding='utf-8', newline='')` to preserve `\r\n`), locate the anchor via a stable
substring (e.g. a unique attribute like `data-l2key="..."`, not full lines), derive the real indentation by
slicing the line's leading `[\t ]*` (regex `^[\t ]*`) directly from file content rather than guessing, build
new lines by adding/removing one `\t` per nesting level relative to that derived indent, join with the
file's actual line ending (`\r\n` if that's what's there), and write back with matching `newline=''`. This
guarantees byte-exact indentation consistency with the surrounding template instead of hoping a hand-typed
`old_string`/`new_string` matches. See also [[feedback-no-local-php-verification]] (same environment, no
local `php`/lint to catch the fallout of a bad manual edit — another reason to prefer the derived-indent
script over retyped whitespace).

**Concrete recipe that worked end-to-end (UC76, inserting a new `<div class="form-group">` block into
`desktop/php/stellantis.php`, tabs+CRLF):**
1. `Write` the new block to a scratch file using **real tab characters** for indentation (typed literally,
   not `\t`) and **real newlines** (`\n`, i.e. normal multi-line string) — use *relative* nesting depth
   starting wherever is convenient (e.g. 0/1/2/3 tabs), don't try to match the target file's absolute depth
   yet.
2. Inspect the target file's actual absolute tab depth at the insertion point with
   `sed -n '<n>,<m>p' file | cat -A` (counts `^I` per line) to get the base indent (e.g. 7 tabs for a
   `form-group` at that nesting level in this template).
3. Flat-prepend the missing base tabs to *every* line of the scratch block in one shot:
   `sed -i 's/^/\t\t\t\t/' scratchfile` (count = target base − what you used in step 1) — since every line
   in the block was written at a consistent *relative* depth, a single flat prefix correctly re-bases the
   whole block without touching relative nesting.
4. Splice the now correctly-indented block into the target file with a `perl -0777` script that: reads
   both files raw (`<:raw`), normalizes the block's line endings (`s/\r\n/\n/g; s/\n/\r\n/g` — cheap
   idempotent CRLF enforcement), finds a **stable literal anchor substring already containing real tabs**
   (e.g. `"\t\t\t\t\t\t\t<!-- UC24 : suivi"` — copy this from a `Grep`/`Read` of the target file, not
   retyped) via `index($content, $marker)`, and writes `substr(...,0,$idx) . $block . substr($content,$idx)`
   back to the target file. No regex substitution of escape-sequence text is needed at any point (avoids
   the Bash-argument backslash-collapsing pitfall in [[feedback-no-local-php-verification]]).
5. Verify structurally: `git diff --stat` (expect a small, clean insertion, no reformatting of surrounding
   lines) and a brace/paren-balance script (see [[feedback-no-local-php-verification]]) on the *whole*
   file, comparing the mismatch count against the same check run on `git show HEAD:<file>` — an unchanged
   mismatch count (e.g. from pre-existing `{{...}}` i18n placeholders in HTML being miscounted as code
   braces by a naive stripper) confirms the insertion didn't introduce a *new* imbalance, even when the
   file was never balanced to begin with.

If step 1-4 goes wrong (e.g. wrong tab count guessed), don't try to patch the damage in place — `git status`
then `git checkout -- <file>` to reset to pristine and redo the sequence with the corrected tab count; much
faster than surgical fixes on a mangled CRLF/tab file.
