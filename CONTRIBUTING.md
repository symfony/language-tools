# Contributing to Symfony Language Tools

Thank you for helping improve Symfony Language Tools.

> [!IMPORTANT]
> This repository accepts issues, not external pull requests. The pull request
> feature is disabled. Please report bugs and propose features through
> [GitHub Issues](https://github.com/symfony/language-tools/issues) instead.

## Why issues instead of pull requests?

This is an experiment in issue-first open source development. A useful issue
captures the part of a contribution that is hardest to reconstruct: the problem,
its context, details from the reporter's actual project or use case, and the
constraints a solution must respect.

The most valuable information is often specific to the reporter: their setup,
configuration, project structure, workflow, constraints, and observed behavior.
This context helps steer a fix or feature in the right direction.

Maintainers use coding agents to turn selected issues into code, tests, and
documentation. Maintainers still review and validate every change before it is
committed. The quality of the issue directly affects how quickly and accurately
the change can be implemented.

This model also avoids asking contributors to spend time on a patch that may not
match the project's architecture, scope, or priorities. It is an experiment and
may change as we learn from it.

## Before opening an issue

- Search the [existing issues](https://github.com/symfony/language-tools/issues)
  to avoid duplicates. Adding a reproduction or new context to an existing
  issue is often more useful than opening another one.
- Confirm that the problem still occurs with the
  [latest release](https://github.com/symfony/language-tools/releases/latest).
- Open one issue per bug or feature.
- Report potential security vulnerabilities privately by following the
  [security policy](SECURITY.md).

## Reporting a bug

There is no required template. Use whatever format best explains the problem in
your environment. You and your agent can decide which details are useful. These
might include:

- What happened and what you expected instead.
- Relevant details about your setup, configuration, project, editor, LSP client,
  or package versions.
- Steps, code, logs, screenshots, recordings, or other clues that illustrate the
  problem.
- Whether the behavior is a regression and, if known, the last version that
  worked.
- A minimal reproduction, when creating one is practical.

For editor integrations such as Visual Studio Code, creating a separate
reproduction can be difficult. It is helpful when practical, but it is not
expected. Sanitized configuration, logs, screenshots, or a description of the
affected workspace may provide better clues.

When relevant, run `symfony-lsp --version` for the standalone server or include
the extension version shown by Visual Studio Code. Mention whether the
application runs locally, in a container, or on a remote machine if that context
matters.

Remove credentials, environment values, private source code, customer data, and
other sensitive information before sharing configuration or logs.

## Requesting a feature

There is no required template for feature requests either. Explain the idea in
whatever form best captures the need. Useful context might include:

- The problem or workflow you want to improve.
- A concrete example from your project or use case.
- The behavior or editor experience you would find useful.
- The affected Symfony integration, editor, configuration, or project setup.
- Workarounds, alternatives, or constraints that may help shape the solution.

A proposed API, design, or mockup is welcome but optional. Describing the need
is often enough. Maintainers may choose a different implementation that better
fits the project.

## Using agents and sharing an investigation

Issues prepared with the help of an agent are welcome. Start by pointing the
agent to `CONTRIBUTING.md` and ask it to help prepare the issue. You and the
agent should use your judgment about the format and the details that best
explain your situation.

An agent can inspect your project, gather relevant environment and configuration
details, create a reproduction when useful, analyze logs, or explain the exact
problem. Include whatever findings add useful context about your real setup or
use case.

An agent working in the affected project can often collect this context itself.
Tell it which action and file trigger the problem, then let it inspect relevant
Composer metadata, editor settings, project structure, and source files.

The server writes logs and errors to standard error. The editor or LSP client
decides where to expose or store them. Useful starting points include:

- **Visual Studio Code:** Open `View > Output` and select
  `Symfony Language Tools`. Temporarily set `symfonyLsp.trace` to `messages` or
  `verbose` for redacted protocol details, reproduce the problem, then restore
  it to `off`. See the
  [VS Code troubleshooting guide](docs/editors/vscode.rst#troubleshooting).
- **Neovim:** Run `:checkhealth vim.lsp`. The command
  `:lua print(vim.lsp.log.get_filename())` prints the LSP log path. Set `trace`
  to `messages` or `verbose` temporarily when protocol details may help. See the
  [Neovim troubleshooting guide](docs/editors/neovim.rst#troubleshooting).
- **OpenCode:** Run `opencode debug config` to inspect the resolved
  configuration, `opencode debug lsp diagnostics <file>` for an affected file,
  and `opencode debug paths` to locate its logs. See the
  [OpenCode troubleshooting guide](docs/editors/opencode.rst#troubleshooting).
- **Other clients:** Inspect the client's language server log or captured
  standard error, and include its startup command and configuration when useful.

If the agent cannot access the editor interface, paste the relevant output into
the conversation or save it in a temporary file the agent can read. Tracing
redacts common sensitive fields, but review all output before sharing it.
Relevant excerpts are usually more useful than a complete log.

Root-cause analysis is also welcome but not required. If you or an agent
investigated the code, findings such as a suspected cause, affected components,
constraints, or ways to validate a change can help guide the implementation.

Review an agent-assisted report before submitting it, remove sensitive
information, and make sure it relates to behavior or a need you actually
observed. Agent-generated analysis or draft patches are welcome when useful, but
do not mass-submit generic or speculative reports.

## What happens next?

Maintainers will triage the issue, ask for missing information when needed, and
decide whether it fits the project's scope and priorities. When an issue is
selected for implementation, it becomes the brief used by agents and
maintainers to produce and validate the change.

Opening an issue does not guarantee that it will be implemented. Duplicate,
out-of-scope, or reports that cannot be acted on after follow-up may be closed.

Even without a reproduction, proposed solution, or code contribution, context
from a real project or use case is valuable. Thank you for taking the time to
provide it.
