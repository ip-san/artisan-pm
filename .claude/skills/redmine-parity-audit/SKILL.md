---
name: redmine-parity-audit
description: "Independently verify how well this app (a Laravel reimplementation of Redmine) actually matches Redmine's real behavior, instead of trusting docs/parity-checklist.md's or docs/redmine-gap-backlog.md's own self-reported 'done' status. Use this whenever the user asks things like 'is this really the same as Redmine', 'compare against Redmine again', 'even compared to Redmine, does it hold up', 'list what's really left', or asks for an audit/second opinion/sanity check on parity claims, gap lists, or backlog completeness — especially after the docs have been marked done by prior implementation sessions. Also use it before telling the user a parity effort is '完了'/'complete' if they push back or ask to confirm independently. Do not confuse this with a code-quality review (that's the laravel-best-practices/code-review skills) — this skill is specifically about verifying CLAIMS OF MATCHING AN UPSTREAM PROJECT (Redmine) by reading Redmine's actual source, not about code style."
license: MIT
---

# Redmine parity audit

## Why this skill exists

This app's own docs (`docs/parity-checklist.md`, `docs/redmine-gap-backlog.md`) are written by the same kind of
AI implementation agents that do the work, across many sessions. They self-assess their own work as matching
Redmine and mark rows `done`. That is not the same as independent verification — an implementer who wrote code
believing it matches Redmine has every incentive (and every blind spot) to also believe the doc line that says so.
When a user asks "does this really match Redmine" after a big streak of `done` marks, answering by re-reading the
same docs the same agents wrote is circular. The only thing that actually answers the question is opening
Redmine's real source and this app's real source, side by side, for a specific claim.

This was learned the hard way in one session: a broad self-report of "everything is done" held up fine against a
handful of spot-checks, but a systematic, full-coverage pass (~290 checklist rows, ~165 deeply checked) turned up
9 real code bugs the docs had certified as matching Redmine — including one with security implications (a
closed-project permission check that was backwards) — plus several places where the docs themselves were stale
and contradicted the actual (already-correct) code. Neither direction of error is rare: docs can overclaim OR
underclaim, and only reading the source settles which.

**This is not a one-off lesson — it's this project's recurring pattern.** `docs/parity-checklist.md` was itself
originally built (commit `725db50`) from five parallel audits comparing this app against Redmine controller-by-
controller. The very next audit pass after that (`f8e30ba`, "C-01..C-16: Apply checklist corrections found by the
source comparison") found over a dozen stale statements in the checklist it had just produced. A later pass
(`2730c47`) found that Tracker/IssueStatus admin screens had been marked `done` because the underlying *model*
existed, with nobody having actually checked that an admin route or view existed to use it — and that fix commit
says explicitly: **"a stale `done` is worse than a `missing` for a document whose whole purpose is tracking real
gaps."** Treat every `done` mark in this project's docs, however recent or however many prior audits it survived,
as a claim to verify, not a fact to build on — the project's own history shows this isn't paranoia, it's the
observed base rate.

## The one failure mode to avoid: nested delegation

The first attempt at a large audit used a single coordinator agent that itself spawned five more agents and waited
on them. It stalled for over ten minutes with zero recoverable progress, and the five orphaned sub-agents took
**hours** to respond to direct pings afterward — the whole attempt was wasted. Every other large-scale delegation
in the same session that used flat, single-level dispatch (the orchestrating session directly launching several
independent agents in one turn, none of which spawn further agents) worked reliably, every time.

**Rule: when you delegate an audit, delegate it flat.** You (the orchestrating session) launch every worker
directly, in one message so they run in parallel, and explicitly tell each one not to use the Agent/Task tool
itself. If a worker's job is big enough that it would want to delegate further, that is a sign to split the work
into more flat workers up front, not to let one worker sub-delegate.

## How to scope and split the audit

1. Use `docs/parity-checklist.md`'s row list as your INDEX of what to check — not as evidence. It's organized into
   numbered sections (課題管理, プロジェクト・管理機能・認証, コンテンツモジュール, クエリ・レポート・工数管理・
   ダッシュボード, リポジトリ・REST API・拡張性). Count rows per section (`grep -c` a `done|partial|missing`
   pattern within each section's line range) and split into chunks of roughly 40-55 rows each — big enough to be
   worth a worker, small enough that one agent can get through it without rushing every row. The biggest sections
   (Issues, Projects/Admin/Auth) split into two chunks each; the rest are one chunk each. This produced 7 workers
   for ~290 rows in the session that established this pattern — use that as a starting scale, not a hard number.
2. Give each worker an exact line range (`sed -n 'START,ENDp' docs/parity-checklist.md`) so ranges don't overlap
   or gap.
3. Write ONE shared brief file (in your scratch/temp directory) that every worker reads, rather than repeating the
   methodology in each dispatch prompt. Keep the per-worker prompt to just: "follow the brief, your range is
   X-Y." This is what let a 7-way audit stay consistent without drifting into 7 different methodologies.

## What the shared brief must say

- **Read-only.** No edits, no commits, no write-path tests. This is a verification pass, not an implementation
  pass — mixing the two means a worker under time pressure starts "fixing" things instead of finishing the audit,
  and you lose the clean signal of what's actually broken before anything changes.
- **Do not trust the checklist's status label or prose as ground truth.** Use it only to know which feature to
  look at. Every substantive claim must be re-derived from Redmine's own source
  (`/Users/sesoko/Desktop/workspace/redmine`, or wherever this session's Redmine checkout lives) and compared
  directly against this app's code. If the checklist's own description of what Redmine does is wrong, that's a
  finding too, not something to inherit as fact.
- **No sub-agents.** State this explicitly and give the reason (see above) — a worker that hits a big row and
  wants to "spawn someone to look into it" should instead just budget its own time across rows.
- **Pace against the row count, not per-row perfectionism.** A worker with 40-55 rows cannot deeply verify every
  one in reasonable time. Tell it explicitly: for each row, find the ONE most load-bearing, falsifiable claim
  (an exact permission name, a default value, a multi-step algorithm's tie-break rule, a specific SQL condition
  shape) and check that; skip rows that are pure CRUD/UI description with nothing falsifiable, and say so rather
  than silently omitting them. This triage is what makes "audit everything" tractable instead of a false choice
  between "audit 6 things well" and "audit 290 things badly."
- **Every verdict needs evidence from both sides.** `redmine/app/models/foo.rb:123` and `app/Models/Foo.php:456`
  (or the equivalent view/controller/config path). A ✅ or ❌ with no file:line from both sides is not acceptable —
  it's just a restatement of an opinion. Reading Redmine's actual method body matters too: a filename match or a
  comment referencing the right concept is not the same as reading the real condition/algorithm and confirming it
  matches.
- **Four verdicts, defined precisely:**
  - ✅ 確認一致 — read both sides, they match.
  - ⚠️ 軽微な差異 — matches in spirit but a concrete, specific difference exists; say what it is and whether it's
    a real gap or a reasonable/documented simplification. (A large fraction of real findings land here — don't
    force everything into ✅/❌.)
  - ❌ 不一致・バグ — the claim is false, overstated, or the code doesn't do what's claimed. This includes the
    case where the *checklist's own explanation of Redmine's behavior* is factually wrong, even if the app's
    behavior happens to be reasonable on its own terms.
  - 未検証 — explicitly budgeted out; say why (purely cosmetic, or ran out of time). This is an honest, expected
    outcome, not a failure — a worker that claims to have verified 50 rows deeply in the time available is lying
    to itself.
- **Report format**: one block per row, in the target language, with 判定/根拠/所見 fields (see the worked
  example in the session transcript this skill was drawn from, or improvise a consistent equivalent). Consistent
  formatting across workers is what lets you compile a master report afterward without re-reading everything.
- End with a tally (✅n/⚠️n/❌n/未検証n) and a short list of anything worth a NEW backlog row, with enough detail
  (file:line, concrete failing scenario) to act on directly.

## Known traps when the evidence is a grep, not a read

Mechanical cross-referencing (grepping Redmine for a setting key, permission name, or column, then grepping this
app for the same string) is a fast way to find candidates, but this project's history has hit the same two traps
more than once. Warn workers about both explicitly:

- **A grep hit for "added" doesn't mean it's still there.** A schema column or Redmine feature found via one
  migration/commit can be removed by a *later* one. `A1-24`/`C-18` in the backlog records exactly this: a "missing
  column" finding turned out to be a column Redmine had dropped years earlier — the audit had only grepped the
  `add_column` migration and never checked for a subsequent `drop_column`. When a "Redmine has X, this app
  doesn't" claim rests on a migration or a single commit, check the *current* state of Redmine's schema/source,
  not just the first hit.
- **A grep for one call convention misses indirect wiring.** The original settings-key cross-reference flagged
  `default_projects_modules` and `issue_list_default_columns` as unimplemented because neither appeared in a
  `Setting::get('...')` call — but both were actually wired through a Livewire component property instead, which
  the grep pattern didn't match. Before concluding something is missing because a direct call/reference isn't
  found, check whether it's reached through an intermediate layer (a computed property, a cached value, a service
  class, a differently-named alias) — read the feature's actual UI/behavior, don't stop at one grep pattern coming
  back empty.
- **A model/column existing is not a feature existing.** `2730c47`'s finding (above) generalizes: checking that
  the *backend* concept exists (a model, a table column, a service class) is not the same as checking that a user
  can actually reach and use it (a route, a view, a nav link, an admin menu entry). When a claim is "X is
  implemented," trace the whole path a user would take, not just the data layer underneath it.

These traps mean a bare `grep -c` count (rows matching a pattern) is a *lead*, never a verdict — the file:line
evidence requirement above exists partly to force actually opening the file the grep pointed at, rather than
trusting the hit count.

### A complementary, different-shaped technique: bulk canonical-list diffing

Everything above is about verifying rows someone already wrote down (are these `done` claims true?). The
checklist's original construction used a different, complementary technique worth reaching for when the question
is instead "is anything *entirely* unbuilt that nobody has written a row for yet": diff whole canonical lists
between Redmine and this app at once — every key in `config/settings.yml`, every permission in
`lib/redmine/preparation.rb`, every route in `config/routes.rb`, every queryable filter/column in `*_query.rb`,
every schema column, every Wiki macro, every My Page block type, every Webhook event — and see which names have no
counterpart at all. This surfaces wholesale gaps fast, but produces exactly the two false-negative traps above at
a higher rate (bulk diffing is even more grep-shaped than row-by-row auditing), so any "Redmine has this key/route/
permission and the app has nothing" finding from this technique still needs the same file:line confirmation before
it goes in a report.

## Cross-checking pays off

Two different workers, auditing two different checklist sections, independently found the same stale-documentation
issue (a settings-default change that landed in one commit but wasn't reflected in two different checklist
sections describing those settings). That kind of convergent, independent discovery is strong evidence the finding
is real, not a misreading — when it happens, say so in the compiled report; it's more convincing than either
finding alone.

## Compiling the master report

- Sum the tallies across workers for a top-line number.
- Separate **real code bugs** (❌ that are about the app's actual behavior) from **stale documentation** (❌ or ⚠️
  where the code is fine but the checklist/backlog text lags behind a later change). These need different fixes —
  one is a code change, the other is a docs-only edit — and conflating them in the report makes prioritization
  harder for the user.
- Rank real bugs by actual impact (security/data-integrity first, then functional gaps, then narrow edge cases),
  not by which section happened to find them.
- Be explicit and honest about what was NOT checked (the 未検証 rows), so "audit complete" doesn't imply "100%
  of the app verified" when it means "100% of the app was looked at, most of it verified, some of it triaged out."

## Turning findings into action

- Record findings as new, explicitly-approved backlog rows (this app's existing convention: `A16-XX` etc. in
  `docs/redmine-gap-backlog.md`, following the same queue-row + A-row pattern already used there) before
  implementing fixes — don't silently fix things a verification pass turned up without the same approval gate
  the rest of this app's backlog process uses.
- If a finding's fix would change existing data or an existing enum/schema in a way that could affect already-saved
  user configuration (e.g. removing an enum case a role setting currently uses), don't just delete it — surface
  the choice to the user (keep-and-document vs. migrate-and-remove) the same way any other schema-risk decision in
  this project's workflow gets surfaced, rather than picking one unilaterally.
- Pure documentation-staleness findings are zero-risk text edits — fix those directly without needing the same
  weight of approval as a behavior change, but still call them out so the user knows the doc was wrong, not just
  silently right one edit later.
