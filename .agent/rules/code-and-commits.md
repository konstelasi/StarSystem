---
trigger: always_on
---

# Labels in code and commits

Rules for this repository only. They add to `core.md` and win on a conflict. Do not edit `core.md` or the other synced guides here.

Comments, docblocks, commit messages, branch names and pull request text are read by people who have the code and nothing else. A number or name that only a plan defines means nothing to them, and it stays in the history after the plan changes.

## The test

A line passes when a stranger holding only the code can follow it. Say what the code does and why, in a plain sentence.

## Never write

1. A phase, stage, milestone or step that numbers a plan, such as "Phase 3", "Stage B", "step 4 of 7" or "M2".
2. The name of a brief, plan, roadmap or design document, or a section number of one.
3. An ADR or blueprint number, such as "ADR 0007". Describe the decision in words.
4. "The plan" or "as planned" as the reason for a choice.

A step that the code itself has, such as the updater's step machine, is not a label. The test is whether the number comes from the code or from a plan.

## Check before every commit

Both commands should print nothing. Run the same patterns over the message text too.

```bash
git diff --cached -U0 -- . ':!.agent' | grep -E '^\+' | grep -iE '\b(phase|stage|milestone) ?[0-9]+\b|\b(phase|stage|milestone) [a-d]\b|\bstep [0-9]+ of [0-9]+\b|\bADR ?[0-9]+'
git diff --cached -U0 -- . ':!.agent' | grep -E '^\+.*\bM[0-9]+\b'
```
