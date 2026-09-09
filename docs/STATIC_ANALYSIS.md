# Static analysis — the gate that makes agentic development possible

## Why this is the load-bearing part

An agent writing PHP has no compiler telling it that a property is nullable, that a method may throw, or that a repository just handed back `mixed`. Without a machine check, "the code looks right" is the only signal available — and an LLM is extremely good at producing code that looks right.

Static analysis converts that into a hard, automatable verdict: `No errors found!` or a list of file:line problems the agent can act on without a human. That is precisely the feedback loop an autonomous cycle needs, and it is why an agentic loop bolted onto an untyped, unanalysed codebase produces plausible garbage no matter how good the prompts are.

**The same gate applies to humans.** A rule agents must satisfy and humans may bypass stops being a rule within a sprint, the baseline rots, and the signal the agents depend on disappears.

## The four gates

| Tool | Catches | Config |
|---|---|---|
| **Psalm** | Type errors, `mixed` leaking across boundaries, missing `@throws`, unused params, forbidden functions | `tooling/psalm.xml` |
| **Rector** | Code that has not kept up with the PHP version; dead patterns; mechanical modernisation | `tooling/rector.php` |
| **Deptrac** | Layer violations — Domain importing Infrastructure, cross-domain reach-through | `tooling/deptrac.yaml` |
| **PHPUnit** | Behaviour | `make test-unit`, `make test-integration` |

### Psalm at `errorLevel="1"`

The shipped config runs the strictest level with the strict switches on: `checkForThrowsDocblock`, `disableSuppressAll`, `ensureArrayStringOffsetsExist`, `findUnusedBaselineEntry`, `findUnusedPsalmSuppress`, `findUnusedVariablesAndParams`, `reportMixedIssues`, `sealAllMethods`.

`forbiddenFunctions` bans `dd`, `die`, `dump`, `echo`, `eval`, `exit`, `print`, `var_export` — the debugging residue that agents and humans both leave behind.

**On baselines.** A baseline is a list of errors you have agreed to ignore, and an agent reads it as permission. Run without one if you can. If you must introduce one (a Psalm major upgrade, say), treat it as debt with a stated shrink plan, and never let an agent regenerate it — an agent that can run `--set-baseline` will "fix" a red run by declaring the errors historical.

**On suppressions.** A targeted `@psalm-suppress IssueType` is acceptable only with a comment saying why. A global suppression in `psalm.xml` needs an architectural justification written into the config next to it — the shipped file shows the shape: `MissingThrowsDocblock` is suppressed in adapter layers because transitive vendor exceptions are an implementation detail, not part of the domain contract, and that reasoning is recorded in an XML comment rather than left implicit.

### Rector

Runs `--dry-run` in CI and in the check target; `rector-fix` applies. Keeping it in the loop stops the codebase from drifting behind its declared PHP level, which matters more than usual with agents in the mix: an agent imitates the code it reads, so stale patterns propagate.

### Deptrac

Deptrac is the one gate that checks something no type checker can: whether the architecture the ADRs describe is the architecture the code has.

The shipped ruleset defines four layers — Domain, Application, Infrastructure, UI — and allows dependencies to point only inward:

```
UI -> Application -> Domain
Infrastructure -> Application, Domain
Domain -> (nothing)
```

For agents this is decisive. "Cross-domain access goes through an Application service, an event, or a Uuid plus your own repository" is a sentence in the ADRs that a reviewer may or may not notice being broken. Deptrac makes it a build failure with a file and a line.

## How the gates plug into the loop

```
executor implements a plan step
        |
        v
   make fix-all          <- auto-fix style first, so the analysers report real problems
        |
        v
   make test-unit  ->  make psalm  ->  make rector  ->  make deptrac
        |
   red? -----------------> self-correct (max 3 iterations for the whole run)
        |                        |
        |                  still red after 3 -> HALT, escalate to a human
        v
   all green -> commit, push, hand to the reviewers
```

Three rules make this work rather than merely exist:

1. **Style is auto-fixed before analysis.** Otherwise the analysers report formatting noise and the agent spends its self-correct budget on whitespace.
2. **The self-correct budget is 3 iterations for the whole executor run**, not per step. If step one ate two, step two has one.
3. **A red analyser is a blocker, not an optimisation.** The executor's hard rules say so in those words, because the natural failure mode is an agent reporting "implemented successfully, 4 Psalm errors remain to be addressed".

And the boundary that keeps this honest: **a red run caused by uncovered new logic is not self-correct territory.** A missing test or an unhandled edge case halts as `LOGIC_CONCERN` and goes back to the planner. Self-correct is for typos.

## CI

`.github/workflows/ci.yml` runs the same four gates on every push and pull request. Make them required status checks on your default branch, so the merge gate is enforced by the platform rather than by everyone's good intentions.

The Psalm job uses `--output-format=github`, which annotates the PR diff inline — worth doing, since the human's 5–10 minute merge review is the last gate in the pipeline and inline annotations are what make it possible in that time.

## Local commands

```bash
make psalm         # full run
make psalm-diff    # only files changed against origin/main — fast pre-commit check
make rector        # dry run
make rector-fix    # apply
make deptrac       # layer boundaries
make test-unit
make test-integration
make fix-all       # rector-fix + code style
make check         # lint + psalm + rector + deptrac
```

**After finishing any task — even a one-line fix — run `make check`.** If it is red, the task is not done.
