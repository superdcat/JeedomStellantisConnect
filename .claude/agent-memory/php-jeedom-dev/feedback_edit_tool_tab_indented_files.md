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
