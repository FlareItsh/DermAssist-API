---
name: dermassist-artifacts-and-plans
description: Mandatory workflow, protocols, and standard templates for creating markdown artifacts in DermAssist. Always trigger when proposing, planning, designing, evaluating, refactoring, fixing, reviewing, or implementing any features, UI improvements, architecture, or code changes. Strictly prohibits dumping long plans, design suggestions, or technical evaluations directly into chat.
---

# DermAssist Mandatory Artifacts & Planning Protocol

This skill enforces strict standards for creating, maintaining, and linking markdown artifacts for all tasks in DermAssist.

## 1. Absolute Rule: Never Dump Large Plans or Evaluations in Chat (CRITICAL)

The AI assistant must **NEVER** output:
- Lengthy multi-step implementation plans in chat.
- Detailed design critiques, UI suggestions, or evaluations in chat.
- Deep architectural trade-off comparisons or database schema investigations in chat.

**Required Protocol**:
1. **Always write to a Markdown Artifact** in the artifact directory (`<appDataDir>/brain/<conversation-id>/`).
2. **Respond in Chat with a Concise Summary (1-2 paragraphs max)** that highlights key decisions or questions and provides a prominent markdown link to the artifact (e.g. `[implementation_plan.md](file:///path/to/artifact)`).

---

## 2. Core Artifact Types & Triggers

### A. Implementation Plan (`implementation_plan.md`)
- **Trigger**: **Mandatory before making ANY code modifications**, including:
  - New feature development
  - Component or architecture refactoring
  - Bug fixes (frontend, backend, or AI algorithms)
  - UI redesigns or layout improvements
  - Multi-file or single-file alterations
- **Requirement**: Always set `RequestFeedback: true` in `write_to_file` / `replace_file_content` so the user can review and approve before code execution begins.

#### Standard Structure:
```markdown
# Implementation Plan - [Feature or Fix Name]

[Short 1-2 sentence description of what this plan achieves and why.]

---

## User Review Required

> [!IMPORTANT]
> [Highlight any critical trade-offs, breaking changes, or user decisions needed.]

---

## Proposed Changes

### [Component / Layer Name]
- **Target File**: [`path/to/file`](file:///absolute/path/to/file)
- **Changes**:
  - Detailed bullet points of modifications.
  - Code snippets or diff previews.

---

## Verification Plan

### Automated Tests
- Test command: `php artisan test --compact --filter=...` or `pnpm test`
- Formatting command: `vendor/bin/pint --dirty --format agent` or `npx prettier --write ...`

### Manual / Browser Verification
- Step-by-step verification steps.
```

---

### B. Evaluations & Analyses (`evaluation_notes.md`, `analysis_results.md`)
- **Trigger**: Mandatory whenever:
  - The user asks for design feedback, UI suggestions, or architectural evaluations (e.g., *"Can you suggest an improvement here?"*).
  - Comparing libraries, approaches, or database schemas.
  - Auditing performance, security, or system configurations.

#### Standard Structure:
```markdown
# [Topic Name] Evaluation & Design Suggestions

## Executive Summary
[High level overview of findings.]

---

## Evaluation & Recommendations Table
| Area | Current State | Proposed Enhancement | Aesthetic / Technical Impact |
| :--- | :--- | :--- | :--- |
| ... | ... | ... | ... |

---

## Detailed Breakdown & Code Previews
[Detailed sections with concrete code snippets and before/after comparisons.]
```

---

### C. Walkthroughs (`walkthrough.md`)
- **Trigger**: Mandatory upon completing code modifications and automated verification.
- **Requirement**: Summarizes what was changed, shows diff highlights, documents test outputs, and confirms how the task was verified.

#### Standard Structure:
```markdown
# Walkthrough - [Feature or Fix Name]

## Overview
[What was accomplished and why.]

---

## Key Changes Made
### [Area]
- **Location**: [`path/to/file`](file:///path/to/file)
- Description of changes with short code blocks.

---

## Verification Results
- Test outputs and commands run.
- Browser/UI verification results.
```

---

## 3. Precedence & Zero-Tolerance Enforcement

- **No Size Exceptions**: Even for single-file or single-component changes, an implementation plan or evaluation artifact must precede execution if the user's intent involves design or planning decisions.
- **Immediate Artifact Creation**: Do not wait for the user to ask "can you make an artifact?". Proactively create the artifact on the first turn.
